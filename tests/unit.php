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
check(isLocalIP('fc00::1'),                           'IPv6 ULA fc00::/7 is local');
check(isLocalIP('fd12:3456:789a::1'),                 'IPv6 ULA fd00::/8 is local (regression)');
check(!isLocalIP('2001:4860:4860::8888'),             'public IPv6 is NOT local');
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

echo "rateLimitStep (pure rate-limit decision)\n";
$s = rateLimitStep(null, 1000, 3, 60);
check($s['allowed'] === true  && $s['state']['count'] === 1, 'fresh bucket: request 1 allowed, count=1');
$s = rateLimitStep($s['state'], 1001, 3, 60);
check($s['allowed'] === true  && $s['state']['count'] === 2, 'request 2 allowed, count=2');
$s = rateLimitStep($s['state'], 1002, 3, 60);
check($s['allowed'] === true  && $s['state']['count'] === 3, 'request 3 allowed (at limit)');
$s = rateLimitStep($s['state'], 1003, 3, 60);
check($s['allowed'] === false && $s['state']['count'] === 4, 'request 4 denied (over limit)');
$s = rateLimitStep(['count' => 2, 'first_request' => 1000], 1060, 3, 60);
check($s['allowed'] === true  && $s['state']['count'] === 3, 'exactly at window edge -> same window (boundary)');
$s = rateLimitStep(['count' => 99, 'first_request' => 1000], 1061, 3, 60);
check($s['allowed'] === true  && $s['state']['count'] === 1, 'window elapsed -> counter resets');
check(rateLimitStep('garbage', 500, 3, 60)['state']['count'] === 1,      'malformed state -> fresh bucket');
check(rateLimitStep(['count' => 5], 500, 3, 60)['state']['count'] === 1, 'partial state (no first_request) -> fresh');

echo "enforceRateLimit (per-IP file bucket)\n";
// IP is injected directly now — no $_SERVER mutation needed.
$rlIp  = '203.0.113.201';
$rlKey = 'unittest_' . getmypid() . '_' . str_replace('.', '', (string) microtime(true)); // unique per run
check(enforceRateLimit($rlIp, $rlKey, 2, 60) === true,            'file bucket: request 1 allowed');
check(enforceRateLimit($rlIp, $rlKey, 2, 60) === true,            'file bucket: request 2 allowed (at limit)');
check(enforceRateLimit($rlIp, $rlKey, 2, 60) === false,           'file bucket: request 3 denied');
check(enforceRateLimit($rlIp, 'other_' . $rlKey, 2, 60) === true, 'a separate key has its own budget');
check(enforceRateLimit('203.0.113.202', $rlKey, 2, 60) === true,  'a different IP has its own budget (per-IP isolation)');
@unlink(sys_get_temp_dir() . '/rl_' . sha1($rlKey . '|203.0.113.201') . '.json');
@unlink(sys_get_temp_dir() . '/rl_' . sha1('other_' . $rlKey . '|203.0.113.201') . '.json');
@unlink(sys_get_temp_dir() . '/rl_' . sha1($rlKey . '|203.0.113.202') . '.json');

echo "sweepRateLimitBuckets (stale bucket GC)\n";
$tmpDir = sys_get_temp_dir();
$freshBucket = $tmpDir . '/rl_' . sha1('sweeptest_fresh_' . getmypid()) . '.json';
$staleBucket = $tmpDir . '/rl_' . sha1('sweeptest_stale_' . getmypid()) . '.json';
file_put_contents($freshBucket, '{}'); touch($freshBucket, 5000);
file_put_contents($staleBucket, '{}'); touch($staleBucket, 1000);
// Small clock so any real bucket (mtime ~now) reads as "future" (negative age) and is left alone.
sweepRateLimitBuckets(5000, 3600);
check(is_file($freshBucket) === true,  'fresh bucket kept (age <= maxAge)');
check(is_file($staleBucket) === false, 'stale bucket swept (age > maxAge)');
@unlink($freshBucket); @unlink($staleBucket);

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

echo "detectHostingType (VPN/datacenter heuristic)\n";
is_eq(detectHostingType(['org' => 'AS212238 Datacamp Limited']), 'datacenter', 'known hosting provider -> datacenter');
is_eq(detectHostingType(['org' => 'AS15169 Google LLC']), 'datacenter',        'Google LLC -> datacenter');
is_eq(detectHostingType(['org' => 'AS64500 Example VPN Services']), 'vpn',      'VPN wording -> vpn');
is_eq(detectHostingType(['org' => 'AS701 Verizon Business']), null,             'residential ISP -> null (no false positive)');
is_eq(detectHostingType(['org' => 'AS7922 Comcast Cable Communications']), null, 'Comcast -> null');
is_eq(detectHostingType([]), null,                                             'no org -> null');
is_eq(detectHostingType('nope'), null,                                         'non-array -> null');

