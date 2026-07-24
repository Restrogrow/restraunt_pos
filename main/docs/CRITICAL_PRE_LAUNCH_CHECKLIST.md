# 🚨 CRITICAL PRE-LAUNCH CHECKLIST
## Must-Do Before Going Live Tomorrow

**Priority: URGENT - Do these first!**

---

## 1. Database Indexes (15 minutes) ⚠️ CRITICAL

**Without these, your site will be SLOW with just 10-20 users.**

### ✅ Already Completed:
- **All critical indexes added** to both production and localhost databases
- **Script available:** `admin/run_indexes_both_dbs.php` - Can run on both databases
- **SQL file updated:** `database/database_schema.sql` and `database/database_schema_clean.sql` include all indexes
- **Indexes are in main schema files** - New installations will have them automatically

### Indexes Added:
```sql
-- Users table
ALTER TABLE users ADD INDEX idx_restaurant_id (restaurant_id);
ALTER TABLE users ADD INDEX idx_username (username);
ALTER TABLE users ADD INDEX idx_is_active (is_active);

-- Menu Items (MOST IMPORTANT - used on every page)
ALTER TABLE menu_items ADD INDEX idx_restaurant_menu (restaurant_id, menu_id);
ALTER TABLE menu_items ADD INDEX idx_available_category (is_available, item_category);
ALTER TABLE menu_items ADD INDEX idx_restaurant_available (restaurant_id, is_available);

-- Orders
ALTER TABLE orders ADD INDEX idx_restaurant_date (restaurant_id, created_at);
ALTER TABLE orders ADD INDEX idx_status_date (order_status, created_at);

-- Menu
ALTER TABLE menu ADD INDEX idx_restaurant_active (restaurant_id, is_active);

-- Payments
ALTER TABLE payments ADD INDEX idx_order_id (order_id);
ALTER TABLE payments ADD INDEX idx_restaurant_date (restaurant_id, created_at);
```

### To Verify Indexes Exist:
```sql
-- Check all indexes on critical tables
SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLUMNS
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('users', 'menu_items', 'orders', 'menu', 'payments')
  AND INDEX_NAME LIKE 'idx_%'
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;
```

**Time:** 15 minutes  
**Impact:** 10x faster queries  
**Status:** ✅ Completed - All indexes added to production and localhost databases

---

## 2. Database Connection Limits (5 minutes) ⚠️ CRITICAL

**Prevent "Too many connections" errors.**

### ✅ Already Optimized:
- **Persistent connections enabled** - Multiple tabs/requests share connections
- **Visibility checks** - Hidden tabs make zero database requests
- **Optimized auto-refresh** - 10-30 second intervals (reduced from 3-5 seconds)
- **Session check optimized** - 30 seconds (reduced from 2 seconds)

### Still Check Limits:
```sql
-- Check current limit
SHOW VARIABLES LIKE 'max_connections';

-- Check current connections
SHOW STATUS LIKE 'Threads_connected';

-- Check max used connections
SHOW STATUS LIKE 'Max_used_connections';

-- If needed, increase limit (contact Hostinger if you can't change)
SET GLOBAL max_connections = 200;  -- Minimum for launch
```

### Connection Usage:
- **Before optimizations:** 10 tabs = 10+ connections
- **After optimizations:** 100 tabs = 1-3 connections (only visible tabs use DB)
- **Expected usage:** ~5-10% of max_connections under normal load

**Time:** 5 minutes  
**Impact:** Prevents crashes under load  
**Status:** ✅ Optimized - Connection pooling and visibility checks implemented

---

## 3. Enable Error Logging (10 minutes) ⚠️ CRITICAL

**You need to see errors in production.**

### ✅ Already Configured:
- **Error logging enabled** in `db_connection.php` and all controller files
- **Centralized error handler** in `config/error_handler.php` with automatic log directory creation
- **Multiple log files:**
  - `logs/errors.log` - General errors
  - `logs/security.log` - Security-related errors
  - `logs/general.log` - Info messages
- **Automatic log cleanup** - Old logs (>30 days) are automatically cleaned
- **Error logging** is enabled across all API endpoints and controllers

### Log Files Location:
```
/home/u509616587/domains/restrogrow.com/public_html/logs/
├── errors.log          # General application errors
├── security.log        # Security-related errors (auth, SQL, etc.)
└── general.log         # Info messages and warnings
```

### To View Logs:
```bash
# Via SSH (if available)
tail -f /home/u509616587/domains/restrogrow.com/public_html/logs/errors.log

# Or via File Manager in Hostinger cPanel
# Navigate to: public_html/logs/
```

### Verify Logging Works:
```php
// Test error logging (add temporarily to any PHP file)
error_log("Test error log entry - " . date('Y-m-d H:i:s'));
// Check logs/errors.log to confirm it appears
```

