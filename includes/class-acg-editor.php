<?php
/**
 * Post-editor integration (P2): a "Cover Generator" meta box with a Generate button that
 * calls fal, downloads the result to a temp file, and previews it. Setting it as the
 * featured image lands in P3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Editor {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'wp_ajax_acg_generate_cover', array( __CLASS__, 'ajax_generate_cover' ) );
	}

	public static function add_box() {
		foreach ( array( 'post', 'page' ) as $type ) {
			add_meta_box( 'acg_cover_box', 'Cover Generator', array( __CLASS__, 'render_box' ), $type, 'side', 'default' );
		}
	}

	public static function render_box( $post ) {
		$has_key = ACG_Fal::has_key();

		echo '<div id="acg-box">';
		if ( ! $has_key ) {
			echo '<p>Add a fal.ai API key in <a href="' . esc_url( admin_url( 'options-general.php?page=article-cover-generator' ) ) . '">Cover Generator settings</a> to enable generation.</p>';
			echo '</div>';
			return;
		}

		echo '<p><button type="button" class="button button-primary" id="acg-generate" data-post="' . esc_attr( $post->ID ) . '">Generate cover</button></p>';
		echo '<p id="acg-gen-status" style="color:#646970;margin:6px 0;"></p>';
		echo '<div id="acg-gen-preview"></div>';
		echo '<p class="description">Generates a brand cover from the title and previews it. Setting it as the featured image arrives in P3.</p>';
		echo '</div>';

		$nonce = wp_create_nonce( 'acg_generate_cover_' . $post->ID );
		$ajax  = esc_url_raw( admin_url( 'admin-ajax.php' ) );

		echo '<script>(function(){var b=document.getElementById("acg-generate");if(!b){return;}b.addEventListener("click",function(){var s=document.getElementById("acg-gen-status");var pv=document.getElementById("acg-gen-preview");b.disabled=true;s.style.color="#646970";s.textContent="Generating, this can take up to a minute\u2026";pv.innerHTML="";var d=new URLSearchParams();d.append("action","acg_generate_cover");d.append("nonce","' . esc_js( $nonce ) . '");d.append("post_id","' . esc_attr( $post->ID ) . '");fetch("' . esc_js( $ajax ) . '",{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d.toString()}).then(function(r){return r.json();}).then(function(j){b.disabled=false;if(j&&j.success&&j.data&&j.data.image_url){s.style.color="#1a7f37";s.textContent=(j.data.message||"Generated.");var im=document.createElement("img");im.src=j.data.image_url;im.style.maxWidth="100%";im.style.height="auto";im.style.marginTop="6px";im.style.borderRadius="4px";pv.appendChild(im);}else{s.style.color="#b32d2e";s.textContent=(j&&j.data&&j.data.message)?j.data.message:"Generation failed.";}}).catch(function(){b.disabled=false;s.style.color="#b32d2e";s.textContent="Network error.";});});})();</script>';
	}

	public static function ajax_generate_cover() {
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		check_ajax_referer( 'acg_generate_cover_' . $post_id, 'nonce' );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Not allowed.' ) );
		}

		$res = ACG_Generator::generate_cover( $post_id );

		if ( ! empty( $res['ok'] ) ) {
			$kb = $res['bytes'] ? round( $res['bytes'] / 1024 ) : 0;
			wp_send_json_success(
				array(
					'image_url' => $res['image_url'],
					'message'   => 'Generated and downloaded' . ( $kb ? ' (' . $kb . ' KB).' : '.' ),
				)
			);
		}
		wp_send_json_error( array( 'message' => $res['error'] ? $res['error'] : 'Generation failed.' ) );
	}
}
