<?php
/**
 * Settings: admin menu + options page (Settings / Prompts / Diagnostics tabs).
 * P0 saves the fal key, model, path, output options, and the two default prompt templates.
 * P1 adds: a live model dropdown fetched from fal, an API-key test button, and a models refresh.
 * Full template CRUD and the generation button arrive in P2.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Settings {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
		add_action( 'admin_init', array( $this, 'maybe_refresh_models' ) );
		add_action( 'wp_ajax_acg_test_key', array( $this, 'ajax_test_key' ) );
	}

	/** Default option set. */
	public static function defaults() {
		return array(
			'fal_api_key'       => '',
			'model'             => 'fal-ai/flux/dev',
			'image_size'        => 'landscape_16_9',
			'path'              => 'overlay', // overlay (Path 1) | all_in_one (Path 2)
			'extra_image_count' => 2,
			'output_max_width'  => 1600,
			'output_quality'    => 82,
			'output_format'     => 'webp', // webp | jpeg
			'llm_refine'        => 0,
			'cover_prompt'      => ACG_Prompts::COVER_SCENE,
			'extra_prompt'      => ACG_Prompts::EXTRA_SCENE,
		);
	}

	public static function get() {
		$opts = get_option( ACG_OPTION );
		return wp_parse_args( is_array( $opts ) ? $opts : array(), self::defaults() );
	}

	public function menu() {
		add_options_page(
			'Article Cover Generator',
			'Cover Generator',
			'manage_options',
			'article-cover-generator',
			array( $this, 'render' )
		);
	}

	public function handle_save() {
		if ( empty( $_POST['acg_save'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'acg_save_settings' );

		$o = self::get();

		$prev_key = $o['fal_api_key'];

		$o['fal_api_key']       = isset( $_POST['fal_api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['fal_api_key'] ) ) : $o['fal_api_key'];
		$o['model']             = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : $o['model'];
		$o['image_size']        = isset( $_POST['image_size'] ) ? sanitize_text_field( wp_unslash( $_POST['image_size'] ) ) : $o['image_size'];
		$o['path']              = ( isset( $_POST['path'] ) && 'all_in_one' === $_POST['path'] ) ? 'all_in_one' : 'overlay';
		$o['extra_image_count'] = isset( $_POST['extra_image_count'] ) ? max( 0, min( 5, (int) $_POST['extra_image_count'] ) ) : $o['extra_image_count'];
		$o['output_max_width']  = isset( $_POST['output_max_width'] ) ? max( 600, min( 2400, (int) $_POST['output_max_width'] ) ) : $o['output_max_width'];
		$o['output_quality']    = isset( $_POST['output_quality'] ) ? max( 50, min( 95, (int) $_POST['output_quality'] ) ) : $o['output_quality'];
		$o['output_format']     = ( isset( $_POST['output_format'] ) && 'jpeg' === $_POST['output_format'] ) ? 'jpeg' : 'webp';
		$o['llm_refine']        = empty( $_POST['llm_refine'] ) ? 0 : 1;
		$o['cover_prompt']      = isset( $_POST['cover_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cover_prompt'] ) ) : $o['cover_prompt'];
		$o['extra_prompt']      = isset( $_POST['extra_prompt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra_prompt'] ) ) : $o['extra_prompt'];

		update_option( ACG_OPTION, $o );

		// If the key changed, the cached model list may no longer apply.
		if ( $prev_key !== $o['fal_api_key'] ) {
			ACG_Fal::clear_models_cache();
		}

		add_settings_error( 'acg', 'acg_saved', 'Settings saved.', 'updated' );
		set_transient( 'acg_settings_errors', get_settings_errors(), 30 );
	}

	/** "Refresh models" action: clear the cache, re-prime it, and return to the page. */
	public function maybe_refresh_models() {
		if ( empty( $_GET['acg_action'] ) || 'refresh_models' !== $_GET['acg_action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'acg_refresh_models' );

		ACG_Fal::clear_models_cache();
		ACG_Fal::list_text_to_image_models( true );

		add_settings_error( 'acg', 'acg_models', 'Model list refreshed from fal.', 'updated' );
		set_transient( 'acg_settings_errors', get_settings_errors(), 30 );

		wp_safe_redirect( admin_url( 'options-general.php?page=article-cover-generator' ) );
		exit;
	}

	/** AJAX: test the fal API key (uses the posted candidate so it can be tested before saving). */
	public function ajax_test_key() {
		check_ajax_referer( 'acg_test_key', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}

		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		$res = ACG_Fal::test_key( $key );

		if ( ! empty( $res['ok'] ) ) {
			wp_send_json_success( array( 'message' => $res['message'] ) );
		}
		wp_send_json_error( array( 'message' => $res['message'] ) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$o    = self::get();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings';
		$base = admin_url( 'options-general.php?page=article-cover-generator' );

		if ( $errors = get_transient( 'acg_settings_errors' ) ) {
			delete_transient( 'acg_settings_errors' );
			settings_errors( 'acg' );
		}

		echo '<div class="wrap"><h1>Article Cover Generator</h1>';
		echo '<p style="color:#646970;">fal client, the live model dropdown and the key test are live (P1). Generation, compression and attachment land in P2 to P5.</p>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( array( 'settings' => 'Settings', 'prompts' => 'Prompts', 'diagnostics' => 'Diagnostics' ) as $key => $label ) {
			$class = ( $tab === $key ) ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $class ) . '" href="' . esc_url( add_query_arg( 'tab', $key, $base ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';

		if ( 'diagnostics' === $tab ) {
			echo '<h2>Server image capabilities</h2>';
			ACG_Diagnostics::render();
			echo '</div>';
			return;
		}

		$is_settings = ( 'prompts' !== $tab );

		echo '<form method="post" action="' . esc_url( $base ) . '">';
		wp_nonce_field( 'acg_save_settings' );

		if ( 'prompts' === $tab ) {
			echo '<h2>Prompt templates</h2>';
			echo '<p>Defaults are loaded below. Placeholder variables: <code>' . esc_html( implode( '</code>, <code>', array_keys( ACG_Prompts::variables() ) ) ) . '</code>.</p>';
			echo '<table class="form-table"><tbody>';
			echo '<tr><th scope="row"><label for="cover_prompt">Cover prompt (Editorial Glass)</label></th><td><textarea id="cover_prompt" name="cover_prompt" rows="8" class="large-text code">' . esc_textarea( $o['cover_prompt'] ) . '</textarea></td></tr>';
			echo '<tr><th scope="row"><label for="extra_prompt">Extra-image prompt (Editorial Scene)</label></th><td><textarea id="extra_prompt" name="extra_prompt" rows="6" class="large-text code">' . esc_textarea( $o['extra_prompt'] ) . '</textarea></td></tr>';
			echo '</tbody></table>';
		} else {
			echo '<h2>Settings</h2>';
			echo '<table class="form-table"><tbody>';

			// fal API key + Test key button.
			echo '<tr><th scope="row"><label for="fal_api_key">fal.ai API key</label></th><td>';
			echo '<input type="password" id="fal_api_key" name="fal_api_key" value="' . esc_attr( $o['fal_api_key'] ) . '" class="regular-text" autocomplete="off" /> ';
			echo '<button type="button" class="button" id="acg-test-key">Test key</button>';
			echo '<span id="acg-test-key-result" style="margin-left:10px;"></span>';
			echo '<p class="description">Stored in this site only. Used as <code>Authorization: Key &lt;key&gt;</code>. The test only checks model discovery and does not use image credit.</p></td></tr>';

			// Image model: live dropdown when available, else a built-in fallback list.
			$models     = ACG_Fal::list_text_to_image_models();
			$using_live = ! empty( $models );
			if ( ! $using_live ) {
				$models = ACG_Fal::fallback_models();
			}
			$ids = wp_list_pluck( $models, 'id' );
			if ( '' !== $o['model'] && ! in_array( $o['model'], $ids, true ) ) {
				array_unshift( $models, array( 'id' => $o['model'], 'name' => $o['model'] . ' (current)' ) );
			}

			echo '<tr><th scope="row"><label for="model">Image model</label></th><td>';
			echo '<select id="model" name="model" class="regular-text">';
			foreach ( $models as $m ) {
				echo '<option value="' . esc_attr( $m['id'] ) . '" ' . selected( $o['model'], $m['id'], false ) . '>' . esc_html( $m['name'] ) . ' &mdash; ' . esc_html( $m['id'] ) . '</option>';
			}
			echo '</select> ';
			$refresh_url = wp_nonce_url( add_query_arg( 'acg_action', 'refresh_models', $base ), 'acg_refresh_models' );
			echo '<a href="' . esc_url( $refresh_url ) . '" class="button">Refresh models</a>';
			if ( $using_live ) {
				echo '<p class="description">Live text-to-image models from fal (active only), cached 12 hours. ' . count( $models ) . ' available.</p>';
			} else {
				echo '<p class="description">Showing a built-in fallback list. Add a valid fal API key, save, then click <em>Refresh models</em> to load the live catalogue.</p>';
			}
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="image_size">Cover image size</label></th><td><input type="text" id="image_size" name="image_size" value="' . esc_attr( $o['image_size'] ) . '" class="regular-text" /><p class="description">fal size keyword, e.g. <code>landscape_16_9</code>.</p></td></tr>';

			echo '<tr><th scope="row">Layout path</th><td>';
			echo '<label><input type="radio" name="path" value="overlay" ' . checked( $o['path'], 'overlay', false ) . '> Path 1: AI scene + code overlay (recommended)</label><br>';
			echo '<label><input type="radio" name="path" value="all_in_one" ' . checked( $o['path'], 'all_in_one', false ) . '> Path 2: all-in-one prompt</label>';
			echo '</td></tr>';

			echo '<tr><th scope="row"><label for="extra_image_count">Extra in-article images</label></th><td><input type="number" id="extra_image_count" name="extra_image_count" value="' . esc_attr( $o['extra_image_count'] ) . '" min="0" max="5" class="small-text" /></td></tr>';

			echo '<tr><th scope="row"><label for="output_max_width">Output max width (px)</label></th><td><input type="number" id="output_max_width" name="output_max_width" value="' . esc_attr( $o['output_max_width'] ) . '" min="600" max="2400" class="small-text" /></td></tr>';

			echo '<tr><th scope="row"><label for="output_quality">Output quality</label></th><td><input type="number" id="output_quality" name="output_quality" value="' . esc_attr( $o['output_quality'] ) . '" min="50" max="95" class="small-text" /><p class="description">WebP/JPEG quality. 82 is a good balance.</p></td></tr>';

			echo '<tr><th scope="row">Output format</th><td>';
			echo '<label><input type="radio" name="output_format" value="webp" ' . checked( $o['output_format'], 'webp', false ) . '> WebP (recommended)</label> ';
			echo '<label><input type="radio" name="output_format" value="jpeg" ' . checked( $o['output_format'], 'jpeg', false ) . '> JPEG (fallback)</label>';
			echo '</td></tr>';

			echo '<tr><th scope="row">LLM prompt refine</th><td><label><input type="checkbox" name="llm_refine" value="1" ' . checked( $o['llm_refine'], 1, false ) . '> Turn the article into a fitting scene before generating</label></td></tr>';

			echo '</tbody></table>';
		}

		echo '<p class="submit"><button type="submit" name="acg_save" value="1" class="button button-primary">Save changes</button></p>';
		echo '</form></div>';

		if ( $is_settings ) {
			$nonce = wp_create_nonce( 'acg_test_key' );
			$ajax  = esc_url_raw( admin_url( 'admin-ajax.php' ) );
			echo '<script>(function(){var b=document.getElementById("acg-test-key");if(!b){return;}b.addEventListener("click",function(){var o=document.getElementById("acg-test-key-result");var k=(document.getElementById("fal_api_key")||{}).value||"";o.textContent="Testing\u2026";o.style.color="#646970";var d=new URLSearchParams();d.append("action","acg_test_key");d.append("nonce","' . esc_js( $nonce ) . '");d.append("key",k);fetch("' . esc_js( $ajax ) . '",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d.toString()}).then(function(r){return r.json();}).then(function(j){o.textContent=(j&&j.data&&j.data.message)?j.data.message:((j&&j.success)?"OK":"Test failed.");o.style.color=(j&&j.success)?"#1a7f37":"#b32d2e";}).catch(function(){o.textContent="Network error.";o.style.color="#b32d2e";});});})();</script>';
		}
	}
}
