<?php
/**
 * Enhanced External IP Address Finder - SECURE VERSION
 *
 * A web application to find your external IP address and hostname
 * Works with VPNs and proxies by checking various HTTP headers
 */

// Set secure headers with more permissive CSP that still maintains security
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://api.ipify.org https://ipinfo.io https://api.ip.sb https://api.myip.com; script-src 'self'; style-src 'self'; frame-src https://api.ipify.org; img-src 'self' data:;");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Start session for rate limiting
session_start();

// Simple rate limiting function
function enforceRateLimit() {
    $maxRequests = 10;
    $timeWindow = 60; // 60 seconds

    if (!isset($_SESSION['rate_limit'])) {
        $_SESSION['rate_limit'] = [
            'count' => 0,
            'first_request' => time()
        ];
    }

    // Reset counter if time window has passed
    if (time() - $_SESSION['rate_limit']['first_request'] > $timeWindow) {
        $_SESSION['rate_limit'] = [
            'count' => 0,
            'first_request' => time()
        ];
    }

    // Increment counter
    $_SESSION['rate_limit']['count']++;

    // Check if limit exceeded
    if ($_SESSION['rate_limit']['count'] > $maxRequests) {
        header('HTTP/1.1 429 Too Many Requests');
        echo "Rate limit exceeded. Please try again later.";
        exit;
    }
}

// Apply rate limiting
enforceRateLimit();

// Function to get the client's real IP address considering proxies and VPNs
function getClientIP() {
    // Define trusted proxies
    $trustedProxies = [
        // Add your trusted proxy IPs here
        '127.0.0.1',
        // '10.0.0.1',
        // '10.0.0.2',
    ];

    // Always trust REMOTE_ADDR as a starting point
    $clientIP = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Only consider X-Forwarded-For if from trusted reverse proxies
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($clientIP, $trustedProxies)) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwardedIP = trim($ips[0]);
        if (filter_var($forwardedIP, FILTER_VALIDATE_IP)) {
            return $forwardedIP;
        }
    }

    // Validate the IP format
    if (!filter_var($clientIP, FILTER_VALIDATE_IP)) {
        return '0.0.0.0';
    }

    return $clientIP;
}

// Function to validate JSON string
function isValidJson($string) {
    json_decode($string);
    return (json_last_error() === JSON_ERROR_NONE);
}

// Function to get the external IP address from API services
function getExternalIP() {
    // Fixed list of trusted API endpoints - no user input allowed
    $apis = [
        'https://api.ipify.org?format=json',
        'https://ipinfo.io/json',
        'https://api.ip.sb/jsonip',
        'https://api.myip.com'
    ];

    foreach ($apis as $api) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Ensure SSL verification is enabled
            curl_setopt($ch, CURLOPT_USERAGENT, 'IP Finder/1.0');
            $response = curl_exec($ch);
            $error = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode == 200 && !empty($response)) {
                // Validate JSON structure before parsing
                if (!isValidJson($response)) {
                    continue;
                }

                $data = json_decode($response, true);

                // Validate expected schema
                if (!isset($data['ip']) && !isset($data['query'])) {
                    continue;
                }

                // Extract and validate IP
                $ip = isset($data['ip']) ? $data['ip'] : $data['query'];
                if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                    continue;
                }

                return ['success' => true, 'ip' => $ip];
            }
        } catch (Exception $e) {
            continue; // Try the next API
        }
    }

    return ['success' => false, 'message' => 'Failed to retrieve external IP address'];
}

// Function to get hostname from IP - using PHP's built-in function only
function resolveHostname($ip) {
    // Validate IP format first
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    // Use PHP's built-in function (no shell commands)
    $hostname = gethostbyaddr($ip);

    // If hostname is the same as IP, no DNS record exists
    if ($hostname === $ip) {
        return null;
    }

    return $hostname;
}

// Get additional IP information using ipinfo.io
function getIPInfo($ip) {
    // Validate IP format first
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://ipinfo.io/{$ip}/json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Ensure SSL verification is enabled
        curl_setopt($ch, CURLOPT_USERAGENT, 'IP Finder/1.0');
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode == 200 && !empty($response)) {
            // Validate JSON before parsing
            if (!isValidJson($response)) {
                return null;
            }

            $data = json_decode($response, true);

            // Additional validation of expected fields
            if (!isset($data['ip']) || !filter_var($data['ip'], FILTER_VALIDATE_IP)) {
                return null;
            }

            return $data;
        }
    } catch (Exception $e) {
        // Silent fail
    }

    return null;
}

// Clear the output buffer to prevent var_dump from showing
ob_start();

// Get client IP (from headers - useful for proxy detection)
$clientIP = getClientIP();

// Get external IP (from API services)
$externalIPData = getExternalIP();
$externalIP = $externalIPData['success'] ? $externalIPData['ip'] : 'Unknown';

// Get hostname for both IPs
$clientHostname = $clientIP ? resolveHostname($clientIP) : null;
$externalHostname = $externalIPData['success'] ? resolveHostname($externalIP) : null;

// Get additional information for the external IP
$ipInfo = $externalIPData['success'] ? getIPInfo($externalIP) : null;

// Clear any output buffer
ob_end_clean();

