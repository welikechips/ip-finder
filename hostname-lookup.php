<?php
/**
 * Hostname Lookup Service
 * Performs secure DNS reverse lookups like ipchicken
 */

// Set secure headers
header("Content-Type: application/json");
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");

// Start session for rate limiting
session_start();

// Include shared utility functions
require_once 'utils.php';

// Apply rate limiting with stricter limits for this endpoint
if (!enforceRateLimit('hostname_rate_limit', 5, 60)) {
    header('HTTP/1.1 429 Too Many Requests');
    echo json_encode(['error' => 'Rate limit exceeded. Please try again later.']);
    exit;
}

// Check if IP parameter exists
if (!isset($_GET['ip'])) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Missing IP parameter']);
    exit;
}

// Get and validate IP
$ip = trim($_GET['ip']);
if (!validateIP($ip)) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Invalid IP format']);
    exit;
}

// Get hostname using the shared function
$hostname = resolveHostname($ip);

// Return response
if ($hostname) {
    echo json_encode(['hostname' => $hostname]);
} else {
    echo json_encode(['hostname' => null]);
}
?>