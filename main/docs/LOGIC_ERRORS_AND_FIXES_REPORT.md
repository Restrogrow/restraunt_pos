# Logic Errors and Fixes Report
**Date:** January 2025  
**Status:** Pre-Production Code Review

---

## 🔴 CRITICAL LOGIC ERRORS

### 1. **Order/KOT Number Generation - Potential Duplicates** ✅ FIXED
**Severity:** HIGH  
**Location:** `controllers/pos_operations.php` (lines 95-97)

**Issue:**
```php
$kotNumber = 'KOT-' . date('Ymd') . '-' . rand(1000, 9999);
$orderNumber = 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
```

**Problem:**
- Uses `rand(1000, 9999)` which can generate duplicate numbers
- If multiple orders are created in the same second, there's a high chance of collision
- No database uniqueness check before insertion
- Could cause data integrity issues

**Status:** ✅ **FIXED**
- Updated `generateKOTNumber()` and `generateOrderNumber()` functions in `controllers/kot_operations.php` to check for duplicates in the database
- Functions now accept `$conn` and `$restaurant_id` parameters
- Implemented collision detection with retry logic (up to 100 attempts)
- Uses `random_int()` instead of `rand()` for better randomness
- Added fallback to timestamp-based generation if all attempts fail
- Updated all call sites:
  - `controllers/pos_operations.php` - KOT and Order number generation
  - `controllers/kot_operations.php` - All KOT and Order number generation
  - `api/process_website_order.php` - Website order number generation
  - `handleHoldOrder()` - Held order number generation with collision check

**Fix:**
```php
// Generate unique KOT number with collision check
do {
    $kotNumber = 'KOT-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM kot WHERE kot_number = ? AND restaurant_id = ?");
    $checkStmt->execute([$kotNumber, $restaurant_id]);
} while ($checkStmt->fetchColumn() > 0);

// Same for order number
do {
    $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
    $checkStmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE order_number = ? AND restaurant_id = ?");
    $checkStmt->execute([$orderNumber, $restaurant_id]);
} while ($checkStmt->fetchColumn() > 0);
```

**Files Affected:**
- `controllers/pos_operations.php` (lines 95-97)
- `api/process_website_order.php` (line 49)
- Any other file generating order/KOT numbers

---

### 2. **Race Condition in KOT Status Update** ✅ FIXED
**Severity:** MEDIUM  
**Location:** `controllers/kot_operations.php` (lines 227-262)

**Issue:**
- Multiple concurrent requests to update KOT status to "Ready" could create duplicate orders
- The check for existing orders uses fuzzy matching (amount, table, time window) which is not reliable
- No database-level locking or unique constraint

**Problem:**
```php
// This check is not atomic and can fail under concurrent requests
$check_order_sql = "SELECT o.id FROM orders o 
                    WHERE o.restaurant_id = ? 
                    AND o.table_id = ? 
                    AND ABS(o.total - ?) < 0.01
                    ...";
```

**Status:** ✅ **FIXED**
- Implemented database-level locking using `SELECT ... FOR UPDATE` to lock the KOT row
- This ensures only one transaction can process a KOT at a time, preventing race conditions
- Improved duplicate detection by:
  - Storing KOT number in order notes as `[KOT: KOT-YYYYMMDD-XXXX]` for reliable tracking
  - Using KOT number pattern matching in addition to fuzzy matching
  - Checking for existing orders both before and after status update
  - Extended time window check from 15 to 30 minutes for better reliability
- Fixed duplicate code issue in `handleUpdateKOTStatus()` function
- Added early return if KOT is already "Ready" or "Completed" and order exists
- All checks are performed within the transaction with the lock held, ensuring atomicity

**Implementation:**
```php
// Lock the KOT row to prevent concurrent updates
$lock_sql = "SELECT * FROM kot WHERE id = ? AND restaurant_id = ? FOR UPDATE";
$lock_stmt = $conn->prepare($lock_sql);
$lock_stmt->execute([$kot_id, $restaurant_id]);
$kot = $lock_stmt->fetch();

// Check if order exists using KOT number in notes
$check_order_sql = "SELECT o.id FROM orders o 
                    WHERE o.restaurant_id = ? 
                    AND (o.notes LIKE ? OR ...)
                    LIMIT 1";
// Store KOT number in order notes: [KOT: KOT-YYYYMMDD-XXXX]
```

