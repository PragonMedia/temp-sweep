<?php
// clickid.php — fetch RedTrack clickid once per session via ?format=json and return JSON
// Call from JS: fetch('/clickid.php', { method:'POST', credentials:'include', body: new URLSearchParams({ qs: location.search, fbp: getCookie('_fbp'), fbc: getCookie('_fbc') }) })

// Set error handling
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Set headers early
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// Error handler to return JSON on fatal errors
register_shutdown_function(function () {
  $error = error_get_last();
  if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(200); // Return 200 with error info
    echo json_encode([
      'ok' => false,
      'error' => 'PHP Error: ' . $error['message'],
      'file' => $error['file'],
      'line' => $error['line']
    ]);
  }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
  @session_start(); // Suppress warnings if session can't start
}

/* --- Config --- */
// Function to extract domain and route from current URL
function getDomainAndRoute()
{
  // Get domain from HTTP_HOST (includes .com)
  $domain = $_SERVER['HTTP_HOST'] ?? '';

  // Remove www. prefix if present
  $domain = preg_replace('/^www\./', '', $domain);

  // Extract route from REQUEST_URI
  $requestUri = $_SERVER['REQUEST_URI'] ?? '';
  $path = parse_url($requestUri, PHP_URL_PATH);

  // Remove leading slash and get first segment
  $path = ltrim($path, '/');
  $segments = explode('/', $path);
  $route = $segments[0] ?? '';

  // If route is empty or is a PHP file, try to get from referrer
  if (empty($route) || strpos($route, '.php') !== false) {
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if ($referrer) {
      $referrerPath = parse_url($referrer, PHP_URL_PATH);
      $referrerPath = ltrim($referrerPath, '/');
      $referrerSegments = explode('/', $referrerPath);
      $route = $referrerSegments[0] ?? '';
    }
  }

  return ['domain' => $domain, 'route' => $route];
}

// Function to fetch route data from API
function fetchRouteData($domain, $route)
{
  // Try to get API URL from environment variable, fallback to localhost
  $apiBase = getenv('API_BASE_URL') ?: 'http://localhost:3000';
  $apiUrl = $apiBase . '/api/v1/domain-route-details?domain=' . urlencode($domain) . '&route=' . urlencode($route);

  $ch = curl_init($apiUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,  // Reduced timeout for faster failure
    CURLOPT_TIMEOUT => 3,          // Reduced timeout for faster failure
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
    ],
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error = curl_error($ch);
  curl_close($ch);

  // Silently fail if API is unavailable - use fallback
  if ($error || $httpCode !== 200) {
    error_log("API fetch failed for domain=$domain route=$route: $error (HTTP $httpCode)");
    return null;
  }

  $data = json_decode($response, true);
  return $data;
}

// HARD CODED FOR TESTING - Extract domain and route
$domainRoute = getDomainAndRoute();
$domain = $domainRoute['domain'];
$route = $domainRoute['route'];

// TESTING: Hard coded values
//$domain = "sample-new-domain";
//$route = "nn-new";

// Fetch route data from API
$cmpId = "68405d20d4a5e7f4cc123742"; // Fallback default

if (!empty($domain) && !empty($route)) {
  $apiData = fetchRouteData($domain, $route);
  if ($apiData && isset($apiData['success']) && $apiData['success'] && array_key_exists('rtkID', $apiData['routeData'])) {
    // Use the value from API, even if it's null
    $cmpId = $apiData['routeData']['rtkID'];
  }
}

// Log rtkID for testing
error_log("TESTING - rtkID: " . $cmpId);



define('SESSION_KEY', 'rt_clickid');
define('SESSION_TTL', 6 * 3600);                // 6h cache
define('RT_BASE', 'https://dx8jy.ttrk.io');
define('COOKIE_NAME', 'rtkclickid-store');      // parity with RT JS

