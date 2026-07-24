# Database Optimization Guide
## RestroGrow - Production Database Performance & Scalability

**Target:** Support 500+ concurrent users with optimal performance  
**Last Updated:** 2024  
**Status:** Production Ready

---

## Table of Contents

1. [Database Configuration Tuning](#database-configuration-tuning)
2. [Indexing Strategy](#indexing-strategy)
3. [Query Optimization](#query-optimization)
4. [Connection Management](#connection-management)
5. [Caching Strategy](#caching-strategy)
6. [Backup & Recovery](#backup--recovery)
7. [Monitoring & Performance](#monitoring--performance)
8. [Scalability Best Practices](#scalability-best-practices)

---

## Database Configuration Tuning

### MySQL/MariaDB Configuration (my.cnf / my.ini)

```ini
[mysqld]
# Connection Settings
max_connections = 500
max_user_connections = 300
thread_cache_size = 50
table_open_cache = 4000
table_definition_cache = 2000

# Buffer Pool (Adjust based on available RAM - use 70-80% of total RAM)
# For 4GB RAM server: 2.5GB
# For 8GB RAM server: 5GB
innodb_buffer_pool_size = 2G
innodb_buffer_pool_instances = 4
innodb_log_file_size = 512M
innodb_log_buffer_size = 64M

# Query Cache (MySQL 5.7 and below)
query_cache_type = 1
query_cache_size = 128M
query_cache_limit = 4M

# InnoDB Settings
innodb_flush_log_at_trx_commit = 2  # Balance between performance and safety
innodb_flush_method = O_DIRECT
innodb_file_per_table = 1
innodb_read_io_threads = 8
innodb_write_io_threads = 8
innodb_thread_concurrency = 0

# Temporary Tables
tmp_table_size = 128M
max_heap_table_size = 128M

# Sort & Join
sort_buffer_size = 4M
join_buffer_size = 4M
read_buffer_size = 2M
read_rnd_buffer_size = 4M

# Slow Query Log
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow-query.log
long_query_time = 2
log_queries_not_using_indexes = 1

# Binary Logging (for replication and point-in-time recovery)
log_bin = /var/log/mysql/mysql-bin.log
binlog_format = ROW
expire_logs_days = 7
max_binlog_size = 500M

# Character Set
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
```

### Hostinger-Specific Optimizations

```sql
-- Run these queries after connecting to your database
SET GLOBAL max_connections = 500;
SET GLOBAL innodb_buffer_pool_size = 2147483648; -- 2GB (adjust based on your plan)
SET GLOBAL query_cache_size = 134217728; -- 128MB
SET GLOBAL tmp_table_size = 134217728; -- 128MB
SET GLOBAL max_heap_table_size = 134217728; -- 128MB
```

**Note:** Some settings may require root access. Contact Hostinger support if needed.

---

## Indexing Strategy

### Critical Indexes for Performance

```sql
-- Users Table
ALTER TABLE users 
ADD INDEX idx_restaurant_id (restaurant_id),
ADD INDEX idx_username (username),
ADD INDEX idx_is_active (is_active),
ADD INDEX idx_created_at (created_at),
ADD INDEX idx_subscription_status (subscription_status, renewal_date);

-- Menu Items Table
ALTER TABLE menu_items
ADD INDEX idx_restaurant_menu (restaurant_id, menu_id),
ADD INDEX idx_category_type (item_category, item_type),
ADD INDEX idx_available_category (is_available, item_category),
ADD INDEX idx_sort_order (sort_order),
ADD INDEX idx_created_at (created_at);

-- Orders Table
ALTER TABLE orders
ADD INDEX idx_restaurant_date (restaurant_id, created_at),
ADD INDEX idx_status_date (order_status, created_at),
ADD INDEX idx_table_restaurant (table_number, restaurant_id),
ADD INDEX idx_customer (customer_id);

-- Menu Table
ALTER TABLE menu
ADD INDEX idx_restaurant_active (restaurant_id, is_active),
ADD INDEX idx_sort_order (sort_order);

-- Payments Table
ALTER TABLE payments
ADD INDEX idx_order_id (order_id),
ADD INDEX idx_restaurant_date (restaurant_id, created_at),
ADD INDEX idx_status (payment_status);

-- Reservations Table
ALTER TABLE reservations
ADD INDEX idx_restaurant_date (restaurant_id, reservation_date),
ADD INDEX idx_status_date (status, reservation_date),
ADD INDEX idx_customer (customer_phone);

-- Composite Indexes for Common Queries
ALTER TABLE menu_items
ADD INDEX idx_restaurant_available_category (restaurant_id, is_available, item_category);

ALTER TABLE orders
ADD INDEX idx_restaurant_status_date (restaurant_id, order_status, created_at);
```

### Index Maintenance

```sql
-- Check index usage (run monthly)
SELECT 
    TABLE_NAME,
    INDEX_NAME,
    CARDINALITY,
    INDEX_TYPE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = 'u509616587_restrogrow'
ORDER BY TABLE_NAME, INDEX_NAME;

-- Analyze tables to update statistics
ANALYZE TABLE users, menu_items, orders, menu, payments, reservations;

-- Optimize tables (run during low traffic)
OPTIMIZE TABLE users, menu_items, orders, menu, payments, reservations;
```

---

## Query Optimization

### Best Practices

1. **Always Use Indexed Columns in WHERE Clauses**
   ```sql
   -- Good
   SELECT * FROM menu_items WHERE restaurant_id = ? AND is_available = 1;
   
   -- Bad (no index on item_name_en)
   SELECT * FROM menu_items WHERE item_name_en LIKE '%pizza%';
   ```

2. **Limit Result Sets**
   ```sql
   -- Always use LIMIT
   SELECT * FROM orders WHERE restaurant_id = ? ORDER BY created_at DESC LIMIT 50;
   ```

3. **Use Prepared Statements**
   ```php
   // Already implemented in db_connection.php
   $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
   $stmt->execute([$id]);
   ```

4. **Avoid SELECT ***
   ```sql
   -- Good
   SELECT id, username, restaurant_name FROM users WHERE id = ?;
   
   -- Bad
   SELECT * FROM users WHERE id = ?;
   ```

5. **Use JOINs Instead of Subqueries**
   ```sql
   -- Good
   SELECT mi.*, m.menu_name 
   FROM menu_items mi 
   JOIN menu m ON mi.menu_id = m.id 
   WHERE mi.restaurant_id = ?;
   
   -- Bad
   SELECT *, (SELECT menu_name FROM menu WHERE id = menu_items.menu_id) 
   FROM menu_items 
   WHERE restaurant_id = ?;
   ```

6. **Batch Operations**
   ```php
   // Good - Batch insert
   $pdo->beginTransaction();
   $stmt = $pdo->prepare("INSERT INTO menu_items (...) VALUES (?, ?, ...)");
   foreach ($items as $item) {
       $stmt->execute([...]);
   }
   $pdo->commit();
   ```

### Query Performance Monitoring

```sql
-- Enable query profiling
SET profiling = 1;

-- Run your query
SELECT * FROM menu_items WHERE restaurant_id = 1;

-- Check profile
SHOW PROFILES;
SHOW PROFILE FOR QUERY 1;

-- Find slow queries
SELECT 
    sql_text,
    exec_count,
    avg_timer_wait/1000000000000 as avg_time_sec,
    sum_timer_wait/1000000000000 as total_time_sec
FROM performance_schema.events_statements_summary_by_digest
ORDER BY avg_timer_wait DESC
LIMIT 10;
```

---

## Connection Management

### Current Implementation (db_connection.php)

The current implementation already includes:
- ✅ Connection retry logic
- ✅ Health checks
- ✅ Proper error handling
- ✅ Connection statistics

### Additional Optimizations

```php
// Add to db_connection.php for connection pooling
$options = [
    PDO::ATTR_PERSISTENT => false,  // Keep false for better connection management
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_TIMEOUT => 5,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, 
                                     SESSION wait_timeout = 30,
                                     SESSION interactive_timeout = 30",
];
```

### Connection Pooling (Advanced)

For high-traffic scenarios, consider using a connection pooler like:
- **ProxySQL** (recommended for MySQL)
- **PgBouncer** (for PostgreSQL)

---

## Caching Strategy

### 1. Application-Level Caching

#### Implement Redis/Memcached

```php
// config/cache_config.php
class CacheManager {
    private $redis;
    
    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
    }
    
    public function get($key) {
        return $this->redis->get($key);
    }
    
    public function set($key, $value, $ttl = 3600) {
        return $this->redis->setex($key, $ttl, $value);
    }
    
    public function delete($key) {
        return $this->redis->del($key);
    }
    
    public function clearPattern($pattern) {
        $keys = $this->redis->keys($pattern);
        if (!empty($keys)) {
            return $this->redis->del($keys);
        }
        return 0;
    }
}
```

#### Cache Menu Items

```php
// api/get_menu_items.php - Add caching
require_once __DIR__ . '/../config/cache_config.php';

$cache = new CacheManager();
$cacheKey = "menu_items_{$restaurant_id}_{$menuFilter}_{$categoryFilter}";

// Try cache first
$cached = $cache->get($cacheKey);
if ($cached !== false) {
    header('Content-Type: application/json');
    echo $cached;
    exit;
}

// If not cached, fetch from database
$menuItems = fetchMenuItemsFromDB($restaurant_id, $menuFilter, $categoryFilter);

// Cache for 5 minutes
$cache->set($cacheKey, json_encode($menuItems), 300);

echo json_encode($menuItems);
```

#### Cache Dashboard Stats

```php
// api/get_dashboard_stats.php
$cacheKey = "dashboard_stats_{$restaurant_id}_" . date('Y-m-d-H');
$cached = $cache->get($cacheKey);

if ($cached !== false) {
    echo $cached;
    exit;
}

// Calculate stats...
$stats = calculateStats($restaurant_id);

// Cache for 1 hour
$cache->set($cacheKey, json_encode($stats), 3600);
echo json_encode($stats);
```

### 2. Database Query Cache

```sql
-- Enable query cache (MySQL 5.7 and below)
SET GLOBAL query_cache_type = 1;
SET GLOBAL query_cache_size = 134217728; -- 128MB

-- For MySQL 8.0+, query cache is removed
-- Use application-level caching instead
```

### 3. Browser Caching

```php
// api/image.php - Already implemented
header('Cache-Control: public, max-age=31536000'); // 1 year for images

// For API responses
header('Cache-Control: public, max-age=300'); // 5 minutes for dynamic data
header('ETag: "' . md5($response) . '"');
```

### 4. CDN for Static Assets

- Use Cloudflare or similar CDN
- Cache static images, CSS, JS files
- Enable compression (gzip/brotli)

---

## Backup & Recovery

### Automated Backup Strategy

#### 1. Daily Full Backup Script

```bash
#!/bin/bash
# backup_database.sh

DB_NAME="u509616587_restrogrow"
DB_USER="u509616587_restrogrow"
DB_PASS="your_password"
BACKUP_DIR="/home/u509616587/backups"
DATE=$(date +%Y%m%d_%H%M%S)
RETENTION_DAYS=30

# Create backup directory
mkdir -p $BACKUP_DIR

# Full database backup
mysqldump -u $DB_USER -p$DB_PASS \
    --single-transaction \
    --routines \
    --triggers \
    --events \
    $DB_NAME | gzip > $BACKUP_DIR/backup_${DATE}.sql.gz

# Remove backups older than retention period
find $BACKUP_DIR -name "backup_*.sql.gz" -mtime +$RETENTION_DAYS -delete

echo "Backup completed: backup_${DATE}.sql.gz"
```

#### 2. Incremental Backup (Binary Logs)

```bash
#!/bin/bash
# backup_binlogs.sh

BACKUP_DIR="/home/u509616587/backups/binlogs"
mkdir -p $BACKUP_DIR

# Copy binary logs
mysqlbinlog --read-from-remote-server \
    --host=localhost \
    --user=u509616587_restrogrow \
    --password=your_password \
    --raw \
    --stop-never \
    mysql-bin.* > $BACKUP_DIR/
```

#### 3. Automated Cron Jobs

```cron
# Daily full backup at 2 AM
0 2 * * * /home/u509616587/scripts/backup_database.sh >> /var/log/backup.log 2>&1

# Hourly incremental backup
0 * * * * /home/u509616587/scripts/backup_binlogs.sh >> /var/log/backup.log 2>&1
```

### PHP Backup Script

```php
<?php
// admin/backup_database.php
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/auth.php';

// Only allow superadmin
if (!isset($_SESSION['superadmin_id'])) {
    die('Unauthorized');
}

$backupDir = __DIR__ . '/../backups/';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql.gz';
$filepath = $backupDir . $filename;

$command = sprintf(
    'mysqldump -u %s -p%s --single-transaction --routines --triggers %s | gzip > %s',
    escapeshellarg($username),
    escapeshellarg($password),
    escapeshellarg($dbname),
    escapeshellarg($filepath)
);

exec($command, $output, $returnVar);

if ($returnVar === 0) {
    echo json_encode(['success' => true, 'file' => $filename, 'size' => filesize($filepath)]);
} else {
    echo json_encode(['success' => false, 'error' => 'Backup failed']);
}
?>
```

### Backup Verification

```sql
-- Test restore on staging server
mysql -u user -p database_name < backup_file.sql

-- Verify data integrity
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM menu_items;
SELECT COUNT(*) FROM orders;
```

### Cloud Backup (Recommended)

- **Automated daily backups to cloud storage**
- Use services like:
  - AWS S3
  - Google Cloud Storage
  - Backblaze B2
  - Hostinger Backup Service

```bash
# Upload to S3
aws s3 cp $BACKUP_DIR/backup_${DATE}.sql.gz s3://your-bucket/backups/
```

---

## Monitoring & Performance

### Key Metrics to Monitor

1. **Connection Metrics**
   ```sql
   SHOW STATUS LIKE 'Threads_connected';
   SHOW STATUS LIKE 'Max_used_connections';
   SHOW VARIABLES LIKE 'max_connections';
   ```

2. **Query Performance**
   ```sql
   SHOW STATUS LIKE 'Slow_queries';
   SHOW STATUS LIKE 'Questions';
   SHOW STATUS LIKE 'Uptime';
   ```

3. **Cache Hit Rate**
   ```sql
   SHOW STATUS LIKE 'Qcache_hits';
   SHOW STATUS LIKE 'Qcache_inserts';
   -- Hit rate = Qcache_hits / (Qcache_hits + Qcache_inserts) * 100
   ```

4. **InnoDB Metrics**
   ```sql
   SHOW STATUS LIKE 'Innodb_buffer_pool_read%';
   SHOW STATUS LIKE 'Innodb_buffer_pool_pages%';
   ```

### Performance Monitoring Script

```php
<?php
// admin/db_monitor.php
require_once __DIR__ . '/../db_connection.php';

$stats = [];

// Connection stats
$result = $pdo->query("SHOW STATUS WHERE Variable_name IN 
    ('Threads_connected', 'Max_used_connections', 'Slow_queries', 'Questions')");
$stats['connections'] = $result->fetchAll(PDO::FETCH_KEY_PAIR);

// Table sizes
$result = $pdo->query("
    SELECT 
        table_name,
        ROUND(((data_length + index_length) / 1024 / 1024), 2) AS size_mb
    FROM information_schema.TABLES
    WHERE table_schema = DATABASE()
    ORDER BY (data_length + index_length) DESC
");
$stats['table_sizes'] = $result->fetchAll(PDO::FETCH_ASSOC);

// Slow queries
$result = $pdo->query("
    SELECT sql_text, exec_count, avg_timer_wait/1000000000000 as avg_sec
    FROM performance_schema.events_statements_summary_by_digest
    ORDER BY avg_timer_wait DESC
    LIMIT 10
");
$stats['slow_queries'] = $result->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($stats, JSON_PRETTY_PRINT);
?>
```

### Alerting

Set up alerts for:
- Connection pool exhaustion (>80% of max_connections)
- Slow query count increasing
- Disk space usage (>80%)
- Replication lag (if using replication)

---

## Scalability Best Practices

### 1. Read Replicas (For 500+ Users)

```sql
-- Master-Slave Replication Setup
-- Master: Write operations
-- Slave: Read operations

-- In application, route reads to slave
$readPdo = new PDO($slaveDsn, $user, $pass);
$writePdo = new PDO($masterDsn, $user, $pass);
```

### 2. Database Sharding (Advanced)

For very large scale:
- Shard by restaurant_id
- Each shard handles subset of restaurants
- Requires application-level routing

### 3. Partitioning Large Tables

```sql
-- Partition orders table by date
ALTER TABLE orders
PARTITION BY RANGE (YEAR(created_at)) (
    PARTITION p2024 VALUES LESS THAN (2025),
    PARTITION p2025 VALUES LESS THAN (2026),
    PARTITION p_future VALUES LESS THAN MAXVALUE
);
```

### 4. Archive Old Data

```sql
-- Move old orders to archive table
CREATE TABLE orders_archive LIKE orders;

INSERT INTO orders_archive 
SELECT * FROM orders 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);

DELETE FROM orders 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 YEAR);
```

### 5. Optimize Image Storage

- Store images in object storage (S3, Cloudflare R2)
- Use CDN for image delivery
- Implement lazy loading
- Compress images (WebP format)

---

## Implementation Checklist

### Immediate Actions (Week 1)
- [ ] Add critical indexes to all tables
- [ ] Enable slow query log
- [ ] Set up daily automated backups
- [ ] Implement basic caching for menu items
- [ ] Optimize existing slow queries

### Short-term (Month 1)
- [ ] Tune MySQL configuration
- [ ] Implement Redis/Memcached caching
- [ ] Set up monitoring dashboard
- [ ] Create backup verification process
- [ ] Optimize all API endpoints

### Long-term (Quarter 1)
- [ ] Set up read replicas (if needed)
- [ ] Implement CDN for static assets
- [ ] Archive old data
- [ ] Set up automated performance alerts
- [ ] Load testing with 500+ concurrent users

---

## Performance Targets

| Metric | Target | Current | Status |
|--------|--------|---------|--------|
| Page Load Time | < 2s | - | ⏳ |
| API Response Time | < 500ms | - | ⏳ |
| Database Query Time | < 100ms | - | ⏳ |
| Concurrent Users | 500+ | - | ⏳ |
| Cache Hit Rate | > 80% | - | ⏳ |
| Uptime | 99.9% | - | ⏳ |

---

## Troubleshooting

### High Connection Count

```sql
-- Find connections by user
SELECT user, COUNT(*) as connections 
FROM information_schema.PROCESSLIST 
GROUP BY user;

-- Kill idle connections
SELECT CONCAT('KILL ', id, ';') 
FROM information_schema.PROCESSLIST 
WHERE command = 'Sleep' 
AND time > 300;
```

### Slow Queries

```sql
-- Find slow queries
SELECT * FROM mysql.slow_log 
ORDER BY start_time DESC 
LIMIT 10;

-- Explain query plan
EXPLAIN SELECT * FROM menu_items WHERE restaurant_id = 1;
```

### Memory Issues

```sql
-- Check buffer pool usage
SHOW STATUS LIKE 'Innodb_buffer_pool%';

-- If usage is > 95%, increase innodb_buffer_pool_size
```

---

## Resources

- [MySQL Performance Tuning](https://dev.mysql.com/doc/refman/8.0/en/optimization.html)
- [Redis Documentation](https://redis.io/documentation)
- [Database Indexing Best Practices](https://use-the-index-luke.com/)

---

**Last Updated:** 2024  
**Maintained By:** Development Team  
**Review Frequency:** Quarterly

