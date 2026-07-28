<?php
// Simple auth: only allow from local connections or with valid session
$addr = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($addr, ['127.0.0.1', '::1'], true)) {
    // Could also check session here if available
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$fp = fopen('memory_dump.json', 'w');
if ($fp) {
    meminfo_dump($fp);
    fclose($fp);
} else {
    echo 'Failed to open memory_dump.json for writing';
}

