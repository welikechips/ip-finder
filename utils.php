<?php
/**
 * IP Finder - Shared Utility Functions
 * Common functions used across multiple files
 */

// Validate IP function
function validateIP($ip) {
    // Check basic IP format
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return true;
}

// Function to validate JSON string
function isValidJson($string) {
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

// Check if an IP is a local network IP
function isLocalIP($ip) {
    $localIPRanges = [
        '/^192\.168\./',
        '/^10\./',
        '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
        '/^127\./',
        '/^169\.254\./',
        '/^::1$/',
        '/^f[cd][0-9a-f]{2}:/i'  // fc00::/7 unique-local (fc/fd prefixes)
    ];

    foreach ($localIPRanges as $range) {
        if (preg_match($range, $ip)) {
            return true;
        }
    }

    return false;
}

// Function to get the client's real IP address.
//
// This app is deployed behind Render's edge (Cloudflare -> Render load balancer),
// which is the ONLY path to the container. That edge sets the real visitor IP in the
// headers below and OVERWRITES the *-Client-IP headers, so a visitor can't spoof them.
// We prefer those, then fall back to the first X-Forwarded-For entry, then REMOTE_ADDR
// (which is all that exists during local/direct access with no proxy in front).
function getClientIP() {
    // Cloudflare/Render single-value headers — most trustworthy (edge-controlled).
    foreach (['HTTP_TRUE_CLIENT_IP', 'HTTP_CF_CONNECTING_IP'] as $header) {
        if (!empty($_SERVER[$header]) && filter_var($_SERVER[$header], FILTER_VALIDATE_IP)) {
            return $_SERVER[$header];
        }
    }

    // X-Forwarded-For: on Render the real client is the FIRST (leftmost) entry.
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $firstHop = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($firstHop, FILTER_VALIDATE_IP)) {
            return $firstHop;
        }
    }

    // Direct access (e.g. local dev) — use the TCP peer.
    $remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
    return validateIP($remoteAddr) ? $remoteAddr : '0.0.0.0';
}

// Single source of truth for the external IP-lookup services. getExternalIP() curls
// these full URLs; externalServiceOrigins() derives the scheme://host list the browser
// CSP connect-src is built from — add a service here and both stay in sync.
function externalIPServices() {
    return [
        'https://api.ipify.org?format=json',
        'https://ipinfo.io/json',
        'https://api.ip.sb/jsonip',
        'https://api.myip.com',
    ];
}

// Deduplicated scheme://host origins of externalIPServices(), order preserved. Feeds the
// CSP connect-src in index.php so it can't drift from the server-side lookup list.
function externalServiceOrigins() {
    $origins = [];
    foreach (externalIPServices() as $url) {
        $u = parse_url($url);
        if (isset($u['scheme'], $u['host'])) {
            $origins[$u['scheme'] . '://' . $u['host']] = true;
        }
    }
    return array_keys($origins);
}

// Function to get the external IP address from API services
function getExternalIP() {
    // Fixed list of trusted, HTTPS-only endpoints (externalIPServices) — no user input.
    // httpGetJson enforces HTTPS + SSL verification and returns decoded JSON or null.
    foreach (externalIPServices() as $api) {
        $data = httpGetJson($api);
        if (!is_array($data)) {
            continue;
        }
        if (isset($data['ip']) && validateIP($data['ip'])) {
            return ['success' => true, 'ip' => $data['ip']];
        }
        if (isset($data['query']) && validateIP($data['query'])) {
            return ['success' => true, 'ip' => $data['query']];
        }
    }

    return ['success' => false, 'message' => 'Failed to retrieve external IP address'];
}

// Fetch a URL and return decoded JSON as an associative array, or null on any failure.
// Restricted to HTTPS to avoid SSRF / protocol surprises.
function httpGetJson($url, $timeout = 5) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IP Finder/1.0');
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode == 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
    }
    return null;
}

