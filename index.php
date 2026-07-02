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

// Display timestamps in Eastern time (auto EDT/EST). VPNs make IP-based timezone
// unreliable, so this is a fixed default independent of the visitor's IP.
date_default_timezone_set('America/New_York');

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Set secure headers with more permissive CSP that still maintains security
header("Content-Security-Policy: default-src 'self'; connect-src 'self' https://api.ipify.org https://ipinfo.io https://api.ip.sb https://api.myip.com stun:stun.l.google.com:19302; script-src 'self' 'nonce-" . htmlspecialchars($_SESSION['csrf_token']) . "'; style-src 'self'; frame-src https://api.ipify.org; img-src 'self' data:;");
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
// HSTS — the site is served HTTPS-only (Render/Cloudflare), so tell browsers to always use TLS.
// Scoped to this host + its subdomains; no preload (hard to reverse).
header("Strict-Transport-Security: max-age=63072000; includeSubDomains");

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

// Content negotiation: CLI/API clients get plain text or JSON; browsers get the HTML page.
$reqFormat = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : '';
$reqAccept = isset($_SERVER['HTTP_ACCEPT']) ? $_SERVER['HTTP_ACCEPT'] : '';
$reqAgent  = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
$wantsJson = ($reqFormat === 'json')
    || (stripos($reqAccept, 'application/json') !== false && stripos($reqAccept, 'text/html') === false);
$wantsText = in_array($reqFormat, ['text', 'txt', 'plain'], true)
    || (bool) preg_match('~\b(curl|wget|httpie|python-requests|libwww-perl|go-http-client)\b~i', $reqAgent);

// Clear the output buffer to keep stray output from the lookup functions out of the response
ob_start();

// Determine the visitor's IP. Behind Render's Cloudflare edge, getClientIP() reads the real
// client IP from True-Client-IP / CF-Connecting-IP / the first X-Forwarded-For hop.
$clientIP = getClientIP();
$clientIsPublic = ($clientIP && !isLocalIP($clientIP) && $clientIP !== '0.0.0.0');

if ($clientIsPublic) {
    // Authoritative: the address the visitor is actually connecting from.
    $externalIPData = ['success' => true, 'ip' => $clientIP];
} else {
    // No trusted proxy in front (e.g. local dev) — fall back to a server-side lookup.
    $externalIPData = getExternalIP();
}
$externalIP = $externalIPData['success'] ? $externalIPData['ip'] : 'Unknown';

// Fast path: bare plain-text IP for CLI clients (curl/wget/...). Skips the hostname + geo
// lookups they don't need, so `curl ip.jiveturkey.rocks` stays quick.
if ($wantsText && !$wantsJson) {
    ob_end_clean();
    header('Content-Type: text/plain; charset=utf-8');
    echo $externalIP . "\n";
    exit;
}

// Hostname + geolocation for the IP we're displaying (needed for JSON and the HTML page).
$externalHostname = $externalIPData['success'] ? resolveHostname($externalIP) : null;
$ipInfo = $externalIPData['success'] ? getIPInfo($externalIP) : null;

// Clear any output buffer
ob_end_clean();

// Anonymization flags for the IP (Tor exit / VPN / datacenter) — best-effort heuristics.
$ipFlags = $externalIPData['success'] ? getIPFlags($externalIP, $ipInfo) : [];

