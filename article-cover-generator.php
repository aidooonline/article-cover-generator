<?php
/**
 * Plugin Name:       Article Cover Generator
 * Description:        One-click AI cover and in-article images for posts, powered by fal.ai. Generates a brand-consistent cover (and optional extra images), compresses to WebP, and sets the featured image.
 * Version:           0.3.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       article-cover-generator
 *
 * Build status: P2 (generate + download to temp from the post editor) on top of P0 scaffold
 * and P1 fal client. Compression, media sideload and the featured image land in P3 to P5.
 * See README.md.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ACG_VERSION', '0.3.1' );
define( 'ACG_FILE', __FILE__ );
define( 'ACG_DIR', plugin_dir_path( __FILE__ ) );
define( 'ACG_URL', plugin_dir_url( __FILE__ ) );
define( 'ACG_OPTION', 'acg_settings' );

require_once ACG_DIR . 'includes/class-acg-prompts.php';
require_once ACG_DIR . 'includes/class-acg-diagnostics.php';
require_once ACG_DIR . 'includes/class-acg-fal.php';
require_once ACG_DIR . 'includes/class-acg-generator.php';
require_once ACG_DIR . 'includes/class-acg-settings.php';
require_once ACG_DIR . 'includes/class-acg-editor.php';

add_action( 'plugins_loaded', function () {
	ACG_Settings::instance();
	ACG_Editor::init();
} );

register_activation_hook( __FILE__, function () {
	if ( ! is_array( get_option( ACG_OPTION ) ) ) {
		add_option( ACG_OPTION, ACG_Settings::defaults() );
	}
} );