---

### 3. **Missing Null Checks in get_session.php**
**Severity:** MEDIUM  
**Location:** `admin/get_session.php` (lines 100-102)

**Issue:**
- When fetching restaurant info for staff, if `restaurant_id` is null or invalid, the query fails silently
- No validation that restaurant exists before merging data

**Problem:**
```php
if (!empty($staffRow['restaurant_id'])) {
    $restStmt = $conn->prepare("SELECT restaurant_name, currency_symbol, restaurant_logo, business_qr_code_path FROM users WHERE restaurant_id = :restaurant_id LIMIT 1");
    $restStmt->execute([':restaurant_id' => $staffRow['restaurant_id']]);
    $restRow = $restStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $row = array_merge($staffRow, $restRow); // Could merge with empty array
}
```

**Fix:**
```php
if (!empty($staffRow['restaurant_id'])) {
    $restStmt = $conn->prepare("SELECT restaurant_name, currency_symbol, restaurant_logo, business_qr_code_path FROM users WHERE restaurant_id = :restaurant_id LIMIT 1");
    $restStmt->execute([':restaurant_id' => $staffRow['restaurant_id']]);
    $restRow = $restStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($restRow) {
        $row = array_merge($staffRow, $restRow);
    } else {
        // Log warning: staff has invalid restaurant_id
        error_log("Warning: Staff ID {$staffRow['id']} has invalid restaurant_id: {$staffRow['restaurant_id']}");
        $row = $staffRow;
    }
} else {
    error_log("Warning: Staff ID {$staffRow['id']} has no restaurant_id");
    $row = $staffRow;
}
```

---

### 4. **Hardcoded Database Credentials in Fallback Code**
**Severity:** CRITICAL  
**Location:** Multiple files

**Issue:**
- Fallback database connection code contains hardcoded credentials
- Empty password is a major security risk
- Credentials exposed in source code

**Files Affected:**
- `controllers/pos_operations.php` (lines 43-46)
- `controllers/waiter_request_operations.php` (lines 43-46)
- `api/get_payments.php` (lines 40-43)
- `api/get_dashboard_stats.php`
- `api/get_menu_items.php`
- And 5+ more files

**Fix:**
```php
// Replace hardcoded credentials with environment variables
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'restro2';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

// Or better: throw exception if db_connection.php doesn't exist
if (!file_exists(__DIR__ . '/../db_connection.php')) {
    throw new Exception('Database configuration file not found. Please contact administrator.');
}
```

---

## 🟡 MEDIUM PRIORITY LOGIC ISSUES

### 5. **Session Check Interval Not Cleared on Page Unload**
**Severity:** LOW-MEDIUM  
**Location:** `assets/js/script.js`, `public/script.js`

**Issue:**
- Session check interval continues running even after user navigates away
- Could cause memory leaks in single-page applications
- Multiple intervals could stack if page is reloaded quickly

**Fix:**
```javascript
// Clear interval on page unload
window.addEventListener('beforeunload', () => {
    stopSessionCheck();
});

// Also clear when navigating away
window.addEventListener('pagehide', () => {
    stopSessionCheck();
});
```

---

### 6. **Cart Items Validation - Missing Type Checks**
**Severity:** MEDIUM  
**Location:** `controllers/pos_operations.php` (line 81)

**Issue:**
- `json_decode` can return null on invalid JSON
- No validation that cart items have required fields (id, name, price, quantity)
- Could cause SQL errors if invalid data is passed

**Fix:**
```php
$cartItems = json_decode($_POST['cartItems'] ?? '[]', true);

if (!is_array($cartItems)) {
    echo json_encode(['success' => false, 'message' => 'Invalid cart items format']);
    return;
}

// Validate each item
foreach ($cartItems as $item) {
    if (!isset($item['id']) || !isset($item['name']) || !isset($item['price']) || !isset($item['quantity'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item: missing required fields']);
        return;
    }
    
    // Validate types
    if (!is_numeric($item['id']) || !is_numeric($item['price']) || !is_numeric($item['quantity'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item: invalid data types']);
        return;
    }
    
    // Validate ranges
    if ($item['quantity'] <= 0 || $item['price'] < 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid cart item: invalid quantity or price']);
        return;
    }
}
```

---

### 7. **Transaction Rollback Not Always Called on Error**
**Severity:** MEDIUM  
**Location:** `controllers/pos_operations.php`, `controllers/kot_operations.php`