// Better proxy detection logic - accounts for NAT routers
// Local IP ranges
$localIPRanges = [
    '/^192\.168\./',
    '/^10\./',
    '/^172\.(1[6-9]|2[0-9]|3[0-1])\./',
    '/^127\./',
    '/^169\.254\./',
    '/^::1$/',
    '/^fc00::/'
];

// Check if an IP is a local network IP
function isLocalIP($ip) {
    global $localIPRanges;

    foreach ($localIPRanges as $range) {
        if (preg_match($range, $ip)) {
            return true;
        }
    }

    return false;
}

// Detect if using VPN by checking if:
// 1. The client IP is NOT a local IP AND
// 2. The client IP is different from the external IP
// This will only show VPN/proxy warning for actual proxies, not for normal NAT router setups
$usingProxy = (!isLocalIP($clientIP) && $clientIP !== $externalIP && $clientIP !== '0.0.0.0' && $externalIP !== 'Unknown');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enhanced IP Finder</title>
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="icon" href="data:,">
</head>
<body>
<div class="container">
    <h1>Enhanced IP Finder</h1>

    <div class="tab-container">
        <div class="tab active" id="server-tab">Server Detection</div>
        <div class="tab" id="client-tab">Browser Detection</div>
    </div>

    <!-- Server-side IP detection -->
    <div id="server-side" class="tab-content active">
        <div class="ip-info">
            <h2>Your External IP (Server Detection)</h2>

            <?php if ($externalIPData['success']): ?>
                <div class="ip-row">
                    <span class="ip-label">IP Address:</span>
                    <span class="ip-value">
                            <?php echo htmlspecialchars($externalIP); ?>
                        </span>
                </div>

                <div class="ip-row">
                    <span class="ip-label">Hostname:</span>
                    <span class="ip-value">
                            <?php if ($externalHostname): ?>
                                <?php echo htmlspecialchars($externalHostname); ?>
                            <?php else: ?>
                                <i>No hostname found</i>
                            <?php endif; ?>
                        </span>
                </div>
            <?php else: ?>
                <div class="ip-row">
                    <span class="ip-label">Error:</span>
                    <span class="error"><?php echo htmlspecialchars($externalIPData['message']); ?></span>
                </div>
            <?php endif; ?>

            <p class="note-text">Note: This detection method uses server-side API calls and may not reflect browser proxy settings.</p>
        </div>

        <!-- Proxy/VPN Detection -->
        <?php if ($usingProxy): ?>
            <div class="proxy-warning">
                <strong>VPN/Proxy Detected:</strong> Your connection appears to be going through a proxy or VPN.
                <div class="ip-row-noborder">
                    <span class="ip-label">Direct Client IP:</span>
                    <span class="ip-value"><?php echo htmlspecialchars($clientIP); ?></span>
                </div>
                <?php if ($clientHostname): ?>
                    <div class="hostname">
                        Hostname: <?php echo htmlspecialchars($clientHostname); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Additional IP Information -->
        <?php if ($ipInfo): ?>
            <div class="location-info">
                <h2>IP Location Info</h2>

                <?php if (isset($ipInfo['city']) && isset($ipInfo['region']) && isset($ipInfo['country'])): ?>
                    <div class="ip-row">
                        <span class="ip-label">Location:</span>
                        <span class="ip-value">
                        <?php echo htmlspecialchars($ipInfo['city'] . ', ' . $ipInfo['region'] . ', ' . $ipInfo['country']); ?>
                    </span>
                    </div>
                <?php endif; ?>

                <?php if (isset($ipInfo['org'])): ?>
                    <div class="ip-row">
                        <span class="ip-label">Organization:</span>
                        <span class="ip-value"><?php echo htmlspecialchars($ipInfo['org']); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (isset($ipInfo['timezone'])): ?>
                    <div class="ip-row-noborder">
                        <span class="ip-label">Timezone:</span>
                        <span class="ip-value"><?php echo htmlspecialchars($ipInfo['timezone']); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Client-side IP detection (for browser proxies) -->
    <div id="client-side" class="tab-content">
        <div class="client-side">
            <h2>Your External IP (Browser Detection)</h2>
            <p>This detection method uses your browser to detect the IP, which works better with browser-based proxies like FoxyProxy.</p>

            <div id="loading">
                <div class="spinner"></div>
                <p>Detecting IP address via browser...</p>
            </div>

            <div id="browser-ip-result" class="hidden">
                <div class="ip-row">
                    <span class="ip-label">IP Address:</span>
                    <span class="ip-value" id="browser-ip">Detecting...</span>
                </div>
                <div class="ip-row">
                    <span class="ip-label">Hostname:</span>
                    <span class="ip-value" id="browser-hostname">Detecting...</span>
                </div>
            </div>

            <!-- External service iframe fallback with proper security attributes -->
            <div class="alternative-method">
                <p class="alternative-title">Alternative Method: External IP checker service</p>
                <iframe src="https://api.ipify.org" id="ip-frame"
                        class="external-iframe"
                        sandbox="allow-scripts allow-same-origin"
                        referrerpolicy="no-referrer"></iframe>
            </div>
        </div>
    </div>

    <form method="post" id="ip-form">
        <button type="submit" class="refresh-btn">Refresh Information</button>
    </form>

    <div class="info-footer">
        <p>This tool shows your external IP address, hostname, and detects if you're using a VPN or proxy.</p>
        <p>Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
</div>

<!-- External JavaScript file -->
<script src="public/js/ip-finder.js"></script>
</body>
</html>