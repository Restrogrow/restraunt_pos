<?php
/**
 * Marketing studio tables (created on first use).
 *
 *  marketing_brand_kit   one row per restaurant: logo, two colours, headline font, phone,
 *                        address, tagline, hashtags, caption language. Used by every poster,
 *                        video and caption.
 *  marketing_designs     saved posters / videos / captions ("My campaigns"): the editor state
 *                        as JSON plus a small thumbnail, the campaign tag and the coupon.
 *  marketing_order_src   which website orders came from which campaign link (?src=...).
 *                        Written by api/process_website_order.php after the order commits.
 */

function mkEnsureTables(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $conn->exec("CREATE TABLE IF NOT EXISTS marketing_brand_kit (
        restaurant_id VARCHAR(20) NOT NULL PRIMARY KEY,
        logo MEDIUMTEXT NULL,
        color1 VARCHAR(9) NOT NULL DEFAULT '#F97316',
        color2 VARCHAR(9) NOT NULL DEFAULT '#111827',
        font VARCHAR(20) NOT NULL DEFAULT 'modern',
        phone VARCHAR(30) NOT NULL DEFAULT '',
        address VARCHAR(160) NOT NULL DEFAULT '',
        tagline VARCHAR(80) NOT NULL DEFAULT '',
        hashtags VARCHAR(255) NOT NULL DEFAULT '',
        language VARCHAR(10) NOT NULL DEFAULT 'en',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS marketing_designs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        kind VARCHAR(10) NOT NULL DEFAULT 'poster',
        name VARCHAR(100) NOT NULL DEFAULT '',
        src VARCHAR(60) NOT NULL DEFAULT '',
        coupon_code VARCHAR(50) NOT NULL DEFAULT '',
        state MEDIUMTEXT NULL,
        thumb MEDIUMTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_rest (restaurant_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->exec("CREATE TABLE IF NOT EXISTS marketing_order_src (
        order_id INT NOT NULL PRIMARY KEY,
        restaurant_id VARCHAR(20) NOT NULL,
        src VARCHAR(60) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_src (restaurant_id, src)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/** A campaign tag as it may appear in a link: lowercase letters, digits and dashes. */
function mkCleanSrc($s): string
{
    $s = strtolower(trim((string)$s));
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
    return substr(trim($s, '-'), 0, 60);
}

/**
 * Remember which campaign link a website order came from. Never throws: a missing table
 * or bad tag just means the order isn't attributed.
 */
function mkRecordOrderSource(PDO $conn, string $restaurantId, int $orderId, $src): void
{
    $src = mkCleanSrc($src);
    if ($src === '' || $orderId <= 0) return;
    try {
        $st = $conn->prepare("INSERT IGNORE INTO marketing_order_src (order_id, restaurant_id, src) VALUES (?, ?, ?)");
        $st->execute([$orderId, $restaurantId, $src]);
    } catch (Throwable $e) {
        // table not created yet (nobody opened Marketing): nothing to attribute to
    }
}
