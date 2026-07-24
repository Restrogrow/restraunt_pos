<?php
// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Max-Age: 86400'); // 24 hours
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/auth.php';
require_superadmin();

// Get connection using getConnection() for lazy connection support
if (function_exists('getConnection')) {
    $conn = getConnection();
} else {
    // Fallback to $pdo if getConnection() doesn't exist (backward compatibility)
    global $pdo;
    $conn = $pdo ?? null;
    if (!$conn) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection not available']);
        exit();
    }
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
$action = $_GET['action'] ?? '';

// Auto-create payment_links table if missing
try { $conn->query("SELECT id FROM payment_links LIMIT 1"); } catch (PDOException $e) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS payment_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            restaurant_name VARCHAR(255) DEFAULT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            transaction_id VARCHAR(100) DEFAULT NULL,
            payment_status ENUM('Pending','Success','Failed') DEFAULT 'Pending',
            payment_url TEXT DEFAULT NULL,
            phonepe_order_id VARCHAR(100) DEFAULT NULL,
            phonepe_checkout_url TEXT DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_restaurant (restaurant_id),
            INDEX idx_status (payment_status),
            INDEX idx_transaction (transaction_id)
        )");

    } catch (PDOException $e3) {}
}
// Ensure phonepe_checkout_url column exists (for existing tables)

// Auto-create settlements table if missing
try { $conn->query("SELECT id FROM settlements LIMIT 1"); } catch (PDOException $e) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS settlements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            upi_id VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            transaction_id VARCHAR(100) DEFAULT NULL,
            phonepe_transaction_id VARCHAR(100) DEFAULT NULL,
            status ENUM('Pending','Success','Failed') DEFAULT 'Pending',
            response_data TEXT DEFAULT NULL,
            notes VARCHAR(255) DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_transaction (transaction_id)
        )");
    } catch (PDOException $e3) {}
}

// Auto-create coupons table if missing
try { $conn->query("SELECT id FROM coupons LIMIT 1"); } catch (PDOException $e) {
    try {
        $conn->exec("CREATE TABLE IF NOT EXISTS coupons (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            coupon_code VARCHAR(50) NOT NULL,
            discount_type ENUM('percent','flat') NOT NULL DEFAULT 'percent',
            discount_value DECIMAL(10,2) NOT NULL,
            minimum_order_amount DECIMAL(10,2) DEFAULT 0.00,
            max_uses INT DEFAULT 0,
            current_uses INT DEFAULT 0,
            valid_from DATE DEFAULT NULL,
            valid_until DATE DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 1,
            description VARCHAR(255) DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_coupon (restaurant_id, coupon_code),
            INDEX idx_restaurant (restaurant_id)
        )");
    } catch (PDOException $e2) {}
}

