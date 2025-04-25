<?php
/**
 * Enhanced External IP Address Finder - SECURE VERSION (Improved)
 *
 * A web application to find your external IP address and hostname
 * Works with VPNs and proxies by checking various HTTP headers
 */

// Start session for rate limiting and CSRF protection
session_start();

// Include shared utility functions
require_once 'utils.php';

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set secure headers with more permissive CSP that still maintains security
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://api.ipify.org https://ipinfo.io https://api.ip.sb https://api.myip.com; script-src 'self' 'nonce-".htmlspecialchars($_SESSION['csrf_token'])."'; style-src 'self'; frame-src https://api.ipify.org; img-src 'self' data:;");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");

// Apply rate limiting
if (!enforceRateLimit('main_rate_limit', 10, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    echo "Rate limit exceeded. Please try again later.";
    exit;
}

// Verify CSRF token on POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('HTTP/1.1 403 Forbidden');
        echo "CSRF validation failed";
        exit;
    }
}

// Clear the output buffer to prevent var_dump from showing
ob_start();

// Get client IP (from headers - useful for proxy detection)
$clientIP = getClientIP();

// Get external IP (from API services)
$externalIPData = getExternalIP();
$externalIP = $externalIPData['success'] ? $externalIPData['ip'] : 'Unknown';

// Get hostname for both IPs with enhanced lookup
$clientHostname = $clientIP ? resolveHostname($clientIP) : null;
$externalHostname = $externalIPData['success'] ? resolveHostname($externalIP) : null;

// Get additional information for the external IP
$ipInfo = $externalIPData['success'] ? getIPInfo($externalIP) : null;

// Clear any output buffer
ob_end_clean();

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
        <!-- Add CSRF token to form -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <button type="submit" class="refresh-btn">Refresh Information</button>
    </form>

    <div class="info-footer">
        <p>This tool shows your external IP address, hostname, and detects if you're using a VPN or proxy.</p>
        <p>Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
</div>

<!-- External JavaScript file with enhanced security -->
<script nonce="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" src="public/js/ip-finder.js"></script>
</body>
</html>