<?php
/**
 * fal.ai API client (P1): authenticated requests, an API-key test, and live text-to-image
 * model discovery for the settings dropdown. Image generation lands in P2 and reuses request().
 *
 * Verified against the fal Platform API (docs.fal.ai/platform-apis/v1/models):
 *   - Auth header:  Authorization: Key <FAL_KEY>
 *   - Model search: GET https://api.fal.ai/v1/models?category=text-to-image&status=active&limit=100
 *       -> {
 *            "models": [
 *              { "endpoint_id": "fal-ai/flux/dev",
 *                "metadata": { "display_name": "FLUX.1 [dev]", "category": "text-to-image",
 *                              "status": "active", ... } }
 *            ],
 *            "next_cursor": null,
 *            "has_more": false
 *          }
 * Model discovery uses an API-scope key and does NOT consume image-generation credit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ACG_Fal {

	const API_BASE         = 'https://api.fal.ai/v1';
	const QUEUE_BASE       = 'https://queue.fal.run';
	const MODELS_CACHE_KEY = 'acg_models_t2i';
	const MODELS_CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const MAX_PAGES        = 6; // Safety cap on pagination.

	/** The stored fal key, or a passed-in candidate (trimmed). */
	public static function key( $candidate = null ) {
		if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
			return trim( $candidate );
		}
		$o = ACG_Settings::get();
		return isset( $o['fal_api_key'] ) ? trim( (string) $o['fal_api_key'] ) : '';
	}

	public static function has_key() {
		return '' !== self::key();
	}

	/**
	 * Make an authorized request to the fal API.
	 *
	 * @return array{code:int, body:array|null, error:string|null}
	 */
	private static function request( $method, $path, $args = array(), $key = null ) {
		$key = self::key( $key );
		if ( '' === $key ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => 'No fal.ai API key is set.',
			);
		}

		$url = ( 0 === strpos( $path, 'http' ) ) ? $path : self::API_BASE . $path;

		$defaults = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Key ' . $key,
				'Accept'        => 'application/json',
			),
		);
		$args = array_replace_recursive( $defaults, $args );

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return array(
				'code'  => 0,
				'body'  => null,
				'error' => $res->get_error_message(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$raw  = (string) wp_remote_retrieve_body( $res );
		$body = ( '' !== trim( $raw ) ) ? json_decode( $raw, true ) : null;

		$error = null;
		if ( $code < 200 || $code >= 300 ) {
			$error = self::error_for_code( $code, $body );
		}

		return array(
			'code'  => $code,
			'body'  => is_array( $body ) ? $body : null,
			'error' => $error,
		);
	}

	/** Turn an HTTP status (and any fal error detail) into a human message. */
	private static function error_for_code( $code, $body ) {
		$detail = '';
		if ( is_array( $body ) ) {
			foreach ( array( 'detail', 'message', 'error' ) as $field ) {
				if ( isset( $body[ $field ] ) && is_string( $body[ $field ] ) && '' !== $body[ $field ] ) {
					$detail = $body[ $field ];
					break;
				}
			}
		}

		switch ( (int) $code ) {
			case 401:
				$msg = 'Unauthorized: the API key is missing or invalid.';
				break;
			case 403:
				$msg = 'Forbidden: the key is valid but lacks the required scope.';
				break;
			case 429:
				$msg = 'Rate limited by fal. Please try again shortly.';
				break;
			case 0:
				$msg = 'Could not reach fal.';
				break;
			default:
				$msg = 'fal returned HTTP ' . (int) $code . '.';
				break;
		}

		return ( '' !== $detail ) ? ( $msg . ' ' . $detail ) : $msg;
	}

	/**
	 * Validate an API key with a tiny, generation-free request.
	 *
	 * @return array{ok:bool, message:string}
	 */
	public static function test_key( $key = null ) {
		$key = self::key( $key );
		if ( '' === $key ) {
			return array(
				'ok'      => false,
				'message' => 'Enter an API key first.',
			);
		}

		$r = self::request( 'GET', '/models?limit=1', array(), $key );

		if ( 200 === $r['code'] ) {
			return array(
				'ok'      => true,
				'message' => 'Key is valid. fal model discovery is reachable.',
			);
		}

		return array(
			'ok'      => false,
			'message' => $r['error'] ? $r['error'] : 'Key test failed.',
		);
	}

	/**
	 * Live text-to-image model list for the settings dropdown. Cached for 12h.
	 *
	 * @param bool $force_refresh Bypass and rebuild the cache.
	 * @return array<int, array{id:string, name:string}> Empty array on failure.
	 */
	public static function list_text_to_image_models( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::MODELS_CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! self::has_key() ) {
			return array();
		}

		$found  = array(); // id => name
		$cursor = '';

		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$path = '/models?category=text-to-image&status=active&limit=100';
			if ( '' !== $cursor ) {
				$path .= '&cursor=' . rawurlencode( $cursor );
			}

			$r = self::request( 'GET', $path );
			if ( 200 !== $r['code'] || ! is_array( $r['body'] ) || empty( $r['body']['models'] ) ) {
				break;
			}

			foreach ( $r['body']['models'] as $m ) {
				if ( empty( $m['endpoint_id'] ) ) {
					continue;
				}
				$id   = (string) $m['endpoint_id'];
				$name = $id;
				if ( isset( $m['metadata']['display_name'] ) && '' !== (string) $m['metadata']['display_name'] ) {
					$name = (string) $m['metadata']['display_name'];
				}
				$found[ $id ] = $name;
			}

			if ( empty( $r['body']['has_more'] ) || empty( $r['body']['next_cursor'] ) ) {
				break;
			}
			$cursor = (string) $r['body']['next_cursor'];
		}

		if ( empty( $found ) ) {
			return array();
		}

		asort( $found, SORT_NATURAL | SORT_FLAG_CASE );

		$list = array();
		foreach ( $found as $id => $name ) {
			$list[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}

		set_transient( self::MODELS_CACHE_KEY, $list, self::MODELS_CACHE_TTL );
		return $list;
	}

	public static function clear_models_cache() {
		delete_transient( self::MODELS_CACHE_KEY );
	}

	/**
	 * Submit a generation request to the fal queue.
	 *
	 * @return array{ok:bool, request_id:string, status_url:string, response_url:string, error:string|null}
	 */
	public static function submit( $model, array $input ) {
		$model = trim( (string) $model );
		if ( '' === $model ) {
			return array( 'ok' => false, 'request_id' => '', 'status_url' => '', 'response_url' => '', 'error' => 'No model selected.' );
		}

		$url = self::QUEUE_BASE . '/' . ltrim( $model, '/' );
		$r   = self::request(
			'POST',
			$url,
			array(
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $input ),
			)
		);

		if ( 200 !== $r['code'] && 201 !== $r['code'] ) {
			return array( 'ok' => false, 'request_id' => '', 'status_url' => '', 'response_url' => '', 'error' => $r['error'] ? $r['error'] : 'Submit failed.' );
		}

		$b = is_array( $r['body'] ) ? $r['body'] : array();
		return array(
			'ok'           => ! empty( $b['request_id'] ),
			'request_id'   => isset( $b['request_id'] ) ? (string) $b['request_id'] : '',
			'status_url'   => isset( $b['status_url'] ) ? (string) $b['status_url'] : '',
			'response_url' => isset( $b['response_url'] ) ? (string) $b['response_url'] : '',
			'error'        => empty( $b['request_id'] ) ? 'No request_id in the fal response.' : null,
		);
	}

	/**
	 * Poll a queued request to completion, then fetch and parse the image URL.
	 * Uses the status_url / response_url returned by submit (do not reconstruct).
	 *
	 * @return array{ok:bool, image_url:string, status:string, error:string|null}
	 */
	public static function poll_result( $status_url, $response_url, $timeout = 90, $interval = 2 ) {
		if ( '' === (string) $status_url || '' === (string) $response_url ) {
			return array( 'ok' => false, 'image_url' => '', 'status' => '', 'error' => 'Missing queue URLs.' );
		}

		$deadline = time() + (int) $timeout;
		$status   = '';

		while ( true ) {
			$s = self::request( 'GET', $status_url );
			if ( 200 !== $s['code'] || ! is_array( $s['body'] ) ) {
				return array( 'ok' => false, 'image_url' => '', 'status' => $status, 'error' => $s['error'] ? $s['error'] : 'Status check failed.' );
			}

			$status = isset( $s['body']['status'] ) ? (string) $s['body']['status'] : '';

			if ( 'COMPLETED' === $status ) {
				break;
			}
			if ( in_array( $status, array( 'ERROR', 'FAILED', 'CANCELLED' ), true ) ) {
				return array( 'ok' => false, 'image_url' => '', 'status' => $status, 'error' => 'fal request ' . strtolower( $status ) . '.' );
			}
			if ( time() >= $deadline ) {
				return array( 'ok' => false, 'image_url' => '', 'status' => $status, 'error' => 'Timed out waiting for fal (' . (int) $timeout . 's).' );
			}

			sleep( max( 1, (int) $interval ) );
		}

		$res = self::request( 'GET', $response_url );
		if ( 200 !== $res['code'] || ! is_array( $res['body'] ) ) {
			return array( 'ok' => false, 'image_url' => '', 'status' => $status, 'error' => $res['error'] ? $res['error'] : 'Could not fetch the result.' );
		}

		$image_url = self::extract_image_url( $res['body'] );
		if ( '' === $image_url ) {
			return array( 'ok' => false, 'image_url' => '', 'status' => $status, 'error' => 'No image URL in the fal result.' );
		}

		return array( 'ok' => true, 'image_url' => $image_url, 'status' => $status, 'error' => null );
	}

	/**
	 * Defensively pull the first image URL from a fal result. Handles the standard
	 * images[] shape plus image{}, a bare url, and output/data/response envelopes.
	 */
	public static function extract_image_url( $body ) {
		if ( ! is_array( $body ) ) {
			return '';
		}

		foreach ( array( 'output', 'data', 'response' ) as $wrap ) {
			if ( isset( $body[ $wrap ] ) && is_array( $body[ $wrap ] ) ) {
				$inner = self::extract_image_url( $body[ $wrap ] );
				if ( '' !== $inner ) {
					return $inner;
				}
			}
		}

		if ( ! empty( $body['images'] ) && is_array( $body['images'] ) ) {
			$first = reset( $body['images'] );
			if ( is_array( $first ) && ! empty( $first['url'] ) ) {
				return (string) $first['url'];
			}
			if ( is_string( $first ) && '' !== $first ) {
				return $first;
			}
		}

		if ( ! empty( $body['image'] ) ) {
			if ( is_array( $body['image'] ) && ! empty( $body['image']['url'] ) ) {
				return (string) $body['image']['url'];
			}
			if ( is_string( $body['image'] ) ) {
				return $body['image'];
			}
		}

		if ( ! empty( $body['url'] ) && is_string( $body['url'] ) ) {
			return $body['url'];
		}

		return '';
	}

	/**
	 * High-level: submit a prompt and return the generated image URL.
	 *
	 * @param string $prompt The filled prompt text.
	 * @param array  $opts   Optional: model, image_size, input (extra fal params), timeout.
	 * @return array{ok:bool, image_url:string, error:string|null}
	 */
	public static function generate_image( $prompt, array $opts = array() ) {
		$prompt = trim( (string) $prompt );
		if ( '' === $prompt ) {
			return array( 'ok' => false, 'image_url' => '', 'error' => 'Empty prompt.' );
		}

		$o     = ACG_Settings::get();
		$model = isset( $opts['model'] ) ? $opts['model'] : $o['model'];

		$input = array(
			'prompt'          => $prompt,
			'negative_prompt' => class_exists( 'ACG_Prompts' ) ? ACG_Prompts::NEGATIVE_PROMPT : '',
			'image_size'      => isset( $opts['image_size'] ) ? $opts['image_size'] : $o['image_size'],
			'num_images'      => 1,
		);
		if ( isset( $opts['input'] ) && is_array( $opts['input'] ) ) {
			$input = array_merge( $input, $opts['input'] );
		}

		$sub = self::submit( $model, $input );
		if ( empty( $sub['ok'] ) ) {
			return array( 'ok' => false, 'image_url' => '', 'error' => $sub['error'] );
		}

		$timeout = isset( $opts['timeout'] ) ? (int) $opts['timeout'] : 90;
		$res     = self::poll_result( $sub['status_url'], $sub['response_url'], $timeout );

		return array(
			'ok'        => ! empty( $res['ok'] ),
			'image_url' => isset( $res['image_url'] ) ? $res['image_url'] : '',
			'error'     => isset( $res['error'] ) ? $res['error'] : null,
		);
	}

	/**
	 * Minimal, well-known fallback list so the dropdown still offers sensible options
	 * when no key is set or the live fetch fails. The free choice is preserved because
	 * the saved model is always injected as an option by the settings screen.
	 *
	 * @return array<int, array{id:string, name:string}>
	 */
	public static function fallback_models() {
		return array(
			array( 'id' => 'fal-ai/flux/dev',                   'name' => 'FLUX.1 [dev]' ),
			array( 'id' => 'fal-ai/flux/schnell',               'name' => 'FLUX.1 [schnell]' ),
			array( 'id' => 'fal-ai/flux-pro/v1.1',              'name' => 'FLUX1.1 [pro]' ),
			array( 'id' => 'fal-ai/recraft-v3',                 'name' => 'Recraft V3' ),
			array( 'id' => 'fal-ai/stable-diffusion-v35-large', 'name' => 'Stable Diffusion 3.5 Large' ),
		);
	}
}
