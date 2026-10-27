<?php
/**
 * The new owner's menu (photos / PDF), the last step of a sign-up link (admin/login.php).
 * Signed-in owner only; the files go to the private uploads/signup_menus folder (config/signup_invites.php)
 * and show in Super Admin › Sign-up links.
 */
header('Content-Type: application/json');
ini_set('display_errors', '0');
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/demo_restaurant.php';
require_once __DIR__ . '/../config/signup_invites.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('POST only');
    if (empty($_SESSION['user_id']) || empty($_SESSION['restaurant_id']) || ($_SESSION['user_type'] ?? '') !== 'admin') {
        http_response_code(401);
        throw new InvalidArgumentException('Please log in again.');
    }
    if (isDemoRestaurantSession()) throw new InvalidArgumentException('This is turned off in the demo restaurant.');
    $rid = (string)$_SESSION['restaurant_id'];
    session_write_close();
    if (empty($_FILES['menu']['name'][0])) throw new InvalidArgumentException('Please choose your menu photos or PDF.');
    $n = inviteMenuSave(getConnection(), $rid, $_FILES['menu']);
    echo json_encode(['success' => true, 'saved' => $n, 'message' => "Got your menu ($n file" . ($n === 1 ? '' : 's') . '). We will set it up for you.']);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('signup_menu: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not save your menu. Please try again.']);
}
