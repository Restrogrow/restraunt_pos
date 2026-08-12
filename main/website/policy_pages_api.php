<?php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/policy_defaults.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../config/session_config.php';
startSecureSession(true); // Skip timeout validation for public customer website

$action = $_GET['action'] ?? 'get';

$VALID_TYPES = ['privacy', 'terms', 'refund', 'shipping', 'cookie'];

function buildDefaultPolicyHtml($type, $restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone, $custom_domain) {
  switch ($type) {
    case 'privacy':
      return getDefaultPrivacyPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone);
    case 'terms':
      $showGoldenBird = in_array($custom_domain, ['sultaniarestaurant.in', 'www.sultaniarestaurant.in'], true);
      return getDefaultTermsOfServiceHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone, $showGoldenBird);
    case 'refund':
      return getDefaultRefundPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone);
    case 'shipping':
      return getDefaultShippingPolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone);
    case 'cookie':
      return getDefaultCookiePolicyHtml($restaurant_name, $restaurant_owner, $restaurant_email, $restaurant_phone);
  }
  return '';
}

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
  try {
    $conn = getConnection();
  } catch (Exception $e) {
    error_log("Error getting connection in policy_pages_api.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
  }
} else {
  global $pdo;
  $conn = $pdo ?? null;
  if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection not available']);
    exit;
  }
}

// Make sure the table exists (self-healing, avoids a separate manual migration step)
try {
  $conn->exec("CREATE TABLE IF NOT EXISTS policy_pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(20) NOT NULL,
    policy_type VARCHAR(20) NOT NULL,
    content LONGTEXT DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_restaurant_policy (restaurant_id, policy_type)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
  error_log("Error ensuring policy_pages table exists: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'message' => 'Database setup failed']);
  exit;
}

try {
  // Public reads (used by admin editor AND by the public policy pages) may pass
  // restaurant_id explicitly; falls back to the logged-in session.
  if ($action === 'get') {
    $restaurant_id = $_GET['restaurant_id'] ?? ($_SESSION['restaurant_id'] ?? null);
    if (!$restaurant_id) {
      echo json_encode(['success' => false, 'message' => 'Missing restaurant_id']);
      exit;
    }

    $type = $_GET['policy_type'] ?? null;
    if ($type !== null) {
      if (!in_array($type, $VALID_TYPES, true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid policy_type']);
        exit;
      }
      $stmt = $conn->prepare('SELECT content, updated_at FROM policy_pages WHERE restaurant_id = :rid AND policy_type = :type LIMIT 1');
      $stmt->execute([':rid' => $restaurant_id, ':type' => $type]);
      $row = $stmt->fetch(PDO::FETCH_ASSOC);

      // Build the default template text (with this restaurant's own name/owner/contact
      // details filled in) so the admin editor can pre-fill the textarea with real,
      // ready-to-tweak wording instead of an empty box.
      $defaultHtml = '';
      $userStmt = $conn->prepare('SELECT restaurant_name, owner_name, email, phone, custom_domain FROM users WHERE restaurant_id = :rid LIMIT 1');
      $userStmt->execute([':rid' => $restaurant_id]);
      $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);
      if ($userRow) {
        $defaultHtml = buildDefaultPolicyHtml(
          $type,
          $userRow['restaurant_name'] ?? 'Restaurant',
          $userRow['owner_name'] ?? '',
          $userRow['email'] ?? '',
          $userRow['phone'] ?? '',
          $userRow['custom_domain'] ?? ''
        );
      }

      echo json_encode([
        'success' => true,
        'content' => $row['content'] ?? '',
        'default_html' => $defaultHtml,
        'updated_at' => $row['updated_at'] ?? null
      ]);
      exit;
    }

    // No specific type requested: return all 5 at once (used by the admin editor to show status badges)
    $stmt = $conn->prepare('SELECT policy_type, content, updated_at FROM policy_pages WHERE restaurant_id = :rid');
    $stmt->execute([':rid' => $restaurant_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($VALID_TYPES as $t) {
      $result[$t] = ['content' => '', 'updated_at' => null];
    }
    foreach ($rows as $row) {
      $result[$row['policy_type']] = ['content' => $row['content'] ?? '', 'updated_at' => $row['updated_at']];
    }
    echo json_encode(['success' => true, 'policies' => $result]);
    exit;
  }

  // Writes are restricted to the logged-in admin's own restaurant (session-only,
  // never trust a restaurant_id passed from the client for save/reset).
  if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) {
      http_response_code(401);
      echo json_encode(['success' => false, 'message' => 'You must be logged in to edit policy pages']);
      exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $type = $data['policy_type'] ?? '';
    if (!in_array($type, $VALID_TYPES, true)) {
      echo json_encode(['success' => false, 'message' => 'Invalid policy_type']);
      exit;
    }
    $content = trim($data['content'] ?? '');

    $stmt = $conn->prepare('INSERT INTO policy_pages (restaurant_id, policy_type, content) VALUES (:rid, :type, :content)
      ON DUPLICATE KEY UPDATE content = VALUES(content)');
    $stmt->execute([':rid' => $restaurant_id, ':type' => $type, ':content' => $content !== '' ? $content : null]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $restaurant_id = $_SESSION['restaurant_id'] ?? null;
    if (!$restaurant_id) {
      http_response_code(401);
      echo json_encode(['success' => false, 'message' => 'You must be logged in to edit policy pages']);
      exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $type = $data['policy_type'] ?? '';
    if (!in_array($type, $VALID_TYPES, true)) {
      echo json_encode(['success' => false, 'message' => 'Invalid policy_type']);
      exit;
    }

    $stmt = $conn->prepare('DELETE FROM policy_pages WHERE restaurant_id = :rid AND policy_type = :type');
    $stmt->execute([':rid' => $restaurant_id, ':type' => $type]);
    echo json_encode(['success' => true]);
    exit;
  }

  echo json_encode(['success' => false, 'message' => 'Invalid action']);
} catch (Exception $e) {
  http_response_code(500);
  error_log("Error in policy_pages_api.php: " . $e->getMessage());
  echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
