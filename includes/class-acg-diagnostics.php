<?php
/**
 * Diagnostics: reports the image-processing capabilities of the actual server, so we know which
 * compression path is available before relying on it. This is the answer to "compression failed
 * last time": we detect, we do not assume.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Diagnostics {

	/** Collect server capabilities. */
	public static function collect() {
		$gd_loaded = function_exists( 'imagecreatetruecolor' );

		$imagick_loaded = extension_loaded( 'imagick' ) && class_exists( 'Imagick' );
		$imagick_webp   = false;
		if ( $imagick_loaded ) {
			try {
				$imagick_webp = ! empty( Imagick::queryFormats( 'WEBP' ) );
			} catch ( \Throwable $e ) {
				$imagick_webp = false;
			}
		}

		return array(
			'php_version'          => PHP_VERSION,
			'imagick'              => $imagick_loaded,
			'imagick_webp'         => $imagick_webp,
			'gd'                   => $gd_loaded,
			'gd_webp'              => function_exists( 'imagewebp' ),
			'memory_limit'         => ini_get( 'memory_limit' ),
			'upload_max_filesize'  => ini_get( 'upload_max_filesize' ),
			'post_max_size'        => ini_get( 'post_max_size' ),
			'max_execution_time'   => ini_get( 'max_execution_time' ),
			'allow_url_fopen'      => (bool) ini_get( 'allow_url_fopen' ),
		);
	}

	/** Choose the best available encoder for compression. */
	public static function best_encoder( array $d ) {
		if ( $d['imagick'] && $d['imagick_webp'] ) {
			return 'imagick-webp';
		}
		if ( $d['gd'] && $d['gd_webp'] ) {
			return 'gd-webp';
		}
		if ( $d['imagick'] ) {
			return 'imagick-jpeg';
		}
		if ( $d['gd'] ) {
			return 'gd-jpeg';
		}
		return 'none';
	}

	/** Render the diagnostics panel. */
	public static function render() {
		$d   = self::collect();
		$enc = self::best_encoder( $d );

		$rows = array(
			'PHP version'           => esc_html( $d['php_version'] ),
			'Imagick extension'     => self::badge( $d['imagick'] ),
			'Imagick WebP support'  => self::badge( $d['imagick_webp'] ),
			'GD extension'          => self::badge( $d['gd'] ),
			'GD WebP support'       => self::badge( $d['gd_webp'] ),
			'Memory limit'          => esc_html( $d['memory_limit'] ),
			'Upload max filesize'   => esc_html( $d['upload_max_filesize'] ),
			'Post max size'         => esc_html( $d['post_max_size'] ),
			'Max execution time'    => esc_html( $d['max_execution_time'] ) . 's',
			'allow_url_fopen'       => self::badge( $d['allow_url_fopen'] ),
		);

		echo '<table class="widefat striped" style="max-width:640px;margin-top:12px;">';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th style="width:240px;">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $value ) . '</td></tr>';
		}
		echo '</table>';

		$ok = ( 'none' !== $enc );
		echo '<p style="margin-top:14px;font-size:14px;">Chosen compression path: <strong>' . esc_html( $enc ) . '</strong> ';
		echo $ok
			? '<span style="color:#1a7f37;">(compression is available on this server)</span>'
			: '<span style="color:#b32d2e;">(no image library found, compression cannot run here, contact the host)</span>';
		echo '</p>';
	}

	private static function badge( $bool ) {
		return $bool
			? '<span style="color:#1a7f37;font-weight:600;">Yes</span>'
			: '<span style="color:#b32d2e;font-weight:600;">No</span>';
	}
}