// Normalize an ipwho.is response into the same field shape ipinfo.io returns
// (city / region / country / org / timezone). Returns null if it lacks geo data.
function normalizeIpwhois($who) {
    if (!is_array($who) || empty($who['success']) || empty($who['city'])) {
        return null;
    }
    $org = null;
    if (isset($who['connection']['asn'], $who['connection']['org'])) {
        $org = 'AS' . $who['connection']['asn'] . ' ' . $who['connection']['org'];
    } elseif (isset($who['connection']['isp'])) {
        $org = $who['connection']['isp'];
    }
    return [
        'ip'       => isset($who['ip']) ? $who['ip'] : null,
        'city'     => $who['city'],
        'region'   => isset($who['region']) ? $who['region'] : null,
        'country'  => isset($who['country_code']) ? $who['country_code'] : (isset($who['country']) ? $who['country'] : null),
        'org'      => $org,
        'timezone' => isset($who['timezone']['id']) ? $who['timezone']['id'] : null,
    ];
}

// Get geolocation for an IP. Primary provider is ipinfo.io — authenticate it with an
// IPINFO_TOKEN env var for reliable use from datacenter IPs (our Render host gets its
// token-less requests limited). Falls back to ipwho.is (token-free) so the location
// still resolves with zero config.
function getIPInfo($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    // Primary: ipinfo.io. A limited/blocked response omits 'city', so require it.
    $token = getenv('IPINFO_TOKEN');
    $ipinfoUrl = "https://ipinfo.io/{$ip}/json" . ($token ? '?token=' . urlencode($token) : '');
    $data = httpGetJson($ipinfoUrl);
    if (is_array($data) && isset($data['ip']) && filter_var($data['ip'], FILTER_VALIDATE_IP) && !empty($data['city'])) {
        return $data;
    }

    // Fallback: ipwho.is, normalized to ipinfo's field shape.
    return normalizeIpwhois(httpGetJson("https://ipwho.is/{$ip}"));
}

// Raw HTTP GET (text body), HTTPS-only. Returns the body string or null on failure.
function httpGetRaw($url, $timeout = 4) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'IP Finder/1.0');
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code == 200 && is_string($body) && $body !== '') ? $body : null;
}

// Heuristic: does this IP's org look like a hosting / datacenter / VPN provider?
// Returns 'vpn', 'datacenter', or null. Best-effort — label the result honestly, it is
// not proof. Residential ISPs (Verizon, Comcast, etc.) are intentionally NOT matched.
function detectHostingType($ipInfo) {
    if (!is_array($ipInfo) || empty($ipInfo['org'])) {
        return null;
    }
    $org = strtolower($ipInfo['org']);

    // Explicit VPN wording wins.
    if (preg_match('/\bvpn\b/', $org)) {
        return 'vpn';
    }
    // Known hosting / cloud providers (substring match on org).
    $providers = [
        'datacamp', 'digitalocean', 'digital ocean', 'ovh', 'hetzner', 'linode', 'vultr',
        'm247', 'choopa', 'leaseweb', 'contabo', 'scaleway', 'psychz', 'quadranet',
        'amazon', 'aws', 'google llc', 'google cloud', 'microsoft', 'azure', 'oracle cloud',
        'cloudflare', 'akamai', 'fastly', 'hostwinds', 'colocation', 'hostinger',
    ];
    foreach ($providers as $p) {
        if (strpos($org, $p) !== false) {
            return 'datacenter';
        }
    }
    // Generic hosting keywords.
    if (preg_match('/\b(hosting|datacenter|data ?center|colo|dedicated servers?|cloud)\b/', $org)) {
        return 'datacenter';
    }
    return null;
}

