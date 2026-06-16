<?php
/**
 * Uninstall: remove plugin options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'acg_settings' );
delete_transient( 'acg_settings_errors' );
