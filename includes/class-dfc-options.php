<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DFC_Options {
	public const OPTION_NAME = 'dfc_options';

	public function defaults(): array {
		return [
			'ttl'              => 3600,
			'cache_logged_in'  => 0,
			'debug_headers'    => 0,
			'deny_tags'        => [
				'et_pb_contact_form',
				'et_pb_signup',
				'et_pb_login',
				'et_pb_search',
				'et_pb_shop',
				'et_pb_wc_add_to_cart',
				'et_pb_wc_cart_notice',
				'et_pb_wc_checkout_additional_info',
			],
			'allow_tags'       => [],
		];
	}

	public function get_all(): array {
		$raw = get_option( self::OPTION_NAME, [] );
		$raw = is_array( $raw ) ? $raw : [];

		return array_merge( $this->defaults(), $raw );
	}

	public function get_bool( string $key ): bool {
		$all = $this->get_all();
		return ! empty( $all[ $key ] );
	}

	public function get_int( string $key ): int {
		$all = $this->get_all();
		return isset( $all[ $key ] ) ? (int) $all[ $key ] : 0;
	}

	public function get_tags( string $key ): array {
		$all  = $this->get_all();
		$tags = $all[ $key ] ?? [];
		if ( ! is_array( $tags ) ) {
			$tags = [];
		}

		$out = [];
		foreach ( $tags as $tag ) {
			$tag = strtolower( trim( (string) $tag ) );
			if ( '' === $tag ) {
				continue;
			}
			$out[] = $tag;
		}

		return array_values( array_unique( $out ) );
	}

	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$out   = $this->defaults();

		$ttl = isset( $input['ttl'] ) ? (int) $input['ttl'] : $out['ttl'];
		if ( $ttl < 0 ) {
			$ttl = 0;
		}
		$out['ttl'] = $ttl;

		$out['cache_logged_in'] = empty( $input['cache_logged_in'] ) ? 0 : 1;
		$out['debug_headers']   = empty( $input['debug_headers'] ) ? 0 : 1;

		$out['deny_tags']  = $this->sanitize_tags_input( $input['deny_tags'] ?? $out['deny_tags'] );
		$out['allow_tags'] = $this->sanitize_tags_input( $input['allow_tags'] ?? $out['allow_tags'] );

		return $out;
	}

	private function sanitize_tags_input( $value ): array {
		$tags = [];

		if ( is_string( $value ) ) {
			$value = preg_split( "/\r\n|\r|\n/", $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $tag ) {
				$tag = strtolower( trim( (string) $tag ) );
				if ( '' === $tag ) {
					continue;
				}
				$tags[] = $tag;
			}
		}

		return array_values( array_unique( $tags ) );
	}
}

