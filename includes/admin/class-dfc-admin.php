<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DFC_Admin {
	private DFC_Options $options;

	public function __construct( DFC_Options $options ) {
		$this->options = $options;
	}

	public function bootstrap(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( DFC_PLUGIN_FILE ), [ $this, 'plugin_action_links' ] );
	}

	public function plugin_action_links( array $links ): array {
		$url   = admin_url( 'options-general.php?page=divi-fragment-cache' );
		$links = array_merge(
			[
				'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'divi-fragment-cache' ) . '</a>',
			],
			$links
		);

		return $links;
	}

	public function register_menu(): void {
		add_options_page(
			esc_html__( 'Divi Fragment Cache', 'divi-fragment-cache' ),
			esc_html__( 'Divi Fragment Cache', 'divi-fragment-cache' ),
			'manage_options',
			'divi-fragment-cache',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'dfc_settings',
			DFC_Options::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this->options, 'sanitize' ],
				'default'           => $this->options->defaults(),
			]
		);

		add_settings_section(
			'dfc_main',
			esc_html__( 'Settings', 'divi-fragment-cache' ),
			'__return_null',
			'divi-fragment-cache'
		);

		add_settings_field(
			'dfc_ttl',
			esc_html__( 'Cache TTL (seconds)', 'divi-fragment-cache' ),
			[ $this, 'render_field_ttl' ],
			'divi-fragment-cache',
			'dfc_main'
		);

		add_settings_field(
			'dfc_cache_logged_in',
			esc_html__( 'Cache for logged-in users', 'divi-fragment-cache' ),
			[ $this, 'render_field_cache_logged_in' ],
			'divi-fragment-cache',
			'dfc_main'
		);

		add_settings_field(
			'dfc_debug_headers',
			esc_html__( 'Debug header', 'divi-fragment-cache' ),
			[ $this, 'render_field_debug_headers' ],
			'divi-fragment-cache',
			'dfc_main'
		);

		add_settings_field(
			'dfc_deny_tags',
			esc_html__( 'Denied shortcodes (denylist)', 'divi-fragment-cache' ),
			[ $this, 'render_field_deny_tags' ],
			'divi-fragment-cache',
			'dfc_main'
		);

		add_settings_field(
			'dfc_allow_tags',
			esc_html__( 'Allowed shortcodes (allowlist)', 'divi-fragment-cache' ),
			[ $this, 'render_field_allow_tags' ],
			'divi-fragment-cache',
			'dfc_main'
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Divi Fragment Cache', 'divi-fragment-cache' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'dfc_settings' );
		do_settings_sections( 'divi-fragment-cache' );
		submit_button();
		echo '</form>';
		echo '</div>';
	}

	public function render_field_ttl(): void {
		$value = $this->options->get_int( 'ttl' );
		printf(
			'<input type="number" min="0" step="1" name="%s[ttl]" value="%s" class="small-text" /> <p class="description">%s</p>',
			esc_attr( DFC_Options::OPTION_NAME ),
			esc_attr( (string) $value ),
			esc_html__( 'Set to 0 to disable caching.', 'divi-fragment-cache' )
		);
	}

	public function render_field_cache_logged_in(): void {
		$checked = $this->options->get_bool( 'cache_logged_in' );
		printf(
			'<label><input type="checkbox" name="%s[cache_logged_in]" value="1" %s /> %s</label>',
			esc_attr( DFC_Options::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Enable caching for authenticated users.', 'divi-fragment-cache' )
		);
	}

	public function render_field_debug_headers(): void {
		$checked = $this->options->get_bool( 'debug_headers' );
		printf(
			'<label><input type="checkbox" name="%s[debug_headers]" value="1" %s /> %s</label><p class="description">%s</p>',
			esc_attr( DFC_Options::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__( 'Send the X-Divi-FC header with hit/miss stats.', 'divi-fragment-cache' ),
			esc_html__( 'Only affects frontend requests.', 'divi-fragment-cache' )
		);
	}

	public function render_field_deny_tags(): void {
		$value = implode( "\n", $this->options->get_tags( 'deny_tags' ) );
		printf(
			'<textarea name="%s[deny_tags]" rows="8" cols="50" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( DFC_Options::OPTION_NAME ),
			esc_textarea( $value ),
			esc_html__( 'One shortcode tag per line (e.g. et_pb_text). These shortcodes will never be cached.', 'divi-fragment-cache' )
		);
	}

	public function render_field_allow_tags(): void {
		$value = implode( "\n", $this->options->get_tags( 'allow_tags' ) );
		printf(
			'<textarea name="%s[allow_tags]" rows="8" cols="50" class="large-text code">%s</textarea><p class="description">%s</p>',
			esc_attr( DFC_Options::OPTION_NAME ),
			esc_textarea( $value ),
			esc_html__( 'One shortcode tag per line (e.g. et_pb_text). Leave empty to cache all Divi shortcodes (et_pb_*) except those in the denylist. If you add one or more tags here, ONLY these tags will be cached (denylist still applies).', 'divi-fragment-cache' )
		);
	}
}
