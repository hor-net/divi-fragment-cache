<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DFC_Cache {
	private const TRANSIENT_PREFIX = 'divi_fc_';
	private const PAGE_TRANSIENT_PREFIX = 'divi_fpc_';
	private const CACHE_GROUP      = 'divi-fragment-cache';
	private const POST_META_KEY    = '_divi_fragment_cache_keys';
	private const QUERY_BYPASS     = 'divi_fc_bypass';
	private const QUERY_PURGE      = 'divi_fc_purge';
	private const ACTION_PURGE_ALL = 'dfc_purge_all';

	private DFC_Options $options;

	private array $served_stack = [];
	private array $miss_stack   = [];
	private array $tag_counters = [];
	private array $pending_post_keys = [];
	private bool $request_bypass = false;
	private bool $should_call_divi_reinit = false;
	private array $pending_animation_data = [];
	private int $debug_hits = 0;
	private int $debug_misses = 0;
	private int $debug_purges = 0;

	private bool $page_cache_active = false;
	private bool $page_cache_finalizing = false;
	private bool $page_cache_stored = false;
	private string $page_cache_key = '';
	private string $page_cache_buffer = '';
	private int $page_cache_ob_level = 0;
	private ?int $page_cache_next_invalidate_at = null;
	private bool $page_cache_uncacheable = false;
	private int $page_debug_hits = 0;
	private int $page_debug_misses = 0;
	private string $page_store_status = '';

	public function __construct( DFC_Options $options ) {
		$this->options = $options;
	}

	public function bootstrap(): void {
		add_action( 'init', [ $this, 'maybe_handle_full_page_cache' ], 0 );
		add_filter( 'pre_do_shortcode_tag', [ $this, 'track_shortcode_conditions_for_page_cache' ], 5, 4 );
		add_filter( 'pre_do_shortcode_tag', [ $this, 'maybe_return_cached_shortcode' ], 120, 4 );
		add_filter( 'do_shortcode_tag', [ $this, 'maybe_store_cached_shortcode' ], 120, 4 );
		add_action( 'wp', [ $this, 'handle_query_params' ], 0 );
		add_action( 'send_headers', [ $this, 'send_debug_headers' ], 999 );
		add_action( 'wp_footer', [ $this, 'maybe_print_edd_purchase_link_state_script' ], 998 );
		add_action( 'wp_footer', [ $this, 'maybe_print_animation_reinit_script' ], 999 );
		add_action( 'admin_bar_menu', [ $this, 'register_admin_bar_menu' ], 100 );
		add_action( 'admin_post_' . self::ACTION_PURGE_ALL, [ $this, 'handle_purge_all_request' ] );
		add_action( 'wp_ajax_dfc_edd_cart_state', [ $this, 'ajax_edd_cart_state' ] );
		add_action( 'wp_ajax_nopriv_dfc_edd_cart_state', [ $this, 'ajax_edd_cart_state' ] );
		add_action( 'save_post_customer_discount', [ $this, 'on_customer_discount_changed' ], 20 );
		add_action( 'deleted_post', [ $this, 'on_deleted_post' ], 20, 1 );
		add_action( 'shutdown', [ $this, 'flush_pending_post_keys' ], 0 );
		add_action( 'shutdown', [ $this, 'finalize_full_page_cache' ], 9999 );
		add_action( 'save_post', [ $this, 'on_save_post' ], 20, 1 );
		add_action( 'delete_post', [ $this, 'on_delete_post' ], 20, 1 );
	}

	public function on_customer_discount_changed(): void {
		$this->delete_edd_discounts_pro_transition_transient();
	}

	public function on_deleted_post( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}
		$type = function_exists( 'get_post_type' ) ? (string) get_post_type( $post_id ) : '';
		if ( 'customer_discount' !== $type ) {
			return;
		}
		$this->delete_edd_discounts_pro_transition_transient();
	}

	private function edd_discounts_pro_transition_transient_key(): string {
		$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
		return 'dfc_edd_dp_next_transition_' . $blog_id;
	}

	private function delete_edd_discounts_pro_transition_transient(): void {
		$key = $this->edd_discounts_pro_transition_transient_key();
		if ( '' === $key ) {
			return;
		}
		delete_transient( $key );
	}

	private function edd_discounts_pro_next_transition_timestamp(): ?int {
		$key = $this->edd_discounts_pro_transition_transient_key();
		$cached = get_transient( $key );
		if ( is_numeric( $cached ) ) {
			$cached = (int) $cached;
			return $cached > 0 ? $cached : null;
		}

		if ( ! function_exists( 'post_type_exists' ) || ! post_type_exists( 'customer_discount' ) ) {
			set_transient( $key, 0, 300 );
			return null;
		}

		$now = (int) current_time( 'timestamp' );

		$ids = [];
		if ( class_exists( 'WP_Query' ) ) {
			$q = new WP_Query(
				[
					'post_type'              => 'customer_discount',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
				]
			);
			$ids = isset( $q->posts ) && is_array( $q->posts ) ? $q->posts : [];
		}

		$min = null;
		foreach ( $ids as $post_id ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 ) {
				continue;
			}

			$data = get_post_meta( $post_id, 'frontend', true );
			$data = is_array( $data ) ? $data : [];

			$start = isset( $data['start'] ) ? trim( (string) $data['start'] ) : '';
			$end   = isset( $data['end'] ) ? trim( (string) $data['end'] ) : '';

			if ( '' !== $start ) {
				$ts = strtotime( $start );
				if ( is_int( $ts ) && $ts > $now && ( null === $min || $ts < $min ) ) {
					$min = $ts;
				}
			}
			if ( '' !== $end ) {
				$ts = strtotime( $end );
				if ( is_int( $ts ) && $ts > $now && ( null === $min || $ts < $min ) ) {
					$min = $ts;
				}
			}
		}

		if ( null === $min ) {
			set_transient( $key, 0, 300 );
			return null;
		}

		$cache_for = $min - $now;
		if ( $cache_for < 1 ) {
			$cache_for = 1;
		} elseif ( $cache_for > 300 ) {
			$cache_for = 300;
		}
		set_transient( $key, $min, $cache_for );
		return $min;
	}

	public function ajax_edd_cart_state(): void {
		if ( is_user_logged_in() ) {
			wp_send_json_error( [ 'reason' => 'auth' ], 403 );
		}

		if ( ! function_exists( 'EDD' ) ) {
			wp_send_json_error( [ 'reason' => 'no_edd' ], 404 );
		}

		$edd = EDD();
		if ( ! is_object( $edd ) || ! isset( $edd->cart ) || ! is_object( $edd->cart ) || ! is_callable( [ $edd->cart, 'get_contents' ] ) ) {
			wp_send_json_error( [ 'reason' => 'no_cart' ], 404 );
		}

		$contents = $edd->cart->get_contents();
		$contents = is_array( $contents ) ? $contents : [];

		$ids = [];
		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) || empty( $item['id'] ) ) {
				continue;
			}
			$ids[] = (int) $item['id'];
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );

		$checkout = function_exists( 'edd_get_checkout_uri' ) ? (string) edd_get_checkout_uri() : '';

		wp_send_json_success(
			[
				'ids'      => $ids,
				'checkout' => $checkout,
			]
		);
	}

	public function maybe_print_edd_purchase_link_state_script(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		if ( function_exists( 'edd_is_checkout' ) && edd_is_checkout() ) {
			return;
		}

		if ( ! function_exists( 'EDD' ) ) {
			return;
		}

		$ajaxurl = admin_url( 'admin-ajax.php' );
		$ajaxurl = is_string( $ajaxurl ) ? $ajaxurl : '';
		if ( '' === $ajaxurl ) {
			return;
		}

		$json_ajax = wp_json_encode( $ajaxurl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json_ajax ) || '' === $json_ajax ) {
			return;
		}

		echo '<script>
