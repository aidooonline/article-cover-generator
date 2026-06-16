<?php
/**
 * Standalone logic test for ACG_Fal. Stubs the WP functions it uses and feeds canned HTTP
 * responses that match the VERIFIED fal /v1/models schema, then asserts parsing, pagination,
 * caching, request construction (auth header + query params) and error handling.
 * Not loaded by WordPress; run with `php tests/test-acg-fal.php`.
 */

error_reporting( E_ALL );

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['__http_queue'] = array(); // FIFO of array('code'=>int,'body'=>string) or array('wp_error'=>string)
$GLOBALS['__http_calls'] = array(); // recorded URLs
$GLOBALS['__http_args']  = array(); // recorded args
$GLOBALS['__transients'] = array();
$GLOBALS['__options']    = array( 'acg_settings' => array( 'fal_api_key' => 'TESTKEY' ) );

class WP_Error {
	public $msg;
	public function __construct( $msg ) { $this->msg = $msg; }
	public function get_error_message() { return $this->msg; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }

function get_option( $k ) { return isset( $GLOBALS['__options'][ $k ] ) ? $GLOBALS['__options'][ $k ] : false; }
function get_transient( $k ) { return isset( $GLOBALS['__transients'][ $k ] ) ? $GLOBALS['__transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['__transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['__transients'][ $k ] ); return true; }

function wp_json_encode( $d ) { return json_encode( $d ); }

// Stubs used by ACG_Generator::build_cover_vars().
function get_the_title( $id ) { return 'Is Accra a Good Place to Invest?'; }
function get_the_category( $id ) { $c = new stdClass(); $c->name = 'Real Estate Market Ghana'; return array( $c ); }
function get_bloginfo( $k ) { return 'Imaani Homes'; }
function apply_filters( $tag, $val ) { return $val; }

function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['__http_calls'][] = $url;
	$GLOBALS['__http_args'][]  = $args;
	if ( empty( $GLOBALS['__http_queue'] ) ) {
		return new WP_Error( 'no canned response queued for ' . $url );
	}
	$next = array_shift( $GLOBALS['__http_queue'] );
	if ( isset( $next['wp_error'] ) ) {
		return new WP_Error( $next['wp_error'] );
	}
	return array( '__code' => $next['code'], '__body' => $next['body'] );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['__code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['__body'] : ''; }

// Minimal ACG_Settings so ACG_Fal::key() can read the stored key.
class ACG_Settings {
	public static function get() { return get_option( 'acg_settings' ); }
}

require __DIR__ . '/../includes/class-acg-fal.php';
require __DIR__ . '/../includes/class-acg-prompts.php';
require __DIR__ . '/../includes/class-acg-generator.php';

// ---- tiny assert harness ----
$fail = 0;
function ok( $cond, $label ) {
	global $fail;
	if ( $cond ) {
		echo "PASS  $label\n";
	} else {
		echo "FAIL  $label\n";
		$fail++;
	}
}
function reset_http() {
	$GLOBALS['__http_queue'] = array();
	$GLOBALS['__http_calls'] = array();
	$GLOBALS['__http_args']  = array();
}

// ===== 1) list_text_to_image_models: 2-page pagination, verified schema =====
ACG_Fal::clear_models_cache();
reset_http();
$page1 = json_encode( array(
	'models' => array(
		array( 'endpoint_id' => 'fal-ai/flux/dev',   'metadata' => array( 'display_name' => 'FLUX.1 [dev]', 'category' => 'text-to-image', 'status' => 'active' ) ),
		array( 'endpoint_id' => 'fal-ai/recraft-v3', 'metadata' => array( 'display_name' => 'Recraft V3',   'category' => 'text-to-image', 'status' => 'active' ) ),
	),
	'next_cursor' => 'Mg==',
	'has_more'    => true,
) );
$page2 = json_encode( array(
	'models' => array(
		array( 'endpoint_id' => 'fal-ai/ideogram/v2', 'metadata' => array( 'display_name' => 'Ideogram V2', 'category' => 'text-to-image', 'status' => 'active' ) ),
		array( 'endpoint_id' => 'fal-ai/flux/schnell' ), // no metadata -> name should fall back to id
	),
	'next_cursor' => null,
	'has_more'    => false,
) );
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => $page1 ),
	array( 'code' => 200, 'body' => $page2 ),
);
$list = ACG_Fal::list_text_to_image_models();
$ids  = array_column( $list, 'id' );
$byid = array(); foreach ( $list as $m ) { $byid[ $m['id'] ] = $m['name']; }

