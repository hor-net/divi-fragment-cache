<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DFC_Cache {
	private const TRANSIENT_PREFIX = 'divi_fc_';
	private const CACHE_GROUP      = 'divi-fragment-cache';
	private const POST_META_KEY    = '_divi_fragment_cache_keys';
	private const QUERY_BYPASS     = 'divi_fc_bypass';
	private const QUERY_PURGE      = 'divi_fc_purge';

	private DFC_Options $options;

	private array $served_stack = [];
	private array $miss_stack   = [];
	private array $tag_counters = [];
	private array $pending_post_keys = [];
	private bool $request_bypass = false;
	private int $debug_hits = 0;
	private int $debug_misses = 0;
	private int $debug_purges = 0;

	public function __construct( DFC_Options $options ) {
		$this->options = $options;
	}

	public function bootstrap(): void {
		add_filter( 'pre_do_shortcode_tag', [ $this, 'maybe_return_cached_shortcode' ], 120, 4 );
		add_filter( 'do_shortcode_tag', [ $this, 'maybe_store_cached_shortcode' ], 120, 4 );
		add_action( 'wp', [ $this, 'handle_query_params' ], 0 );
		add_action( 'send_headers', [ $this, 'send_debug_headers' ], 999 );
		add_action( 'shutdown', [ $this, 'flush_pending_post_keys' ], 0 );
		add_action( 'save_post', [ $this, 'on_save_post' ], 20, 1 );
		add_action( 'delete_post', [ $this, 'on_delete_post' ], 20, 1 );
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
			];
			return $override;
		}

		$this->debug_hits++;

		$payload = is_array( $hit ) ? $hit : [ 'html' => (string) $hit ];
		$this->served_stack[] = [
			'tag'     => $tag,
			'key'     => $key,
			'payload' => $payload,
		];

		if ( isset( $payload['counts'] ) && is_array( $payload['counts'] ) ) {
			$this->fast_forward_occurrence_counters( $tag, $payload['counts'] );
		}

		$output = (string) ( $payload['html'] ?? '' );
		$output = apply_filters( 'do_shortcode_tag', $output, $tag, $attrs, $m );

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

		$payload = [
			'html'   => (string) $output,
			'css'    => $css,
			'counts' => isset( $miss['counts'] ) && is_array( $miss['counts'] ) ? $miss['counts'] : $this->extract_shortcode_counts( $m ),
		];

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
				'v'       => 1,
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
		}

		delete_post_meta( $post_id, self::POST_META_KEY );
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
