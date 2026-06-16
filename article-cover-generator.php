<?php
/**
 * Plugin Name:       Article Cover Generator
 * Description:        One-click AI cover and in-article images for posts, powered by fal.ai. Generates a brand-consistent cover (and optional extra images), compresses to WebP, and sets the featured image.
 * Version:           0.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       article-cover-generator
 *
 * Build status: P1 (fal client + live model dropdown + API-key test) on top of the P0 scaffold,
 * diagnostics, settings and prompt library. Generation, compression and attachment land in
 * P2 to P5. See README.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACG_VERSION', '0.2.0' );
define( 'ACG_FILE', __FILE__ );
define( 'ACG_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACG_URL', plugin_dir_url( __FILE__ ) );
define( 'ACG_OPTION', 'acg_settings' );

require_once ACG_DIR . 'includes/class-acg-prompts.php';
require_once ACG_DIR . 'includes/class-acg-diagnostics.php';
require_once ACG_DIR . 'includes/class-acg-fal.php';
require_once ACG_DIR . 'includes/class-acg-settings.php';

add_action( 'plugins_loaded', function () {
	ACG_Settings::instance();
} );

register_activation_hook( __FILE__, function () {
	if ( ! is_array( get_option( ACG_OPTION ) ) ) {
		add_option( ACG_OPTION, ACG_Settings::defaults() );
	}
} );
