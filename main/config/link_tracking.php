<?php
/**
 * Tracked links: every link a restaurant shares (WhatsApp campaigns and automatic messages, posters/QR,
 * captions, Google posts…) is a short link  restrogrow.com/<slug>/go/<code>  (<custom domain>/go/<code> also works).
 * Opening it (main/website/go.php) counts the click and sends the visitor on to the restaurant's page with
 * ?src=<tag>&coupon=<code>, so the existing order attribution (rgCampaign → marketing_order_src) and the
 * gift-code matching keep working. WhatsApp links carry ?m=<outbox id>, so we know which customer clicked.
 *
 * Tables: tracked_links (one per campaign / design / post / message kind), link_clicks (one per click).
 */
if (!function_exists('tlEnsure')) {

    function tlEnsure(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        require_once __DIR__ . '/marketing_helpers.php';
        mkEnsureTables($conn);   // marketing_order_src: where tagged orders are remembered
        $conn->exec("CREATE TABLE IF NOT EXISTS tracked_links (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            code VARCHAR(12) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            ref VARCHAR(40) NOT NULL,
            label VARCHAR(120) NULL,
            target VARCHAR(20) NOT NULL DEFAULT 'menu',
            url VARCHAR(500) NULL,
            src VARCHAR(60) NULL,
            coupon VARCHAR(40) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_tl_code (code),
            UNIQUE KEY uq_tl_ref (restaurant_id, kind, ref)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS link_clicks (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            link_id INT NOT NULL,
            restaurant_id VARCHAR(50) NOT NULL,
            ts DATETIME NOT NULL,
            day DATE NOT NULL,
            visitor CHAR(16) NOT NULL,
            device VARCHAR(10) NOT NULL DEFAULT 'mobile',
            outbox_id INT NULL,
            INDEX idx_lc_link (link_id, day),
            INDEX idx_lc_rest (restaurant_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /**
     * The restaurant's website on restrogrow.com/<slug>, the slug made from its name exactly like the website
     * router does (website/router.php createRestaurantSlug). Links always use the main domain: it works for
     * every restaurant, with or without its own domain.
     */
    function tlSiteBase(PDO $conn, string $rid): array {
        static $cache = [];
        if (isset($cache[$rid])) return $cache[$rid];
        $st = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $name = (string)$st->fetchColumn();
        $app = rtrim((string)(function_exists('env') ? env('APP_URL', '') : ''), '/') ?: 'https://restrogrow.com';
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $cache[$rid] = ['site' => $app . '/' . $slug, 'slug' => $slug];
    }

    /** Page a link can open, as a path on the restaurant's site. */
    function tlTargets(): array {
        return ['menu' => 'menu', 'loyalty' => 'loyalty', 'home' => '', 'reservations' => 'reservations', 'catering' => 'catering', 'plans' => 'plans'];
    }

    /**
     * The tracked link for one thing (a campaign, a design, a post…), created the first time.
     * $o: label, target (menu|loyalty|home|…|url), url (for target url), src, coupon
     */
    function tlLink(PDO $conn, string $rid, string $kind, string $ref, array $o = []): array {
        tlEnsure($conn);
        $st = $conn->prepare('SELECT * FROM tracked_links WHERE restaurant_id = ? AND kind = ? AND ref = ?');
        $st->execute([$rid, $kind, $ref]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $target = (string)($o['target'] ?? 'menu');
        if (!isset(tlTargets()[$target]) && $target !== 'url') $target = 'menu';
        $url = $target === 'url' && preg_match('#^https://[^\s"<>]{4,490}$#', (string)($o['url'] ?? '')) ? (string)$o['url'] : null;
        if ($target === 'url' && !$url) $target = 'menu';
        $src = mkCleanSrc((string)($o['src'] ?? '')) ?: null;   // same cleaning the order side uses
        $coupon = mb_substr(strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($o['coupon'] ?? ''))), 0, 40) ?: null;
        $label = mb_substr(trim((string)($o['label'] ?? '')), 0, 120) ?: null;
        if ($row) {
            // keep the code; follow later edits to where it points
            if ($row['target'] !== $target || $row['url'] !== $url || $row['src'] !== $src || $row['coupon'] !== $coupon || ($label && $row['label'] !== $label)) {
                $conn->prepare('UPDATE tracked_links SET target = ?, url = ?, src = ?, coupon = ?, label = COALESCE(?, label) WHERE id = ?')
                     ->execute([$target, $url, $src, $coupon, $label, $row['id']]);
                $row = array_merge($row, ['target' => $target, 'url' => $url, 'src' => $src, 'coupon' => $coupon, 'label' => $label ?: $row['label']]);
            }
        } else {
            for ($i = 0; $i < 6; $i++) {
                $code = tlNewCode();
                try {
                    $conn->prepare('INSERT INTO tracked_links (restaurant_id, code, kind, ref, label, target, url, src, coupon) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                         ->execute([$rid, $code, $kind, $ref, $label, $target, $url, $src, $coupon]);
                    break;
                } catch (PDOException $e) {
                    if ($i === 5) throw $e;   // a duplicate code, try another
                    $st->execute([$rid, $kind, $ref]);
                    if ($row = $st->fetch(PDO::FETCH_ASSOC)) break;   // someone else just made it
                }
            }
            if (!$row) { $st->execute([$rid, $kind, $ref]); $row = $st->fetch(PDO::FETCH_ASSOC); }
        }
        $row['short'] = tlShortUrl($conn, $rid, $row['code']);
        return $row;
    }

    function tlNewCode(): string {
        $a = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 7; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
        return $s;
    }

    function tlShortUrl(PDO $conn, string $rid, string $code, ?int $outboxId = null): string {
        $b = tlSiteBase($conn, $rid);
        return $b['site'] . '/go/' . $code . ($outboxId ? '?m=' . base_convert((string)$outboxId, 10, 36) . '-' . tlSign($outboxId) : '');
    }

    /** Short signature on a message id in a link: a guessed id can't open someone else's gift code (website/go.php). */
    function tlSign(int $outboxId): string {
        $k = (string)(env('WA_KEY_SECRET', '') ?: env('PAYMENT_KEY_SECRET', '') ?: env('DB_PASS', '') ?: 'rg-links');
        return substr(hash_hmac('sha256', 'tl|' . $outboxId, $k), 0, 8);
    }

    /** Where a link sends the visitor (with the tag and coupon the order attribution reads). */
    function tlDestination(PDO $conn, array $l, ?string $coupon = null): string {
        if ($l['target'] === 'url' && $l['url']) return $l['url'];
        $b = tlSiteBase($conn, $l['restaurant_id']);
        $path = tlTargets()[$l['target']] ?? 'menu';
        $q = ['src' => tlSrc($l)];
        $c = $coupon ?: $l['coupon'];
        if ($c) $q['coupon'] = $c;
        return $b['site'] . ($path !== '' ? '/' . $path : '/') . '?' . http_build_query($q);
    }

    /** The tag an order carries when it came through this link. */
    function tlSrc(array $l): string { return $l['src'] ?: mkCleanSrc('l-' . $l['code']); }

    /**
     * Numbers for a set of links: clicks, people, by day, devices, orders and sales through the link's tag
     * (and its coupon, used since the link was made). $since: 'Y-m-d' or null for all time.
     * Returns [link_id => [...]].
     */
    function tlStats(PDO $conn, string $rid, array $links, ?string $since = null): array {
        tlEnsure($conn);
        $out = [];
        if (!$links) return $out;
        $ids = array_map(fn($l) => (int)$l['id'], $links);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $p = array_merge([$rid], $ids);
        $dw = '';
        if ($since) { $dw = ' AND day >= ?'; $p[] = $since; }
        $st = $conn->prepare("SELECT link_id, COUNT(*) clicks, COUNT(DISTINCT visitor) people, SUM(device = 'mobile') mobile, MAX(ts) last_click
            FROM link_clicks WHERE restaurant_id = ? AND link_id IN ($in)$dw GROUP BY link_id");
        $st->execute($p);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['link_id']] = ['clicks' => (int)$r['clicks'], 'people' => (int)$r['people'], 'mobile' => (int)$r['mobile'], 'last_click' => $r['last_click']];
        foreach ($links as $l) {
            $id = (int)$l['id'];
            $out[$id] = ($out[$id] ?? ['clicks' => 0, 'people' => 0, 'mobile' => 0, 'last_click' => null]) + ['orders' => 0, 'sales' => 0.0];
            // orders tagged with the link (website orders), counted once each
            try {
                $q = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) s FROM marketing_order_src m JOIN orders o ON o.id = m.order_id
                    WHERE m.restaurant_id = ? AND m.src = ? AND o.order_status <> 'Cancelled' AND o.deleted_at IS NULL" . ($since ? ' AND o.created_at >= ?' : ''));
                $q->execute(array_filter([$rid, tlSrc($l), $since], fn($v) => $v !== null));
                $r = $q->fetch(PDO::FETCH_ASSOC);
                $out[$id]['orders'] = (int)$r['n'];
                $out[$id]['sales'] = (float)$r['s'];
            } catch (PDOException $e) { /* marketing_order_src not made yet */ }
        }
        return $out;
    }

    /** Clicks per day for one link, last $days days (for a small chart). */
    function tlDaily(PDO $conn, string $rid, int $linkId, int $days = 14): array {
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $st = $conn->prepare('SELECT day, COUNT(*) n FROM link_clicks WHERE restaurant_id = ? AND link_id = ? AND day >= ? GROUP BY day');
        $st->execute([$rid, $linkId, $from]);
        $m = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[$r['day']] = (int)$r['n'];
        $o = [];
        for ($i = $days - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $o[] = $m[$d] ?? 0; }
        return $o;
    }
}
