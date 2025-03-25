<?php
/**
 * External IP Address Finder
 * 
 * A simple web application to find your external IP address
 */

// Function to get the external IP address
function getExternalIP() {
    $apis = [
        'https://api.ipify.org?format=json',
        'https://ipinfo.io/json',
        'https://api.ip.sb/jsonip'
    ];
    
    foreach ($apis as $api) {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200) {
                $data = json_decode($response, true);
                
                // Different APIs have different response formats
                if (isset($data['ip'])) {
                    return ['success' => true, 'ip' => $data['ip']];
                } elseif (isset($data['query'])) {
                    return ['success' => true, 'ip' => $data['query']];
                }
            }
        } catch (Exception $e) {
            continue; // Try the next API
        }
    }
    
    return ['success' => false, 'message' => 'Failed to retrieve IP address'];
}

// Get the IP address when the page loads or when requested
$ipData = getExternalIP();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External IP Finder</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f5f5f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .container {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 30px;
            text-align: center;
            width: 400px;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .ip-display {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
            margin: 20px 0;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        .status {
            margin: 15px 0;
            font-size: 14px;
        }
        .success {
            color: #27ae60;
        }
        .error {
            color: #e74c3c;
        }
        .refresh-btn {
            background-color: #3498db;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.3s;
        }
        .refresh-btn:hover {
            background-color: #2980b9;
        }
        .info {
            margin-top: 20px;
            font-size: 12px;
            color: #7f8c8d;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>External IP Finder</h1>
        
        <div class="ip-display">
            <?php if ($ipData['success']): ?>
                <?php echo htmlspecialchars($ipData['ip']); ?>
            <?php else: ?>
                Error retrieving IP
            <?php endif; ?>
        </div>
        
        <div class="status <?php echo $ipData['success'] ? 'success' : 'error'; ?>">
            <?php if ($ipData['success']): ?>
                IP address retrieved successfully
            <?php else: ?>
                <?php echo htmlspecialchars($ipData['message']); ?>
            <?php endif; ?>
        </div>
        
        <form method="post">
            <button type="submit" class="refresh-btn">Refresh IP</button>
        </form>
        
        <div class="info">
            <p>This tool shows your external (public) IP address as seen by other servers on the internet.</p>
            <p>Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
