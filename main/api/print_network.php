<?php
// Relays raw ESC/POS bytes to a LAN thermal printer (or the bundled virtual
// test server) over a plain TCP socket, since browsers cannot open raw
// sockets themselves. Used by the "Network Printer" print mode.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json');

requirePermission(PERMISSION_MANAGE_ORDERS);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$ip = trim((string)($input['ip'] ?? ''));
$port = (int)($input['port'] ?? 9100);
$dataB64 = (string)($input['data'] ?? '');

if ($ip === '' || $dataB64 === '') {
    echo json_encode(['success' => false, 'message' => 'Missing printer address or print data']);
    exit;
}

if ($port < 1 || $port > 65535) {
    $port = 9100;
}

// Only allow hostnames/IPv4 addresses - blocks attempts to smuggle in
// URLs, scheme prefixes, or other unexpected socket targets.
if (!preg_match('/^[a-zA-Z0-9.\-]{1,255}$/', $ip)) {
    echo json_encode(['success' => false, 'message' => 'Invalid printer address']);
    exit;
}

$raw = base64_decode($dataB64, true);
if ($raw === false || $raw === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid print data']);
    exit;
}

// A full receipt is at most a few KB of ESC/POS bytes; cap generously to
// block accidental/abusive oversized payloads.
if (strlen($raw) > 200000) {
    echo json_encode(['success' => false, 'message' => 'Print data too large']);
    exit;
}

$errno = 0;
$errstr = '';
$socket = @fsockopen($ip, $port, $errno, $errstr, 5);

if (!$socket) {
    echo json_encode([
        'success' => false,
        'message' => "Could not connect to printer at {$ip}:{$port} ({$errstr})"
    ]);
    exit;
}

stream_set_timeout($socket, 5);
$written = fwrite($socket, $raw);
fclose($socket);

if ($written === false) {
    echo json_encode(['success' => false, 'message' => 'Connected but failed to send data to printer']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Sent to printer']);