echo "ipInList (Tor exit-list membership)\n";
$torSample = "203.0.113.4\n203.0.113.5\n198.51.100.10\n";
check(ipInList('203.0.113.5', $torSample) === true,        'exact IP present -> true');
check(ipInList('203.0.113.9', $torSample) === false,       'IP absent -> false');
check(ipInList('203.0.113.50', "203.0.113.5\n") === false, 'no partial-line match (.5 vs .50)');
check(ipInList('not-an-ip', $torSample) === false,         'invalid IP -> false');
check(ipInList('203.0.113.5', '') === false,               'empty list -> false');

echo "externalServiceOrigins (CSP single-source)\n";
is_eq(externalServiceOrigins(),
      ['https://api.ipify.org', 'https://ipinfo.io', 'https://api.ip.sb', 'https://api.myip.com'],
      'origins derived as scheme://host, deduped, in order');

echo "normalizeMaxmind (GeoLite2 record -> ipinfo field shape)\n";
$mmCity = [
    'city'         => ['names' => ['en' => 'Linkoping']],
    'country'      => ['iso_code' => 'SE', 'names' => ['en' => 'Sweden']],
    'location'     => ['time_zone' => 'Europe/Stockholm'],
    'subdivisions' => [['iso_code' => 'E', 'names' => ['en' => 'Ostergotland County']]],
];
$mmAsn = ['autonomous_system_number' => 29518, 'autonomous_system_organization' => 'Bredband2 AB'];
$mm = normalizeMaxmind($mmCity, $mmAsn, '89.160.20.112');
is_eq($mm['city'], 'Linkoping',                'city from City record');
is_eq($mm['region'], 'Ostergotland County',    'region from subdivision name');
is_eq($mm['country'], 'SE',                    'country from iso_code');
is_eq($mm['org'], 'AS29518 Bredband2 AB',      'org built AS<num> <org> (ipinfo style)');
is_eq($mm['timezone'], 'Europe/Stockholm',     'timezone from location.time_zone');
is_eq($mm['ip'], '89.160.20.112',              'ip passed through');
is_eq(normalizeMaxmind(['subdivisions' => [['iso_code' => 'CA']], 'city' => ['names' => ['en' => 'X']], 'country' => ['iso_code' => 'US']], null, '1.2.3.4')['region'],
      'CA',                                    'region falls back to subdivision iso_code');
is_eq(normalizeMaxmind(null, ['autonomous_system_number' => 1221, 'autonomous_system_organization' => 'Telstra Pty Ltd'], '1.128.0.0')['org'],
      'AS1221 Telstra Pty Ltd',                'ASN-only record -> org built');
is_eq(normalizeMaxmind(null, ['autonomous_system_organization' => 'SoloOrg'])['org'],
      'SoloOrg',                               'org without ASN number -> org name alone');
check(normalizeMaxmind(null, null) === null,   'no city + no asn -> null');
check(normalizeMaxmind([], []) === null,       'empty records -> null');
check(normalizeMaxmind('x', 'y') === null,     'non-array records -> null');

echo "geoLookupLocal (real maxminddb reader against bundled test fixtures)\n";
if (class_exists('MaxMind\\Db\\Reader')) {
    putenv('GEOIP_DB_DIR=' . __DIR__ . '/fixtures/geoip');
    $swe = geoLookupLocal('89.160.20.112');   // present in BOTH City + ASN test fixtures
    is_eq($swe['city'], "Link\u{f6}ping",                  'fixture: city from City DB');
    is_eq($swe['region'], "\u{d6}sterg\u{f6}tland County", 'fixture: region from City DB');
    is_eq($swe['country'], 'SE',                           'fixture: country iso_code');
    is_eq($swe['org'], 'AS29518 Bredband2 AB',             'fixture: org merged from ASN DB');
    is_eq($swe['timezone'], 'Europe/Stockholm',            'fixture: timezone from City DB');
    is_eq(geoLookupLocal('2.125.160.216')['city'], 'Boxford',            'fixture: city-only IP resolves city');
    is_eq(geoLookupLocal('1.128.0.0')['org'], 'AS1221 Telstra Pty Ltd',  'fixture: ASN-only IP resolves org');
    check(geoLookupLocal('203.0.113.1') === null,          'fixture: IP absent from both DBs -> null (fallback trigger)');
} else {
    echo "  SKIP  maxminddb extension not loaded (fixture reader needs the built image)\n";
}

echo "\n" . $GLOBALS['__tests'] . " checks, " . $GLOBALS['__fails'] . " failed\n";
exit($GLOBALS['__fails'] > 0 ? 1 : 0);