ok( count( $list ) === 4, 'parses 4 models across 2 pages (got ' . count( $list ) . ')' );
ok( count( $GLOBALS['__http_calls'] ) === 2, 'made exactly 2 HTTP calls (pagination) (got ' . count( $GLOBALS['__http_calls'] ) . ')' );
ok( in_array( 'fal-ai/flux/dev', $ids, true ) && in_array( 'fal-ai/flux/schnell', $ids, true ), 'collected endpoint_ids from both pages' );
ok( isset( $byid['fal-ai/flux/dev'] ) && 'FLUX.1 [dev]' === $byid['fal-ai/flux/dev'], 'uses metadata.display_name as the label' );
ok( isset( $byid['fal-ai/flux/schnell'] ) && 'fal-ai/flux/schnell' === $byid['fal-ai/flux/schnell'], 'falls back to endpoint_id when display_name missing' );

// request construction: auth header + query params on the FIRST call
$first_url  = $GLOBALS['__http_calls'][0];
$first_args = $GLOBALS['__http_args'][0];
ok( strpos( $first_url, 'category=text-to-image' ) !== false && strpos( $first_url, 'status=active' ) !== false && strpos( $first_url, 'limit=100' ) !== false, 'first request carries category, status and limit params' );
ok( isset( $first_args['headers']['Authorization'] ) && 'Key TESTKEY' === $first_args['headers']['Authorization'], 'Authorization header is "Key <KEY>"' );
ok( 'GET' === $first_args['method'], 'method is GET for model discovery' );
// second call carries the cursor (rawurlencoded "Mg==" => "Mg%3D%3D")
$second_url = $GLOBALS['__http_calls'][1];
ok( strpos( $second_url, 'cursor=Mg%3D%3D' ) !== false, 'second request carries the rawurlencoded next_cursor' );

// ===== 2) caching: a second call should NOT hit HTTP =====
reset_http();
$list2 = ACG_Fal::list_text_to_image_models();
ok( count( $GLOBALS['__http_calls'] ) === 0, 'second call served from the 12h transient cache (0 HTTP calls)' );
ok( count( $list2 ) === 4, 'cached list is intact (4 models)' );

// ===== 3) force refresh after clearing cache re-fetches =====
ACG_Fal::clear_models_cache();
reset_http();
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array(
		'models'      => array( array( 'endpoint_id' => 'fal-ai/flux-pro/v1.1', 'metadata' => array( 'display_name' => 'FLUX1.1 [pro]' ) ) ),
		'next_cursor' => null,
		'has_more'    => false,
	) ) ),
);
$list3 = ACG_Fal::list_text_to_image_models( true );
ok( count( $GLOBALS['__http_calls'] ) === 1 && count( $list3 ) === 1 && $list3[0]['id'] === 'fal-ai/flux-pro/v1.1', 'force refresh bypasses cache and re-fetches' );

// ===== 4) test_key: 200 OK =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 200, 'body' => json_encode( array( 'models' => array(), 'has_more' => false ) ) ) );
$tk = ACG_Fal::test_key( 'TESTKEY' );
ok( true === $tk['ok'], 'test_key returns ok=true on HTTP 200' );
$tk_url = $GLOBALS['__http_calls'][0];
ok( strpos( $tk_url, '/models?limit=1' ) !== false, 'test_key uses the tiny generation-free /models?limit=1 call' );

// ===== 5) test_key: 401 Unauthorized with fal detail =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 401, 'body' => json_encode( array( 'detail' => 'Invalid credentials' ) ) ) );
$tk401 = ACG_Fal::test_key( 'BADKEY' );
ok( false === $tk401['ok'], 'test_key returns ok=false on HTTP 401' );
ok( strpos( $tk401['message'], 'Unauthorized' ) !== false && strpos( $tk401['message'], 'Invalid credentials' ) !== false, 'surfaces the 401 reason and the fal detail' );