// JSON for API clients (?format=json or Accept: application/json).
if ($wantsJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ip'       => $externalIP,
        'hostname' => $externalHostname,
        'city'     => isset($ipInfo['city']) ? $ipInfo['city'] : null,
        'region'   => isset($ipInfo['region']) ? $ipInfo['region'] : null,
        'country'  => isset($ipInfo['country']) ? $ipInfo['country'] : null,
        'org'      => isset($ipInfo['org']) ? $ipInfo['org'] : null,
        'timezone' => isset($ipInfo['timezone']) ? $ipInfo['timezone'] : null,
        'flags'    => array_values(array_map(function ($f) { return $f['type']; }, $ipFlags)),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

// We now report the client IP directly, so the old server-vs-client proxy
// comparison no longer applies — no false "VPN detected" banner.
$clientHostname = null;
$usingProxy = false;

// App version = the deployed git commit. Render injects RENDER_GIT_COMMIT at runtime;
// GIT_COMMIT can be baked at build time; otherwise it's a local/dev build.
$appCommit = getenv('RENDER_GIT_COMMIT') ?: getenv('GIT_COMMIT') ?: '';
$appVersion = $appCommit !== '' ? substr($appCommit, 0, 7) : 'dev';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Just the tIP</title>
    <link rel="stylesheet" href="public/css/styles.css">
    <link rel="icon" href="data:,">
    <script nonce="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        // Apply a saved theme override before first paint to avoid a flash. Client-only.
        (function () {
            try {
                const t = localStorage.getItem('theme');
                if (t === 'light' || t === 'dark') {
                    document.documentElement.setAttribute('data-theme', t);
                }
            } catch (e) {}
        })();
    </script>
</head>
<body>
<div class="container">
    <button type="button" id="theme-toggle" class="theme-toggle" aria-label="Toggle color theme">◐ Auto</button>
    <h1>Just the t<span class="ip-accent">IP</span></h1>

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
                    <span class="ip-value-group">
                        <span class="ip-value" id="server-ip-value"><?php echo htmlspecialchars($externalIP); ?></span>
                        <button type="button" class="copy-btn" data-copy="server-ip-value" aria-label="Copy IP address" title="Copy IP">📋</button>
                    </span>
                </div>

                <?php if (!empty($ipFlags)): ?>
                    <div class="ip-flags">
                        <?php foreach ($ipFlags as $flag): ?>
                            <span class="ip-flag ip-flag-<?php echo htmlspecialchars($flag['type']); ?>"><?php echo htmlspecialchars($flag['label']); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

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

            <p class="note-text">Note: This is the IP address your connection presents to this server. Browser-based
                proxies (e.g. FoxyProxy) may differ — see Browser Detection.</p>
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
            <p>This detection method uses your browser to detect the IP, which works better with browser-based proxies
                like FoxyProxy.</p>

            <div id="loading">
                <div class="spinner"></div>
                <p>Detecting IP address via browser...</p>
            </div>

            <div id="browser-ip-result" class="hidden">
                <div class="ip-row">
                    <span class="ip-label">IP Address:</span>
                    <span class="ip-value-group">
                        <span class="ip-value" id="browser-ip">Detecting...</span>
                        <button type="button" class="copy-btn" data-copy="browser-ip" aria-label="Copy IP address" title="Copy IP">📋</button>
                    </span>
                </div>
                <div class="ip-row">
                    <span class="ip-label">Hostname:</span>
                    <span class="ip-value" id="browser-hostname">Detecting...</span>
                </div>
            </div>

            <!-- WebRTC leak check — runs entirely in the browser, nothing sent to the server -->
            <div class="webrtc-check">
                <p class="webrtc-title">WebRTC Leak Check</p>
                <p class="note-text">WebRTC can expose IP addresses that bypass a VPN. This check runs entirely in your browser — nothing is sent to the server.</p>
                <div id="webrtc-result" class="webrtc-result">Open this tab to run the check…</div>
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
        <p class="privacy">🔒 Privacy — We don't log or store your IP, hostname, or lookups. No database, and access
            logging is disabled. Your IP is only sent to third-party services to perform the lookup, never kept
            here.</p>
        <p>Last updated: <?php echo date('Y-m-d H:i:s T'); ?></p>
        <p>Version:
            <?php if ($appCommit !== ''): ?>
                <a href="https://github.com/welikechips/ip-finder/commit/<?php echo htmlspecialchars($appCommit); ?>"
                   target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars($appVersion); ?></a>
            <?php else: ?>
                <?php echo htmlspecialchars($appVersion); ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<!-- External JavaScript file with enhanced security -->
<script nonce="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" src="public/js/ip-finder.js"></script>
</body>
</html>