// Pure membership check: is $ip present as a whole line in $listText? (unit-testable)
function ipInList($ip, $listText) {
    if (!is_string($listText) || $listText === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    return preg_match('/^' . preg_quote($ip, '/') . '\s*$/m', $listText) === 1;
}

// Is the IP a known Tor exit node? Uses the Tor Project bulk exit list, cached locally for
// an hour. Privacy note: this fetches the PUBLIC list and checks membership locally — the
// visitor's IP is never sent anywhere.
function isTorExit($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }
    $cacheFile = sys_get_temp_dir() . '/tor_exit_list.txt';
    if (!is_file($cacheFile) || (time() - @filemtime($cacheFile)) > 3600) {
        $list = httpGetRaw('https://check.torproject.org/torbulkexitlist');
        if ($list !== null) {
            @file_put_contents($cacheFile, $list);
        }
    }
    if (is_file($cacheFile)) {
        $list = @file_get_contents($cacheFile);
        return $list !== false && ipInList($ip, $list);
    }
    return false;
}

// Assemble anonymization flags for an IP (best-effort heuristics), e.g. Tor exit / VPN /
// datacenter. Returns a list of ['type' => ..., 'label' => ...].
function getIPFlags($ip, $ipInfo) {
    $flags = [];
    if (isTorExit($ip)) {
        $flags[] = ['type' => 'tor', 'label' => 'Tor exit node'];
    }
    $hosting = detectHostingType($ipInfo);
    if ($hosting === 'vpn') {
        $flags[] = ['type' => 'vpn', 'label' => 'Looks like a VPN'];
    } elseif ($hosting === 'datacenter') {
        $flags[] = ['type' => 'datacenter', 'label' => 'Looks like a datacenter / hosting IP'];
    }
    return $flags;
}

// Enhanced function to get hostname from IP with multiple methods
function resolveHostname($ip) {
    // Validate IP format first
    if (!validateIP($ip)) {
        return null;
    }

    // Method 1: Use PHP's built-in function
    $hostname = gethostbyaddr($ip);

    // If hostname is not the same as IP, we found something
    if ($hostname !== $ip) {
        return $hostname;
    }

    // Method 2: Use DNS lookup with PTR record
    try {
        // Create reverse DNS lookup query
        $reversedIP = implode('.', array_reverse(explode('.', $ip))) . '.in-addr.arpa';

        // Attempt PTR record lookup
        $dnsRecords = dns_get_record($reversedIP, DNS_PTR);

        if (!empty($dnsRecords) && isset($dnsRecords[0]['target'])) {
            return $dnsRecords[0]['target'];
        }
    } catch (Exception $e) {
        // Silent fail, continue to next method
    }

    // Method 3: external API as last resort — HTTPS-hardened via the shared helper
    // (ipinfo.io is good at hostname lookups).
    $data = httpGetJson("https://ipinfo.io/{$ip}/json", 3);
    if (is_array($data) && isset($data['hostname'])) {
        return $data['hostname'];
    }

    // Last attempt - try to specifically handle AWS EC2 instances
    // AWS EC2 hostnames often follow the pattern: ec2-IP-ADDRESS.compute-X.amazonaws.com
    // where IP-ADDRESS has dashes instead of dots
    if (strpos($ip, '.compute-') === false) { // Avoid infinite recursion
        $dashIP = str_replace('.', '-', $ip);
        $possibleEC2Hostname = "ec2-{$dashIP}.compute-1.amazonaws.com";

        // Validate if this hostname resolves back to the IP
        $resolvedIP = gethostbyname($possibleEC2Hostname);
        if ($resolvedIP !== $possibleEC2Hostname && $resolvedIP === $ip) {
            return $possibleEC2Hostname;
        }
    }

    return null;
}

// Simple rate limiting function
function enforceRateLimit($key = 'rate_limit', $maxRequests = 10, $timeWindow = 60) {
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 0,
            'first_request' => time()
        ];
    }

    // Reset counter if time window has passed
    if (time() - $_SESSION[$key]['first_request'] > $timeWindow) {
        $_SESSION[$key] = [
            'count' => 0,
            'first_request' => time()
        ];
    }

    // Increment counter
    $_SESSION[$key]['count']++;

    // Check if limit exceeded
    if ($_SESSION[$key]['count'] > $maxRequests) {
        return false; // Rate limit exceeded
    }

    return true; // Rate limit not exceeded
}
?>