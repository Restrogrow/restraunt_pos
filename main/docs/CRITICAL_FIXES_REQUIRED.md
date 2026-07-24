# 🚨 CRITICAL FIXES REQUIRED BEFORE PRODUCTION

## Immediate Actions (Do These First!)

### 1. 🔴 REMOVE HARDCODED DATABASE CREDENTIALS
**Files to Fix:** 10+ API files
- `api/get_dashboard_stats.php`
- `api/get_menu_items.php`
- `api/get_reservations.php`
- `api/get_payment_methods.php`
- `api/manage_payment_methods.php`
- And more...

**Action:**
```php
// Replace this pattern:
$host = 'localhost';
$dbname = 'restro2';
$username = 'root';
$password = '';

// With:
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'restro2';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';
```

**Create `.env` file:**
```
DB_HOST=localhost
DB_NAME=restro2
DB_USER=your_secure_user
DB_PASS=your_strong_password
```

**Add to `.gitignore`:**
```
.env
```

---

### 2. 🔴 FIX PATH TRAVERSAL VULNERABILITY
**File:** `api/image.php`

**Current Code (VULNERABLE):**
```php
if (strpos($imagePath, 'uploads/') !== 0 && strpos($imagePath, '../uploads/') !== 0) {
    http_response_code(403);
    exit('Access denied');
}
```

**Fixed Code:**
```php
$imagePath = $_GET['path'] ?? '';
$normalizedPath = basename($imagePath); // Remove directory traversal
$fullPath = __DIR__ . '/../uploads/' . $normalizedPath;

// Validate it's actually in uploads directory
$realPath = realpath($fullPath);
$uploadsDir = realpath(__DIR__ . '/../uploads/');

if (!$realPath || strpos($realPath, $uploadsDir) !== 0) {
    http_response_code(403);
    exit('Access denied');
}
```

---

### 3. 🔴 IMPLEMENT CSRF PROTECTION
**Create:** `config/csrf.php`
```php
<?php
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}
?>
```

**Add to all forms:**
```html
<input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
```

**Validate in all POST handlers:**
```php
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit();
}
```

---

### 4. 🔴 IMPROVE SESSION SECURITY
**Add to all files with `session_start()`:**
```php
// Before session_start()
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1); // Only if using HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
session_start();
```

**After successful login (in `admin/auth.php`):**
```php
session_regenerate_id(true); // Add this after login
```

---

### 5. 🟡 STRENGTHEN PASSWORD POLICY
**File:** `admin/auth.php` and `controllers/staff_operations.php`

**Current:** Minimum 6 characters
**Change to:** Minimum 8 characters + complexity

```php
function validatePassword($password) {
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        throw new Exception('Password must contain at least one uppercase letter');
    }
    if (!preg_match('/[a-z]/', $password)) {
        throw new Exception('Password must contain at least one lowercase letter');
    }
    if (!preg_match('/[0-9]/', $password)) {
        throw new Exception('Password must contain at least one number');
    }
    return true;
}
```

---

## ✅ GOOD NEWS - These Are Already Secure!

1. ✅ **Passwords are hashed** - Using `password_hash()` correctly
2. ✅ **Passwords NEVER returned** - Not in API responses
3. ✅ **SQL Injection protected** - All queries use prepared statements
4. ✅ **XSS protected** - `escapeHtml()` function used
5. ✅ **File uploads secure** - Proper validation

---

## 📋 CHECKLIST

- [ ] Remove all hardcoded database credentials
- [ ] Create `.env` file with credentials
- [ ] Add `.env` to `.gitignore`
- [ ] Fix path traversal in `api/image.php`
- [ ] Implement CSRF protection
- [ ] Add secure session configuration
- [ ] Add `session_regenerate_id()` after login
- [ ] Strengthen password policy (8+ chars, complexity)
- [ ] Test all fixes
- [ ] Review security report

---

## ⏱️ ESTIMATED TIME

- **Critical fixes:** 2-3 hours
- **Testing:** 1-2 hours
- **Total:** 3-5 hours

---

**DO NOT GO LIVE WITHOUT FIXING THESE CRITICAL ISSUES!**

