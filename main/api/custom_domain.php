<?php
/**
 * Customer Website › Custom Domain (dashboard). One endpoint for the page:
 *
 *   GET                         settings, the DNS records to add, embed code
 *   GET ?check=1                ...plus a live check: what the domain's DNS points to, and
 *                               does https://<domain>/ show this restaurant's website (= ready)?
 *   POST {action:'save', domain}       save / clear the domain (validated)
 *   POST {action:'switch', on, force}  send customers to the domain. Turning it on is
 *                               refused (409, with the check) until the check passes,
 *                               because once on, the RestroGrow link and every QR code
 *                               redirect there: a domain that isn't ready takes the
 *                               website offline. force=true is the owner's "turn on anyway".
 *
 * Uses the same users.custom_domain / users.embed_enabled columns as before
 * (custom-domain.php, header.php and the QR codes read them).
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
// The customer website keeps the restaurant it shows in the same session, so restaurant_id alone
// isn't a login. Owner / branch admin only: this redirects the restaurant's link and QR codes.
require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_SETTINGS);

$restaurant_id = $_SESSION['restaurant_id'] ?? '';
if (!$restaurant_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
session_write_close(); // the DNS / website check below can be slow; don't hold the session lock

require_once __DIR__ . '/../db_connection.php';

function cdOut($code, $data) { http_response_code($code); echo json_encode($data); exit; }

require_once __DIR__ . '/../config/custom_domain_check.php';   // cdRecords(), cdCheck(), cdNormalize() …

try {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT restaurant_name, custom_domain, embed_enabled FROM users WHERE restaurant_id = ? LIMIT 1");
    $stmt->execute([$restaurant_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) cdOut(404, ['success' => false, 'message' => 'Restaurant not found']);
    $domain = (string)($row['custom_domain'] ?? '');
    $enabled = !empty($row['embed_enabled']);

    // an outlet of a brand: the brand's address is set for all outlets on the Branches page (config/brand.php)
    require_once __DIR__ . '/../config/brand.php';
    $rgBrand = brandOfRestaurant($conn, (string)$restaurant_id);
    if ($rgBrand && $_SERVER['REQUEST_METHOD'] === 'POST') {
        cdOut(409, ['success' => false, 'code' => 'brand', 'message' => 'This outlet is part of the brand ' . $rgBrand['brand']['name'] . '. Its website address is set for all outlets together, on the Branches page.']);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $in['action'] ?? '';

        if ($action === 'save') {
            $d = cdNormalize($in['domain'] ?? '');
            if ($d !== '') {
                if (strlen($d) > 190 || !preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d)) {
                    cdOut(400, ['success' => false, 'message' => 'That doesn’t look like a domain. Type it like order.yourrestaurant.com']);
                }
                $main = cdMainHost();
                if ($d === $main || substr($d, -strlen('.' . $main)) === '.' . $main || in_array($d, ['localhost'], true)) {
                    cdOut(400, ['success' => false, 'message' => 'Use a domain you own, not a RestroGrow address.']);
                }
            }
            $changed = $d !== $domain;
            try {
                // a new domain hasn't been checked yet, so stop sending customers to it until it is
                $conn->prepare("UPDATE users SET custom_domain = ?" . ($changed ? ", embed_enabled = 0" : "") . " WHERE restaurant_id = ?")->execute([$d !== '' ? $d : null, $restaurant_id]);
            } catch (PDOException $e) {
                if ((int)$e->getCode() === 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    cdOut(409, ['success' => false, 'message' => 'That domain is already connected to another restaurant. Please use a different one.']);
                }
                throw $e;
            }
            cdOut(200, ['success' => true, 'domain' => $d, 'switched_off' => $changed && $enabled]);
        }

        if ($action === 'switch') {
            $on = !empty($in['on']);
            if ($on) {
                if ($domain === '') cdOut(400, ['success' => false, 'message' => 'Add your domain first.']);
                $check = cdCheck($domain, (string)$row['restaurant_name']);
                if (!$check['ready'] && empty($in['force'])) {
                    cdOut(409, ['success' => false, 'code' => 'not_ready', 'message' => 'Your domain isn’t showing your website yet.', 'check' => $check]);
                }
            }
            $conn->prepare("UPDATE users SET embed_enabled = ? WHERE restaurant_id = ?")->execute([$on ? 1 : 0, $restaurant_id]);
            cdOut(200, ['success' => true, 'enabled' => $on]);
        }
        cdOut(400, ['success' => false, 'message' => 'Unknown action']);
    }

    // GET
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $basePath = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($basePath === '/' || $basePath === '\\') $basePath = '';
    $baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'restrogrow.com') . $basePath;
    $data = [
        'restaurant_id' => $restaurant_id,
        'restaurant_name' => $row['restaurant_name'],
        'domain' => $domain,
        'enabled' => $enabled,
        'live' => $enabled && $domain !== '',
        'main_host' => cdMainHost(),
        'records' => cdRecords($domain),
        'root' => $domain ? cdSplit($domain)[0] : '',
        'embed_code' => '<div id="rg-restaurant-menu"></div>' . "\n" . '<script src="' . $baseUrl . '/main/embed/embed.js?restaurant=' . urlencode($restaurant_id) . '"></script>',
        'preview_url' => $baseUrl . '/main/website/index.php?restaurant_id=' . urlencode($restaurant_id),
        // part of a brand: its address comes from the brand (config/brand.php)
        'brand' => $rgBrand ? ['name' => $rgBrand['brand']['name'], 'address' => brandOutletUrl($rgBrand['brand'], $rgBrand['outlet'], '', true),
            'picker' => brandPickerUrl($rgBrand['brand'], true), 'own_domain' => $rgBrand['brand']['domain_live'] ? $rgBrand['brand']['custom_domain'] : ''] : null,
    ];
    if (!empty($_GET['check']) && $domain !== '') $data['check'] = cdCheck($domain, (string)$row['restaurant_name']);
    cdOut(200, ['success' => true, 'data' => $data]);
} catch (Throwable $e) {
    error_log('custom_domain.php: ' . $e->getMessage());
    cdOut(500, ['success' => false, 'message' => 'Something went wrong. Please try again.']);
}