// ===== 6) test_key with empty key never calls HTTP =====
reset_http();
$tkEmpty = ACG_Fal::test_key( '' ); // falls back to stored TESTKEY... so empty-handling test uses no stored key:
$GLOBALS['__options']['acg_settings'] = array( 'fal_api_key' => '' );
reset_http();
$tkNone = ACG_Fal::test_key();
ok( false === $tkNone['ok'] && count( $GLOBALS['__http_calls'] ) === 0, 'no key: test_key fails fast without an HTTP call' );

// ===== 7) list models on failure returns empty (so the UI falls back) =====
$GLOBALS['__options']['acg_settings'] = array( 'fal_api_key' => 'TESTKEY' );
ACG_Fal::clear_models_cache();
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 403, 'body' => json_encode( array( 'detail' => 'no scope' ) ) ) );
$listFail = ACG_Fal::list_text_to_image_models();
ok( is_array( $listFail ) && count( $listFail ) === 0, 'list returns empty array on API failure (UI fallback path)' );
ok( false === get_transient( ACG_Fal::MODELS_CACHE_KEY ), 'a failed fetch is NOT cached' );

// ===== 8) transport (WP_Error) is handled =====
ACG_Fal::clear_models_cache();
reset_http();
$GLOBALS['__http_queue'] = array( array( 'wp_error' => 'cURL timeout' ) );
$tkErr = ACG_Fal::test_key( 'TESTKEY' );
ok( false === $tkErr['ok'] && strpos( $tkErr['message'], 'cURL timeout' ) !== false, 'transport error surfaces the WP_Error message' );

// ===== 9) submit: POST to queue, parse request_id/status_url/response_url =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 200, 'body' => json_encode( array(
	'status'       => 'IN_QUEUE',
	'request_id'   => 'req-1',
	'response_url' => 'https://queue.fal.run/fal-ai/flux/dev/requests/req-1',
	'status_url'   => 'https://queue.fal.run/fal-ai/flux/dev/requests/req-1/status',
) ) ) );
$sub = ACG_Fal::submit( 'fal-ai/flux/dev', array( 'prompt' => 'hello', 'image_size' => 'landscape_16_9' ) );
ok( true === $sub['ok'] && 'req-1' === $sub['request_id'], 'submit parses request_id' );
ok( '' !== $sub['status_url'] && '' !== $sub['response_url'], 'submit captures status_url and response_url' );
$su = $GLOBALS['__http_calls'][0];
$sa = $GLOBALS['__http_args'][0];
ok( 'https://queue.fal.run/fal-ai/flux/dev' === $su, 'submit POSTs to queue.fal.run/{model}' );
ok( 'POST' === $sa['method'] && isset( $sa['headers']['Content-Type'] ) && 'application/json' === $sa['headers']['Content-Type'], 'submit sends POST with JSON content-type' );
ok( strpos( (string) $sa['body'], '"prompt"' ) !== false, 'submit body carries the prompt' );

// ===== 10) poll happy path: COMPLETED then images[].url =====
reset_http();
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'status' => 'COMPLETED' ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'images' => array( array( 'url' => 'https://cdn.fal/img.jpg', 'width' => 1280 ) ) ) ) ),
);
$pl = ACG_Fal::poll_result( 'https://q/status', 'https://q/result', 5, 1 );
ok( true === $pl['ok'] && 'https://cdn.fal/img.jpg' === $pl['image_url'], 'poll returns image url on COMPLETED' );
ok( count( $GLOBALS['__http_calls'] ) === 2, 'poll makes 1 status + 1 result call' );

// ===== 11) poll error status =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 200, 'body' => json_encode( array( 'status' => 'ERROR' ) ) ) );
$pe = ACG_Fal::poll_result( 'https://q/status', 'https://q/result', 5, 1 );
ok( false === $pe['ok'] && strpos( $pe['error'], 'error' ) !== false, 'poll surfaces an ERROR status' );

