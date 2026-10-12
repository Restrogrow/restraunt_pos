<?php
/**
 * Apple Wallet web service for the rewards card (Loyalty Studio › Phone card).
 * The pass's webServiceURL points here; Apple appends the path:
 *
 *   POST   /v1/devices/{device}/registrations/{passType}/{serial}   register (body: pushToken)
 *   DELETE /v1/devices/{device}/registrations/{passType}/{serial}   unregister
 *   GET    /v1/devices/{device}/registrations/{passType}?passesUpdatedSince=…   changed serials
 *   GET    /v1/passes/{passType}/{serial}                           the latest pass
 *   POST   /v1/log                                                  device logs
 *
 * Pass requests carry "Authorization: ApplePass <authenticationToken>".
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../config/wallet_card.php';
require_once __DIR__ . '/../config/vip_membership.php';

$path = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
$parts = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

function wsAuthCard(PDO $conn, string $serial): ?array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('apache_request_headers')) { $hh = apache_request_headers(); $h = $hh['Authorization'] ?? $hh['authorization'] ?? ''; }
    if (!preg_match('/^ApplePass\s+(\S+)$/', $h, $m)) return null;
    $st = $conn->prepare("SELECT * FROM wallet_cards WHERE serial = ? AND platform = 'apple'");
    $st->execute([$serial]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    return $c && hash_equals($c['auth_token'], $m[1]) ? $c : null;
}

try {
    $conn = isset($pdo) ? $pdo : getConnection();
    ensureWalletCardSchema($conn);
    $passType = (string)env('APPLE_PASS_TYPE_ID', '');

    if (($parts[0] ?? '') !== 'v1' || $passType === '') { http_response_code(404); exit; }

    if (($parts[1] ?? '') === 'log' && $method === 'POST') {
        $d = json_decode(file_get_contents('php://input'), true);
        foreach ((array)($d['logs'] ?? []) as $l) error_log('Apple Wallet: ' . mb_substr((string)$l, 0, 300));
        http_response_code(200);
        exit;
    }

    if (($parts[1] ?? '') === 'devices' && ($parts[3] ?? '') === 'registrations' && ($parts[4] ?? '') === $passType) {
        $device = substr((string)($parts[2] ?? ''), 0, 100);
        if (isset($parts[5])) {
            $serial = substr((string)$parts[5], 0, 80);
            if (!wsAuthCard($conn, $serial)) { http_response_code(401); exit; }
            if ($method === 'POST') {
                $d = json_decode(file_get_contents('php://input'), true);
                $token = substr((string)($d['pushToken'] ?? ''), 0, 200);
                if ($token === '') { http_response_code(400); exit; }
                $st = $conn->prepare('INSERT INTO wallet_card_devices (device_id, serial, push_token) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE push_token = VALUES(push_token)');
                $st->execute([$device, $serial, $token]);
                http_response_code($st->rowCount() === 1 ? 201 : 200);
                exit;
            }
            if ($method === 'DELETE') {
                $conn->prepare('DELETE FROM wallet_card_devices WHERE device_id = ? AND serial = ?')->execute([$device, $serial]);
                http_response_code(200);
                exit;
            }
            http_response_code(405);
            exit;
        }
        if ($method === 'GET') {
            $since = (string)($_GET['passesUpdatedSince'] ?? '');
            $sql = 'SELECT w.serial, w.updated_at FROM wallet_card_devices d JOIN wallet_cards w ON w.serial = d.serial WHERE d.device_id = ?';
            $args = [$device];
            if ($since !== '' && ctype_digit($since)) { $sql .= ' AND UNIX_TIMESTAMP(w.updated_at) > ?'; $args[] = (int)$since; }
            $st = $conn->prepare($sql);
            $st->execute($args);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$rows) { http_response_code(204); exit; }
            $last = max(array_map(function ($r) { return strtotime((string)$r['updated_at']) ?: time(); }, $rows));
            header('Content-Type: application/json');
            echo json_encode(['serialNumbers' => array_column($rows, 'serial'), 'lastUpdated' => (string)$last]);
            exit;
        }
    }

    if (($parts[1] ?? '') === 'passes' && ($parts[2] ?? '') === $passType && isset($parts[3]) && $method === 'GET') {
        $card = wsAuthCard($conn, substr((string)$parts[3], 0, 80));
        if (!$card) { http_response_code(401); exit; }
        $st = $conn->prepare('SELECT * FROM customers WHERE id = ? AND restaurant_id = ?');
        $st->execute([(int)$card['customer_id'], $card['restaurant_id']]);
        $cust = $st->fetch(PDO::FETCH_ASSOC);
        if (!$cust) { http_response_code(404); exit; }
        $pass = walletCardApplePass($conn, $card['restaurant_id'], $cust, $card);
        if (!$pass) { http_response_code(500); exit; }
        header('Content-Type: application/vnd.apple.pkpass');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s') . ' GMT');
        echo $pass;
        exit;
    }
    http_response_code(404);
} catch (Throwable $e) {
    error_log('wallet_card_service.php: ' . $e->getMessage());
    http_response_code(500);
}
