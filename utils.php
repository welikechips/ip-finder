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
        '/^fc00::/'
    ];

    foreach ($localIPRanges as $range) {
        if (preg_match($range, $ip)) {
            return true;
        }
    }

    return false;
}

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
    $clientIP = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

    // Only consider X-Forwarded-For if from trusted reverse proxies
    if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && in_array($clientIP, $trustedProxies)) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $forwardedIP = trim($ips[0]);
        if (filter_var($forwardedIP, FILTER_VALIDATE_IP)) {
            return $forwardedIP;
        }
    }

    // Validate the IP format
    if (!validateIP($clientIP)) {
        return '0.0.0.0';
    }

    return $clientIP;
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
            // Prevent SSRF by setting allowed protocols
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);
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

                // Improved schema validation
                if (isset($data['ip']) && validateIP($data['ip'])) {
                    return ['success' => true, 'ip' => $data['ip']];
                } else if (isset($data['query']) && validateIP($data['query'])) {
                    return ['success' => true, 'ip' => $data['query']];
                }
            }
        } catch (Exception $e) {
            continue; // Try the next API
        }
    }

    return ['success' => false, 'message' => 'Failed to retrieve external IP address'];
}

// Get additional IP information using ipinfo.io
function getIPInfo($ip)
{
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

    // Method 3: Use an external API as last resort
    try {
        $ch = curl_init();
        // Using ipinfo.io which is good at hostname lookups
        curl_setopt($ch, CURLOPT_URL, "https://ipinfo.io/{$ip}/json");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'IP Finder/1.0');
        $response = curl_exec($ch);
        curl_close($ch);

        if (!empty($response)) {
            // Validate JSON response
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['hostname'])) {
                return $data['hostname'];
            }
        }
    } catch (Exception $e) {
        // Silent fail
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