<?php
/*
Plugin Name: Divi Fragment Cache
Description: Cache dei frammenti HTML generati dagli shortcode dei moduli Divi (et_pb_*) tramite hook di esecuzione shortcode.
Version: 0.1.0
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Divi_Fragment_Cache {
	private const TRANSIENT_PREFIX = 'divi_fc_';
	private const CACHE_GROUP      = 'divi-fragment-cache';
	private const POST_META_KEY    = '_divi_fragment_cache_keys';
	private const QUERY_BYPASS     = 'divi_fc_bypass';
	private const QUERY_PURGE      = 'divi_fc_purge';

	private static $served_stack = [];
	private static $miss_stack   = [];
	private static $tag_counters = [];
	private static $pending_post_keys = [];
	private static $request_bypass = false;
	private static $debug_hits = 0;
	private static $debug_misses = 0;
	private static $debug_purges = 0;

	public static function bootstrap(): void {
		add_filter( 'pre_do_shortcode_tag', [ __CLASS__, 'maybe_return_cached_shortcode' ], 120, 4 );
		add_filter( 'do_shortcode_tag', [ __CLASS__, 'maybe_store_cached_shortcode' ], 120, 4 );
		add_action( 'wp', [ __CLASS__, 'handle_query_params' ], 0 );
		add_action( 'send_headers', [ __CLASS__, 'send_debug_headers' ], 999 );
		add_action( 'shutdown', [ __CLASS__, 'flush_pending_post_keys' ], 0 );
		add_action( 'save_post', [ __CLASS__, 'on_save_post' ], 20, 1 );
		add_action( 'delete_post', [ __CLASS__, 'on_delete_post' ], 20, 1 );
	}

	public static function maybe_return_cached_shortcode( $override, $tag, $attrs, $m ) {
		if ( false !== $override ) {
			return $override;
		}

		if ( ! self::should_consider( $tag, $attrs, $m ) ) {
			return $override;
		}

		$occurrence = self::next_occurrence( $tag );
		$key        = self::build_cache_key( $tag, $attrs, $m, $occurrence );
		$hit        = self::cache_get( $key );

		if ( is_string( $hit ) ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			delete_transient( $key );
			$hit = null;
		}

		if ( null === $hit ) {
			self::$debug_misses++;
			self::$miss_stack[] = [
				'tag'           => $tag,
				'key'           => $key,
				'post_id'       => self::get_current_post_id(),
				'ttl'           => (int) apply_filters( 'divi_fragment_cache_ttl', 3600, $tag, $attrs, $m ),
				'counts'        => self::extract_shortcode_counts( $m ),
				'styles_before' => self::get_styles_snapshot(),
			];
			return $override;
		}

		self::$debug_hits++;

		$payload = is_array( $hit ) ? $hit : [ 'html' => (string) $hit ];
		self::$served_stack[] = [
			'tag'     => $tag,
			'key'     => $key,
			'payload' => $payload,
		];

		if ( isset( $payload['counts'] ) && is_array( $payload['counts'] ) ) {
			self::fast_forward_occurrence_counters( $tag, $payload['counts'] );
		}

		$output = (string) ( $payload['html'] ?? '' );
		$output = apply_filters( 'do_shortcode_tag', $output, $tag, $attrs, $m );

		return $output;
	}

	public static function maybe_store_cached_shortcode( $output, $tag, $attrs, $m ) {
		$served = self::pop_served_if_match( $tag );
		if ( null !== $served ) {
			$payload = is_array( $served['payload'] ?? null ) ? $served['payload'] : [];
			$counts  = isset( $payload['counts'] ) && is_array( $payload['counts'] ) ? $payload['counts'] : [ $tag => 1 ];
			self::bump_divi_order_classes( $counts );

			$css = isset( $payload['css'] ) && is_string( $payload['css'] ) ? $payload['css'] : '';
			if ( '' !== trim( $css ) ) {
				$output = (string) $output . self::wrap_css( $css );
			}

			return $output;
		}

		if ( ! self::should_consider( $tag, $attrs, $m ) ) {
			return $output;
		}

		if ( false === $output ) {
			return $output;
		}

		$miss = self::pop_miss_if_match( $tag );
		if ( null === $miss ) {
			return $output;
		}

		$ttl = isset( $miss['ttl'] ) ? (int) $miss['ttl'] : (int) apply_filters( 'divi_fragment_cache_ttl', 3600, $tag, $attrs, $m );
		if ( $ttl < 1 ) {
			return $output;
		}

		$styles_before = isset( $miss['styles_before'] ) && is_array( $miss['styles_before'] ) ? $miss['styles_before'] : null;
		$css           = '';
		if ( null !== $styles_before ) {
			$styles_after = self::get_styles_snapshot();
			if ( is_array( $styles_after ) ) {
				$css = self::styles_delta_to_css( $styles_before, $styles_after );
			}
		}

		$payload = [
			'html'   => (string) $output,
			'css'    => $css,
			'counts' => isset( $miss['counts'] ) && is_array( $miss['counts'] ) ? $miss['counts'] : self::extract_shortcode_counts( $m ),
		];

		self::cache_set( (string) $miss['key'], $payload, $ttl );
		self::add_cache_key_for_post( (int) ( $miss['post_id'] ?? 0 ), (string) $miss['key'] );

		return $output;
	}

	public static function on_save_post( $post_id ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		self::invalidate_post_cache_by_id( (int) $post_id );
	}

	public static function on_delete_post( $post_id ): void {
		self::invalidate_post_cache_by_id( (int) $post_id );
	}

	public static function handle_query_params(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( self::is_bypass_requested() ) {
			self::$request_bypass = true;
		}

		if ( ! self::is_purge_requested() ) {
			return;
		}

		$post_id = self::get_current_post_id();
		if ( $post_id < 1 ) {
			return;
		}

		if ( ! self::current_user_can_purge_post( $post_id ) ) {
			return;
		}

		self::$request_bypass = true;
		self::$debug_purges++;
		self::invalidate_post_cache_by_id( $post_id );
	}

	public static function send_debug_headers(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		$bypass = self::$request_bypass || self::is_bypass_requested();

		header(
			sprintf(
				'X-Divi-FC: hits=%d; misses=%d; bypass=%d; purges=%d',
				(int) self::$debug_hits,
				(int) self::$debug_misses,
				$bypass ? 1 : 0,
				(int) self::$debug_purges
			)
		);
	}

	public static function flush_pending_post_keys(): void {
		if ( empty( self::$pending_post_keys ) ) {
			return;
		}

		foreach ( self::$pending_post_keys as $post_id => $keys ) {
			$post_id = (int) $post_id;
			if ( $post_id < 1 || empty( $keys ) || ! is_array( $keys ) ) {
				continue;
			}

			$existing = get_post_meta( $post_id, self::POST_META_KEY, true );
			$existing = is_array( $existing ) ? $existing : [];

			$merged = array_values( array_unique( array_merge( $existing, $keys ) ) );
			update_post_meta( $post_id, self::POST_META_KEY, $merged );
		}

		self::$pending_post_keys = [];
	}

	private static function should_consider( $tag, $attrs, $m ): bool {
		if ( ! self::is_divi_module_shortcode( $tag ) ) {
			return false;
		}

		$attrs = is_array( $attrs ) ? $attrs : (array) $attrs;

		if ( self::$request_bypass || self::is_bypass_requested() ) {
			return false;
		}

		if ( self::is_purge_requested() ) {
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

		$cache_when_logged_in = (bool) apply_filters( 'divi_fragment_cache_logged_in', false, $tag, $attrs, $m );
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

		$deny = (array) apply_filters(
			'divi_fragment_cache_denied_tags',
			[
				'et_pb_contact_form',
				'et_pb_signup',
				'et_pb_login',
				'et_pb_search',
				'et_pb_shop',
				'et_pb_wc_add_to_cart',
				'et_pb_wc_cart_notice',
				'et_pb_wc_checkout_additional_info',
			],
			$tag,
			$attrs,
			$m
		);

		if ( in_array( $tag, $deny, true ) ) {
			return false;
		}

		$allow = (array) apply_filters( 'divi_fragment_cache_allowed_tags', [], $tag, $attrs, $m );
		if ( ! empty( $allow ) && ! in_array( $tag, $allow, true ) ) {
			return false;
		}

		return true;
	}

	private static function is_bypass_requested(): bool {
		return isset( $_GET[ self::QUERY_BYPASS ] ) && '' !== (string) $_GET[ self::QUERY_BYPASS ];
	}

	private static function is_purge_requested(): bool {
		return isset( $_GET[ self::QUERY_PURGE ] ) && '' !== (string) $_GET[ self::QUERY_PURGE ];
	}

	private static function current_user_can_purge_post( int $post_id ): bool {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	private static function is_divi_module_shortcode( $tag ): bool {
		return is_string( $tag ) && 0 === strpos( $tag, 'et_pb_' );
	}

	private static function build_cache_key( $tag, $attrs, $m, int $occurrence ): string {
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
				'tag'     => (string) $tag,
				'occ'     => $occurrence,
				'attrs'   => $attrs,
				'content' => $content,
			],
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return self::TRANSIENT_PREFIX . md5( (string) $raw );
	}

	private static function add_cache_key_for_post( int $post_id, string $cache_key ): void {
		if ( $post_id < 1 || '' === $cache_key ) {
			return;
		}

		if ( empty( self::$pending_post_keys[ $post_id ] ) ) {
			self::$pending_post_keys[ $post_id ] = [];
		}

		self::$pending_post_keys[ $post_id ][] = $cache_key;
		self::$pending_post_keys[ $post_id ]   = array_values( array_unique( self::$pending_post_keys[ $post_id ] ) );
	}

	private static function invalidate_post_cache_by_id( int $post_id ): void {
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

	private static function get_current_post_id(): int {
		$post = get_post();
		if ( $post && isset( $post->ID ) ) {
			return (int) $post->ID;
		}

		return 0;
	}

	private static function next_occurrence( string $tag ): int {
		if ( empty( self::$tag_counters[ $tag ] ) ) {
			self::$tag_counters[ $tag ] = 0;
		}

		self::$tag_counters[ $tag ]++;
		return (int) self::$tag_counters[ $tag ];
	}

	private static function fast_forward_occurrence_counters( string $current_tag, array $counts ): void {
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

			if ( empty( self::$tag_counters[ $tag ] ) ) {
				self::$tag_counters[ $tag ] = 0;
			}

			self::$tag_counters[ $tag ] += $inc;
		}
	}

	private static function pop_served_if_match( string $tag ): ?array {
		$top = end( self::$served_stack );
		if ( false === $top || ! is_array( $top ) ) {
			return null;
		}

		if ( ! isset( $top['tag'] ) || $top['tag'] !== $tag ) {
			return null;
		}

		return array_pop( self::$served_stack );
	}

	private static function pop_miss_if_match( string $tag ): ?array {
		$top = end( self::$miss_stack );
		if ( false === $top || ! is_array( $top ) ) {
			return null;
		}

		if ( ! isset( $top['tag'] ) || $top['tag'] !== $tag ) {
			return null;
		}

		return array_pop( self::$miss_stack );
	}

	private static function extract_shortcode_counts( $m ): array {
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

	private static function bump_divi_order_classes( array $counts ): void {
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

	private static function get_styles_snapshot(): ?array {
		if ( ! class_exists( 'ET_Builder_Element' ) || ! is_callable( [ 'ET_Builder_Element', 'get_style_array' ] ) ) {
			return null;
		}

		return (array) ET_Builder_Element::get_style_array( false );
	}

	private static function styles_delta_to_css( array $before, array $after ): string {
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

	private static function wrap_css( string $css ): string {
		$css = trim( $css );
		if ( '' === $css ) {
			return '';
		}

		return sprintf(
			'<style type="text/css" class="et-builder-advanced-style">%s</style>',
			$css
		);
	}

	private static function cache_get( string $key ) {
		$cached = wp_cache_get( $key, self::CACHE_GROUP );
		if ( is_string( $cached ) || is_array( $cached ) ) {
			return $cached;
		}

		$cached = get_transient( $key );
		if ( is_string( $cached ) || is_array( $cached ) ) {
			wp_cache_set( $key, $cached, self::CACHE_GROUP, self::default_ttl() );
			return $cached;
		}

		return null;
	}

	private static function cache_set( string $key, $value, int $ttl ): void {
		wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
		set_transient( $key, $value, $ttl );
	}

	private static function default_ttl(): int {
		$ttl = (int) apply_filters( 'divi_fragment_cache_ttl', 3600, '', [], [] );
		return $ttl > 0 ? $ttl : 3600;
	}
}

Divi_Fragment_Cache::bootstrap();