(function(){
try{
  var u=' . $json_ajax . ';
  function q(s,r){return (r||document).querySelector(s)}
  function qa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))}
  function getId(f){
    var a=q(\'.edd-add-to-cart[data-download-id]\',f);
    if(a&&a.getAttribute){var id=a.getAttribute(\'data-download-id\');if(id){return id}}
    var i=q(\'input[name=download_id]\',f);
    if(i&&i.value){return i.value}
    return \'\'
  }
  function resetForm(f){
    var go=q(\'.edd_go_to_checkout\',f);
    if(go){go.style.display=\'none\'}
    var nojs=qa(\'.edd-no-js\',f);
    for(var n=0;n<nojs.length;n++){nojs[n].style.display=\'none\'}
    var adds=qa(\'.edd-add-to-cart\',f);
    for(var i=0;i<adds.length;i++){
      if(adds[i].classList&&adds[i].classList.contains(\'edd-no-js\')){adds[i].style.display=\'none\';continue}
      if(adds[i].tagName&&adds[i].tagName!==\'A\'){continue}
      adds[i].style.display=\'\'
    }
    var qty=q(\'.edd_download_quantity_wrapper\',f);
    if(qty){qty.style.display=\'\'}
  }
  function apply(ids,checkout){
    var map={};
    for(var i=0;i<ids.length;i++){map[String(ids[i])]=true}
    var forms=qa(\'.edd_download_purchase_form\');
    for(var j=0;j<forms.length;j++){
      var f=forms[j];
      var id=getId(f);
      if(!id){continue}
      var inCart=!!map[String(id)];
      var go=q(\'.edd_go_to_checkout\',f);
      if(!go){continue}
      var adds=qa(\'.edd-add-to-cart\',f);
      for(var k=0;k<adds.length;k++){
        if(adds[k].classList&&adds[k].classList.contains(\'edd-no-js\')){adds[k].style.display=\'none\';continue}
        if(adds[k].tagName&&adds[k].tagName!==\'A\'){continue}
        adds[k].style.display=inCart?\'none\':\'\'
      }
      go.style.display=inCart?\'inline-block\':\'none\';
      if(inCart&&checkout&&go.tagName===\'A\'){go.setAttribute(\'href\',checkout)}
      var qty=q(\'.edd_download_quantity_wrapper\',f);
      if(qty){qty.style.display=inCart?\'none\':\'\'}
    }
  }
  function request(cb){
    if(window.jQuery&&window.jQuery.ajax){
      window.jQuery.ajax({
        type:\'POST\',
        url:u,
        dataType:\'json\',
        data:{action:\'dfc_edd_cart_state\'},
        xhrFields:{withCredentials:true},
        success:function(res){cb(res)},
        error:function(){cb(null)}
      });
      return;
    }
    try{
      var xhr=new XMLHttpRequest();
      xhr.open(\'POST\',u,true);
      xhr.withCredentials=true;
      xhr.setRequestHeader(\'Content-Type\',\'application/x-www-form-urlencoded; charset=UTF-8\');
      xhr.onreadystatechange=function(){
        if(xhr.readyState!==4){return}
        if(xhr.status>=200&&xhr.status<300){
          try{cb(JSON.parse(xhr.responseText))}catch(e){cb(null)}
        }else{
          cb(null)
        }
      };
      xhr.send(\'action=dfc_edd_cart_state\');
    }catch(e){cb(null)}
  }
  function run(){
    var forms=qa(\'.edd_download_purchase_form\');
    if(!forms.length){return}
    for(var i=0;i<forms.length;i++){resetForm(forms[i])}
    request(function(res){
      if(!res||!res.success||!res.data){return}
      var ids=res.data.ids||[];
      var checkout=res.data.checkout||\'\';
      apply(ids,checkout);
    });
  }
  function schedule(){run();setTimeout(run,50);setTimeout(run,250);setTimeout(run,1000)}
  if(document.readyState===\'loading\'){document.addEventListener(\'DOMContentLoaded\',schedule)}else{schedule()}
  window.addEventListener(\'pageshow\',function(){setTimeout(run,0)});
  if(window.jQuery&&window.jQuery(document.body)&&window.jQuery(document.body).on){
    window.jQuery(document.body).on(\'edd_cart_item_added edd_cart_item_removed\',function(){setTimeout(run,0)})
  }
}catch(_){}
})();
</script>';
	}

	public function maybe_handle_full_page_cache(): void {
		$this->page_store_status = '';
		if ( ! $this->should_page_cache_current_request() ) {
			return;
		}

		$key = $this->build_page_cache_key();
		if ( '' === $key ) {
			return;
		}

		if ( $this->is_local_request() && ! headers_sent() ) {
			$p = $this->get_page_cache_file_path_from_key( $key );
			if ( '' !== $p ) {
				header( 'X-Divi-FPC-WP-File: ' . basename( $p ) );
			}
		}

		$hit  = $this->cache_get( $key );
		$html = null;
		$exp  = null;
		if ( is_string( $hit ) && '' !== $hit ) {
			$html = $hit;
			$timeout = get_option( '_transient_timeout_' . $key, 0 );
			$timeout = is_numeric( $timeout ) ? (int) $timeout : 0;
			if ( $timeout > 0 ) {
				$exp = $timeout;
			}
		} elseif ( is_array( $hit ) && isset( $hit['html'] ) && is_string( $hit['html'] ) && '' !== $hit['html'] ) {
			$html = $hit['html'];
			if ( isset( $hit['exp'] ) ) {
				$exp = is_numeric( $hit['exp'] ) ? (int) $hit['exp'] : null;
			}
		}

		if ( is_string( $html ) && '' !== $html ) {
			$this->page_debug_hits++;
			$this->page_store_status = 'hit-mem';

			if ( ! headers_sent() ) {
				header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
				if ( $this->options->get_bool( 'debug_headers' ) ) {
					header( 'X-Divi-FPC-WP: hit' );
				}
			}

			if ( null !== $exp ) {
				$ttl = $exp - (int) current_time( 'timestamp' );
				if ( $ttl > 0 ) {
					$this->write_full_page_cache_file( $key, $html, $ttl );
				}
			}

			if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
				exit;
			}

			echo $html;
			exit;
		}

		$this->page_debug_misses++;
		$this->page_store_status = 'miss';

		$this->page_cache_active = true;
		$this->page_cache_finalizing = false;
		$this->page_cache_stored = false;
		$this->page_cache_key = $key;
		$this->page_cache_buffer = '';
		$this->page_cache_ob_level = 0;
		$this->page_cache_next_invalidate_at = null;
		$this->page_cache_uncacheable = false;

		ob_start( [ $this, 'page_cache_buffer_callback' ] );
		$this->page_cache_ob_level = (int) ob_get_level();
	}

	public function finalize_full_page_cache(): void {
		if ( ! $this->page_cache_active ) {
			return;
		}

		$this->page_cache_finalizing = true;

		if ( ! $this->page_cache_stored ) {
			$this->page_cache_stored = true;
			$this->maybe_store_full_page_cache();
		}

		if ( ! headers_sent() && $this->options->get_bool( 'debug_headers' ) ) {
			$st = '' !== $this->page_store_status ? $this->page_store_status : 'n/a';
			header( 'X-Divi-FPC-WP-Store: ' . $st );
		}

		if ( $this->page_cache_ob_level > 0 ) {
			while ( ob_get_level() > $this->page_cache_ob_level ) {
				$ok = @ob_end_flush();
				if ( true !== $ok ) {
					break;
				}
			}
			if ( ob_get_level() === $this->page_cache_ob_level ) {
				@ob_end_flush();
			}
		}

		$this->page_cache_active = false;
	}

	public function page_cache_buffer_callback( string $buffer ): string {
		if ( ! $this->page_cache_active ) {
			return $buffer;
		}

		$this->page_cache_buffer .= $buffer;

		return $buffer;
	}

	public function track_shortcode_conditions_for_page_cache( $override, $tag, $attrs, $m ) {
		if ( ! $this->page_cache_active || $this->page_cache_uncacheable ) {
			return $override;
		}

		$tag = is_string( $tag ) ? strtolower( $tag ) : '';
		if ( '' === $tag || 0 !== strpos( $tag, 'et_pb_' ) ) {
			return $override;
		}

		$attrs = is_array( $attrs ) ? $attrs : (array) $attrs;
		if ( empty( $attrs['display_conditions'] ) ) {
			return $override;
		}

		$raw = trim( (string) $attrs['display_conditions'] );
		if ( '' === $raw || 'W10=' === $raw ) {
			return $override;
		}

		$result = $this->extract_display_conditions_policy( $raw );
		if ( ! empty( $result['uncacheable'] ) ) {
			$this->page_cache_uncacheable = true;
			return $override;
		}

		if ( isset( $result['next_invalidate_at'] ) && is_int( $result['next_invalidate_at'] ) ) {
			$ts = $result['next_invalidate_at'];
			if ( null === $this->page_cache_next_invalidate_at || $ts < $this->page_cache_next_invalidate_at ) {
				$this->page_cache_next_invalidate_at = $ts;
			}
		}

		return $override;
	}

	public function maybe_return_cached_shortcode( $override, $tag, $attrs, $m ) {
		if ( false !== $override ) {
			return $override;
		}

		if ( ! $this->should_consider( $tag, $attrs, $m ) ) {
			return $override;
		}

		$tag        = (string) $tag;
		$occurrence = $this->next_occurrence( $tag );
		$key        = $this->build_cache_key( $tag, $attrs, $m, $occurrence );
		$hit        = $this->cache_get( $key );

		if ( null === $hit ) {
			$this->debug_misses++;
			$this->miss_stack[] = [
				'tag'           => $tag,
				'key'           => $key,
				'post_id'       => $this->get_current_post_id(),
				'ttl'           => $this->ttl_for( $tag, $attrs, $m ),
				'counts'        => $this->extract_shortcode_counts( $m ),
				'styles_before' => $this->get_styles_snapshot(),
				'free_form_before' => $this->get_free_form_styles_snapshot(),
				'anim_before'   => $this->get_animation_data_snapshot(),
			];
			return $override;
		}

		$payload = is_array( $hit ) ? $hit : [ 'html' => (string) $hit ];

		$html = (string) ( $payload['html'] ?? '' );
		if ( '' === trim( $html ) ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			delete_transient( $key );
			$this->debug_misses++;
			$this->miss_stack[] = [
				'tag'           => $tag,
				'key'           => $key,
				'post_id'       => $this->get_current_post_id(),
				'ttl'           => $this->ttl_for( $tag, $attrs, $m ),
				'counts'        => $this->extract_shortcode_counts( $m ),
				'styles_before' => $this->get_styles_snapshot(),
				'free_form_before' => $this->get_free_form_styles_snapshot(),
				'anim_before'   => $this->get_animation_data_snapshot(),
			];
			return $override;
		}

		$this->debug_hits++;

		$this->served_stack[] = [
			'tag'     => $tag,
			'key'     => $key,
			'payload' => $payload,
		];

		if ( isset( $payload['counts'] ) && is_array( $payload['counts'] ) ) {
			$this->fast_forward_occurrence_counters( $tag, $payload['counts'] );
		}

		if ( $this->output_has_divi_animation_markers( $html ) ) {
			$this->should_call_divi_reinit = true;
		}

		if ( isset( $payload['anim'] ) && is_array( $payload['anim'] ) && ! empty( $payload['anim'] ) ) {
			foreach ( $payload['anim'] as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$class = isset( $entry['class'] ) ? (string) $entry['class'] : '';
				if ( '' === $class ) {
					continue;
				}
				$this->pending_animation_data[ $class ] = $entry;
			}
			$this->should_call_divi_reinit = true;
		}

		$output = apply_filters( 'do_shortcode_tag', $html, $tag, $attrs, $m );

		return $output;
	}

	public function maybe_store_cached_shortcode( $output, $tag, $attrs, $m ) {
		$tag    = (string) $tag;
		$served = $this->pop_served_if_match( $tag );
		if ( null !== $served ) {
			$payload = is_array( $served['payload'] ?? null ) ? $served['payload'] : [];
			$counts  = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [ $tag => 1 ];
			$this->bump_divi_order_classes( $counts );

			$css = isset( $payload['css'] ) && is_string( $payload['css'] ) ? $payload['css'] : '';
			if ( '' !== trim( $css ) ) {
				$output = (string) $output . $this->wrap_css( $css );
			}

			return $output;
		}

		if ( ! $this->should_consider( $tag, $attrs, $m ) ) {
			return $output;
		}

		if ( false === $output ) {
			return $output;
		}

		if ( '' === trim( (string) $output ) ) {
			return $output;
		}

		$miss = $this->pop_miss_if_match( $tag );
		if ( null === $miss ) {
			return $output;
		}

		$ttl = isset( $miss['ttl'] ) ? (int) $miss['ttl'] : $this->ttl_for( $tag, $attrs, $m );
		if ( $ttl < 1 ) {
			return $output;
		}

		$styles_before = isset( $miss['styles_before'] ) && is_array( $miss['styles_before'] ) ? $miss['styles_before'] : null;
		$css           = '';
		if ( null !== $styles_before ) {
			$styles_after = $this->get_styles_snapshot();
			if ( is_array( $styles_after ) ) {
				$css = $this->build_cached_css( (string) $output, $styles_before, $styles_after );
			}
		}

		$free_form_before = isset( $miss['free_form_before'] ) && is_string( $miss['free_form_before'] ) ? $miss['free_form_before'] : null;
		if ( null !== $free_form_before ) {
			$free_form_after = $this->get_free_form_styles_snapshot();
			if ( is_string( $free_form_after ) ) {
				$free_form_css = $this->build_cached_free_form_css( $free_form_before, $free_form_after );
				if ( '' !== $free_form_css ) {
					$css = '' === $css ? $free_form_css : ( $css . "\n\n" . $free_form_css );
				}
			}
		}

		$anim_before = isset( $miss['anim_before'] ) && is_array( $miss['anim_before'] ) ? $miss['anim_before'] : null;
		$anim        = [];
		if ( null !== $anim_before ) {
			$anim_after = $this->get_animation_data_snapshot();
			if ( is_array( $anim_after ) ) {
				$anim = $this->build_cached_animation_data( $anim_before, $anim_after );
			}
		}

		$payload = [
			'html'   => (string) $output,
			'css'    => $css,
			'counts' => isset( $miss['counts'] ) && is_array( $miss['counts'] ) ? $miss['counts'] : $this->extract_shortcode_counts( $m ),
		];

		if ( ! empty( $anim ) ) {
			$payload['anim'] = $anim;
		}

		$this->cache_set( (string) $miss['key'], $payload, $ttl );
		$this->add_cache_key_for_post( (int) ( $miss['post_id'] ?? 0 ), (string) $miss['key'] );

		return $output;
	}

	public function on_save_post( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$this->invalidate_post_cache_by_id( (int) $post_id );
	}

	public function on_delete_post( $post_id ): void {
		$this->invalidate_post_cache_by_id( (int) $post_id );
	}

	public function handle_query_params(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->is_bypass_requested() ) {
			$this->request_bypass = true;
		}

		if ( ! $this->is_purge_requested() ) {
			return;
		}

		$post_id = $this->get_current_post_id();
		if ( $post_id < 1 ) {
			return;
		}

		if ( ! $this->current_user_can_purge_post( $post_id ) ) {
			return;
		}

		$this->request_bypass = true;
		$this->debug_purges++;
		$this->invalidate_post_cache_by_id( $post_id );
	}

	public function send_debug_headers(): void {
		if ( ! $this->options->get_bool( 'debug_headers' ) ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$bypass = $this->request_bypass || $this->is_bypass_requested();

		header(
			sprintf(
				'X-Divi-FC: hits=%d; misses=%d; bypass=%d; purges=%d',
				(int) $this->debug_hits,
				(int) $this->debug_misses,
				$bypass ? 1 : 0,
				(int) $this->debug_purges
			)
		);

		header(
			sprintf(
				'X-Divi-FPC-WP-Store: hits=%d; misses=%d; status=%s',
				(int) $this->page_debug_hits,
				(int) $this->page_debug_misses,
				'' !== $this->page_store_status ? $this->page_store_status : 'n/a'
			)
		);
	}

	public function flush_pending_post_keys(): void {
		if ( empty( $this->pending_post_keys ) ) {
			return;
		}

		foreach ( $this->pending_post_keys as $post_id => $keys ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 || empty( $keys ) || ! is_array( $keys ) ) {
				continue;
			}

			$existing = get_post_meta( $post_id, self::POST_META_KEY, true );
			$existing = is_array( $existing ) ? $existing : [];

			$merged = array_values( array_unique( array_merge( $existing, $keys ) ) );
			update_post_meta( $post_id, self::POST_META_KEY, $merged );
		}

		$this->pending_post_keys = [];
	}

	private function ttl_for( string $tag, $attrs, $m ): int {
		$base = $this->options->get_int( 'ttl' );
		if ( $base < 0 ) {
			$base = 0;
		}

		$ttl = (int) apply_filters( 'divi_fragment_cache_ttl', $base, $tag, $attrs, $m );

		return $ttl > 0 ? $ttl : 0;
	}

	private function should_consider( $tag, $attrs, $m ): bool {
		$tag = is_string( $tag ) ? strtolower( $tag ) : '';
		if ( '' === $tag || ! $this->is_divi_module_shortcode( $tag ) ) {
			return false;
		}

		$attrs = is_array( $attrs ) ? $attrs : (array) $attrs;

		if ( $this->request_bypass || $this->is_bypass_requested() ) {
			return false;
		}

		if ( $this->is_purge_requested() ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			return false;
		}

		$cache_when_logged_in = $this->options->get_bool( 'cache_logged_in' );
		$cache_when_logged_in = (bool) apply_filters( 'divi_fragment_cache_logged_in', $cache_when_logged_in, $tag, $attrs, $m );
		if ( is_user_logged_in() && ! $cache_when_logged_in ) {
			return false;
		}

		if ( isset( $attrs['display_conditions'] ) ) {
			$display_conditions = trim( (string) $attrs['display_conditions'] );
			if ( '' !== $display_conditions && 'W10=' !== $display_conditions ) {
				return false;
			}
		}

		$visibility_keys = [
			'disabled_on',
			'disabled_on_phone',
			'disabled_on_tablet',
			'disabled_on_desktop',
			'disabled_on_mobile',
		];

		foreach ( $visibility_keys as $key ) {
			if ( empty( $attrs[ $key ] ) ) {
				continue;
			}

			$value = (string) $attrs[ $key ];
			foreach ( explode( '|', $value ) as $flag ) {
				if ( 'on' === trim( $flag ) ) {
					return false;
				}
			}
		}

		$deny_default = $this->options->get_tags( 'deny_tags' );
		$deny         = (array) apply_filters( 'divi_fragment_cache_denied_tags', $deny_default, $tag, $attrs, $m );
		$deny         = array_map(
			static function ( $t ) {
				return strtolower( trim( (string) $t ) );
			},
			$deny
		);

		if ( in_array( $tag, $deny, true ) ) {
			return false;
		}

		$allow_default = $this->options->get_tags( 'allow_tags' );
		$allow         = (array) apply_filters( 'divi_fragment_cache_allowed_tags', $allow_default, $tag, $attrs, $m );
		$allow         = array_map(
			static function ( $t ) {
				return strtolower( trim( (string) $t ) );
			},
			$allow
		);

		if ( ! empty( $allow ) && ! in_array( $tag, $allow, true ) ) {
			return false;
		}

		return true;
	}

	private function is_bypass_requested(): bool {
		return isset( $_GET[ self::QUERY_BYPASS ] ) && '' !== (string) $_GET[ self::QUERY_BYPASS ];
	}

	private function is_purge_requested(): bool {
		return isset( $_GET[ self::QUERY_PURGE ] ) && '' !== (string) $_GET[ self::QUERY_PURGE ];
	}

	private function current_user_can_purge_post( int $post_id ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	private function is_divi_module_shortcode( string $tag ): bool {
		return 0 === strpos( $tag, 'et_pb_' );
	}

	private function output_has_divi_animation_markers( string $output ): bool {
		if ( '' === $output ) {
			return false;
		}

		$out = strtolower( $output );

		return false !== strpos( $out, 'et-waypoint' )
			|| false !== strpos( $out, 'data-animation-style' )
			|| false !== strpos( $out, 'et_pb_animation_' );
	}

	public function register_admin_bar_menu( $admin_bar ): void {
		if ( ! is_object( $admin_bar ) || ! is_callable( [ $admin_bar, 'add_node' ] ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! function_exists( 'is_admin_bar_showing' ) || ! is_admin_bar_showing() ) {
			return;
		}

		$parent_id = 'dfc';

		$admin_bar->add_node(
			[
				'id'    => $parent_id,
				'title' => esc_html__( 'Divi FC', 'divi-fragment-cache' ),
				'href'  => false,
			]
		);

		$redirect = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$redirect = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}

		$url = add_query_arg(
			[
				'action'   => self::ACTION_PURGE_ALL,
				'redirect' => $redirect,
			],
			admin_url( 'admin-post.php' )
		);
		$url = wp_nonce_url( $url, self::ACTION_PURGE_ALL );

		$admin_bar->add_node(
			[
				'id'     => 'dfc_purge_all',
				'parent' => $parent_id,
				'title'  => esc_html__( 'Svuota tutta la cache', 'divi-fragment-cache' ),
				'href'   => $url,
			]
		);
	}

	public function handle_purge_all_request(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accesso negato.', 'divi-fragment-cache' ), '', [ 'response' => 403 ] );
		}

		check_admin_referer( self::ACTION_PURGE_ALL );

		$this->purge_all_cache();
		$this->debug_purges++;

		$redirect = '';
		if ( isset( $_GET['redirect'] ) ) {
			$redirect = rawurldecode( (string) wp_unslash( $_GET['redirect'] ) );
		}

		if ( '' !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		wp_safe_redirect( admin_url() );
		exit;
	}

	private function purge_all_cache(): void {
		if ( function_exists( 'delete_post_meta_by_key' ) ) {
			delete_post_meta_by_key( self::POST_META_KEY );
		}

		$this->purge_all_full_page_cache_files();

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}

		$prefixes = [ self::TRANSIENT_PREFIX, self::PAGE_TRANSIENT_PREFIX ];

		foreach ( $prefixes as $prefix ) {
			$prefix = is_string( $prefix ) ? $prefix : '';
			if ( '' === $prefix ) {
				continue;
			}

			$like_transient = $wpdb->esc_like( '_transient_' . $prefix ) . '%';

			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like_transient
				)
			);

			if ( is_array( $rows ) ) {
				foreach ( $rows as $option_name ) {
					$option_name = is_string( $option_name ) ? $option_name : '';
					if ( '' === $option_name ) {
						continue;
					}

					$transient_key = preg_replace( '/^_transient_/', '', $option_name );
					$transient_key = is_string( $transient_key ) ? $transient_key : '';
					if ( '' === $transient_key ) {
						continue;
					}

					wp_cache_delete( $transient_key, self::CACHE_GROUP );
				}
			}

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( '_transient_' . $prefix ) . '%',
					$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
				)
			);
		}
	}

	private function purge_all_full_page_cache_files(): void {
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return;
		}

		$dir = rtrim( (string) WP_CONTENT_DIR, '/' ) . '/cache/divi-fragment-cache/pages';
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '/*.html' );
		if ( ! is_array( $files ) ) {
			return;
		}

		foreach ( $files as $file ) {
			$file = is_string( $file ) ? $file : '';
			if ( '' === $file ) {
				continue;
			}
			@unlink( $file );
		}
	}

	private function should_page_cache_current_request(): bool {
		$ttl = $this->options->get_int( 'ttl' );
		if ( $ttl < 1 ) {
			$this->page_store_status = 'skip-ttl';
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			$this->page_store_status = 'skip-context';
			return false;
		}

		if ( isset( $_SERVER['REQUEST_METHOD'] ) ) {
			$method = strtoupper( (string) $_SERVER['REQUEST_METHOD'] );
			if ( 'GET' !== $method && 'HEAD' !== $method ) {
				$this->page_store_status = 'skip-method';
				return false;
			}
		}

		if ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) {
			$this->page_store_status = 'skip-customize';
			return false;
		}

		if ( function_exists( 'is_preview' ) && is_preview() ) {
			$this->page_store_status = 'skip-preview';
			return false;
		}

		if ( is_user_logged_in() ) {
			$this->page_store_status = 'skip-logged-in';
			return false;
		}

		if ( $this->request_bypass || $this->is_bypass_requested() || $this->is_purge_requested() ) {
			$this->page_store_status = 'skip-bypass';
			return false;
		}

		if ( function_exists( 'is_404' ) && is_404() ) {
			$this->page_store_status = 'skip-404';
			return false;
		}

		if ( function_exists( 'is_feed' ) && is_feed() ) {
			$this->page_store_status = 'skip-feed';
			return false;
		}

		if ( $this->has_uncacheable_cookies() ) {
			$this->page_store_status = 'skip-cookie';
			return false;
		}

		$this->page_store_status = 'ok';
		return true;
	}

	private function build_page_cache_key(): string {
		$host = '';
		if ( isset( $_SERVER['HTTP_HOST'] ) ) {
			$host = strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		}

		$uri = '';
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] );
		}

		$uri = $this->normalize_request_uri_for_cache_key( $uri );
		if ( '' === $host || '' === $uri ) {
			return '';
		}

		$raw = wp_json_encode(
			[
				'v'       => 6,
				'ssl'     => function_exists( 'is_ssl' ) && is_ssl() ? 1 : 0,
				'host'    => $host,
				'uri'     => $uri,
				'user'    => 'guest',
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return self::PAGE_TRANSIENT_PREFIX . md5( (string) $raw );
	}

	private function cookie_vary_hash(): string {
		return '';
	}

	private function normalize_request_uri_for_cache_key( string $uri ): string {
		$uri = trim( $uri );
		if ( '' === $uri ) {
			return '';
		}

		$parts = wp_parse_url( $uri );
		if ( ! is_array( $parts ) ) {
			return $uri;
		}

		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		if ( '' === $query ) {
			return $path;
		}

		parse_str( $query, $args );
		if ( ! is_array( $args ) ) {
			return $path . '?' . $query;
		}

		$drop_prefixes = [ 'utm_', 'fbclid', 'gclid', 'msclkid', '_ga', '_gid' ];
		foreach ( array_keys( $args ) as $k ) {
			$k = (string) $k;
			foreach ( $drop_prefixes as $drop ) {
				if ( 0 === strpos( $k, $drop ) ) {
					unset( $args[ $k ] );
					break;
				}
			}
		}

		if ( empty( $args ) ) {
			return $path;
		}

		ksort( $args );
		return $path . '?' . http_build_query( $args, '', '&' );
	}

	private function has_uncacheable_cookies(): bool {
		if ( empty( $_COOKIE ) || ! is_array( $_COOKIE ) ) {
			return false;
		}

		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			if ( '' === $name ) {
				continue;
			}

			if ( 0 === strpos( $name, 'wordpress_logged_in_' ) ) {
				return true;
			}
			if ( 0 === strpos( $name, 'wp_woocommerce_session_' ) ) {
				return true;
			}

			$exact = [
				'woocommerce_cart_hash',
				'woocommerce_items_in_cart',
			];
			if ( in_array( $name, $exact, true ) ) {
				return true;
			}
		}

		return false;
	}

	private function maybe_store_full_page_cache(): void {
		if ( function_exists( 'edd_is_checkout' ) && edd_is_checkout() ) {
			$this->page_store_status = 'skip-checkout';
			return;
		}

		if ( $this->page_cache_uncacheable ) {
			$this->page_store_status = 'skip-conditions';
			return;
		}

		$key = $this->page_cache_key;
		if ( '' === $key ) {
			$this->page_store_status = 'skip-key';
			return;
		}

		$html = $this->page_cache_buffer;
		if ( '' === trim( $html ) ) {
			$this->page_store_status = 'skip-empty';
			return;
		}

		$status = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		if ( $status < 200 || $status >= 300 ) {
			$this->page_store_status = 'skip-status';
			return;
		}

		$headers = function_exists( 'headers_list' ) ? (array) headers_list() : [];
		$content_type = '';
		foreach ( $headers as $h ) {
			$h = strtolower( (string) $h );
			if ( 0 === strpos( $h, 'content-type:' ) ) {
				$content_type = trim( substr( $h, strlen( 'content-type:' ) ) );
				break;
			}
		}

		if ( '' !== $content_type && false === strpos( $content_type, 'text/html' ) ) {
			$this->page_store_status = 'skip-content-type';
			return;
		}

		$set_cookies = [];
		foreach ( $headers as $h ) {
			$h = strtolower( (string) $h );
			if ( 0 === strpos( $h, 'set-cookie:' ) ) {
				$raw = trim( substr( (string) $h, strlen( 'set-cookie:' ) ) );
				$eq  = strpos( $raw, '=' );
				if ( false !== $eq ) {
					$name = trim( substr( $raw, 0, $eq ) );
					if ( '' !== $name ) {
						$set_cookies[] = $name;
					}
				}
			}
		}

		if ( ! empty( $set_cookies ) ) {
			$set_cookies = array_values( array_unique( $set_cookies ) );
			foreach ( $set_cookies as $cookie_name ) {
				$cookie_name = strtolower( (string) $cookie_name );
				if ( 'phpsessid' === $cookie_name ) {
					continue;
				}
				if ( 0 === strpos( $cookie_name, 'edd_' ) ) {
					continue;
				}
				$this->page_store_status = 'skip-set-cookie:' . implode( ',', $set_cookies );
				return;
			}
		}

		$ttl = $this->options->get_int( 'ttl' );
		if ( $ttl < 1 ) {
			$this->page_store_status = 'skip-ttl';
			return;
		}

		$now = (int) current_time( 'timestamp' );

		if ( null !== $this->page_cache_next_invalidate_at ) {
			$delta = $this->page_cache_next_invalidate_at - $now;
			if ( $delta > 0 && $delta < $ttl ) {
				$ttl = $delta;
			}
		}

		$next_discount = $this->edd_discounts_pro_next_transition_timestamp();
		if ( null !== $next_discount ) {
			$delta = $next_discount - $now;
			if ( $delta > 0 && $delta < $ttl ) {
				$ttl = $delta;
			}
		}

		if ( $ttl < 1 ) {
			$this->page_store_status = 'skip-ttl-window';
			return;
		}

		$exp = $now + $ttl;
		$this->cache_set(
			$key,
			[
				'html' => $html,
				'exp'  => $exp,
			],
			$ttl
		);
		$this->add_cache_key_for_post( $this->get_current_post_id(), $key );
		$file_ok = $this->write_full_page_cache_file( $key, $html, $ttl );
		$this->flush_pending_post_keys();
		$this->page_store_status = 'stored;ttl=' . (int) $ttl . ';file=' . ( $file_ok ? '1' : '0' );
	}

	private function is_local_request(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( trim( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host || '127.0.0.1' === $host ) {
			return true;
		}

		return false !== strpos( $host, '.local' );
	}

	private function write_full_page_cache_file( string $key, string $html, int $ttl ): bool {
		$path = $this->get_page_cache_file_path_from_key( $key );
		if ( '' === $path ) {
			$this->page_store_status = 'skip-write-path';
			return false;
		}

		$dir = dirname( $path );
		if ( function_exists( 'wp_mkdir_p' ) ) {
			wp_mkdir_p( $dir );
		} elseif ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true );
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			$this->page_store_status = 'skip-write-perms';
			return false;
		}

		$exp = (int) current_time( 'timestamp' ) + max( 1, $ttl );
		$out = $html;

		$tmp = $path . '.tmp';
		$ok  = false !== @file_put_contents( $tmp, $out, LOCK_EX );
		if ( ! $ok ) {
			@unlink( $tmp );
			$this->page_store_status = 'skip-write-put';
			return false;
		}

		@rename( $tmp, $path );
		@chmod( $path, 0644 );
		@touch( $path, $exp );
		return true;
	}

	private function get_page_cache_file_path_from_key( string $key ): string {
		if ( '' === $key || 0 !== strpos( $key, self::PAGE_TRANSIENT_PREFIX ) ) {
			return '';
		}

		$hash = substr( $key, strlen( self::PAGE_TRANSIENT_PREFIX ) );
		$hash = is_string( $hash ) ? strtolower( trim( $hash ) ) : '';
		if ( '' === $hash || ! preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
			return '';
		}

		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			return '';
		}

		return rtrim( (string) WP_CONTENT_DIR, '/' ) . '/cache/divi-fragment-cache/pages/' . $hash . '.html';
	}

	private function extract_display_conditions_policy( string $encoded ): array {
		$decoded = base64_decode( $encoded, true );
		if ( false === $decoded ) {
			$decoded = base64_decode( $encoded, false );
		}

		if ( false === $decoded || '' === $decoded ) {
			return [ 'uncacheable' => true ];
		}

		$data = json_decode( $decoded, true );
		if ( null === $data || ! is_array( $data ) ) {
			return [ 'uncacheable' => true ];
		}

		if ( empty( $data ) ) {
			return [ 'uncacheable' => false ];
		}

		$now      = (int) current_time( 'timestamp' );
		$next_ts  = null;

		foreach ( $data as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}

			$name     = isset( $condition['condition'] ) ? (string) $condition['condition'] : '';
			$settings = isset( $condition['conditionSettings'] ) && is_array( $condition['conditionSettings'] ) ? $condition['conditionSettings'] : [];
			if ( isset( $settings['enableCondition'] ) && 'off' === (string) $settings['enableCondition'] ) {
				continue;
			}

			if ( '' === $name ) {
				continue;
			}

			$uncacheable_conditions = [
				'browser',
				'operatingSystem',
				'cookie',
				'pageVisit',
				'postVisit',
				'numberOfViews',
				'cartContents',
				'productPurchase',
				'productStock',
			];
			if ( in_array( $name, $uncacheable_conditions, true ) ) {
				return [ 'uncacheable' => true ];
			}

			if ( 'dateTime' === $name ) {
				$ts = $this->next_invalidate_at_for_date_time_condition( $settings, $now );
				if ( null !== $ts ) {
					$next_ts = null === $next_ts ? $ts : min( $next_ts, $ts );
				}
			}
		}

		if ( null !== $next_ts ) {
			return [
				'uncacheable'        => false,
				'next_invalidate_at' => $next_ts,
			];
		}

		return [ 'uncacheable' => false ];
	}

	private function next_invalidate_at_for_date_time_condition( array $settings, int $now ): ?int {
		$legacy_display_rule = isset( $settings['dateTimeDisplay'] ) ? (string) $settings['dateTimeDisplay'] : 'isAfter';
		$display_rule        = isset( $settings['displayRule'] ) ? (string) $settings['displayRule'] : $legacy_display_rule;

		$date      = isset( $settings['date'] ) ? trim( (string) $settings['date'] ) : '';
		$time      = isset( $settings['time'] ) ? trim( (string) $settings['time'] ) : '';
		$all_day   = isset( $settings['allDay'] ) ? (string) $settings['allDay'] : '';
		$from_time = isset( $settings['fromTime'] ) ? trim( (string) $settings['fromTime'] ) : '';
		$until_time = isset( $settings['untilTime'] ) ? trim( (string) $settings['untilTime'] ) : '';

		if ( '' === $date ) {
			return null;
		}

		$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );

		$candidates = [];

		$parse = static function ( string $s, DateTimeZone $tz ): ?DateTimeImmutable {
			$s = trim( $s );
			if ( '' === $s ) {
				return null;
			}
			try {
				return new DateTimeImmutable( $s, $tz );
			} catch ( Exception $e ) {
				return null;
			}
		};

		$target_date = $parse( $date, $tz );
		$target_dt   = $parse( $date . ' ' . ( '' !== $time ? $time : '00:00' ), $tz );
		$target_from = $parse( $date . ' ' . ( '' !== $from_time ? $from_time : '00:00' ), $tz );
		$target_until = $parse( $date . ' ' . ( '' !== $until_time ? $until_time : '23:59' ), $tz );

		if ( null === $target_date || null === $target_dt || null === $target_from || null === $target_until ) {
			return null;
		}

		switch ( $display_rule ) {
			case 'isAfter':
			case 'isBefore':
				$candidates[] = (int) $target_dt->getTimestamp();
				break;

			case 'isOnSpecificDate':
			case 'isNotOnSpecificDate':
				if ( 'off' === $all_day ) {
					$candidates[] = (int) $target_from->getTimestamp();
					$candidates[] = (int) $target_until->getTimestamp();
				} else {
					$candidates[] = (int) $target_date->getTimestamp();
					$candidates[] = (int) $target_date->modify( 'tomorrow' )->getTimestamp();
				}

				$repeat = isset( $settings['repeat'] ) ? (string) $settings['repeat'] : '';
				$repeat_frequency = isset( $settings['repeatFrequency'] ) ? (string) $settings['repeatFrequency'] : '';
				if ( 'on' === $repeat ) {
					if ( 'monthly' === $repeat_frequency ) {
						for ( $i = 1; $i <= 24; $i++ ) {
							$d = $target_date->modify( '+' . $i . ' month' );
							if ( 'off' === $all_day ) {
								$candidates[] = (int) $d->modify( $from_time )->getTimestamp();
								$candidates[] = (int) $d->modify( $until_time )->getTimestamp();
							} else {
								$candidates[] = (int) $d->getTimestamp();
								$candidates[] = (int) $d->modify( 'tomorrow' )->getTimestamp();
							}
						}
					} elseif ( 'annually' === $repeat_frequency ) {
						for ( $i = 1; $i <= 10; $i++ ) {
							$d = $target_date->modify( '+' . $i . ' year' );
							if ( 'off' === $all_day ) {
								$candidates[] = (int) $d->modify( $from_time )->getTimestamp();
								$candidates[] = (int) $d->modify( $until_time )->getTimestamp();
							} else {
								$candidates[] = (int) $d->getTimestamp();
								$candidates[] = (int) $d->modify( 'tomorrow' )->getTimestamp();
							}
						}
					} else {
						return null;
					}
				}
				break;

			case 'isOnSpecificDays':
				$weekdays = isset( $settings['weekdays'] ) ? (string) $settings['weekdays'] : '';
				$weekdays = '' !== $weekdays ? array_filter( explode( '|', $weekdays ) ) : [];
				$weekdays = array_map(
					static function ( $w ) {
						return strtolower( trim( (string) $w ) );
					},
					$weekdays
				);

				$cur = new DateTimeImmutable( '@' . $now );
				$cur = $cur->setTimezone( $tz );
				for ( $i = 0; $i <= 14; $i++ ) {
					$d = $cur->modify( '+' . $i . ' day' );
					$day = strtolower( $d->format( 'l' ) );
					if ( empty( $weekdays ) || ! in_array( $day, $weekdays, true ) ) {
						continue;
					}

					if ( 'off' === $all_day ) {
						$candidates[] = (int) $d->modify( '' !== $from_time ? $from_time : '00:00' )->getTimestamp();
						$candidates[] = (int) $d->modify( '' !== $until_time ? $until_time : '23:59' )->getTimestamp();
					} else {
						$candidates[] = (int) $d->setTime( 0, 0 )->getTimestamp();
						$candidates[] = (int) $d->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp();
					}
				}
				break;

			case 'isFirstDayOfMonth':
			case 'isLastDayOfMonth':
				$cur = new DateTimeImmutable( '@' . $now );
				$cur = $cur->setTimezone( $tz );
				$d = 'isFirstDayOfMonth' === $display_rule ? $cur->modify( 'first day of this month' ) : $cur->modify( 'last day of this month' );
				$d2 = 'isFirstDayOfMonth' === $display_rule ? $cur->modify( 'first day of next month' ) : $cur->modify( 'last day of next month' );
				foreach ( [ $d, $d2 ] as $day_dt ) {
					if ( 'off' === $all_day ) {
						$candidates[] = (int) $day_dt->modify( '' !== $from_time ? $from_time : '00:00' )->getTimestamp();
						$candidates[] = (int) $day_dt->modify( '' !== $until_time ? $until_time : '23:59' )->getTimestamp();
					} else {
						$candidates[] = (int) $day_dt->setTime( 0, 0 )->getTimestamp();
						$candidates[] = (int) $day_dt->modify( 'tomorrow' )->setTime( 0, 0 )->getTimestamp();
					}
				}
				break;

			default:
				return null;
		}

		$future = array_values(
			array_filter(
				$candidates,
				static function ( $ts ) use ( $now ) {
					return is_int( $ts ) && $ts > $now;
				}
			)
		);

		if ( empty( $future ) ) {
			return null;
		}

		return (int) min( $future );
	}

	private function find_earliest_future_timestamp_in_data( $data, int $now ): ?int {
		$min = null;

		$stack = [ $data ];
		while ( ! empty( $stack ) ) {
			$cur = array_pop( $stack );

			if ( is_array( $cur ) ) {
				foreach ( $cur as $k => $v ) {
					if ( is_array( $v ) || is_object( $v ) ) {
						$stack[] = $v;
						continue;
					}

					$ts = $this->maybe_parse_timestamp_value( $v );
					if ( null !== $ts && $ts > $now ) {
						$min = null === $min ? $ts : min( $min, $ts );
					}
				}
				continue;
			}

			if ( is_object( $cur ) ) {
				foreach ( get_object_vars( $cur ) as $k => $v ) {
					if ( is_array( $v ) || is_object( $v ) ) {
						$stack[] = $v;
						continue;
					}

					$ts = $this->maybe_parse_timestamp_value( $v );
					if ( null !== $ts && $ts > $now ) {
						$min = null === $min ? $ts : min( $min, $ts );
					}
				}
			}
		}

		return $min;
	}

	private function maybe_parse_timestamp_value( $value ): ?int {
		if ( is_int( $value ) ) {
			if ( $value > 1000000000 ) {
				return $value;
			}
			return null;
		}

		if ( is_float( $value ) ) {
			$i = (int) $value;
			if ( $i > 1000000000 ) {
				return $i;
			}
			return null;
		}

		if ( is_string( $value ) ) {
			$s = trim( $value );
			if ( '' === $s ) {
				return null;
			}

			if ( preg_match( '/^\d{10,13}$/', $s ) ) {
				$n = (int) $s;
				if ( $n > 1000000000000 ) {
					$n = (int) floor( $n / 1000 );
				}
				return $n > 1000000000 ? $n : null;
			}

			if ( ! preg_match( '/\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $s ) && false === strpos( $s, 'T' ) ) {
				return null;
			}

			if ( ! class_exists( 'DateTimeImmutable' ) ) {
				$ts = strtotime( $s );
				return is_int( $ts ) && $ts > 0 ? $ts : null;
			}

			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
			try {
				$dt = new DateTimeImmutable( $s, $tz );
				$ts = (int) $dt->getTimestamp();
				return $ts > 0 ? $ts : null;
			} catch ( Exception $e ) {
				return null;
			}
		}

		return null;
	}

	private function get_animation_data_snapshot(): ?array {
		if ( ! function_exists( 'et_builder_handle_animation_data' ) ) {
			return null;
		}

		$data = et_builder_handle_animation_data();
		if ( ! is_array( $data ) ) {
			return null;
		}

		return $data;
	}

	private function build_cached_animation_data( array $before, array $after ): array {
		$before_classes = [];
		foreach ( $before as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['class'] ) ) {
				continue;
			}
			$before_classes[ (string) $entry['class'] ] = true;
		}

		$delta = [];
		foreach ( $after as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['class'] ) ) {
				continue;
			}

			$class = (string) $entry['class'];
			if ( isset( $before_classes[ $class ] ) ) {
				continue;
			}

			$delta[] = $entry;
		}

		return $delta;
	}

	public function maybe_print_animation_reinit_script(): void {
		if ( ! $this->should_call_divi_reinit ) {
			return;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( $this->request_bypass || $this->is_bypass_requested() || $this->is_purge_requested() ) {
			return;
		}

		$entries = array_values( $this->pending_animation_data );
		$json    = wp_json_encode( $entries, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = '[]';
		}

		echo '<script>(function(){try{var a=' . $json . ';if(!window.et_animation_data){window.et_animation_data=[]}if(a&&a.length){var e={};for(var i=0;i<window.et_animation_data.length;i++){var c=window.et_animation_data[i]&&window.et_animation_data[i]["class"];if(c){e[c]=true}}for(var j=0;j<a.length;j++){var it=a[j];var cls=it&&it["class"];if(!cls||e[cls]){continue}window.et_animation_data.push(it);e[cls]=true}}if(typeof window.et_process_animation_data==="function"){window.et_process_animation_data(true)}if(typeof window.et_reinit_waypoint_modules==="function"){window.et_reinit_waypoint_modules()}}catch(_){}})();</script>';
	}

	private function build_cache_key( string $tag, $attrs, $m, int $occurrence ): string {
		$attrs = is_array( $attrs ) ? $attrs : [];

		$post_id = 0;
		$post    = get_post();
		if ( $post && isset( $post->ID ) ) {
			$post_id = (int) $post->ID;
		}

		$content = '';
		if ( is_array( $m ) && isset( $m[5] ) && is_string( $m[5] ) ) {
			$content = $m[5];
		}

		$raw = wp_json_encode(
			[
				'v'       => 6,
				'blog_id' => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
				'post_id' => $post_id,
				'locale'  => function_exists( 'determine_locale' ) ? (string) determine_locale() : '',
				'tag'     => $tag,
				'occ'     => $occurrence,
				'attrs'   => $attrs,
				'content' => $content,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return self::TRANSIENT_PREFIX . md5( (string) $raw );
	}

	private function add_cache_key_for_post( int $post_id, string $cache_key ): void {
		if ( $post_id < 1 || '' === $cache_key ) {
			return;
		}

		if ( empty( $this->pending_post_keys[ $post_id ] ) ) {
			$this->pending_post_keys[ $post_id ] = [];
		}

		$this->pending_post_keys[ $post_id ][] = $cache_key;
		$this->pending_post_keys[ $post_id ]   = array_values( array_unique( $this->pending_post_keys[ $post_id ] ) );
	}

	private function invalidate_post_cache_by_id( int $post_id ): void {
		if ( $post_id < 1 ) {
			return;
		}

		$keys = get_post_meta( $post_id, self::POST_META_KEY, true );
		if ( ! is_array( $keys ) || empty( $keys ) ) {
			delete_post_meta( $post_id, self::POST_META_KEY );
			return;
		}

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			wp_cache_delete( $key, self::CACHE_GROUP );
			delete_transient( $key );
			$this->maybe_delete_full_page_cache_file_for_key( $key );
		}

		delete_post_meta( $post_id, self::POST_META_KEY );
	}

	private function maybe_delete_full_page_cache_file_for_key( string $key ): void {
		if ( 0 !== strpos( $key, self::PAGE_TRANSIENT_PREFIX ) ) {
			return;
		}

		$path = $this->get_page_cache_file_path_from_key( $key );
		if ( '' === $path ) {
			return;
		}

		@unlink( $path );
	}

	private function get_current_post_id(): int {
		$post = get_post();
		if ( $post && isset( $post->ID ) ) {
			return (int) $post->ID;
		}

		return 0;
	}

	private function next_occurrence( string $tag ): int {
		if ( empty( $this->tag_counters[ $tag ] ) ) {
			$this->tag_counters[ $tag ] = 0;
		}

		$this->tag_counters[ $tag ]++;
		return (int) $this->tag_counters[ $tag ];
	}

	private function fast_forward_occurrence_counters( string $current_tag, array $counts ): void {
		foreach ( $counts as $tag => $count ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}

			$tag = strtolower( $tag );
			if ( 0 !== strpos( $tag, 'et_pb_' ) ) {
				continue;
			}

			$inc = (int) $count;
			if ( $current_tag === $tag ) {
				$inc--;
			}

			if ( $inc < 1 ) {
				continue;
			}

			if ( empty( $this->tag_counters[ $tag ] ) ) {
				$this->tag_counters[ $tag ] = 0;
			}

			$this->tag_counters[ $tag ] += $inc;
		}
	}

	private function pop_served_if_match( string $tag ): ?array {
		$top = end( $this->served_stack );
		if ( false === $top || ! is_array( $top ) ) {
			return null;
		}

		if ( ! isset( $top['tag'] ) || $top['tag'] !== $tag ) {
			return null;
		}

		return array_pop( $this->served_stack );
	}

	private function pop_miss_if_match( string $tag ): ?array {
		$top = end( $this->miss_stack );
		if ( false === $top || ! is_array( $top ) ) {
			return null;
		}

		if ( ! isset( $top['tag'] ) || $top['tag'] !== $tag ) {
			return null;
		}

		return array_pop( $this->miss_stack );
	}

	private function extract_shortcode_counts( $m ): array {
		if ( ! is_array( $m ) || empty( $m[0] ) || ! is_string( $m[0] ) ) {
			return [];
		}

		$shortcode = $m[0];
		$counts    = [];

		if ( preg_match_all( '/\\[(?!\\/)(et_pb_[a-z0-9_]+)/i', $shortcode, $matches ) ) {
			foreach ( $matches[1] as $t ) {
				$t = strtolower( (string) $t );
				if ( empty( $counts[ $t ] ) ) {
					$counts[ $t ] = 0;
				}
				$counts[ $t ]++;
			}
		}

		return $counts;
	}

	private function bump_divi_order_classes( array $counts ): void {
		if ( ! class_exists( 'ET_Builder_Element' ) || ! is_callable( [ 'ET_Builder_Element', 'set_order_class' ] ) ) {
			return;
		}

		foreach ( $counts as $tag => $count ) {
			if ( ! is_string( $tag ) ) {
				continue;
			}

			$tag = strtolower( $tag );
			if ( 0 !== strpos( $tag, 'et_pb_' ) ) {
				continue;
			}

			$times = (int) $count;
			if ( $times < 1 ) {
				continue;
			}

			for ( $i = 0; $i < $times; $i++ ) {
				ET_Builder_Element::set_order_class( $tag );
			}
		}
	}

	private function get_styles_snapshot(): ?array {
		if ( ! class_exists( 'ET_Builder_Element' ) || ! is_callable( [ 'ET_Builder_Element', 'get_style_array' ] ) ) {
			return null;
		}

		return (array) ET_Builder_Element::get_style_array( false );
	}

	private function get_free_form_styles_snapshot(): ?string {
		if ( ! class_exists( 'ET_Builder_Element' ) || ! is_callable( [ 'ET_Builder_Element', 'get_free_form_styles' ] ) ) {
			return null;
		}

		return (string) ET_Builder_Element::get_free_form_styles();
	}

	private function styles_delta_to_css( array $before, array $after ): string {
		$delta = [];

		foreach ( $after as $media_query => $rules ) {
			if ( ! is_array( $rules ) ) {
				continue;
			}

			foreach ( $rules as $selector => $settings ) {
				if ( ! is_array( $settings ) || empty( $settings['declaration'] ) ) {
					continue;
				}

				$declaration = (string) $settings['declaration'];
				$before_decl = null;
				if ( isset( $before[ $media_query ][ $selector ]['declaration'] ) ) {
					$before_decl = (string) $before[ $media_query ][ $selector ]['declaration'];
				}

				if ( null !== $before_decl && $before_decl === $declaration ) {
					continue;
				}

				if ( empty( $delta[ $media_query ] ) ) {
					$delta[ $media_query ] = [];
				}

				$delta[ $media_query ][ $selector ] = $declaration;
			}
		}

		if ( empty( $delta ) ) {
			return '';
		}

		$out = '';
		foreach ( $delta as $media_query => $rules ) {
			$chunk = '';
			foreach ( $rules as $selector => $declaration ) {
				$chunk .= "\n{$selector} { {$declaration} }";
			}

			if ( '' === $chunk ) {
				continue;
			}

			if ( 'general' === $media_query ) {
				$out .= $chunk;
				continue;
			}

			$out .= "\n\n{$media_query} {\n{$chunk}\n}";
		}

		return ltrim( $out );
	}

	private function build_cached_css( string $html, array $styles_before, array $styles_after ): string {
		$order_classes = $this->extract_order_classes_from_html( $html );
		$by_class_css  = '';

		if ( ! empty( $order_classes ) ) {
			$by_class_css = $this->styles_for_order_classes_to_css( $styles_after, $order_classes );
		}

		$delta_css = $this->styles_delta_to_css( $styles_before, $styles_after );

		if ( '' === $by_class_css ) {
			return $delta_css;
		}

		if ( '' === $delta_css ) {
			return $by_class_css;
		}

		return $by_class_css . "\n\n" . $delta_css;
	}

	private function build_cached_free_form_css( string $before, string $after ): string {
		if ( '' === $after || $before === $after ) {
			return '';
		}

		if ( '' === $before ) {
			return trim( $after );
		}

		$before_len = strlen( $before );
		if ( 0 === strpos( $after, $before ) ) {
			return trim( substr( $after, $before_len ) );
		}

		$pos = strpos( $after, $before );
		if ( false !== $pos ) {
			$delta = substr( $after, 0, $pos ) . substr( $after, $pos + $before_len );
			return trim( $delta );
		}

		return '';
	}

	private function extract_order_classes_from_html( string $html ): array {
		if ( '' === $html ) {
			return [];
		}

		$matches = [];
		if ( ! preg_match_all( '/\bet_pb_[a-z0-9_]+_[0-9]+[a-z0-9_-]*\b/i', $html, $matches ) ) {
			return [];
		}

		$classes = [];
		foreach ( $matches[0] as $class_name ) {
			$class_name = strtolower( trim( (string) $class_name ) );
			if ( '' === $class_name ) {
				continue;
			}
			$classes[] = $class_name;
		}

		return array_values( array_unique( $classes ) );
	}

	private function selector_targets_order_class( string $selector, array $order_classes ): bool {
		if ( '' === $selector || empty( $order_classes ) ) {
			return false;
		}

		$selector_lc = strtolower( $selector );

		foreach ( $order_classes as $class_name ) {
			if ( '' === $class_name ) {
				continue;
			}

			if ( false !== strpos( $selector_lc, '.' . $class_name ) ) {
				return true;
			}
		}

		return false;
	}

	private function styles_for_order_classes_to_css( array $styles, array $order_classes ): string {
		if ( empty( $styles ) || empty( $order_classes ) ) {
			return '';
		}

		$out = '';
		foreach ( $styles as $media_query => $rules ) {
			if ( ! is_array( $rules ) || empty( $rules ) ) {
				continue;
			}

			$chunk = '';
			foreach ( $rules as $selector => $settings ) {
				if ( ! is_string( $selector ) || ! is_array( $settings ) || empty( $settings['declaration'] ) ) {
					continue;
				}

				if ( ! $this->selector_targets_order_class( $selector, $order_classes ) ) {
					continue;
				}

				$chunk .= "\n{$selector} { {$settings['declaration']} }";
			}

			if ( '' === $chunk ) {
				continue;
			}

			if ( 'general' === $media_query ) {
				$out .= $chunk;
				continue;
			}

			$out .= "\n\n{$media_query} {\n{$chunk}\n}";
		}

		return ltrim( $out );
	}

	private function wrap_css( string $css ): string {
		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}

		return sprintf(
			'<style type="text/css" class="et-builder-advanced-style">%s</style>',
			$css
		);
	}

	private function cache_get( string $key ) {
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_string( $cached ) || is_array( $cached ) ) {
			return $cached;
		}

		$cached = get_transient( $key );
		if ( is_string( $cached ) || is_array( $cached ) ) {
			wp_cache_set( $key, $cached, self::CACHE_GROUP, $this->default_ttl() );
			return $cached;
		}

		return null;
	}

	private function cache_set( string $key, $value, int $ttl ): void {
		wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
		set_transient( $key, $value, $ttl );
	}

	private function default_ttl(): int {
		$ttl = $this->ttl_for( '', [], [] );
		return $ttl > 0 ? $ttl : 3600;
	}
}
