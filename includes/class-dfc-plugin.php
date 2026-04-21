<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once DFC_PLUGIN_DIR . 'includes/class-dfc-options.php';
require_once DFC_PLUGIN_DIR . 'includes/class-dfc-cache.php';
require_once DFC_PLUGIN_DIR . 'includes/admin/class-dfc-admin.php';

final class DFC_Plugin {
	private static ?self $instance = null;

	private DFC_Options $options;
	private DFC_Cache $cache;
	private ?DFC_Admin $admin = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->options = new DFC_Options();
		$this->cache   = new DFC_Cache( $this->options );
	}

	public function init(): void {
		add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );

		$this->cache->bootstrap();

		if ( is_admin() ) {
			$this->admin = new DFC_Admin( $this->options );
			$this->admin->bootstrap();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain(
			'divi-fragment-cache',
			false,
			dirname( plugin_basename( DFC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	public static function activate(): void {
		$options = new DFC_Options();
		if ( false === get_option( DFC_Options::OPTION_NAME, false ) ) {
			add_option( DFC_Options::OPTION_NAME, $options->defaults() );
		}
	}

	public static function deactivate(): void {
	}
}