**Time:** 10 minutes  
**Impact:** Can debug issues in production  
**Status:** ✅ Completed - Error logging fully configured and active

---

## 4. Test Image Loading (5 minutes) ⚠️ CRITICAL

**Make sure images work on live server.**

1. Visit: `https://restrogrow.com/test_image_loading.php`
2. Verify all images load correctly
3. Check uploads directory exists and is writable

**Time:** 5 minutes  
**Impact:** Images won't show if broken

---

## 5. Test Database Connection (2 minutes) ⚠️ CRITICAL

**Verify connection works on live server.**

1. Visit: `https://restrogrow.com/sujay/index.php`
2. Check "Database Status" shows "Online"
3. Verify connection stats are working

**Time:** 2 minutes   
**Impact:** Site won't work if DB connection fails

---

## 6. Set Up Basic Backup (20 minutes) ⚠️ CRITICAL

**Don't lose data on day 1.**

### Option A: Hostinger Backup (Easiest)
- Enable daily backups in Hostinger control panel
- Set retention to 7 days minimum

### Option B: Manual Backup Script
```bash
# Create backup script: /home/u509616587/backup.sh
#!/bin/bash
mysqldump -u u509616587_restrogrow -p'your_password' u509616587_restrogrow | gzip > /home/u509616587/backups/backup_$(date +%Y%m%d).sql.gz

# Make executable
chmod +x /home/u509616587/backup.sh

# Run daily via cron
# Add to crontab: 0 2 * * * /home/u509616587/backup.sh
```

**Time:** 20 minutes  
**Impact:** Can recover if something goes wrong

---

## 7. Disable Test Files (5 minutes) ⚠️ CRITICAL

**Don't expose test files to public.**

```bash
# Move or delete test files
mv test_image_loading.php test_image_loading.php.bak
# Or add to .htaccess to block access
```

**Or add to .htaccess:**
```apache
# Block test files
<FilesMatch "test_.*\.php$">
    Order allow,deny
    Deny from all
</FilesMatch>
```

**Time:** 5 minutes  
**Impact:** Prevents exposing sensitive info

---

## 8. Verify Uploads Directory (5 minutes) ⚠️ CRITICAL

**Images won't upload if this is wrong.**

```bash
# Check uploads directory exists
ls -la /home/u509616587/domains/restrogrow.com/public_html/uploads/

# Set correct permissions
chmod 755 /home/u509616587/domains/restrogrow.com/public_html/uploads/
chmod 755 /home/u509616587/domains/restrogrow.com/public_html/uploads/logos/
chmod 755 /home/u509616587/domains/restrogrow.com/public_html/uploads/menu_items/
chmod 755 /home/u509616587/domains/restrogrow.com/public_html/uploads/banners/
```

**Time:** 5 minutes  
**Impact:** Image uploads will fail

---

## 9. Test Critical User Flows (30 minutes) ⚠️ CRITICAL

**Make sure basic functionality works.**

- [ ] Login works (admin, superadmin)
- [ ] Menu items display correctly
- [ ] Images load on menu pages
- [ ] Orders can be created
- [ ] Payments can be processed
- [ ] Dashboard loads without errors

**Time:** 30 minutes  
**Impact:** Core functionality must work

---

## 10. Set Production Error Display (2 minutes) ⚠️ CRITICAL

**Hide errors from users, log them instead.**

```php
// In db_connection.php and all entry points
ini_set('display_errors', 0);  // Hide from users
ini_set('log_errors', 1);      // Log to file
```

**Time:** 2 minutes  
**Impact:** Professional appearance, security

---

## Quick Execution Order

1. **Database Indexes** (15 min) - Do this FIRST
2. **Test Database Connection** (2 min)
3. **Test Image Loading** (5 min)
4. **Verify Uploads Directory** (5 min)
5. **Set Error Display** (2 min)
6. **Enable Error Logging** (10 min)
7. **Set Connection Limits** (5 min)
8. **Test Critical Flows** (30 min)
9. **Set Up Backup** (20 min)
10. **Disable Test Files** (5 min)

**Total Time: ~1.5 hours**

---

## What You Can Skip for Now

- ❌ Caching (can add later)
- ❌ Advanced monitoring (basic logging is enough)
- ❌ Read replicas (not needed for launch)
- ❌ CDN setup (can add after launch)
- ❌ Advanced security hardening (basic is fine)

---

## Emergency Contacts

- **Hostinger Support:** For database connection issues
- **Check Logs:** `/home/u509616587/domains/restrogrow.com/public_html/logs/php_errors.log`

---

## Post-Launch (Do These After Launch)

- [ ] Set up automated daily backups
- [ ] Implement caching (Redis/Memcached)
- [ ] Set up monitoring alerts
- [ ] Optimize slow queries
- [ ] Add CDN for images

---

**🎯 Focus: Get it working first, optimize later!**