try {
  switch ($action) {
    case 'getRestaurants':
      $q = trim($_GET['q'] ?? '');
      $page = max(1, (int)($_GET['page'] ?? 1));
      $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
      $offset = ($page - 1) * $limit;

      $where = '';
      $params = [];
      if ($q !== '') {
        $where = "WHERE username LIKE :q OR restaurant_name LIKE :q OR restaurant_id LIKE :q";
        $params[':q'] = "%$q%";
      }





      $sql = "SELECT 
                id, username, restaurant_id, restaurant_name, is_active, created_at,
                subscription_status,
                trial_end_date,
                renewal_date,
                description,
                show_install_app,
                whatsapp_orders,
                payment_gateway_type,
                GREATEST(DATEDIFF(COALESCE(renewal_date, trial_end_date, DATE_ADD(DATE(created_at), INTERVAL 30 DAY)), CURRENT_DATE()), 0) AS days_left,
                CASE 
                  WHEN subscription_status = 'disabled' THEN 'Disabled'
                  WHEN CURRENT_DATE() < COALESCE(renewal_date, trial_end_date, DATE_ADD(DATE(created_at), INTERVAL 30 DAY)) THEN 'Active'
                  ELSE 'Expired'
                END AS trial_status
              FROM users $where 
              ORDER BY created_at DESC 
              LIMIT :limit OFFSET :offset";
      $stmt = $conn->prepare($sql);
      foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();

      $countStmt = $conn->prepare("SELECT COUNT(*) as c FROM users $where");
      foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
      $countStmt->execute();
      $total = (int)($countStmt->fetch()['c'] ?? 0);

      echo json_encode(['success' => true, 'restaurants' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
      break;

    case 'createRestaurant':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $username = trim($data['username'] ?? '');
      $password = $data['password'] ?? '';
      $restaurant_name = trim($data['restaurant_name'] ?? '');
      if (!$username || !$password || !$restaurant_name) {
        throw new Exception('Missing required fields');
      }
      // Auto-generate unique restaurant_id: RES + 3 letters + 3 digits
      $generateId = function() use ($conn) {
        $letters = substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 3);
        $digits = str_pad(strval(random_int(0, 999)), 3, '0', STR_PAD_LEFT);
        $rid = 'RES' . $letters . $digits;
        $check = $conn->prepare('SELECT COUNT(*) FROM users WHERE restaurant_id = :rid');
        $check->execute([':rid' => $rid]);
        return $check->fetchColumn() == 0 ? $rid : null;
      };
      $attempts = 0; $restaurant_id = null;
      while ($attempts < 5 && !$restaurant_id) { $restaurant_id = $generateId(); $attempts++; }
      if (!$restaurant_id) { throw new Exception('Failed to generate restaurant id'); }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("INSERT INTO users (username, password, restaurant_id, restaurant_name, is_active, payment_gateway_type) VALUES (:u, :p, :rid, :rname, 1, 'cash_only')");
      $stmt->execute([':u' => $username, ':p' => $hash, ':rid' => $restaurant_id, ':rname' => $restaurant_name]);
      echo json_encode(['success' => true, 'message' => 'Restaurant created', 'restaurant_id' => $restaurant_id]);
      break;

    case 'toggleRestaurant':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $active = (int)($data['is_active'] ?? 1);
      if ($id <= 0) throw new Exception('Invalid id');
      if ($active === 1) {
        // Re-enable: set renewal_date +30d and status trial
        $stmt = $conn->prepare("UPDATE users SET is_active=1, subscription_status='trial', renewal_date = DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), disabled_at=NULL WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        $info = $conn->prepare('SELECT renewal_date FROM users WHERE id=:id');
        $info->execute([':id'=>$id]);
        $renew = $info->fetchColumn();
        echo json_encode(['success'=>true,'message'=>'Enabled; renewal set','renewal_date'=>$renew]);
      } else {
        // Disable
        $stmt = $conn->prepare("UPDATE users SET is_active=0, subscription_status='disabled', disabled_at=NOW() WHERE id=:id");
        $stmt->execute([':id'=>$id]);
        echo json_encode(['success'=>true,'message'=>'Disabled']);
      }
      break;

    case 'resetPassword':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $password = $data['password'] ?? '';
      if ($id <= 0 || !$password) throw new Exception('Invalid payload');
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare('UPDATE users SET password = :p WHERE id = :id');
      $stmt->execute([':p' => $hash, ':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'Password reset']);
      break;

    case 'generateAutoLogin':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid restaurant ID');

      $stmt = $conn->prepare("SELECT id, restaurant_id FROM users WHERE id = ?");
      $stmt->execute([$id]);
      $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$restaurant) throw new Exception('Restaurant not found');

      $token = bin2hex(random_bytes(32));
      $expires = date('Y-m-d H:i:s', time() + 300);

      $stmt = $conn->prepare("UPDATE users SET auto_login_token = ?, auto_login_expires = ? WHERE id = ?");
      $stmt->execute([$token, $expires, $id]);

      // Store token in session instead of URL to prevent logging in server access logs
      $_SESSION['auto_login_token'] = $token;
      $_SESSION['auto_login_restaurant_id'] = $restaurant['restaurant_id'];

      $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $base_path = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
      if ($base_path === '/' || $base_path === '\\') $base_path = '';
      $site_url = $scheme . '://' . $host . $base_path;
      $autoLoginUrl = $site_url . '/main/admin/auto_login.php';

      echo json_encode(['success' => true, 'url' => $autoLoginUrl]);
      break;

    case 'updatePaymentGateway':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $type = $data['type'] ?? '';
      if ($id <= 0 || !in_array($type, ['cash_only', 'cash_and_gateway'])) {
        throw new Exception('Invalid payload');
      }

      $stmt = $conn->prepare('UPDATE users SET payment_gateway_type = :type WHERE id = :id');
      $stmt->execute([':type' => $type, ':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'Payment gateway type updated']);
      break;

    case 'updateInstallApp':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $show = (int)($data['show_install_app'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid id');

      $stmt = $conn->prepare('UPDATE users SET show_install_app = :show WHERE id = :id');
      $stmt->execute([':show' => $show, ':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'Install app setting updated']);
      break;

    case 'updateWhatsappOrders':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $whatsapp = (int)($data['whatsapp_orders'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid id');

      $stmt = $conn->prepare('UPDATE users SET whatsapp_orders = :whatsapp WHERE id = :id');
      $stmt->execute([':whatsapp' => $whatsapp, ':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'WhatsApp orders setting updated']);
      break;

    case 'getPasswordResetData':
      $page = max(1, (int)($_GET['page'] ?? 1));
      $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
      $offset = ($page - 1) * $limit;

      try {
        // Check if table exists
        $tableCheck = $conn->query("SHOW TABLES LIKE 'password_reset_tokens'");
        if ($tableCheck->rowCount() == 0) {
          echo json_encode(['success' => false, 'message' => 'password_reset_tokens table does not exist. Please run the migration first.']);
          break;
        }

        $sql = "SELECT 
                  prt.id, prt.user_id, prt.token, prt.expires_at, prt.used, prt.created_at,
                  u.username, u.restaurant_name, u.email
                FROM password_reset_tokens prt
                LEFT JOIN users u ON prt.user_id = u.id
                ORDER BY prt.created_at DESC 
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $countStmt = $conn->query("SELECT COUNT(*) as c FROM password_reset_tokens");
        $total = (int)($countStmt->fetch()['c'] ?? 0);

        echo json_encode(['success' => true, 'tokens' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit]);
      } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
      }
      break;

    case 'getStats':
      $stats = [];
      // totals
      $stats['restaurants'] = (int)($conn->query("SELECT COUNT(*) FROM users")->fetchColumn());
      $stats['active'] = (int)($conn->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn());
      // revenue and orders today (sum across restaurants)
      $today = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
      $revStmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE DATE(created_at)=?");
      $revStmt->execute([$today]);
      $stats['todayRevenue'] = (float)$revStmt->fetchColumn();
      $ordStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=?");
      $ordStmt->execute([$today]);
      $stats['todayOrders'] = (int)$ordStmt->fetchColumn();
      echo json_encode(['success'=>true,'stats'=>$stats]);
      break;

    case 'getPayments':
      $search = trim($_GET['search'] ?? '');
      $status = trim($_GET['status'] ?? '');
      $limit = min(200, max(10, (int)($_GET['limit'] ?? 100)));
      $offset = max(0, (int)($_GET['offset'] ?? 0));

      $sql = "SELECT 
                p.id,
                p.transaction_id,
                p.restaurant_id,
                p.amount,
                p.payment_method,
                p.payment_status,
                p.subscription_type,
                p.created_at,
                u.restaurant_name
              FROM payments p
              LEFT JOIN users u ON u.restaurant_id = p.restaurant_id
              WHERE 1=1";
      $params = [];
      if ($search !== '') {
        $sql .= " AND (p.transaction_id LIKE :search OR p.restaurant_id LIKE :search OR u.restaurant_name LIKE :search)";
        $params[':search'] = "%$search%";
      }
      if ($status !== '') {
        $sql .= " AND p.payment_status = :status";
        $params[':status'] = $status;
      }
      $sql .= " ORDER BY p.created_at DESC LIMIT :limit OFFSET :offset";
      $stmt = $conn->prepare($sql);
      foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
      }
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      echo json_encode([
        'success' => true,
        'payments' => $stmt->fetchAll()
      ]);
      break;

    case 'getCoupons':
      $restaurant_id = trim($_GET['restaurant_id'] ?? '');
      $all = $_GET['all'] ?? '';
      $sql = "SELECT c.*, u.restaurant_name FROM coupons c LEFT JOIN users u ON u.restaurant_id = c.restaurant_id";
      $params = [];
      if ($restaurant_id !== '') {
        $sql .= " WHERE c.restaurant_id = :rid";
        $params[':rid'] = $restaurant_id;
      }
      $sql .= " ORDER BY c.created_at DESC";
      $stmt = $conn->prepare($sql);
      foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
      $stmt->execute();
      echo json_encode(['success' => true, 'coupons' => $stmt->fetchAll()]);
      break;

    case 'createCoupon':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $rid = trim($data['restaurant_id'] ?? '');
      $code = strtoupper(trim($data['coupon_code'] ?? ''));
      $type = $data['discount_type'] ?? 'percent';
      $value = (float)($data['discount_value'] ?? 0);
      $minOrder = (float)($data['minimum_order_amount'] ?? 0);
      $maxUses = (int)($data['max_uses'] ?? 0);
      $validFrom = $data['valid_from'] ?? null;
      $validUntil = $data['valid_until'] ?? null;
      $desc = trim($data['description'] ?? '');
      if (!$rid || !$code || $value <= 0) throw new Exception('Missing required fields');
      if (!in_array($type, ['percent', 'flat'])) throw new Exception('Invalid discount type');
      $stmt = $conn->prepare("INSERT INTO coupons (restaurant_id, coupon_code, discount_type, discount_value, minimum_order_amount, max_uses, valid_from, valid_until, description) VALUES (:rid, :code, :type, :val, :min, :max, :from, :until, :desc)");
      $stmt->execute([':rid'=>$rid, ':code'=>$code, ':type'=>$type, ':val'=>$value, ':min'=>$minOrder, ':max'=>$maxUses, ':from'=>$validFrom, ':until'=>$validUntil, ':desc'=>$desc]);
      echo json_encode(['success' => true, 'message' => 'Coupon created']);
      break;

    case 'updateCoupon':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid id');
      $fields = [];
      $params = [':id' => $id];
      foreach (['coupon_code','discount_type','discount_value','minimum_order_amount','max_uses','valid_from','valid_until','description'] as $f) {
        if (isset($data[$f])) {
          $fields[] = "$f = :$f";
          $params[":$f"] = $data[$f];
        }
      }
      if (empty($fields)) throw new Exception('Nothing to update');
      $stmt = $conn->prepare("UPDATE coupons SET " . implode(', ', $fields) . " WHERE id = :id");
      $stmt->execute($params);
      echo json_encode(['success' => true, 'message' => 'Coupon updated']);
      break;

    case 'toggleCoupon':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $active = (int)($data['is_active'] ?? 1);
      if ($id <= 0) throw new Exception('Invalid id');
      $stmt = $conn->prepare('UPDATE coupons SET is_active = :active WHERE id = :id');
      $stmt->execute([':active' => $active, ':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'Coupon status updated']);
      break;

    case 'getBranchAdmins':
      // Auto-create tables if missing
      try { $conn->query("SELECT id FROM branch_admins LIMIT 1"); } catch (PDOException $e) {
        $conn->exec("CREATE TABLE IF NOT EXISTS branch_admins (
          id INT AUTO_INCREMENT PRIMARY KEY,
          username VARCHAR(50) NOT NULL UNIQUE,
          password_hash VARCHAR(255) NOT NULL,
          display_name VARCHAR(100) DEFAULT NULL,
          is_active TINYINT(1) DEFAULT 1,
          created_by INT DEFAULT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS branch_restaurant_links (
          id INT AUTO_INCREMENT PRIMARY KEY,
          branch_admin_id INT NOT NULL,
          restaurant_id VARCHAR(10) NOT NULL,
          label VARCHAR(100) DEFAULT NULL,
          FOREIGN KEY (branch_admin_id) REFERENCES branch_admins(id) ON DELETE CASCADE
        )");
      }
      $admins = $conn->query("SELECT * FROM branch_admins ORDER BY created_at DESC")->fetchAll();
      foreach ($admins as &$a) {
        $linkStmt = $conn->prepare("SELECT brl.restaurant_id, brl.label, u.restaurant_name FROM branch_restaurant_links brl LEFT JOIN users u ON u.restaurant_id = brl.restaurant_id WHERE brl.branch_admin_id = ?");
        $linkStmt->execute([$a['id']]);
        $a['links'] = $linkStmt->fetchAll();
      }
      echo json_encode(['success' => true, 'admins' => $admins]);
      break;

    case 'createBranchAdmin':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $username = trim($data['username'] ?? '');
      $password = $data['password'] ?? '';
      $displayName = trim($data['display_name'] ?? '');
      $restaurantIds = $data['restaurant_ids'] ?? [];
      if (!$username || !$password) throw new Exception('Username and password required');
      if (empty($restaurantIds)) throw new Exception('Select at least one restaurant');
      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = $conn->prepare("INSERT INTO branch_admins (username, password_hash, display_name, created_by) VALUES (?, ?, ?, ?)");
      $stmt->execute([$username, $hash, $displayName, $_SESSION['superadmin_id'] ?? null]);
      $adminId = $conn->lastInsertId();
      $linkStmt = $conn->prepare("INSERT INTO branch_restaurant_links (branch_admin_id, restaurant_id) VALUES (?, ?)");
      foreach ($restaurantIds as $rid) {
        $linkStmt->execute([$adminId, $rid]);
      }
      echo json_encode(['success' => true, 'message' => 'Branch admin created']);
      break;

    case 'toggleBranchAdmin':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $active = (int)($data['is_active'] ?? 1);
      if ($id <= 0) throw new Exception('Invalid id');
      $stmt = $conn->prepare("UPDATE branch_admins SET is_active = ? WHERE id = ?");
      $stmt->execute([$active, $id]);
      echo json_encode(['success' => true, 'message' => $active ? 'Branch admin enabled' : 'Branch admin disabled']);
      break;

    case 'getLinkedRestaurants':
      $id = (int)($_GET['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid id');
      $stmt = $conn->prepare("SELECT brl.restaurant_id, brl.label, u.restaurant_name FROM branch_restaurant_links brl LEFT JOIN users u ON u.restaurant_id = brl.restaurant_id WHERE brl.branch_admin_id = ?");
      $stmt->execute([$id]);
      echo json_encode(['success' => true, 'links' => $stmt->fetchAll()]);
      break;

    case 'updateBranchLinks':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $restaurantIds = $data['restaurant_ids'] ?? [];
      if ($id <= 0) throw new Exception('Invalid id');
      $conn->prepare("DELETE FROM branch_restaurant_links WHERE branch_admin_id = ?")->execute([$id]);
      $linkStmt = $conn->prepare("INSERT INTO branch_restaurant_links (branch_admin_id, restaurant_id) VALUES (?, ?)");
      foreach ($restaurantIds as $rid) {
        $linkStmt->execute([$id, $rid]);
      }
      echo json_encode(['success' => true, 'message' => 'Links updated']);
      break;

    case 'deleteCoupon':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid id');
      $stmt = $conn->prepare('DELETE FROM coupons WHERE id = :id');
      $stmt->execute([':id' => $id]);
      echo json_encode(['success' => true, 'message' => 'Coupon deleted']);
            break;

    case 'updateSubscription':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $action_type = $data['action_type'] ?? ''; // activate, expire, custom
      $duration_months = (int)($data['duration_months'] ?? 1);
      $notes = trim($data['notes'] ?? '');
      if ($id <= 0 || !in_array($action_type, ['activate', 'expire', 'custom'])) {
        throw new Exception('Invalid payload');
      }
      if ($action_type === 'activate') {
        // Activate for default 30 days
        $stmt = $conn->prepare("UPDATE users SET subscription_status='active', renewal_date=DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY), is_active=1, disabled_at=NULL, subscription_updated_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
        // Log payment placeholder
        $restStmt = $conn->prepare("SELECT restaurant_id, restaurant_name FROM users WHERE id=?");
        $restStmt->execute([$id]);
        $rest = $restStmt->fetch(PDO::FETCH_ASSOC);
        if ($rest) {
          $txn = 'ADMIN_' . time() . '_' . $id;
          $payStmt = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, duration_months, payment_status, payment_method, notes) VALUES (?, ?, ?, ?, 'monthly', 1, 'success', 'manual', ?)");
          $payStmt->execute([$id, $rest['restaurant_id'], $txn, 0, $notes]);
        }
        echo json_encode(['success' => true, 'message' => 'Subscription activated for 30 days']);
      } elseif ($action_type === 'expire') {
        $stmt = $conn->prepare("UPDATE users SET subscription_status='expired', is_active=1, subscription_updated_at=NOW() WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Subscription marked as expired']);
      } elseif ($action_type === 'custom') {
        // Custom duration
        if ($duration_months < 1 || $duration_months > 60) throw new Exception('Duration must be 1-60 months');
        $stmt = $conn->prepare("UPDATE users SET subscription_status='active', renewal_date=DATE_ADD(CURRENT_DATE(), INTERVAL ? MONTH), is_active=1, disabled_at=NULL, subscription_updated_at=NOW() WHERE id=?");
        $stmt->execute([$duration_months, $id]);
        $restStmt = $conn->prepare("SELECT restaurant_id, restaurant_name FROM users WHERE id=?");
        $restStmt->execute([$id]);
        $rest = $restStmt->fetch(PDO::FETCH_ASSOC);
        if ($rest) {
          $txn = 'ADMIN_' . time() . '_' . $id;
          $payStmt = $conn->prepare("INSERT INTO subscription_payments (user_id, restaurant_id, transaction_id, amount, subscription_type, duration_months, payment_status, payment_method, notes) VALUES (?, ?, ?, ?, 'custom', ?, 'success', 'manual', ?)");
          $payStmt->execute([$id, $rest['restaurant_id'], $txn, 0, $duration_months, $notes]);
        }
        echo json_encode(['success' => true, 'message' => "Subscription activated for {$duration_months} months"]);
      }
      break;

    case 'getSubscriptionPayments':
      $restaurant_id = trim($_GET['restaurant_id'] ?? '');
      $limit = min(100, max(5, (int)($_GET['limit'] ?? 20)));
      $offset = max(0, (int)($_GET['offset'] ?? 0));
      $where = '';
      $params = [];
      if ($restaurant_id !== '') {
        $where = "WHERE sp.restaurant_id = :rid";
        $params[':rid'] = $restaurant_id;
      }
      $sql = "SELECT sp.*, u.restaurant_name FROM subscription_payments sp LEFT JOIN users u ON u.id = sp.user_id $where ORDER BY sp.created_at DESC LIMIT :limit OFFSET :offset";
      $stmt = $conn->prepare($sql);
      foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      echo json_encode(['success' => true, 'payments' => $stmt->fetchAll()]);
      break;

    case 'getSubscriptionStats':
      $active = (int)$conn->query("SELECT COUNT(*) FROM users WHERE subscription_status='active' AND (renewal_date IS NULL OR renewal_date >= CURDATE())")->fetchColumn();
      $trial = (int)$conn->query("SELECT COUNT(*) FROM users WHERE subscription_status='trial' AND (trial_end_date IS NULL OR trial_end_date >= CURDATE())")->fetchColumn();
      $expired = (int)$conn->query("SELECT COUNT(*) FROM users WHERE subscription_status='expired' OR subscription_status='trial' AND trial_end_date < CURDATE()")->fetchColumn();
      $monthlyRev = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM subscription_payments WHERE payment_status='success' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
      echo json_encode(['success'=>true, 'stats'=>['active'=>$active, 'trial'=>$trial, 'expired'=>$expired, 'monthly_revenue'=>$monthlyRev]]);
      break;

break;

    case 'getPhonePeMode':
      $mode = $_SESSION['phonepe_mode'] ?? 'demo';
      echo json_encode(['success' => true, 'mode' => $mode]);
      break;

    case 'setPhonePeMode':
      $mode = $_POST['mode'] ?? $_GET['mode'] ?? '';
      if (!in_array($mode, ['demo', 'sandbox', 'production'])) {
        throw new Exception('Invalid mode. Use: demo, sandbox, or production');
      }
      $_SESSION['phonepe_mode'] = $mode;
      echo json_encode(['success' => true, 'mode' => $mode]);
      break;

    case 'getPaymentLinks':
      $restaurant_id = trim($_GET['restaurant_id'] ?? '');
      $status = trim($_GET['status'] ?? '');
      $page = max(1, (int)($_GET['page'] ?? 1));
      $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
      $offset = ($page - 1) * $limit;

      $where = '';
      $params = [];
      if ($restaurant_id !== '') {
        $where .= " AND pl.restaurant_id = :rid";
        $params[':rid'] = $restaurant_id;
      }
      if ($status !== '') {
        $where .= " AND pl.payment_status = :status";
        $params[':status'] = $status;
      }

      $sql = "SELECT pl.*, u.restaurant_name FROM payment_links pl LEFT JOIN users u ON u.restaurant_id = pl.restaurant_id WHERE 1=1 $where ORDER BY pl.created_at DESC LIMIT :limit OFFSET :offset";
      $stmt = $conn->prepare($sql);
      foreach ($params as $k=>$v) $stmt->bindValue($k, $v);
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();

      $countSql = "SELECT COUNT(*) FROM payment_links pl WHERE 1=1 $where";
      $countStmt = $conn->prepare($countSql);
      foreach ($params as $k=>$v) $countStmt->bindValue($k, $v);
      $countStmt->execute();
      $total = (int)$countStmt->fetchColumn();

      echo json_encode(['success' => true, 'links' => $stmt->fetchAll(), 'total' => $total]);
      break;

    case 'createPaymentLink':
      $data = $_POST;
      $restaurant_id = trim($data['restaurant_id'] ?? '');
      $amount = (float)($data['amount'] ?? 0);
      $notes = trim($data['notes'] ?? '');

      if (!$restaurant_id || $amount <= 0) {
        throw new Exception('Restaurant ID and valid amount required');
      }

      // Get restaurant info
      $stmt = $conn->prepare("SELECT restaurant_name, phonepe_merchant_id, phonepe_salt_key, phonepe_environment FROM users WHERE restaurant_id = ? LIMIT 1");
      $stmt->execute([$restaurant_id]);
      $restaurant = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$restaurant) {
        throw new Exception('Restaurant not found');
      }

      $restaurant_name = $restaurant['restaurant_name'] ?? $restaurant_id;

      // Load env for PhonePe global config
      if (file_exists(__DIR__ . '/../config/env_loader.php')) {
          require_once __DIR__ . '/../config/env_loader.php';
      }

      // Determine which environment to use (session toggle overrides everything)
      $session_mode = $_SESSION['phonepe_mode'] ?? '';
      $isSandbox = $session_mode === 'sandbox';
      $isDemo = $session_mode === 'demo';

      // Pick credentials based on environment
      if ($isSandbox) {
        $phonepe_client_id = env('PHONEPE_SANDBOX_MERCHANT_ID', '');
        $phonepe_client_secret = env('PHONEPE_SANDBOX_SALT_KEY', '');
        $phonepe_client_version = env('PHONEPE_SANDBOX_SALT_INDEX', '1');
      } else {
        $phonepe_client_id = env('PHONEPE_CLIENT_ID', '');
        $phonepe_client_secret = env('PHONEPE_CLIENT_SECRET', '');
        $phonepe_client_version = env('PHONEPE_CLIENT_VERSION', '');
        if (empty($phonepe_client_id) || empty($phonepe_client_secret)) {
          $phonepe_client_id = !empty($restaurant['phonepe_merchant_id']) && $restaurant['phonepe_merchant_id'] !== 'YOUR_MERCHANT_ID'
              ? $restaurant['phonepe_merchant_id']
              : (defined('PHONEPE_MERCHANT_ID') ? PHONEPE_MERCHANT_ID : env('PHONEPE_MERCHANT_ID', ''));
          $phonepe_client_secret = !empty($restaurant['phonepe_salt_key']) && strpos($restaurant['phonepe_salt_key'], 'your_salt_key') === false
              ? $restaurant['phonepe_salt_key']
              : (defined('PHONEPE_SALT_KEY') ? PHONEPE_SALT_KEY : env('PHONEPE_SALT_KEY', ''));
          $phonepe_client_version = env('PHONEPE_SALT_INDEX', '1');
        }
      }

      $demo_mode = $isDemo || empty($phonepe_client_id) || empty($phonepe_client_secret);

      $phonepe_base_url = $isSandbox
          ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
          : 'https://api.phonepe.com/apis/pg';
      $phonepe_pay_url = $phonepe_base_url . '/pg/v1/pay';
      $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $base_path = dirname(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
      if ($base_path === '/' || $base_path === '\\') $base_path = '';
      $site_url = $scheme . '://' . $host . $base_path;
      $redirect_url = $site_url . '/main/superadmin/payment_link_redirect.php';
      $callback_url = $site_url . '/main/superadmin/payment_link_callback.php';

      // Generate transaction ID
      $transaction_id = 'SPL_' . strtoupper(substr($restaurant_id, 0, 6)) . '_' . time() . '_' . rand(100, 999);

      // Save pending payment link record
      $stmt = $conn->prepare("INSERT INTO payment_links (restaurant_id, restaurant_name, amount, transaction_id, payment_status, notes, created_by) VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
      $stmt->execute([$restaurant_id, $restaurant_name, $amount, $transaction_id, $notes, $_SESSION['superadmin_id'] ?? null]);
      $payment_link_id = $conn->lastInsertId();

      if ($demo_mode) {
        // Demo mode - simulate payment link
        $demo_payment_url = $redirect_url . '?demo=1&transaction_id=' . urlencode($transaction_id) . '&amount=' . urlencode($amount);

        $stmt = $conn->prepare("UPDATE payment_links SET payment_url = ?, payment_status = 'Pending' WHERE id = ?");
        $stmt->execute([$demo_payment_url, $payment_link_id]);

        echo json_encode([
          'success' => true,
          'message' => 'Demo payment link created',
          'payment_link_id' => $payment_link_id,
          'payment_url' => $demo_payment_url,
          'transaction_id' => $transaction_id,
          'demo_mode' => true
        ]);
        break;
      }

      // Real PhonePe API integration
      if ($isSandbox) {
        // Sandbox: use v2 OAuth (same base for auth + checkout, Developer Settings credentials)
        $auth_url = $phonepe_base_url . '/v1/oauth/token';
        $ch = curl_init($auth_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
          'client_id' => $phonepe_client_id,
          'client_version' => $phonepe_client_version,
          'client_secret' => $phonepe_client_secret,
          'grant_type' => 'client_credentials',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $auth_resp = curl_exec($ch);
        $auth_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $auth_err = curl_error($ch);
        curl_close($ch);

        if ($auth_err) throw new Exception('Auth connection error: ' . $auth_err);
        $auth_data = json_decode($auth_resp, true);
        if ($auth_http !== 200 || !isset($auth_data['access_token'])) {
          throw new Exception('Auth error: ' . ($auth_data['message'] ?? 'HTTP ' . $auth_http));
        }

        // Create checkout session
        $pay_url = $phonepe_base_url . '/checkout/v2/pay';
        $payload = [
          'merchantOrderId' => $transaction_id,
          'amount' => (int)($amount * 100),
          'expireAfter' => 1200,
          'callbackUrl' => $callback_url,
          'paymentFlow' => ['type' => 'PG_CHECKOUT', 'merchantUrls' => ['redirectUrl' => $redirect_url]],
        ];

        $ch = curl_init($pay_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
          'Content-Type: application/json',
          'Authorization: O-Bearer ' . $auth_data['access_token'],
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $resp_data = json_decode($resp, true);
        if ($http === 200 && isset($resp_data['redirectUrl'])) {
          $payment_url = $resp_data['redirectUrl'];
          $phonepe_order_id = $resp_data['orderId'] ?? '';
        } else {
          $msg = $resp_data['message'] ?? ($resp_data['code'] ?? 'unknown');
          throw new Exception('Sandbox API error (HTTP ' . $http . '): ' . $msg);
        }
      } else {
        // Production: use v1 hash-based API
        $payload = [
          'merchantId' => $phonepe_client_id,
          'merchantTransactionId' => $transaction_id,
          'merchantUserId' => 'SUPERADMIN',
          'amount' => (int)($amount * 100),
          'redirectUrl' => $redirect_url,
          'redirectMode' => 'GET',
          'callbackUrl' => $callback_url,
          'paymentInstrument' => ['type' => 'PAY_PAGE'],
        ];

        $base64_payload = base64_encode(json_encode($payload));
        $x_verify = hash('sha256', $base64_payload . '/pg/v1/pay' . $phonepe_client_secret) . '###' . $phonepe_client_version;

        $ch = curl_init($phonepe_pay_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['request' => $base64_payload]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-VERIFY: ' . $x_verify]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err = curl_error($ch);
        curl_close($ch);

        if ($curl_err) throw new Exception('Connection error: ' . $curl_err);
        $resp_data = json_decode($resp, true);
        if ($http === 200 && isset($resp_data['success']) && $resp_data['success'] === true) {
          $payment_url = $resp_data['data']['instrumentResponse']['redirectInfo']['url'] ?? null;
          $phonepe_order_id = $resp_data['data']['merchantTransactionId'] ?? '';
          if (!$payment_url) throw new Exception('No redirect URL in response');
        } else {
          $msg = $resp_data['message'] ?? ($resp_data['code'] ?? 'unknown');
          if (!empty($resp_data['data'])) $msg .= ' | ' . json_encode($resp_data['data']);
          throw new Exception('Prod API error (HTTP ' . $http . '): ' . $msg);
        }
      }

      $our_payment_url = $redirect_url . '?id=' . $payment_link_id;
      $phonepe_checkout_url = $payment_url;
      $stmt = $conn->prepare("UPDATE payment_links SET payment_url = ?, phonepe_checkout_url = ?, phonepe_order_id = ?, payment_status = 'Pending' WHERE id = ?");
      $stmt->execute([$our_payment_url, $phonepe_checkout_url, $phonepe_order_id, $payment_link_id]);

      echo json_encode([
        'success' => true,
        'message' => 'Payment link created successfully',
        'payment_link_id' => $payment_link_id,
        'payment_url' => $our_payment_url,
        'transaction_id' => $transaction_id,
        'demo_mode' => false
      ]);
      break;

    case 'updatePaymentLinkStatus':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $id = (int)($data['id'] ?? 0);
      $status = $data['status'] ?? '';
      if ($id <= 0 || !in_array($status, ['Success', 'Failed'])) throw new Exception('Invalid params');
      $stmt = $conn->prepare("UPDATE payment_links SET payment_status = ? WHERE id = ? AND payment_status = 'Pending'");
      $stmt->execute([$status, $id]);
      if ($stmt->rowCount() === 0) throw new Exception('Link not found or already processed');
      echo json_encode(['success' => true, 'message' => 'Status updated to ' . $status]);
      break;

    case 'refreshPaymentLinkStatus':
      $id = (int)($_GET['id'] ?? 0);
      $code = $_GET['code'] ?? '';
      if ($id <= 0) throw new Exception('Invalid ID');

      $stmt = $conn->prepare("SELECT * FROM payment_links WHERE id = ?");
      $stmt->execute([$id]);
      $link = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$link) throw new Exception('Payment link not found');

      if ($link['payment_status'] !== 'Pending') {
        echo json_encode(['success' => true, 'payment_status' => $link['payment_status'], 'message' => 'Already ' . $link['payment_status']]);
        break;
      }

      // Auto-confirm from redirect code (works for both sandbox and production)
      if ($code === 'PAYMENT_SUCCESS') {
        $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Success' WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true, 'payment_status' => 'Success', 'message' => 'Payment confirmed via redirect!']);
        break;
      }

      if (file_exists(__DIR__ . '/../config/env_loader.php')) {
          require_once __DIR__ . '/../config/env_loader.php';
      }

      $stmt = $conn->prepare("SELECT phonepe_merchant_id, phonepe_salt_key, phonepe_environment FROM users WHERE restaurant_id = ? LIMIT 1");
      $stmt->execute([$link['restaurant_id']]);
      $rest = $stmt->fetch(PDO::FETCH_ASSOC);

      $session_mode = $_SESSION['phonepe_mode'] ?? '';
      $isSandbox = $session_mode === 'sandbox';

      if ($isSandbox) {
        $client_id = env('PHONEPE_SANDBOX_MERCHANT_ID', '');
        $client_secret = env('PHONEPE_SANDBOX_SALT_KEY', '');
        $client_version = env('PHONEPE_SANDBOX_SALT_INDEX', '1');
      } else {
        $client_id = env('PHONEPE_CLIENT_ID', '');
        $client_secret = env('PHONEPE_CLIENT_SECRET', '');
        $client_version = env('PHONEPE_CLIENT_VERSION', '');
        if (empty($client_id) || empty($client_secret)) {
          $client_id = !empty($rest['phonepe_merchant_id']) && $rest['phonepe_merchant_id'] !== 'YOUR_MERCHANT_ID'
              ? $rest['phonepe_merchant_id'] : env('PHONEPE_MERCHANT_ID', '');
          $client_secret = !empty($rest['phonepe_salt_key']) && strpos($rest['phonepe_salt_key'], 'your_salt_key') === false
              ? $rest['phonepe_salt_key'] : env('PHONEPE_SALT_KEY', '');
          $client_version = env('PHONEPE_SALT_INDEX', '1');
        }
      }

      $demo_mode = $session_mode === 'demo' || empty($client_id) || empty($client_secret);

      if ($demo_mode) {
        echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Demo mode - status remains Pending']);
        break;
      }

      $phonepe_base_url = $isSandbox
          ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
          : 'https://api.phonepe.com/apis/pg';
      $txn_id = $link['transaction_id'];
      $pp_order_id = $link['phonepe_order_id'] ?? '';

      if ($isSandbox) {
        // v2 OAuth status check
        $auth_url = $phonepe_base_url . '/v1/oauth/token';
        $ch = curl_init($auth_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
          'client_id' => $client_id,
          'client_version' => $client_version,
          'client_secret' => $client_secret,
          'grant_type' => 'client_credentials',
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $auth_resp = curl_exec($ch);
        $auth_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($auth_http !== 200 || !($auth_data = json_decode($auth_resp, true)) || !isset($auth_data['access_token'])) {
          echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Could not authenticate with sandbox API']);
          break;
        }

        // Use PhonePe's orderId if available, else fall back to transaction_id
        $lookup_id = !empty($pp_order_id) ? $pp_order_id : $txn_id;
        $status_url = $phonepe_base_url . '/checkout/v2/order/' . urlencode($lookup_id) . '/status';
        $ch = curl_init($status_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
          'Authorization: O-Bearer ' . $auth_data['access_token'],
          'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $resp) {
          $status_data = json_decode($resp, true);
          $state = $status_data['state'] ?? $status_data['orderStatus'] ?? $status_data['responseCode'] ?? '';
          if (in_array($state, ['COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS'])) {
            $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Success' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'payment_status' => 'Success', 'message' => 'Payment confirmed!']);
          } elseif (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'PAYMENT_FAILED', 'PAYMENT_ERROR'])) {
            $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Failed' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'payment_status' => 'Failed', 'message' => 'Payment failed']);
          } else {
            echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Payment still pending', 'api_status' => $state]);
          }
        } else {
          echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Could not verify status (HTTP: ' . $http_code . ')']);
        }
      } else {
        // Production: v1 hash-based status check
        $status_url = $phonepe_base_url . '/pg/v1/status/' . urlencode($client_id) . '/' . urlencode($txn_id);
        $x_verify = hash('sha256', '/pg/v1/status/' . $client_id . '/' . $txn_id . $client_secret) . '###' . $client_version;

        $ch = curl_init($status_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
          'X-VERIFY: ' . $x_verify,
          'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200 && $response) {
          $status_data = json_decode($response, true);
          $state = $status_data['state'] ?? $status_data['code'] ?? '';

          if ($status_data['success'] === true && ($state === 'COMPLETED' || $state === 'SUCCESS' || $state === 'PAYMENT_SUCCESS')) {
            $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Success' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'payment_status' => 'Success', 'message' => 'Payment confirmed!']);
          } elseif ($status_data['success'] === false || in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'PAYMENT_FAILED', 'PAYMENT_ERROR'])) {
            $stmt = $conn->prepare("UPDATE payment_links SET payment_status = 'Failed' WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'payment_status' => 'Failed', 'message' => 'Payment failed']);
          } else {
            echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Payment still pending', 'api_status' => $state]);
          }
        } else {
          echo json_encode(['success' => true, 'payment_status' => 'Pending', 'message' => 'Could not verify status (HTTP: ' . $http_code . ')']);
        }
      }
      break;

    case 'getMenus':
      $restaurant_id = trim($_GET['restaurant_id'] ?? '');
      if (!$restaurant_id) throw new Exception('restaurant_id required');
      try { $conn->query("SELECT id FROM menu LIMIT 1"); } catch (PDOException $e) {
        try { $conn->exec("CREATE TABLE IF NOT EXISTS menu (
          id INT AUTO_INCREMENT PRIMARY KEY,
          restaurant_id VARCHAR(10) NOT NULL,
          menu_name VARCHAR(100) NOT NULL,
          is_active TINYINT(1) DEFAULT 1,
          sort_order INT DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY unique_menu_per_restaurant (restaurant_id, menu_name),
          INDEX idx_restaurant_id (restaurant_id)
        )"); } catch (PDOException $e2) {}
      }
      $stmt = $conn->prepare("SELECT id, menu_name, is_active, sort_order FROM menu WHERE restaurant_id = :rid ORDER BY sort_order ASC, menu_name ASC");
      $stmt->execute([':rid' => $restaurant_id]);
      $menus = $stmt->fetchAll();

      // Fetch subcategories for each menu
      if (count($menus) > 0) {
        $menuIds = array_column($menus, 'id');
        $placeholders = implode(',', array_fill(0, count($menuIds), '?'));
        $subStmt = $conn->prepare("SELECT id, menu_id, subcategory_name, sort_order FROM subcategories WHERE menu_id IN ($placeholders) ORDER BY sort_order ASC, subcategory_name ASC");
        $subStmt->execute($menuIds);
        $subcategories = $subStmt->fetchAll();
        $subByMenu = [];
        foreach ($subcategories as $sub) {
          $subByMenu[$sub['menu_id']][] = $sub;
        }
        foreach ($menus as &$menu) {
          $menu['subcategories'] = $subByMenu[$menu['id']] ?? [];
        }
      }

      echo json_encode(['success' => true, 'menus' => $menus]);
      break;

    case 'saveMenus':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $restaurant_id = trim($data['restaurant_id'] ?? '');
      $categories = $data['categories'] ?? [];
      if (!$restaurant_id || !is_array($categories)) throw new Exception('Invalid payload');

      try { $conn->query("SELECT id FROM menu LIMIT 1"); } catch (PDOException $e) {
        try { $conn->exec("CREATE TABLE IF NOT EXISTS menu (
          id INT AUTO_INCREMENT PRIMARY KEY,
          restaurant_id VARCHAR(10) NOT NULL,
          menu_name VARCHAR(100) NOT NULL,
          is_active TINYINT(1) DEFAULT 1,
          sort_order INT DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY unique_menu_per_restaurant (restaurant_id, menu_name),
          INDEX idx_restaurant_id (restaurant_id)
        )"); } catch (PDOException $e2) {}
      }

      $conn->beginTransaction();
      try {
        $conn->prepare("DELETE FROM menu WHERE restaurant_id = :rid")->execute([':rid' => $restaurant_id]);
        $stmt = $conn->prepare("INSERT INTO menu (restaurant_id, menu_name, sort_order) VALUES (:rid, :name, :sort)");
        foreach ($categories as $i => $name) {
          $name = trim($name);
          if ($name !== '') {
            $stmt->execute([':rid' => $restaurant_id, ':name' => $name, ':sort' => $i]);
          }
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Menu categories saved']);
      } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
      }
      break;

    case 'saveSubcategories':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $menu_id = (int)($data['menu_id'] ?? 0);
      $subcategories = $data['subcategories'] ?? [];
      if (!$menu_id || !is_array($subcategories)) throw new Exception('Invalid payload');

      try { $conn->query("SELECT id FROM subcategories LIMIT 1"); } catch (PDOException $e) {
        try {
          $conn->exec("CREATE TABLE IF NOT EXISTS subcategories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_id INT NOT NULL,
            subcategory_name VARCHAR(100) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (menu_id) REFERENCES menu(id) ON DELETE CASCADE,
            INDEX idx_menu_id (menu_id),
            INDEX idx_sort_order (sort_order),
            UNIQUE KEY unique_subcategory_per_menu (menu_id, subcategory_name)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e2) {}
      }

      $conn->beginTransaction();
      try {
        $conn->prepare("DELETE FROM subcategories WHERE menu_id = :mid")->execute([':mid' => $menu_id]);
        $stmt = $conn->prepare("INSERT INTO subcategories (menu_id, subcategory_name, sort_order) VALUES (:mid, :name, :sort)");
        foreach ($subcategories as $i => $name) {
          $name = trim($name);
          if ($name !== '') {
            $stmt->execute([':mid' => $menu_id, ':name' => $name, ':sort' => $i]);
          }
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Subcategories saved']);
      } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
      }
      break;

    case 'saveFullMenu':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $restaurant_id = trim($data['restaurant_id'] ?? '');
      $text = trim($data['text'] ?? '');
      if (!$restaurant_id || $text === '') throw new Exception('Invalid payload');

      // Ensure tables exist
      try { $conn->query("SELECT id FROM menu LIMIT 1"); } catch (PDOException $e) {
        try { $conn->exec("CREATE TABLE IF NOT EXISTS menu (
          id INT AUTO_INCREMENT PRIMARY KEY,
          restaurant_id VARCHAR(10) NOT NULL,
          menu_name VARCHAR(100) NOT NULL,
          is_active TINYINT(1) DEFAULT 1,
          sort_order INT DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          UNIQUE KEY unique_menu_per_restaurant (restaurant_id, menu_name),
          INDEX idx_restaurant_id (restaurant_id)
        )"); } catch (PDOException $e2) {}
      }
      try { $conn->query("SELECT id FROM subcategories LIMIT 1"); } catch (PDOException $e) {
        try {
          $conn->exec("CREATE TABLE IF NOT EXISTS subcategories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_id INT NOT NULL,
            subcategory_name VARCHAR(100) NOT NULL,
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (menu_id) REFERENCES menu(id) ON DELETE CASCADE,
            INDEX idx_menu_id (menu_id),
            INDEX idx_sort_order (sort_order),
            UNIQUE KEY unique_subcategory_per_menu (menu_id, subcategory_name)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e2) {}
      }

      // Parse comma-separated format: "Pizza (Margherita, Pepperoni), Burger, Beverages"
      preg_match_all('/(?:[^,(]|\([^)]*\))+/', $text, $matches);
      $rawItems = array_map('trim', $matches[0]);
      $items = [];
      foreach ($rawItems as $raw) {
        if ($raw === '') continue;
        if (preg_match('/^(.+?)\s*\((.+)\)\s*$/', $raw, $m)) {
          $category = trim($m[1]);
          $subcategories = array_map('trim', explode(',', $m[2]));
          $subcategories = array_values(array_filter($subcategories, fn($s) => $s !== ''));
          $items[] = ['name' => $category, 'subcategories' => $subcategories];
        } else {
          $items[] = ['name' => $raw, 'subcategories' => []];
        }
      }

      if (empty($items)) throw new Exception('No valid categories found');

      $conn->beginTransaction();
      try {
        $conn->prepare("DELETE FROM menu WHERE restaurant_id = :rid")->execute([':rid' => $restaurant_id]);
        $menuStmt = $conn->prepare("INSERT INTO menu (restaurant_id, menu_name, sort_order) VALUES (:rid, :name, :sort)");
        $subStmt = $conn->prepare("INSERT INTO subcategories (menu_id, subcategory_name, sort_order) VALUES (:mid, :name, :sort)");
        foreach ($items as $i => $item) {
          $menuStmt->execute([':rid' => $restaurant_id, ':name' => $item['name'], ':sort' => $i]);
          $menuId = $conn->lastInsertId();
          foreach ($item['subcategories'] as $j => $sub) {
            $subStmt->execute([':mid' => $menuId, ':name' => $sub, ':sort' => $j]);
          }
        }
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Menu categories and subcategories saved']);
      } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
      }
      break;

    case 'saveMenuItems':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $restaurant_id = trim($data['restaurant_id'] ?? '');
      $text = trim($data['text'] ?? '');
      $mode = $data['mode'] ?? 'update';
      if (!$restaurant_id || $text === '') throw new Exception('Invalid payload');
      if (!in_array($mode, ['replace', 'update'])) $mode = 'update';

      // Ensure menu_items table exists
      try { $conn->query("SELECT id FROM menu_items LIMIT 1"); } catch (PDOException $e) {
        try {
          $conn->exec("CREATE TABLE IF NOT EXISTS menu_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(10) NOT NULL,
            menu_id INT NOT NULL,
            item_name_en VARCHAR(200) NOT NULL,
            item_description_en TEXT,
            item_category VARCHAR(100),
            subcategory_id INT DEFAULT NULL,
            item_type ENUM('Veg','Non Veg','Egg','Drink','Dessert','Other') DEFAULT 'Veg',
            preparation_time INT DEFAULT 0,
            calories INT DEFAULT 0,
            is_available BOOLEAN DEFAULT TRUE,
            base_price DECIMAL(10,2) DEFAULT 0.00,
            has_variations BOOLEAN DEFAULT FALSE,
            item_image VARCHAR(500),
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (menu_id) REFERENCES menu(id) ON DELETE CASCADE,
            INDEX idx_restaurant_id (restaurant_id),
            INDEX idx_menu_id (menu_id)
          )");
        } catch (PDOException $e2) {}
      }
      // Ensure menu_item_variations table exists
      try { $conn->query("SELECT id FROM menu_item_variations LIMIT 1"); } catch (PDOException $e) {
        try {
          $conn->exec("CREATE TABLE IF NOT EXISTS menu_item_variations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            menu_item_id INT NOT NULL,
            variation_name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sort_order INT DEFAULT 0,
            is_available BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE CASCADE,
            UNIQUE KEY unique_variation_per_item (menu_item_id, variation_name)
          )");
        } catch (PDOException $e2) {}
      }

      // Parse lines: Category[ > Subcategory] | Item Name | Price | Type
      $lines = explode("\n", $text);
      $inserted = 0;
      $errors = [];

      // Cache menu + subcategory lookups
      $menuCache = []; // name => id
      $subCache = []; // menuId_name => id
      $createdCats = 0;
      $createdSubs = 0;

      $menuFindStmt = $conn->prepare("SELECT id FROM menu WHERE restaurant_id = :rid AND menu_name = :name");
      $menuCreateStmt = $conn->prepare("INSERT INTO menu (restaurant_id, menu_name, sort_order) VALUES (:rid, :name, :sort)");
      $subFindStmt = $conn->prepare("SELECT s.id FROM subcategories s JOIN menu m ON s.menu_id = m.id WHERE m.restaurant_id = :rid AND m.menu_name = :mname AND s.subcategory_name = :sname");
      $subCreateStmt = $conn->prepare("INSERT INTO subcategories (menu_id, subcategory_name, sort_order) VALUES (:mid, :name, :sort)");
      $insertStmt = $conn->prepare("INSERT INTO menu_items (restaurant_id, menu_id, subcategory_id, item_name_en, item_description_en, base_price, item_type, preparation_time, calories, is_available, has_variations, item_image, sort_order) VALUES (:rid, :mid, :sid, :name, :desc, :price, :type, :prep, :cal, :stock, :hvar, :iimg, :sort)");
      $varInsertStmt = $conn->prepare("INSERT INTO menu_item_variations (menu_item_id, variation_name, price, sort_order) VALUES (:iid, :vname, :vprice, :vsort)");
      $findItemStmt = $conn->prepare("SELECT id FROM menu_items WHERE restaurant_id = :rid AND menu_id = :mid AND item_name_en = :name");
      $updateItemStmt = $conn->prepare("UPDATE menu_items SET subcategory_id = :sid, item_description_en = :desc, base_price = :price, item_type = :type, preparation_time = :prep, calories = :cal, is_available = :stock, has_variations = :hvar, item_image = :iimg WHERE id = :iid");

      if ($mode === 'replace') {
        // Full replace: delete all menu (cascades to subcategories and items)
        $conn->exec("DELETE FROM menu WHERE restaurant_id = " . $conn->quote($restaurant_id));
      }

      $conn->beginTransaction();
      try {
        foreach ($lines as $i => $line) {
          $line = trim($line);
          if ($line === '') continue;
          $parts = array_map('trim', explode('|', $line));
          if (count($parts) < 2) { continue; }

          $catPart = $parts[0];
          $itemName = $parts[1];
          $price = isset($parts[2]) ? floatval(str_replace(['₹', ','], '', $parts[2])) : 0;
          $type = isset($parts[3]) ? ucfirst(strtolower(trim($parts[3]))) : 'Veg';
          $validTypes = ['Veg', 'Non Veg', 'Egg', 'Drink', 'Dessert', 'Other'];
          if (!in_array($type, $validTypes)) $type = 'Veg';
          $description = $parts[4] ?? '';
          $prepTime = isset($parts[5]) ? intval($parts[5]) : 0;
          $calories = isset($parts[6]) ? intval($parts[6]) : 0;
          $stock = isset($parts[7]) ? (intval($parts[7]) === 1 ? 1 : 0) : 1;
          $variationsRaw = $parts[8] ?? '';
          $parsedVariations = [];
          if ($variationsRaw) {
            foreach (explode(',', $variationsRaw) as $v) {
              $vp = array_map('trim', explode(':', $v, 2));
              if (count($vp) === 2 && $vp[0] !== '') {
                $parsedVariations[] = ['name' => $vp[0], 'price' => floatval(str_replace(['₹', ','], '', $vp[1]))];
              }
            }
          }
          $hasVariations = !empty($parsedVariations) ? 1 : 0;
          $imageUrl = $parts[9] ?? '';

          // Parse category[ > subcategory]
          $category = $catPart;
          $subcategoryName = null;
          if (preg_match('/^(.+?)\s*>\s*(.+)$/', $catPart, $m)) {
            $category = trim($m[1]);
            $subcategoryName = trim($m[2]);
            // Skip redundant subcategory if it matches the category name
            if (strcasecmp($subcategoryName, $category) === 0) $subcategoryName = null;
          }

          // Resolve or auto-create menu_id
          if (!isset($menuCache[$category])) {
            $menuFindStmt->execute([':rid' => $restaurant_id, ':name' => $category]);
            $menuRow = $menuFindStmt->fetch();
            if ($menuRow) {
              $menuCache[$category] = $menuRow['id'];
            } else {
              $menuCreateStmt->execute([':rid' => $restaurant_id, ':name' => $category, ':sort' => 0]);
              $menuCache[$category] = $conn->lastInsertId();
              $createdCats++;
            }
          }
          $menuId = $menuCache[$category];

          // Resolve or auto-create subcategory_id
          $subcategoryId = null;
          if ($subcategoryName) {
            $subKey = $menuId . '_' . $subcategoryName;
            if (!isset($subCache[$subKey])) {
              $subFindStmt->execute([':rid' => $restaurant_id, ':mname' => $category, ':sname' => $subcategoryName]);
              $subRow = $subFindStmt->fetch();
              if ($subRow) {
                $subCache[$subKey] = $subRow['id'];
              } else {
                $subCreateStmt->execute([':mid' => $menuId, ':name' => $subcategoryName, ':sort' => 0]);
                $subCache[$subKey] = $conn->lastInsertId();
                $createdSubs++;
              }
            }
            $subcategoryId = $subCache[$subKey];
          }

          if ($mode === 'update') {
            $findItemStmt->execute([':rid' => $restaurant_id, ':mid' => $menuId, ':name' => $itemName]);
            $existingItem = $findItemStmt->fetch();
          } else {
            $existingItem = false;
          }

          if ($existingItem) {
            $itemId = $existingItem['id'];
            $updateItemStmt->execute([
              ':sid' => $subcategoryId,
              ':desc' => $description,
              ':price' => $price,
              ':type' => $type,
              ':prep' => $prepTime,
              ':cal' => $calories,
              ':stock' => $stock,
              ':hvar' => $hasVariations,
              ':iimg' => $imageUrl ?: null,
              ':iid' => $itemId
            ]);
            // Delete old variations and re-insert
            $conn->prepare("DELETE FROM menu_item_variations WHERE menu_item_id = :iid")->execute([':iid' => $itemId]);
          } else {
            $insertStmt->execute([
              ':rid' => $restaurant_id,
              ':mid' => $menuId,
              ':sid' => $subcategoryId,
              ':name' => $itemName,
              ':desc' => $description,
              ':price' => $price,
              ':type' => $type,
              ':prep' => $prepTime,
              ':cal' => $calories,
              ':stock' => $stock,
              ':hvar' => $hasVariations,
              ':iimg' => $imageUrl ?: null,
              ':sort' => $i
            ]);
            $itemId = $conn->lastInsertId();
          }

          if ($hasVariations) {
            foreach ($parsedVariations as $vj => $v) {
              $varInsertStmt->execute([':iid' => $itemId, ':vname' => $v['name'], ':vprice' => $v['price'], ':vsort' => $vj]);
            }
          }

          $inserted++;
        }
        $conn->commit();
        $msg = "$inserted menu item(s) added successfully.";
        if ($createdCats > 0) $msg .= " ($createdCats category(s) auto-created)";
        if ($createdSubs > 0) $msg .= " ($createdSubs subcategory(s) auto-created)";
        if (!empty($errors)) $msg .= " Warnings: " . implode('; ', $errors);
        echo json_encode(['success' => true, 'message' => $msg, 'errors' => $errors]);
      } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
      }
      break;

    case 'generateMenuItemsFromImages':
      set_time_limit(120);
      require_once __DIR__ . '/../config/env_loader.php';
      $geminiApiKey = env('GEMINI_API_KEY', '');
      if (!$geminiApiKey) throw new Exception('Gemini API key is not configured. Add GEMINI_API_KEY to main/.env.');

      $restaurant_id = trim($_POST['restaurant_id'] ?? '');
      $existingCategories = trim($_POST['categories'] ?? '');
      if (!$restaurant_id) throw new Exception('restaurant_id required');

      if (empty($_FILES['images']) || empty($_FILES['images']['name'][0])) {
        throw new Exception('Please upload at least one menu photo.');
      }

      $allowedMimes = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true, 'image/gif' => true];
      $maxFileSize = 8 * 1024 * 1024; // 8MB per image
      $maxFiles = 6;

      $names = $_FILES['images']['name'];
      $tmpNames = $_FILES['images']['tmp_name'];
      $uploadErrors = $_FILES['images']['error'];
      $fileCount = count($names);
      if ($fileCount > $maxFiles) throw new Exception("Please upload at most $maxFiles photos at a time.");

      $imageParts = [];
      $finfo = finfo_open(FILEINFO_MIME_TYPE);
      for ($i = 0; $i < $fileCount; $i++) {
        if ($uploadErrors[$i] !== UPLOAD_ERR_OK) {
          throw new Exception("Upload failed for \"{$names[$i]}\" (error code {$uploadErrors[$i]}).");
        }
        if (!is_uploaded_file($tmpNames[$i])) continue;
        if (filesize($tmpNames[$i]) > $maxFileSize) {
          finfo_close($finfo);
          throw new Exception("Image \"{$names[$i]}\" is larger than 8MB.");
        }
        $mime = finfo_file($finfo, $tmpNames[$i]);
        if (!isset($allowedMimes[$mime])) {
          finfo_close($finfo);
          throw new Exception("Image \"{$names[$i]}\" must be JPG, PNG, WEBP, or GIF.");
        }
        $bytes = file_get_contents($tmpNames[$i]);
        $imageParts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
      }
      finfo_close($finfo);
      if (empty($imageParts)) throw new Exception('No valid images were uploaded.');

      $promptText = "You are a restaurant menu data extractor. Look at the attached menu photo(s) carefully and extract every dish/item you can read.\n\n"
        . "Output ONLY plain text lines in this exact pipe-delimited format, one item per line, and NOTHING else (no markdown, no code fences, no headings, no explanations, no numbering):\n\n"
        . "Category[ > Subcategory] | Item Name | Price | Type | Description | PrepTime | Calories | Stock | Variations(Name:Price) | ImageURL\n\n"
        . "RULES (strictly follow these):\n"
        . "1. SEPARATOR: Use | (pipe) between fields, never commas.\n"
        . "2. CATEGORY: Infer a sensible category from the menu section headings (e.g. Starters, Main Course, Pizza, Beverages, Desserts). Use \" > \" only if the photo actually groups items under a subheading within a category; never make the subcategory equal to the category name.\n"
        . "3. PRICE: Numbers only (e.g. 199, 12.99), no currency symbols. If a price is unreadable, put 0.\n"
        . "4. TYPE: One of exactly: Veg, Non Veg, Egg, Drink, Dessert, Other.\n"
        . "5. DESCRIPTION: Write a short realistic 5-15 word description based on the item name and type.\n"
        . "6. PREPTIME: Estimate minutes based on item type (appetizers 5-10, mains 15-25, desserts 5-10, drinks 2-5).\n"
        . "7. CALORIES: Estimate a realistic number, or leave blank if unsure.\n"
        . "8. STOCK: Always 1.\n"
        . "9. VARIATIONS: If the photo shows sizes/options with different prices, use \"Small:99,Medium:149,Large:199\" format, otherwise leave blank.\n"
        . "10. IMAGEURL: Always leave this field blank — do not invent a URL.\n"
        . "11. Extract every item visible in the photo(s). Do not invent items that are not in the photo(s).\n\n"
        . "Example correct lines:\n"
        . "Pizza > Margherita | Classic Margherita | 199 | Veg | Fresh mozzarella and basil | 15 | 350 | 1 | Small:149,Medium:199 |\n"
        . "Beverages | Coke | 49 | Drink | 330ml can | 2 | 140 | 1 | |\n";

      if ($existingCategories !== '') {
        $promptText .= "\nThese categories already exist in the system — reuse the matching one by exact name where it fits: {$existingCategories}\n";
      }

      $parts = array_merge([['text' => $promptText]], $imageParts);
      $requestBody = [
        'contents' => [['parts' => $parts]],
        'generationConfig' => [
          'temperature' => 0.4,
          'maxOutputTokens' => 16384,
          'thinkingConfig' => ['thinkingBudget' => 0]
        ]
      ];

      $geminiModel = env('GEMINI_MODEL', 'gemini-3.5-flash');
      $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$geminiModel}:generateContent";

      $ch = curl_init($geminiUrl);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestBody));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_TIMEOUT, 100);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $geminiApiKey
      ]);
      $geminiResponse = curl_exec($ch);
      $curlErr = curl_error($ch);
      $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($curlErr) throw new Exception('Could not reach the Gemini API: ' . $curlErr);
      $result = json_decode($geminiResponse, true);

      if ($httpCode !== 200) {
        $apiMsg = $result['error']['message'] ?? ('HTTP ' . $httpCode);
        throw new Exception('Gemini API error: ' . $apiMsg);
      }

      $text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
      $finishReason = $result['candidates'][0]['finishReason'] ?? '';
      if ($text === '') {
        if ($finishReason === 'SAFETY') throw new Exception('Gemini declined to process these images (safety filter). Try different photos.');
        throw new Exception('Gemini did not return any text. Please try again with clearer photos.');
      }

      // Strip markdown code fences if the model added any despite instructions
      $text = preg_replace('/^```[a-zA-Z]*\s*$/m', '', $text);
      $text = str_replace('```', '', $text);

      // Keep only lines that look like pipe-delimited item rows
      $itemLines = array_values(array_filter(array_map('trim', explode("\n", $text)), function ($l) {
        return $l !== '' && strpos($l, '|') !== false;
      }));

      if (empty($itemLines)) {
        throw new Exception('Could not detect any menu items in the uploaded photo(s). Try clearer, well-lit photos.');
      }

      echo json_encode(['success' => true, 'text' => implode("\n", $itemLines), 'count' => count($itemLines)]);
      break;

    case 'getSettlements':
      $page = max(1, (int)($_GET['page'] ?? 1));
      $limit = min(50, max(5, (int)($_GET['limit'] ?? 20)));
      $offset = ($page - 1) * $limit;
      $stmt = $conn->prepare("SELECT s.*, sa.username AS created_by_username FROM settlements s LEFT JOIN super_admins sa ON sa.id = s.created_by ORDER BY s.created_at DESC LIMIT :limit OFFSET :offset");
      $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
      $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
      $stmt->execute();
      $countStmt = $conn->query("SELECT COUNT(*) FROM settlements");
      $total = (int)$countStmt->fetchColumn();
      echo json_encode(['success' => true, 'settlements' => $stmt->fetchAll(), 'total' => $total]);
      break;

    case 'createSettlement':
      $data = json_decode(file_get_contents('php://input'), true) ?? [];
      $upi_id = trim($data['upi_id'] ?? '');
      $amount = (float)($data['amount'] ?? 0);
      $notes = trim($data['notes'] ?? '');

      if (!$upi_id || $amount <= 0) {
        throw new Exception('UPI ID and valid amount required');
      }

      // Validate UPI ID format (basic check)
      if (!preg_match('/^[\w\.\-]+@[\w\.\-]+$/', $upi_id)) {
        throw new Exception('Invalid UPI ID format');
      }

      if (file_exists(__DIR__ . '/../config/env_loader.php')) {
          require_once __DIR__ . '/../config/env_loader.php';
      }

      $session_mode = $_SESSION['phonepe_mode'] ?? '';
      $isSandbox = $session_mode === 'sandbox';
      $isDemo = $session_mode === 'demo';

      // Pick credentials based on environment
      if ($isSandbox) {
        $merchant_id = env('PHONEPE_SANDBOX_MERCHANT_ID', '');
        $salt_key = env('PHONEPE_SANDBOX_SALT_KEY', '');
        $salt_index = env('PHONEPE_SANDBOX_SALT_INDEX', '1');
      } else {
        $merchant_id = env('PHONEPE_CLIENT_ID', '');
        $salt_key = env('PHONEPE_CLIENT_SECRET', '');
        $salt_index = env('PHONEPE_CLIENT_VERSION', '1');
        if (empty($merchant_id) || empty($salt_key)) {
          $merchant_id = env('PHONEPE_MERCHANT_ID', '');
          $salt_key = env('PHONEPE_SALT_KEY', '');
          $salt_index = env('PHONEPE_SALT_INDEX', '1');
        }
      }

      $demo_mode = $isDemo || empty($merchant_id) || empty($salt_key);

      $transaction_id = 'STL_' . strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $upi_id), 0, 6)) . '_' . time() . '_' . rand(100, 999);

      // Insert pending settlement record
      $insertStmt = $conn->prepare("INSERT INTO settlements (upi_id, amount, transaction_id, status, notes, created_by) VALUES (?, ?, ?, 'Pending', ?, ?)");
      $insertStmt->execute([$upi_id, $amount, $transaction_id, $notes, $_SESSION['superadmin_id'] ?? null]);
      $settlement_id = $conn->lastInsertId();

      if ($demo_mode) {
        // Demo mode - simulate successful settlement
        $stmt = $conn->prepare("UPDATE settlements SET status = 'Success', response_data = ? WHERE id = ?");
        $stmt->execute([json_encode(['demo' => true, 'message' => 'Demo settlement simulation']), $settlement_id]);
        echo json_encode([
          'success' => true,
          'message' => 'Demo settlement processed successfully',
          'settlement_id' => $settlement_id,
          'transaction_id' => $transaction_id,
          'demo_mode' => true
        ]);
        break;
      }

      // PhonePe Payout API (v1 hash-based, similar to payment API)
      $phonepe_base_url = $isSandbox
          ? 'https://api-preprod.phonepe.com/apis/pg-sandbox'
          : 'https://api.phonepe.com/apis/pg';
      $payout_url = $phonepe_base_url . '/pg/v1/payout';

      $payout_payload = [
        'merchantId' => $merchant_id,
        'merchantTransactionId' => $transaction_id,
        'merchantUserId' => 'SUPERADMIN',
        'transferMode' => 'UPI',
        'beneficiaryDetails' => [
          'beneficiaryName' => 'Beneficiary',
          'beneficiaryAccount' => $upi_id,
          'beneficiaryIFSC' => '',
          'beneficiaryMobile' => '',
          'beneficiaryEmail' => ''
        ],
        'amount' => (int)($amount * 100),
        'purpose' => 'SETTLEMENT'
      ];

      $base64_payload = base64_encode(json_encode($payout_payload));
      $x_verify = hash('sha256', $base64_payload . '/pg/v1/payout' . $salt_key) . '###' . $salt_index;

      $ch = curl_init($payout_url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['request' => $base64_payload]));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-VERIFY: ' . $x_verify,
        'accept: application/json',
      ]);
      curl_setopt($ch, CURLOPT_TIMEOUT, 30);
      $resp = curl_exec($ch);
      $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      $curl_err = curl_error($ch);
      curl_close($ch);

      $response_data = ['http_code' => $http];
      if ($curl_err) {
        $response_data['error'] = $curl_err;
      }
      if ($resp) {
        $resp_data = json_decode($resp, true);
        $response_data['response'] = $resp_data;
      }

      // Determine success/failure
      $payout_success = false;
      $payout_message = 'Payout failed';
      $phonepe_txn_id = '';

      if (!$curl_err && $http === 200 && $resp) {
        $resp_data = json_decode($resp, true);
        if (isset($resp_data['success']) && $resp_data['success'] === true) {
          $payout_success = true;
          $payout_message = 'Payout successful';
          $phonepe_txn_id = $resp_data['data']['transactionId'] ?? $resp_data['data']['merchantTransactionId'] ?? '';
        } else {
          $payout_message = $resp_data['message'] ?? ($resp_data['code'] ?? 'Payout API error');
        }
      } elseif ($curl_err) {
        $payout_message = 'Connection error: ' . $curl_err;
      } else {
        $payout_message = 'Payout API returned HTTP ' . $http;
      }

      $new_status = $payout_success ? 'Success' : 'Failed';
      $updateStmt = $conn->prepare("UPDATE settlements SET status = ?, phonepe_transaction_id = ?, response_data = ? WHERE id = ?");
      $updateStmt->execute([$new_status, $phonepe_txn_id, json_encode($response_data), $settlement_id]);

      echo json_encode([
        'success' => $payout_success,
        'message' => $payout_message,
        'settlement_id' => $settlement_id,
        'transaction_id' => $transaction_id,
        'status' => $new_status,
        'phonepe_transaction_id' => $phonepe_txn_id,
        'demo_mode' => false
      ]);
      break;

    default:
      echo json_encode(['success' => false, 'message' => 'Invalid action']);
  }
} catch (Exception $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


