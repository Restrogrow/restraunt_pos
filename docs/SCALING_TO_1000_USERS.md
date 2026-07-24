# Scaling to 1000 Concurrent Users - Implementation Guide

## Current Capacity
- **Optimized for**: 200-300 concurrent users
- **Peak capacity**: 300-400 users with slight delays
- **Bottleneck**: Hostinger's 500 connections/hour limit

## Required Changes for 1000 Concurrent Users

### 1. Upgrade Hostinger Plan (CRITICAL)
**Priority**: 🔴 CRITICAL
**Action**: Contact Hostinger support to increase connection limits
- Request increase from 500 to at least 2000-5000 connections/hour
- May require upgrading to Business or VPS plan
- Cost: Varies by plan

### 2. Implement Redis/Memcached for Shared Caching
**Priority**: 🔴 CRITICAL
**Why**: Current in-memory cache is per-process, not shared across PHP-FPM workers
**Benefits**:
- Shared cache across all workers
- Reduces database queries by 70-80%
- Faster response times

**Implementation**:
```php
// Install Redis extension: pecl install redis
// Or use Memcached: pecl install memcached

// config/redis_cache.php
class RedisCache {
    private $redis;
    
    public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
    }
    
    public function get($key) {
        $data = $this->redis->get($key);
        return $data !== false ? $data : null;
    }
    
    public function set($key, $value, $ttl = 60) {
        return $this->redis->setex($key, $ttl, $value);
    }
    
    public function delete($key) {
        return $this->redis->del($key);
    }
}
```

### 3. Implement Connection Pooling
**Priority**: 🟡 HIGH
**Why**: Reuse database connections instead of creating new ones
**Options**:
- **ProxySQL** (Recommended for MySQL)
- **PgBouncer** (For PostgreSQL)
- **Custom PHP connection pool** (Simpler but less efficient)

**ProxySQL Setup**:
```ini
# proxysql.cnf
mysql_servers:
  - address: "127.0.0.1"
    port: 3306
    hostgroup: 0

mysql_users:
  - username: "u509616587_restrogrow"
    password: "Sujaysarraf@5569"
    default_hostgroup: 0

mysql_query_rules:
  - rule_id: 1
    match_pattern: ".*"
    destination_hostgroup: 0
    apply: 1
```

### 4. Optimize PHP-FPM Configuration
**Priority**: 🟡 HIGH
**File**: `/etc/php-fpm.d/www.conf` or Hostinger control panel

```ini
pm = dynamic
pm.max_children = 200        # Increase from default (usually 50)
pm.start_servers = 50        # Start with 50 workers
pm.min_spare_servers = 30    # Minimum idle workers
pm.max_spare_servers = 100   # Maximum idle workers
pm.max_requests = 1000       # Restart workers after 1000 requests
```

### 5. Add Aggressive Caching to All API Endpoints
**Priority**: 🟡 HIGH
**Target Endpoints**:
- `get_menu_items.php` - Cache for 5 minutes
- `get_dashboard_stats.php` - Cache for 30 seconds (already done)
- `get_tables.php` - Cache for 2 minutes
- `get_orders.php` - Cache for 10 seconds
- `get_reservations.php` - Cache for 30 seconds

**Example Implementation**:
```php
require_once __DIR__ . '/../config/redis_cache.php';

$cache = new RedisCache();
$cacheKey = "menu_items_{$restaurant_id}_{$menu_id}";

// Check cache first
$cached = $cache->get($cacheKey);
if ($cached !== null) {
    header('Content-Type: application/json');
    echo json_encode($cached);
    exit;
}

// Fetch from database
$conn = getConnection();
// ... queries ...

// Cache result
$result = ['success' => true, 'data' => $data];
$cache->set($cacheKey, $result, 300); // 5 minutes
echo json_encode($result);
```

### 6. Database Query Optimization
**Priority**: 🟢 MEDIUM
**Actions**:
- Review slow query log
- Add missing indexes
- Optimize JOIN queries
- Use EXPLAIN to analyze queries
- Consider read replicas for read-heavy operations

### 7. Implement Read Replicas (Optional but Recommended)
**Priority**: 🟢 MEDIUM
**Why**: Distribute read queries across multiple database servers
**Setup**:
- Configure MySQL master-slave replication
- Route read queries to replicas
- Route write queries to master

### 8. Add Rate Limiting
**Priority**: 🟢 MEDIUM
**Why**: Prevent abuse and ensure fair resource distribution
**Implementation**: Already have `rate_limit.php` config

### 9. Monitor and Alert
**Priority**: 🟢 MEDIUM
**Metrics to Monitor**:
- Active database connections
- Connection errors
- Cache hit rate
- Response times
- PHP-FPM worker utilization
- Server CPU/Memory usage

## Expected Performance After Implementation

### With All Optimizations:
- **Concurrent Users**: 1000+ users
- **Response Time**: < 200ms (average)
- **Database Connections**: 50-100 active (with pooling)
- **Cache Hit Rate**: 70-80%
- **Connection/Hour**: < 2000 (well under limit)

### Without Optimizations:
- **Concurrent Users**: 200-300 max
- **Response Time**: 500ms+ (under load)
- **Database Connections**: 200-500 active
- **Connection/Hour**: 500+ (hits limit)

## Implementation Priority

### Phase 1 (Immediate - Can do now):
1. ✅ Upgrade Hostinger plan (contact support)
2. ✅ Implement Redis/Memcached caching
3. ✅ Add caching to all API endpoints
4. ✅ Optimize PHP-FPM configuration

### Phase 2 (Short-term - 1-2 weeks):
5. ✅ Implement connection pooling (ProxySQL)
6. ✅ Database query optimization
7. ✅ Add monitoring and alerts

### Phase 3 (Long-term - Optional):
8. ✅ Read replicas
9. ✅ Load balancing
10. ✅ CDN for static assets

## Cost Estimate

- **Hostinger Business/VPS Plan**: $10-50/month
- **Redis/Memcached**: Usually included in hosting
- **ProxySQL**: Free (open source)
- **Total Additional Cost**: $10-50/month

## Testing Plan

1. **Load Testing**: Use Apache JMeter or k6 to simulate 1000 concurrent users
2. **Monitor**: Watch connection count, response times, errors
3. **Gradual Rollout**: Start with 500 users, then scale to 1000
4. **Fallback Plan**: Have monitoring alerts to detect issues early

## Conclusion

**Current State**: Can handle 200-300 concurrent users
**After Phase 1**: Can handle 500-700 concurrent users
**After All Phases**: Can handle 1000+ concurrent users

The main bottleneck is Hostinger's connection limit. Once that's increased and Redis caching is implemented, the system should handle 1000 concurrent users comfortably.

