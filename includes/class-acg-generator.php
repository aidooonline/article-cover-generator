<?php
/**
 * Generation orchestration (P2): build the prompt from a post, call fal, and download the
 * result to a temp file. Compression, media sideload and setting the featured image land in
 * P3, which will consume the temp file this class produces (and clear the pending meta).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Generator {

	const PENDING_META = '_acg_pending_cover';

	/** Build the {placeholder} values for a post's cover prompt. */
	public static function build_cover_vars( $post_id ) {
		$post_id = (int) $post_id;
		$title   = $post_id ? get_the_title( $post_id ) : '';

		$category = '';
		if ( $post_id ) {
			$cats = get_the_category( $post_id );
			if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
				$category = $cats[0]->name;
			}
		}

		$vars = array(
			'title'           => $title,
			'property_scene'  => ACG_Prompts::DEFAULT_PROPERTY_SCENE,
			'section_subject' => ACG_Prompts::DEFAULT_SECTION_SUBJECT,
			'location'        => ACG_Prompts::DEFAULT_LOCATION,
			'site_name'       => get_bloginfo( 'name' ),
			'category'        => $category,
		);

		/**
		 * Filter the scene variables before rendering. An LLM-refine step (future) or a site
		 * can supply a tailored {property_scene}/{location}/{section_subject} here.
		 */
		return apply_filters( 'acg_cover_vars', $vars, $post_id );
	}

	/**
	 * Generate a cover for a post: render prompt -> fal -> download to a temp file.
	 * Returns the preview URL (fal CDN) and the local temp path for P3.
	 *
	 * @return array{ok:bool, image_url:string, prompt:string, tmp:string, bytes:int, error:string|null}
	 */
	public static function generate_cover( $post_id ) {
		$post_id = (int) $post_id;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return self::fail( 'Invalid post.' );
		}
		if ( ! ACG_Fal::has_key() ) {
			return self::fail( 'Add a fal.ai API key in Settings first.' );
		}

		$o      = ACG_Settings::get();
		$vars   = self::build_cover_vars( $post_id );
		$prompt = ACG_Prompts::render( $o['cover_prompt'], $vars );

		$gen = ACG_Fal::generate_image(
			$prompt,
			array(
				'model'      => $o['model'],
				'image_size' => $o['image_size'],
			)
		);
		if ( empty( $gen['ok'] ) || '' === $gen['image_url'] ) {
			return self::fail( $gen['error'] ? $gen['error'] : 'Generation failed.', $prompt );
		}

		$tmp = self::download_to_temp( $gen['image_url'] );
		if ( is_wp_error( $tmp ) ) {
			return self::fail( 'Generated, but the download failed: ' . $tmp->get_error_message(), $prompt, $gen['image_url'] );
		}

		$bytes = (int) @filesize( $tmp );

		// Replace any previous pending temp file for this post, then record the new one for P3.
		self::clear_pending( $post_id );
		update_post_meta(
			$post_id,
			self::PENDING_META,
			array(
				'tmp'        => $tmp,
				'source_url' => $gen['image_url'],
				'prompt'     => $prompt,
				'created'    => time(),
			)
		);

		return array(
			'ok'        => true,
			'image_url' => $gen['image_url'],
			'prompt'    => $prompt,
			'tmp'       => $tmp,
			'bytes'     => $bytes,
			'error'     => null,
		);
	}

	/** Download a remote URL to a temp file using WordPress' own helper. */
	private static function download_to_temp( $url ) {
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $url, 60 );
	}

	/** Remove a previously stored pending temp file (if any) and its meta. */
	public static function clear_pending( $post_id ) {
		$pending = get_post_meta( (int) $post_id, self::PENDING_META, true );
		if ( is_array( $pending ) && ! empty( $pending['tmp'] ) && file_exists( $pending['tmp'] ) ) {
			@unlink( $pending['tmp'] );
		}
		delete_post_meta( (int) $post_id, self::PENDING_META );
	}

	private static function fail( $msg, $prompt = '', $image_url = '' ) {
		return array(
			'ok'        => false,
			'image_url' => $image_url,
			'prompt'    => $prompt,
			'tmp'       => '',
			'bytes'     => 0,
			'error'     => $msg,
		);
	}
}
