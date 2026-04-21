<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'dfc_options' );
delete_site_option( 'dfc_options' );
delete_post_meta_by_key( '_divi_fragment_cache_keys' );