**Issue:**
- Some error paths don't explicitly rollback transactions
- Could leave database in inconsistent state
- Relies on PDO auto-rollback on connection close, but not guaranteed

**Fix:**
```php
try {
    $conn->beginTransaction();
    
    // ... operations ...
    
    $conn->commit();
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    throw $e;
}
```

---

### 8. **Payment Amount Validation Missing**
**Severity:** MEDIUM  
**Location:** `controllers/pos_operations.php` (line 84)

**Issue:**
- No validation that `total = subtotal + tax`
- Could allow incorrect payment amounts
- No check for negative values

**Fix:**
```php
$subtotal = floatval($_POST['subtotal'] ?? 0);
$tax = floatval($_POST['tax'] ?? 0);
$total = floatval($_POST['total'] ?? 0);

// Validate amounts
if ($subtotal < 0 || $tax < 0 || $total < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid amounts: negative values not allowed']);
    return;
}

// Validate calculation (allow small floating point differences)
$calculatedTotal = $subtotal + $tax;
if (abs($total - $calculatedTotal) > 0.01) {
    echo json_encode(['success' => false, 'message' => 'Invalid amounts: total does not match subtotal + tax']);
    return;
}
```

---

## 🟢 LOW PRIORITY / CODE QUALITY ISSUES

### 9. **Inconsistent Error Messages**
**Severity:** LOW  
**Location:** Multiple files

**Issue:**
- Error messages vary in format and detail
- Some return generic "Database error" while others are more specific
- Makes debugging difficult

**Recommendation:**
- Standardize error message format
- Use error codes for client-side handling
- Log detailed errors server-side, return user-friendly messages

---

### 10. **Missing Input Sanitization for Notes/Descriptions**
**Severity:** LOW  
**Location:** Multiple files

**Issue:**
- User input in notes/descriptions is only trimmed, not sanitized
- Could contain malicious content (though XSS is handled on output)

**Fix:**
```php
$notes = htmlspecialchars(trim($_POST['notes'] ?? ''), ENT_QUOTES, 'UTF-8');
// Or use validation function
$notes = sanitizeString($_POST['notes'] ?? '');
```

---

### 11. **Session Check Frequency Too High**
**Severity:** LOW  
**Location:** `assets/js/script.js`, `public/script.js`

**Issue:**
- Checking session every 2 seconds creates unnecessary server load
- With 30-minute timeout, checking every 30-60 seconds would be sufficient

**Fix:**
```javascript
// Check every 30 seconds instead of 2 seconds
sessionCheckInterval = setInterval(async () => {
    // ... check code ...
}, 30000); // 30 seconds
```

---

## 📋 SUMMARY OF REQUIRED FIXES

### Immediate (Before Production):
1. ✅ Fix order/KOT number generation to prevent duplicates
2. ✅ Remove hardcoded database credentials
3. ✅ Add proper transaction rollback handling
4. ✅ Add cart items validation

### High Priority:
5. ✅ Fix race condition in KOT status update
6. ✅ Add null checks in get_session.php
7. ✅ Add payment amount validation

### Medium Priority:
8. ✅ Clear session check interval on page unload
9. ✅ Reduce session check frequency
10. ✅ Standardize error messages

### Low Priority:
11. ✅ Add input sanitization for notes
12. ✅ Improve code documentation

---

## 🔧 RECOMMENDED IMPROVEMENTS

### Database Schema:
- Add unique constraint on `order_number` + `restaurant_id`
- Add unique constraint on `kot_number` + `restaurant_id`
- Add `kot_id` foreign key in orders table (if not exists)
- Add indexes on frequently queried columns

### Code Structure:
- Create a centralized order number generator function
- Create a centralized KOT number generator function
- Add database transaction wrapper function
- Create validation helper functions for common patterns

### Testing:
- Add unit tests for number generation
- Add integration tests for concurrent KOT updates
- Add tests for session expiration handling
- Test with invalid/malicious input data

---

## 📝 NOTES

- Most security issues are already addressed in `docs/SECURITY_AUDIT_REPORT.md`
- This report focuses on logic errors and potential bugs
- All fixes should be tested thoroughly before deployment
- Consider code review for critical fixes

---

**Report Generated:** January 2025  
**Next Review:** After implementing critical fixes

