<?php
/**
 * Unit tests for utils.php — pure logic, no network required.
 *
 *   Run locally:  php tests/unit.php
 *   In the image: docker run --rm --entrypoint php -v "$PWD":/app -w /app <image> tests/unit.php
 *
 * Exit code 0 = all passed, 1 = a check failed.
 */

require __DIR__ . '/../utils.php';

$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function check($cond, $desc) {
    $GLOBALS['__tests']++;
    if ($cond) {
        echo "  PASS  $desc\n";
    } else {
        $GLOBALS['__fails']++;
        echo "  FAIL  $desc\n";
    }
}

function is_eq($actual, $expected, $desc) {
    $ok = ($actual === $expected);
    check($ok, $desc . ($ok ? '' : "  (got " . var_export($actual, true) . ", want " . var_export($expected, true) . ")"));
}

// Call getClientIP() with a controlled $_SERVER, then restore it.
function client_ip_with(array $server) {
    $saved = $_SERVER;
    foreach (['HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        unset($_SERVER[$k]);
    }
    foreach ($server as $k => $v) {
        $_SERVER[$k] = $v;
    }
    $ip = getClientIP();
    $_SERVER = $saved;
    return $ip;
}

echo "validateIP\n";
check(validateIP('8.8.8.8') === true,                 'accepts IPv4');
check(validateIP('2001:4860:4860::8888') === true,    'accepts IPv6');
check(validateIP('999.1.1.1') === false,              'rejects out-of-range octet');
check(validateIP('nope') === false,                   'rejects non-IP text');
check(validateIP('') === false,                       'rejects empty string');

echo "isValidJson\n";
check(isValidJson('{"ip":"1.2.3.4"}') === true,       'accepts a JSON object');
check(isValidJson('[1,2,3]') === true,                'accepts a JSON array');
check(isValidJson('{bad') === false,                  'rejects malformed JSON');

echo "isLocalIP\n";
check(isLocalIP('192.168.1.1'),                       '192.168/16 is local');
check(isLocalIP('10.0.0.5'),                          '10/8 is local');
check(isLocalIP('172.16.0.1'),                        '172.16 is local (low end)');
check(isLocalIP('172.31.255.255'),                    '172.31 is local (high end)');
check(!isLocalIP('172.15.0.1'),                       '172.15 is NOT local');
check(!isLocalIP('172.32.0.1'),                       '172.32 is NOT local');
check(isLocalIP('127.0.0.1'),                         'loopback is local');
check(isLocalIP('169.254.1.1'),                       'link-local is local');
check(isLocalIP('::1'),                               'IPv6 loopback is local');
check(!isLocalIP('8.8.8.8'),                          'public IPv4 is NOT local');
check(!isLocalIP('172.71.195.123'),                   'Cloudflare 172.71 is NOT local (regression)');

echo "getClientIP (proxy header priority)\n";
is_eq(client_ip_with(['HTTP_TRUE_CLIENT_IP' => '1.1.1.1', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9, 10.0.0.1', 'REMOTE_ADDR' => '10.0.0.1']),
      '1.1.1.1', 'True-Client-IP wins over XFF (anti-spoof)');
is_eq(client_ip_with(['HTTP_CF_CONNECTING_IP' => '8.8.4.4', 'HTTP_X_FORWARDED_FOR' => '9.9.9.9', 'REMOTE_ADDR' => '10.0.0.1']),
      '8.8.4.4', 'CF-Connecting-IP used when no True-Client-IP');
is_eq(client_ip_with(['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 172.71.195.123, 10.226.90.65', 'REMOTE_ADDR' => '10.226.90.65']),
      '203.0.113.7', 'XFF leftmost hop is the client');
is_eq(client_ip_with(['HTTP_TRUE_CLIENT_IP' => 'garbage', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'REMOTE_ADDR' => '10.0.0.1']),
      '203.0.113.7', 'invalid True-Client-IP falls through to XFF');
is_eq(client_ip_with(['REMOTE_ADDR' => '8.8.8.8']),
      '8.8.8.8', 'no proxy headers -> REMOTE_ADDR');
is_eq(client_ip_with([]),
      '0.0.0.0', 'nothing set -> 0.0.0.0');

echo "enforceRateLimit\n";
$_SESSION = [];
check(enforceRateLimit('t', 3, 60) === true,          'request 1 under limit');
check(enforceRateLimit('t', 3, 60) === true,          'request 2 under limit');
check(enforceRateLimit('t', 3, 60) === true,          'request 3 at limit');
check(enforceRateLimit('t', 3, 60) === false,         'request 4 exceeds limit');
check(enforceRateLimit('other', 3, 60) === true,      'a separate key has its own budget');

echo "normalizeIpwhois (geolocation fallback)\n";
// Synthetic sample using RFC-documentation values only (TEST-NET IP, doc ASN) — no real IPs.
$who = [
    'ip' => '203.0.113.9', 'success' => true,
    'city' => 'Testville', 'region' => 'Test Region',
    'country' => 'Exampleland', 'country_code' => 'US',
    'connection' => ['asn' => 64500, 'org' => 'Example Networks', 'isp' => 'Example ISP'],
    'timezone' => ['id' => 'America/New_York', 'abbr' => 'EDT'],
];
$n = normalizeIpwhois($who);
is_eq($n['city'], 'Testville',                 'city mapped');
is_eq($n['country'], 'US',                     'country_code preferred for country');
is_eq($n['org'], 'AS64500 Example Networks',   'org built from asn + org (ipinfo style)');
is_eq($n['timezone'], 'America/New_York',      'timezone from timezone.id');
is_eq(normalizeIpwhois(['success' => true, 'city' => 'X', 'connection' => ['isp' => 'SoloISP']])['org'],
      'SoloISP',                               'org falls back to isp when asn/org absent');
check(normalizeIpwhois(['success' => false]) === null, 'unsuccessful response -> null');
check(normalizeIpwhois(['success' => true]) === null,  'no city -> null');
check(normalizeIpwhois('nope') === null,               'non-array -> null');

echo "\n" . $GLOBALS['__tests'] . " checks, " . $GLOBALS['__fails'] . " failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);