// ===== 12) poll timeout (timeout=0, IN_PROGRESS -> immediate timeout, no sleep) =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 200, 'body' => json_encode( array( 'status' => 'IN_PROGRESS' ) ) ) );
$pt = ACG_Fal::poll_result( 'https://q/status', 'https://q/result', 0, 1 );
ok( false === $pt['ok'] && strpos( $pt['error'], 'Timed out' ) !== false, 'poll times out cleanly' );

// ===== 13) extract_image_url across shapes =====
ok( 'A' === ACG_Fal::extract_image_url( array( 'images' => array( array( 'url' => 'A' ) ) ) ), 'extract: images[].url' );
ok( 'B' === ACG_Fal::extract_image_url( array( 'images' => array( 'B' ) ) ), 'extract: images[] string' );
ok( 'C' === ACG_Fal::extract_image_url( array( 'image' => array( 'url' => 'C' ) ) ), 'extract: image.url' );
ok( 'D' === ACG_Fal::extract_image_url( array( 'output' => array( 'images' => array( array( 'url' => 'D' ) ) ) ) ), 'extract: output envelope' );
ok( 'E' === ACG_Fal::extract_image_url( array( 'url' => 'E' ) ), 'extract: top-level url' );
ok( '' === ACG_Fal::extract_image_url( array( 'foo' => 'bar' ) ), 'extract: none -> empty string' );

// ===== 14) generate_image end-to-end (submit -> poll -> result) =====
reset_http();
$GLOBALS['__http_queue'] = array(
	array( 'code' => 200, 'body' => json_encode( array( 'status' => 'IN_QUEUE', 'request_id' => 'r2', 'status_url' => 'https://q/s', 'response_url' => 'https://q/r' ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'status' => 'COMPLETED' ) ) ),
	array( 'code' => 200, 'body' => json_encode( array( 'images' => array( array( 'url' => 'https://cdn.fal/final.jpg' ) ) ) ) ),
);
$gi = ACG_Fal::generate_image( 'a luxury apartment', array( 'model' => 'fal-ai/flux/dev', 'image_size' => 'landscape_16_9', 'timeout' => 5 ) );
ok( true === $gi['ok'] && 'https://cdn.fal/final.jpg' === $gi['image_url'], 'generate_image runs submit -> poll -> result' );

// ===== 15) generate_image rejects an empty prompt without HTTP =====
reset_http();
$ge = ACG_Fal::generate_image( '   ' );
ok( false === $ge['ok'] && count( $GLOBALS['__http_calls'] ) === 0, 'generate_image rejects an empty prompt with no HTTP call' );

// ===== 16) submit failure (401) =====
reset_http();
$GLOBALS['__http_queue'] = array( array( 'code' => 401, 'body' => json_encode( array( 'detail' => 'bad key' ) ) ) );
$sf = ACG_Fal::submit( 'fal-ai/flux/dev', array( 'prompt' => 'x' ) );
ok( false === $sf['ok'] && strpos( $sf['error'], 'Unauthorized' ) !== false, 'submit surfaces a 401' );

// ===== 17) generator prompt construction: vars filled, no leftover placeholders =====
$vars = ACG_Generator::build_cover_vars( 123 );
ok( 'Is Accra a Good Place to Invest?' === $vars['title'] && 'Imaani Homes' === $vars['site_name'] && 'Real Estate Market Ghana' === $vars['category'], 'build_cover_vars pulls title, site and category' );
$prompt = ACG_Prompts::render( ACG_Prompts::COVER_SCENE, $vars );
ok( strpos( $prompt, '{' ) === false, 'rendered cover prompt has no leftover {placeholders}' );
ok( strpos( $prompt, $vars['property_scene'] ) !== false && strpos( $prompt, $vars['location'] ) !== false, 'rendered prompt contains the scene and location' );

echo "\n" . ( $fail === 0 ? "ALL TESTS PASSED" : ( $fail . " TEST(S) FAILED" ) ) . "\n";
exit( $fail === 0 ? 0 : 1 );
