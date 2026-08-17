<?php
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

if (!isset($_SESSION['restaurant_id']) && !isset($_SESSION['superadmin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$restaurant_id = $_SESSION['restaurant_id'] ?? '';
if (!$restaurant_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No restaurant selected']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

try {
    require_once __DIR__ . '/../db_connection.php';
    $conn = getConnection();

    // Only touch the fields actually present in this request. The two
    // callers on the dashboard (the enable/disable toggle, and the "Save
    // Custom Domain" button) each only care about one field — an
    // unconditional two-column UPDATE meant that flipping the toggle alone
    // (with no custom_domain in that payload) silently blanked out an
    // already-saved custom domain, which is why it could "disappear" days
    // after being set with no obvious trigger. Each field is now updated
    // independently, so acting on one never clobbers the other.
    $setClauses = [];
    $params = [];

    if (array_key_exists('embed_enabled', $input)) {
        $setClauses[] = 'embed_enabled = ?';
        $params[] = !empty($input['embed_enabled']) ? 1 : 0;
    }
    if (array_key_exists('custom_domain', $input)) {
        // Normalize: strip an accidentally-pasted protocol/path/trailing slash
        // and lowercase, so "https://Foo.com/" and "foo.com" save identically
        // instead of silently being treated as two different domain strings.
        $domainRaw = trim((string)($input['custom_domain'] ?? ''));
        $domainRaw = preg_replace('#^https?://#i', '', $domainRaw);
        $domainRaw = strtolower(rtrim(explode('/', $domainRaw)[0], '/'));
        $setClauses[] = 'custom_domain = ?';
        // custom_domain has a UNIQUE constraint but an empty string still
        // counts as a duplicate value (unlike NULL) — store NULL when clearing
        // it so more than one restaurant can have "no custom domain" set.
        $params[] = ($domainRaw !== '') ? $domainRaw : null;
    }

    if (empty($setClauses)) {
        throw new Exception('Nothing to save');
    }

    $params[] = $restaurant_id;
    $stmt = $conn->prepare("UPDATE users SET " . implode(', ', $setClauses) . " WHERE restaurant_id = ?");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'message' => 'Embed settings saved']);
} catch (PDOException $e) {
    // Duplicate-entry on the custom_domain UNIQUE key is the one failure a
    // restaurant owner can actually act on (pick a different domain) — surface
    // that specifically instead of the generic message.
    if ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That domain is already connected to another account. Please use a different domain.']);
        exit;
    }
    http_response_code(500);
    error_log("Error in save_embed_settings.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
} catch (Exception $e) {
    http_response_code(500);
    error_log("Error in save_embed_settings.php: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}