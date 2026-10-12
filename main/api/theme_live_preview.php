<?php
/**
 * Live preview on a phone — dashboard side (Website Appearance > "On your phone").
 *
 * POST JSON { action: 'start' }                 — new private session; returns token
 * POST JSON { action: 'push', token, theme }    — the latest unsaved choices (version + 1)
 * POST JSON { action: 'stop', token }           — end the session
 *
 * Always scoped to the session's restaurant.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
require_once __DIR__ . '/../config/theme_live_preview.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}
$rid = $_SESSION['restaurant_id'];
session_write_close();

try {
    $conn = getConnection();
    ensureThemeLivePreviewTable($conn);
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $in['action'] ?? '';

    if ($action === 'start') {
        $token = bin2hex(random_bytes(16));
        $theme = isset($in['theme']) && is_array($in['theme']) ? json_encode($in['theme']) : null;
        if ($theme !== null && strlen($theme) > 3900) $theme = null;
        // Old sessions of this restaurant end when a new one starts
        $conn->prepare('DELETE FROM theme_live_previews WHERE restaurant_id = ? OR updated_at < NOW() - INTERVAL 1 DAY')->execute([$rid]);
        $conn->prepare('INSERT INTO theme_live_previews (token, restaurant_id, theme, version, updated_at) VALUES (?, ?, ?, 1, NOW())')
             ->execute([$token, $rid, $theme]);
        echo json_encode(['success' => true, 'token' => $token]);
        exit;
    }

    $token = (string)($in['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) throw new InvalidArgumentException('Unknown session');

    if ($action === 'push') {
        $theme = json_encode(is_array($in['theme'] ?? null) ? $in['theme'] : []);
        if (strlen($theme) > 3900) throw new InvalidArgumentException('Too many changes to send');
        $st = $conn->prepare('UPDATE theme_live_previews SET theme = ?, version = version + 1, updated_at = NOW() WHERE token = ? AND restaurant_id = ?');
        $st->execute([$theme, $token, $rid]);
        if (!$st->rowCount()) { echo json_encode(['success' => false, 'ended' => true]); exit; }
        $v = $conn->prepare('SELECT version FROM theme_live_previews WHERE token = ?');
        $v->execute([$token]);
        echo json_encode(['success' => true, 'version' => (int)$v->fetchColumn()]);
        exit;
    }

    if ($action === 'stop') {
        $conn->prepare('DELETE FROM theme_live_previews WHERE token = ? AND restaurant_id = ?')->execute([$token, $rid]);
        echo json_encode(['success' => true]);
        exit;
    }

    throw new InvalidArgumentException('Unknown action');
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('theme_live_preview.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Something went wrong']);
}
