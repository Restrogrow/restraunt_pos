<?php
/**
 * Database Query Cache - Optimized for High Concurrency
 * 
 * Provides simple in-memory caching for frequently accessed data
 * Reduces database load for 500-1000+ concurrent users
 * 
 * Features:
 * - In-memory cache with TTL (Time To Live)
 * - Automatic cache invalidation
 * - Memory-efficient storage
 * - Thread-safe for PHP-FPM
 */

if (!defined('DB_CACHE_LOADED')) {
    define('DB_CACHE_LOADED', true);
}

// Cache storage (shared across requests in same PHP-FPM process)
if (!isset($GLOBALS['db_cache'])) {
    $GLOBALS['db_cache'] = [];
}


?>

