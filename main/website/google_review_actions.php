<?php
// "Rate us on Google" prompt: whether to show it, and recording the
// customer's answer. Mirrors loyalty_actions.php's boilerplate/shape — kept
// as its own small file rather than folding into api.php or loyalty_actions.php,
// since it's a distinct concern (not points/tiers) that only needs two tiny
// actions.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true);
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../config/growth_helpers.php';
require_once __DIR__ . '/customer_session.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');

// How often to re-show the prompt if the customer hasn't permanently
// dismissed it — every 3rd completed order, not every order and not random,
// so it stays occasional rather than nagging.
const GOOGLE_REVIEW_PROMPT_EVERY_N_VISITS = 3;

$restaurantId = isset($_REQUEST['restaurant_id']) && $_REQUEST['restaurant_id'] !== ''
    ? $_REQUEST['restaurant_id']
    : (isset($_SESSION['restaurant_id']) ? $_SESSION['restaurant_id'] : '');

$action = $_REQUEST['action'] ?? '';
$phone = isset($_REQUEST['phone']) ? trim($_REQUEST['phone']) : '';

try {
    $conn = function_exists('getConnection') ? getConnection() : ($pdo ?? null);
    if (!$conn) throw new Exception('No connection');
    if (!$restaurantId) throw new Exception('Restaurant not identified');
    if (!$phone) throw new Exception('Phone number is required');

    ensureGrowthSchema($conn);

    // Ownership check shared by all three actions below — this used to
    // trust the bare `phone` request parameter, letting anyone read or
    // change another customer's review-prompt state just by knowing their
    // phone number. Now it only succeeds for that customer's own logged-in
    // account, or a guest session that just proved ownership by placing a
    // real order through this phone (see getAuthorizedCustomer()).
    $authorizedCustomer = getAuthorizedCustomer($conn, $restaurantId, $phone);
    if (!$authorizedCustomer) {
        echo json_encode(['success' => true, 'show' => false]);
        exit;
    }

    switch ($action) {
        case 'check':
            $stmt = $conn->prepare(
                "SELECT total_visits, google_review_opt_out, google_review_last_prompted_visit
                 FROM customers WHERE restaurant_id = ? AND phone = ?"
            );
            $stmt->execute([$restaurantId, $phone]);
            $customer = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$customer || (int)$customer['google_review_opt_out'] === 1) {
                echo json_encode(['success' => true, 'show' => false]);
                break;
            }

            $visits = (int)$customer['total_visits'];
            $lastPrompted = (int)$customer['google_review_last_prompted_visit'];
            $due = $visits > 0 && $visits % GOOGLE_REVIEW_PROMPT_EVERY_N_VISITS === 0 && $visits !== $lastPrompted;

            if (!$due) {
                echo json_encode(['success' => true, 'show' => false]);
                break;
            }

            $uStmt = $conn->prepare("SELECT google_maps_link, address FROM users WHERE restaurant_id = ? LIMIT 1");
            $uStmt->execute([$restaurantId]);
            $restaurant = $uStmt->fetch(PDO::FETCH_ASSOC);

            // Same fallback the restaurant's own "Find Us" page uses
            // (about.php's openMapLink()): a real Google Maps link the owner
            // pasted in Settings takes the customer straight to the listing
            // (often with a one-tap "Write a review" button); with no link
            // set, fall back to a maps search of the plain address, which at
            // least gets them to the right place.
            $reviewLink = '';
            if (!empty($restaurant['google_maps_link'])) {
                $reviewLink = $restaurant['google_maps_link'];
            } elseif (!empty($restaurant['address'])) {
                $reviewLink = 'https://maps.google.com/maps?q=' . urlencode($restaurant['address']);
            }

            if (!$reviewLink) {
                echo json_encode(['success' => true, 'show' => false]);
                break;
            }

            echo json_encode(['success' => true, 'show' => true, 'review_link' => $reviewLink]);
            break;

        case 'mark_prompted':
            // Records that we asked at this visit count — whether the
            // customer rated us, said "maybe later", or just closed the
            // prompt — so it won't reappear until 3 more orders. Permanent
            // opt-out is a separate, stronger flag (see 'dismiss').
            $stmt = $conn->prepare("SELECT total_visits FROM customers WHERE restaurant_id = ? AND phone = ?");
            $stmt->execute([$restaurantId, $phone]);
            $visits = (int)$stmt->fetchColumn();
            $conn->prepare("UPDATE customers SET google_review_last_prompted_visit = ? WHERE restaurant_id = ? AND phone = ?")
                ->execute([$visits, $restaurantId, $phone]);
            echo json_encode(['success' => true]);
            break;

        case 'dismiss':
            // Permanent opt-out — "Don't ask again" means never, not just
            // "not this time".
            $conn->prepare("UPDATE customers SET google_review_opt_out = 1 WHERE restaurant_id = ? AND phone = ?")
                ->execute([$restaurantId, $phone]);
            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    error_log('Error in google_review_actions.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