/* --- Inputs --- */
// Get referrer from POST data first, fallback to HTTP_REFERER header
$referrer = $_POST['referrer'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if (empty($referrer)) {
  // Last resort: construct from current request
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $uri = $_SERVER['REQUEST_URI'] ?? '';
  $referrer = $scheme . '://' . $host . $uri;
}

/* --- Cache hit? --- */
$now = time();
if (!empty($_SESSION[SESSION_KEY]) && !empty($_SESSION[SESSION_KEY . '_ts']) && ($now - $_SESSION[SESSION_KEY . '_ts']) < SESSION_TTL) {
  echo json_encode([
    'ok'      => true,
    'clickid' => (string)$_SESSION[SESSION_KEY],
    'cached'  => true,
    'ref'     => $referrer,
    'mint_url' => null,
    'debug'   => [
      'domain' => $domain,
      'route' => $route,
      'rtkID' => $cmpId
    ]
  ]);
  exit;
}

// If rtkID is null, skip RedTrack request and return early
if ($cmpId === null) {
  echo json_encode([
    'ok'      => false,
    'error'   => 'rtkID is null - RedTrack tracking disabled',
    'ref'     => $referrer,
    'debug'   => [
      'domain' => $domain,
      'route' => $route,
      'rtkID' => null
    ]
  ]);
  exit;
}

/* --- Build mint URL (Variant A): encoded referrer + UTMs as separate params --- */
$rtUrl = RT_BASE . '/' . rawurlencode($cmpId) . '?format=json';

if ($referrer !== '') {
  // 1) encoded referrer
  $rtUrl .= '&referrer=' . rawurlencode($referrer);

  // 2) forward page query as top-level params (KEEP sub1..sub10; drop only noise)
  $qs = parse_url($referrer, PHP_URL_QUERY) ?: '';
  if ($qs !== '') {
    parse_str($qs, $params);

    // drop known noise only
    unset($params['cost'], $params['ref_id']);

    // IMPORTANT: do NOT unset sub1..sub10 — we want sub1
    $cleanQs = http_build_query($params);
    if ($cleanQs !== '') $rtUrl .= '&' . $cleanQs;
  }
}

/* --- Mint clickid --- */
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0';
$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

$ch = curl_init($rtUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_CONNECTTIMEOUT => 8,
  CURLOPT_TIMEOUT        => 15,
  CURLOPT_SSL_VERIFYPEER => true,
  CURLOPT_SSL_VERIFYHOST => 2,
  CURLOPT_USERAGENT      => $ua,
  CURLOPT_HTTPHEADER     => [
    'Accept: application/json',
    'X-Forwarded-For: ' . $clientIp,
    'X-Real-IP: ' . $clientIp,
  ],
]);
$body = curl_exec($ch);
$err  = curl_error($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($err || $code !== 200) {
  // Return 200 with error info instead of 502 to prevent breaking the page
  error_log("RedTrack API failed: $err (HTTP $code) for URL: $rtUrl");
  echo json_encode([
    'ok'    => false,
    'error' => 'RT request failed',
    'status' => $code,
    'detail' => $err ?: 'HTTP ' . $code,
    'url'   => $rtUrl,
    'ref'   => $referrer,
    'debug' => [
      'domain' => $domain,
      'route' => $route,
      'rtkID' => $cmpId
    ]
  ]);
  exit;
}

$payload = json_decode($body, true);
$clickid = $payload['clickid'] ?? null;
if (!$clickid) {
  // Return 200 with error info instead of 502
  error_log("No clickid in RedTrack response for URL: $rtUrl. Response: " . json_encode($payload));
  echo json_encode([
    'ok'    => false,
    'error' => 'No clickid in JSON',
    'url'   => $rtUrl,
    'raw'   => $payload,
    'ref'   => $referrer,
    'debug' => [
      'domain' => $domain,
      'route' => $route,
      'rtkID' => $cmpId
    ]
  ]);
  exit;
}

/* --- Cache & cookie --- */
$_SESSION[SESSION_KEY] = $clickid;
$_SESSION[SESSION_KEY . '_ts'] = time();

$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
setcookie(COOKIE_NAME, $clickid, [
  'expires'  => time() + 86400 * 30,
  'path'     => '/',
  'secure'   => $secure,
  'httponly' => false,   // RT JS reads it
  'samesite' => 'Lax',
]);

/* --- Return --- */
echo json_encode([
  'ok'      => true,
  'clickid' => $clickid,
  'cached'  => false,
  'ref'     => $referrer,
  'mint_url' => $rtUrl,   // helpful for debugging; remove if you prefer
  'debug'   => [
    'domain' => $domain,
    'route' => $route,
    'rtkID' => $cmpId
  ]
]);
