<?php
/**
 * AI manager for the owner (plan: docs/IMPORTANT_TODO.md, feature 2).
 *
 * Every morning the owner gets a short WhatsApp report about yesterday, with up to two one-tap actions
 * ("Reply 1 to …"), and can ask questions on WhatsApp ("sales this week?", "aaj kitna bika?").
 *
 *  - Facts first: ownerMgrFacts() works out every number with SQL. Nothing the AI says is a number of its own.
 *  - Rules pick what matters and which actions to offer; actions are things RestroGrow already does
 *    (switch on the loyalty "We miss you" gift, switch a dish back on).
 *  - Gemini (config/ai_gemini.php) only re-words the plain draft in the owner's language; every number in
 *    its text must be one of the draft's numbers, else the plain draft is sent.
 *  - Questions: Gemini picks one intent + period from a fixed list; PHP runs that report (no free SQL).
 *  - Replies arrive through api/wa_webhook.php (the owner's phone → ownerMgrHandleMessage()).
 *  - Sending: cron/owner_brief.php every 15 minutes, plus a lazy check when the owner opens the AI manager.
 *  - Tables: om_settings, om_briefs, om_actions.
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/ai_gemini.php';

if (!function_exists('ownerMgrHandleMessage')) {

    function ownerMgrEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS om_settings (
            restaurant_id VARCHAR(50) PRIMARY KEY,
            enabled TINYINT NOT NULL DEFAULT 0,
            send_time CHAR(5) NOT NULL DEFAULT '09:00',
            language VARCHAR(10) NOT NULL DEFAULT 'en',
            phone VARCHAR(20) NULL,
            last_sent_on DATE NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS om_briefs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            day DATE NOT NULL,
            facts MEDIUMTEXT NULL,
            text TEXT NOT NULL,
            ai TINYINT NOT NULL DEFAULT 0,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (restaurant_id, day)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS om_actions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            brief_id INT NULL,
            code TINYINT NOT NULL,
            kind VARCHAR(30) NOT NULL,
            params TEXT NULL,
            label VARCHAR(300) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            result VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME NULL,
            INDEX (restaurant_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    function ownerMgrSettings(PDO $conn, string $rid): array
    {
        ownerMgrEnsure($conn);
        $st = $conn->prepare('SELECT * FROM om_settings WHERE restaurant_id = ?');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $u = $conn->prepare('SELECT restaurant_name, phone, timezone, currency_symbol, owner_name FROM users WHERE restaurant_id = ? LIMIT 1');
        $u->execute([$rid]);
        $rest = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $tz = (!empty($rest['timezone']) && in_array($rest['timezone'], timezone_identifiers_list(), true)) ? $rest['timezone'] : 'Asia/Kolkata';
        $phone = preg_replace('/\D/', '', (string)($r['phone'] ?? '')) ?: preg_replace('/\D/', '', (string)($rest['phone'] ?? ''));
        return [
            'enabled' => (int)($r['enabled'] ?? 0) === 1,
            'send_time' => preg_match('/^\d{2}:\d{2}$/', (string)($r['send_time'] ?? '')) ? $r['send_time'] : '09:00',
            'language' => in_array($r['language'] ?? '', ['en', 'hi', 'hinglish'], true) ? $r['language'] : 'en',
            'phone' => $phone,
            'phone_override' => preg_replace('/\D/', '', (string)($r['phone'] ?? '')),
            'last_sent_on' => $r['last_sent_on'] ?? null,
            'tz' => $tz,
            'restaurant' => (string)($rest['restaurant_name'] ?? ''),
            // a person's first name only: "Spice Garden Group" as owner name is not a name to greet
            'owner' => (function () use ($rest) {
                $o = trim((string)($rest['owner_name'] ?? ''));
                $first = trim(explode(' ', $o)[0]);
                if ($o === '' || preg_match('/(group|pvt|ltd|llp|restaurant|foods?|cafe|hotel|kitchen)/i', $o)) return '';
                if (stripos((string)($rest['restaurant_name'] ?? ''), $first) === 0) return '';
                return mb_substr($first, 0, 20);
            })(),
            'currency' => (string)($rest['currency_symbol'] ?? '') ?: '₹',
        ];
    }

    function ownerMgrSaveSettings(PDO $conn, string $rid, array $in): void
    {
        ownerMgrEnsure($conn);
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)($in['send_time'] ?? '')) ? $in['send_time'] : '09:00';
        $lang = in_array($in['language'] ?? '', ['en', 'hi', 'hinglish'], true) ? $in['language'] : 'en';
        $phone = preg_replace('/\D/', '', (string)($in['phone'] ?? ''));
        $conn->prepare('INSERT INTO om_settings (restaurant_id, enabled, send_time, language, phone) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), send_time = VALUES(send_time), language = VALUES(language), phone = VALUES(phone)')
            ->execute([$rid, empty($in['enabled']) ? 0 : 1, $time, $lang, $phone !== '' ? substr($phone, -12) : null]);
    }

    function ownerMgrMoney(array $s, float $n): string
    {
        return $s['currency'] . number_format(round($n));
    }

    /** "Today" etc. in the restaurant's timezone → [from 'Y-m-d H:i:s', to (exclusive), label]. */
    function ownerMgrPeriod(array $s, string $p): array
    {
        $tz = new DateTimeZone($s['tz']);
        $today = new DateTime('today', $tz);
        $d = fn(DateTime $x) => $x->format('Y-m-d 00:00:00');
        $c = fn() => clone $today;
        switch ($p) {
            case 'today': return [$d($c()), $d($c()->modify('+1 day')), 'today'];
            case 'yesterday': return [$d($c()->modify('-1 day')), $d($c()), 'yesterday'];
            case 'this_week': $m = $c()->modify('monday this week'); return [$d($m), $d($c()->modify('+1 day')), 'this week'];
            case 'last_week': $m = $c()->modify('monday last week'); return [$d($m), $d((clone $m)->modify('+7 days')), 'last week'];
            case 'this_month': return [$d($c()->modify('first day of this month')), $d($c()->modify('+1 day')), 'this month'];
            case 'last_month': return [$d($c()->modify('first day of last month')), $d($c()->modify('first day of this month')), 'last month'];
            case 'last_30_days': return [$d($c()->modify('-29 days')), $d($c()->modify('+1 day')), 'the last 30 days'];
            default: return [$d($c()->modify('-6 days')), $d($c()->modify('+1 day')), 'the last 7 days'];
        }
    }

    define('OM_LIVE', "o.deleted_at IS NULL AND o.order_status NOT IN ('Cancelled','Rejected') AND COALESCE(o.notes, '') NOT LIKE 'Order on hold%'");

    function ownerMgrSales(PDO $conn, string $rid, string $from, string $to): array
    {
        $st = $conn->prepare("SELECT COUNT(*) n, COALESCE(SUM(o.total), 0) amount,
                SUM(o.source = 'pos') pos, SUM(o.source = 'website' AND COALESCE(o.notes, '') NOT LIKE '[WhatsApp order]%') web, SUM(COALESCE(o.notes, '') LIKE '[WhatsApp order]%') wa
            FROM orders o WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ?");
        $st->execute([$rid, $from, $to]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $n = (int)($r['n'] ?? 0);
        $amt = round((float)($r['amount'] ?? 0), 2);
        return ['orders' => $n, 'amount' => $amt, 'avg' => $n ? round($amt / $n) : 0, 'pos' => (int)($r['pos'] ?? 0), 'web' => (int)($r['web'] ?? 0), 'wa' => (int)($r['wa'] ?? 0)];
    }

    function ownerMgrDishes(PDO $conn, string $rid, string $from, string $to, string $order = 'DESC', int $limit = 3): array
    {
        $st = $conn->prepare("SELECT oi.item_name name, SUM(oi.quantity) qty, COALESCE(SUM(oi.total_price), 0) amount FROM order_items oi JOIN orders o ON o.id = oi.order_id
            WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? AND oi.menu_item_id IS NOT NULL
            GROUP BY oi.item_name ORDER BY qty $order LIMIT " . (int)$limit);
        $st->execute([$rid, $from, $to]);
        return array_map(fn($r) => ['name' => $r['name'], 'qty' => (int)$r['qty'], 'amount' => round((float)$r['amount'])], $st->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Everything the morning report may say about $day (default: yesterday). Each part fails on its own. */
    function ownerMgrFacts(PDO $conn, string $rid, ?string $day = null): array
    {
        $s = ownerMgrSettings($conn, $rid);
        $tz = new DateTimeZone($s['tz']);
        $D = $day ? new DateTime($day, $tz) : (new DateTime('today', $tz))->modify('-1 day');
        $f = ['day' => $D->format('Y-m-d'), 'day_label' => $D->format('D j M'), 'restaurant' => $s['restaurant']];
        $at = fn(DateTime $x) => $x->format('Y-m-d 00:00:00');
        $next = (clone $D)->modify('+1 day');
        $try = function (string $k, callable $fn) use (&$f) { try { $f[$k] = $fn(); } catch (Throwable $e) { error_log("ownerMgrFacts $k: " . $e->getMessage()); } };

        $try('sales', fn() => ownerMgrSales($conn, $rid, $at($D), $at($next)));
        $try('last_week', fn() => ownerMgrSales($conn, $rid, $at((clone $D)->modify('-7 days')), $at((clone $D)->modify('-6 days'))));
        $try('top', fn() => ownerMgrDishes($conn, $rid, $at($D), $at($next)));
        $try('month', function () use ($conn, $rid, $D, $at, $next) {
            $m = (clone $D)->modify('first day of this month');
            $prevFrom = (clone $m)->modify('-1 month');
            $prevTo = (clone $prevFrom)->modify('+' . ((int)$D->format('j')) . ' days');
            return ['now' => ownerMgrSales($conn, $rid, $at($m), $at($next))['amount'], 'prev' => ownerMgrSales($conn, $rid, $at($prevFrom), $at($prevTo))['amount']];
        });
        // a dish selling much less this week than the week before
        $try('falling', function () use ($conn, $rid, $D, $at, $next) {
            $st = $conn->prepare("SELECT * FROM (SELECT oi.item_name name,
                    SUM(CASE WHEN o.created_at >= ? THEN oi.quantity ELSE 0 END) now_qty,
                    SUM(CASE WHEN o.created_at < ? THEN oi.quantity ELSE 0 END) prev_qty
                FROM order_items oi JOIN orders o ON o.id = oi.order_id
                WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? AND oi.menu_item_id IS NOT NULL
                GROUP BY oi.item_name) x WHERE prev_qty >= 6 ORDER BY now_qty / prev_qty ASC LIMIT 1");
            $wk = $at((clone $D)->modify('-6 days'));
            $st->execute([$wk, $wk, $rid, $at((clone $D)->modify('-13 days')), $at($next)]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $drop = (int)round((1 - (int)$r['now_qty'] / max(1, (int)$r['prev_qty'])) * 100);
            return $drop >= 30 ? ['name' => $r['name'], 'now' => (int)$r['now_qty'], 'prev' => (int)$r['prev_qty'], 'drop' => $drop] : null;
        });
        $try('cancelled', function () use ($conn, $rid, $D, $at, $next) {
            $st = $conn->prepare("SELECT COUNT(*) FROM orders o WHERE o.restaurant_id = ? AND o.deleted_at IS NULL AND o.order_status IN ('Cancelled','Rejected') AND o.created_at >= ? AND o.created_at < ?");
            $st->execute([$rid, $at($D), $at($next)]);
            return (int)$st->fetchColumn();
        });
        $try('customers', function () use ($conn, $rid, $D, $at, $next) {
            $st = $conn->prepare("SELECT COUNT(*) total, SUM(first_at >= ?) new_c FROM (
                    SELECT o.customer_phone, MIN(o2.created_at) first_at FROM orders o
                    JOIN orders o2 ON o2.restaurant_id = o.restaurant_id AND o2.customer_phone = o.customer_phone AND o2.deleted_at IS NULL
                    WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? AND COALESCE(o.customer_phone, '') <> ''
                    GROUP BY o.customer_phone) x");
            $st->execute([$at($D), $rid, $at($D), $at($next)]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $new = (int)($r['new_c'] ?? 0);
            return ['new' => $new, 'repeat' => max(0, (int)($r['total'] ?? 0) - $new)];
        });
        $try('lapsed', function () use ($conn, $rid, $D) {
            $st = $conn->prepare("SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND total_visits >= 3 AND last_visit_date IS NOT NULL AND last_visit_date < ? - INTERVAL 30 DAY AND COALESCE(phone, '') <> ''");
            $st->execute([$rid, $D->format('Y-m-d')]);
            return (int)$st->fetchColumn();
        });
        $try('winback_on', function () use ($conn, $rid) {
            $st = $conn->prepare("SELECT enabled FROM loyalty_automations WHERE restaurant_id = ? AND type = 'winback'");
            $st->execute([$rid]);
            return (int)$st->fetchColumn() === 1;
        });
        // dishes switched off that people were buying (last 30 days)
        $try('off_dishes', function () use ($conn, $rid, $D, $at, $next) {
            $st = $conn->prepare("SELECT mi.id, mi.item_name_en name, SUM(oi.quantity) qty FROM menu_items mi
                JOIN order_items oi ON oi.menu_item_id = mi.id JOIN orders o ON o.id = oi.order_id
                WHERE mi.restaurant_id = ? AND mi.is_available = 0 AND COALESCE(mi.is_hidden, 0) = 0 AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ?
                GROUP BY mi.id, mi.item_name_en HAVING qty >= 5 ORDER BY qty DESC LIMIT 3");
            $st->execute([$rid, $at((clone $D)->modify('-29 days')), $at($next)]);
            return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'qty' => (int)$r['qty']], $st->fetchAll(PDO::FETCH_ASSOC));
        });
        $try('low_stock', function () use ($conn, $rid) {
            $st = $conn->prepare("SELECT item_name FROM inventory_items WHERE restaurant_id = ? AND is_active = 1 AND (quantity_in_stock <= 0 OR (low_stock_threshold > 0 AND quantity_in_stock <= low_stock_threshold)) ORDER BY quantity_in_stock LIMIT 5");
            $st->execute([$rid]);
            return $st->fetchAll(PDO::FETCH_COLUMN);
        });
        // what yesterday's tapped actions did
        $try('results', function () use ($conn, $rid) {
            $st = $conn->prepare("SELECT kind, params, label, done_at FROM om_actions WHERE restaurant_id = ? AND status = 'done' AND done_at >= NOW() - INTERVAL 8 DAY ORDER BY id DESC LIMIT 3");
            $st->execute([$rid]);
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                if ($a['kind'] === 'winback_on') {
                    $g = $conn->prepare("SELECT COUNT(*) sent, COALESCE(SUM(cp.current_uses > 0), 0) used FROM loyalty_rewards_issued r
                        LEFT JOIN coupons cp ON cp.restaurant_id = r.restaurant_id AND cp.coupon_code = r.coupon_code WHERE r.restaurant_id = ? AND r.source = 'winback' AND r.created_at >= ?");
                    $g->execute([$rid, $a['done_at']]);
                    $x = $g->fetch(PDO::FETCH_ASSOC) ?: [];
                    $out[] = ['kind' => 'winback', 'sent' => (int)($x['sent'] ?? 0), 'used' => (int)($x['used'] ?? 0)];
                } elseif ($a['kind'] === 'dish_on') {
                    $p = json_decode((string)$a['params'], true) ?: [];
                    $q = $conn->prepare("SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.menu_item_id = ? AND o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ?");
                    $q->execute([(int)($p['id'] ?? 0), $rid, $a['done_at']]);
                    $out[] = ['kind' => 'dish_on', 'name' => (string)($p['name'] ?? ''), 'sold' => (int)$q->fetchColumn()];
                }
            }
            return $out;
        });
        return $f;
    }

    /** Up to two actions worth offering, from the facts. */
    function ownerMgrPickActions(array $f, array $s): array
    {
        $a = [];
        if (($f['lapsed'] ?? 0) >= 3 && empty($f['winback_on'])) {
            $a[] = ['kind' => 'winback_on', 'params' => [], 'label' => "Turn on the \"We miss you\" gift (" . $s['currency'] . "75 off above " . $s['currency'] . "299) for the " . $f['lapsed'] . " regulars who haven't come in 30 days"];
        }
        foreach ($f['off_dishes'] ?? [] as $d) {
            if (count($a) >= 2) break;
            $a[] = ['kind' => 'dish_on', 'params' => ['id' => $d['id'], 'name' => $d['name']], 'label' => 'Switch ' . $d['name'] . ' back on (it sold ' . $d['qty'] . ' in the last 30 days but is switched off now)'];
        }
        return array_slice($a, 0, 2);
    }

    /** The plain report (also the fallback when the AI is off or gets a number wrong). */
    function ownerMgrPlainText(array $f, array $s, array $actions): string
    {
        $m = fn($n) => ownerMgrMoney($s, (float)$n);
        $L = [];
        $hi = $s['owner'] !== '' ? 'Good morning ' . $s['owner'] . '! ' : 'Good morning! ';
        $L[] = '☀️ ' . $hi . 'Here is ' . ($f['restaurant'] ?: 'your restaurant') . ' yesterday (' . $f['day_label'] . '):';
        $sa = $f['sales'] ?? null;
        if ($sa) {
            $lw = $f['last_week']['amount'] ?? 0;
            $chg = $lw > 0 ? (int)round(($sa['amount'] - $lw) / $lw * 100) : null;
            $L[] = '💰 ' . $m($sa['amount']) . ' from ' . $sa['orders'] . ' orders' . ($chg !== null ? ' (' . ($chg >= 0 ? '+' : '') . $chg . '% vs last ' . substr($f['day_label'], 0, 3) . ')' : '') . ($sa['orders'] ? '. Average bill ' . $m($sa['avg']) . '.' : '.');
            if ($sa['orders']) $L[] = '🧾 Counter ' . $sa['pos'] . ' · Website ' . $sa['web'] . ($sa['wa'] ? ' · WhatsApp ' . $sa['wa'] : '');
        }
        if (!empty($f['top'])) $L[] = '🥇 Top dishes: ' . implode(', ', array_map(fn($d) => $d['name'] . ' (' . $d['qty'] . ')', $f['top']));
        if (!empty($f['falling'])) $L[] = '📉 ' . $f['falling']['name'] . ' sold ' . $f['falling']['drop'] . '% less this week (' . $f['falling']['now'] . ' vs ' . $f['falling']['prev'] . ').';
        if (!empty($f['cancelled'])) $L[] = '❌ ' . $f['cancelled'] . ' order' . ($f['cancelled'] > 1 ? 's were' : ' was') . ' cancelled or rejected.';
        if (!empty($f['customers']) && ($f['customers']['new'] + $f['customers']['repeat']) > 0) $L[] = '👥 ' . $f['customers']['new'] . ' new and ' . $f['customers']['repeat'] . ' returning customers.';
        if (!empty($f['low_stock'])) $L[] = '📦 Running low: ' . implode(', ', $f['low_stock']) . '.';
        foreach ($f['results'] ?? [] as $r) {
            if ($r['kind'] === 'winback' && $r['sent']) $L[] = '🎁 The "We miss you" gift went to ' . $r['sent'] . ' customers; ' . $r['used'] . ' came back and used it.';
            if ($r['kind'] === 'dish_on' && $r['sold']) $L[] = '✅ ' . $r['name'] . ' sold ' . $r['sold'] . ' since you switched it back on.';
        }
        if (!empty($f['month'])) $L[] = '📅 Month so far: ' . $m($f['month']['now']) . ($f['month']['prev'] > 0 ? ' (same days last month ' . $m($f['month']['prev']) . ')' : '') . '.';
        if ($actions) {
            $L[] = '';
            $L[] = 'I can do this for you now:';
            foreach ($actions as $i => $a) $L[] = ($i + 1) . '️⃣ ' . $a['label'];
            $L[] = 'Reply ' . (count($actions) > 1 ? '1 or 2' : '1') . ', or ask me anything (e.g. "sales this week").';
        } else {
            $L[] = '';
            $L[] = 'Ask me anything, e.g. "sales this week" or "top dishes this month".';
        }
        return implode("\n", $L);
    }

    /** Every number written in a text ("₹42,180", "18%", "61") as plain digits. */
    function ownerMgrNumbers(string $t): array
    {
        preg_match_all('/\d[\d,]*(?:\.\d+)?/u', $t, $m);
        return array_values(array_unique(array_map(fn($x) => rtrim(rtrim(str_replace(',', '', $x), '0'), '.') ?: '0', $m[0])));
    }

    /** Gemini re-words the plain report in the owner's language; null if unavailable or it changed a number. */
    function ownerMgrAiText(PDO $conn, string $rid, string $plain, array $s): ?string
    {
        if (!aiGeminiAvailable()) return null;
        $lang = ['en' => 'simple, warm English', 'hi' => 'simple Hindi in Devanagari script (keep dish names as they are)', 'hinglish' => 'Hinglish (Hindi in Roman letters, mixed with English, like Indian restaurant owners text)'][$s['language']];
        $system = "You are the restaurant owner's sharp, friendly business manager writing their morning WhatsApp update.\n"
            . "Rewrite the whole DRAFT in $lang: every sentence and label in that language (dish names stay as written). Keep it short (under 900 characters) and keep the emoji. You may move the most important line to the top, but never repeat a line.\n"
            . "STRICT: use only numbers that appear in the DRAFT, written the same way; do not add percentages, totals or advice with new numbers. "
            . "Keep the numbered action lines and the 'Reply 1 or 2' instruction. Plain WhatsApp text (*bold* allowed), no markdown headings.";
        $t = aiGeminiText($system, "DRAFT:\n" . $plain, $conn, $rid, 900);
        if (!$t) return null;
        $allowed = ownerMgrNumbers($plain);
        foreach (ownerMgrNumbers($t) as $n) {
            if (!in_array($n, $allowed, true) && !in_array($n, ['1', '2'], true)) { error_log("ownerMgrAiText: number $n not in the facts, plain text used"); return null; }
        }
        return trim($t);
    }

    /** Build (and optionally send) the report for yesterday. Returns ['id', 'text', 'actions', 'sent', 'ai']. */
    function ownerMgrBrief(PDO $conn, string $rid, bool $send, ?string $day = null): array
    {
        ownerMgrEnsure($conn);
        $s = ownerMgrSettings($conn, $rid);
        $f = ownerMgrFacts($conn, $rid, $day);
        $actions = ownerMgrPickActions($f, $s);
        $plain = ownerMgrPlainText($f, $s, $actions);
        $ai = ownerMgrAiText($conn, $rid, $plain, $s);
        $text = $ai ?? $plain;
        $conn->prepare("UPDATE om_actions SET status = 'expired' WHERE restaurant_id = ? AND status = 'pending'")->execute([$rid]);
        $conn->prepare('INSERT INTO om_briefs (restaurant_id, day, facts, text, ai) VALUES (?, ?, ?, ?, ?)')
            ->execute([$rid, $f['day'], json_encode($f, JSON_UNESCAPED_UNICODE), $text, $ai ? 1 : 0]);
        $briefId = (int)$conn->lastInsertId();
        $ins = $conn->prepare('INSERT INTO om_actions (restaurant_id, brief_id, code, kind, params, label) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($actions as $i => $a) $ins->execute([$rid, $briefId, $i + 1, $a['kind'], json_encode($a['params'], JSON_UNESCAPED_UNICODE), mb_substr($a['label'], 0, 300)]);
        $sent = false;
        if ($send && $s['phone'] !== '') {
            $sent = ownerMgrSend($conn, $rid, $s, $text);
            if ($sent) $conn->prepare('UPDATE om_briefs SET sent_at = NOW() WHERE id = ?')->execute([$briefId]);
        }
        return ['id' => $briefId, 'text' => $text, 'actions' => $actions, 'sent' => $sent, 'ai' => (bool)$ai, 'facts' => $f];
    }

    function ownerMgrSend(PDO $conn, string $rid, array $s, string $text): bool
    {
        require_once __DIR__ . '/wa_order_engine.php';
        // A business-started message outside WhatsApp's 24-hour window needs an approved template; until the
        // "daily report" template is approved this is sent as text (it arrives once the owner has messaged in the last 24h).
        return woSend($conn, $rid, $s['phone'], $text);
    }

    /** Due now: switched on, not sent today, the restaurant's clock past the send time. */
    function ownerMgrDue(PDO $conn, string $rid): bool
    {
        $s = ownerMgrSettings($conn, $rid);
        if (!$s['enabled'] || $s['phone'] === '') return false;
        $now = new DateTime('now', new DateTimeZone($s['tz']));
        return $s['last_sent_on'] !== $now->format('Y-m-d') && $now->format('H:i') >= $s['send_time'];
    }

    /** Send the report if it's due (cron, and a cheap check when the owner opens the AI manager). */
    function ownerMgrSendIfDue(PDO $conn, string $rid): bool
    {
        if (!ownerMgrDue($conn, $rid)) return false;
        $s = ownerMgrSettings($conn, $rid);
        $today = (new DateTime('now', new DateTimeZone($s['tz'])))->format('Y-m-d');
        // mark first so two runs at once can't both send
        $st = $conn->prepare('UPDATE om_settings SET last_sent_on = ? WHERE restaurant_id = ? AND (last_sent_on IS NULL OR last_sent_on <> ?)');
        $st->execute([$today, $rid, $today]);
        if ($st->rowCount() === 0) return false;
        ownerMgrBrief($conn, $rid, true);
        return true;
    }

    /** Run a pending action by its reply number. Returns the message for the owner. */
    function ownerMgrRunAction(PDO $conn, string $rid, int $code, ?int $id = null): string
    {
        ownerMgrEnsure($conn);
        $st = $id
            ? $conn->prepare("SELECT * FROM om_actions WHERE restaurant_id = ? AND id = ? AND status = 'pending'")
            : $conn->prepare("SELECT * FROM om_actions WHERE restaurant_id = ? AND code = ? AND status = 'pending' ORDER BY id DESC LIMIT 1");
        $st->execute([$rid, $id ?? $code]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        if (!$a) return "There's nothing waiting for number $code right now. Ask me anything, e.g. \"sales this week\".";
        $p = json_decode((string)$a['params'], true) ?: [];
        $result = '';
        try {
            if ($a['kind'] === 'winback_on') {
                require_once __DIR__ . '/loyalty_autopilot.php';
                ensureLoyaltyAutopilotSchema($conn);
                $d = loyaltyAutopilotDefaults()['winback'];
                $conn->prepare("INSERT INTO loyalty_automations (restaurant_id, type, enabled, reward_kind, reward_value, reward_item_id, param, min_order, valid_days, message)
                        VALUES (?, 'winback', 1, ?, ?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE enabled = 1")
                    ->execute([$rid, $d[1], $d[2], $d[3], $d[4], $d[5], $d[6]]);
                $lo = $conn->prepare('SELECT loyalty_enabled FROM growth_settings WHERE restaurant_id = ?');
                $lo->execute([$rid]);
                $result = '✅ Done. The "We miss you" gift is on: customers who haven\'t come in 30 days get it automatically'
                    . ((int)$lo->fetchColumn() === 1 ? '.' : '. Note: your loyalty program is switched off, turn it on in Growth › Loyalty so the gifts go out.')
                    . " I'll tell you how many came back.";
            } elseif ($a['kind'] === 'dish_on') {
                $u = $conn->prepare('UPDATE menu_items SET is_available = 1 WHERE id = ? AND restaurant_id = ?');
                $u->execute([(int)($p['id'] ?? 0), $rid]);
                $result = $u->rowCount() ? '✅ ' . ($p['name'] ?? 'The dish') . ' is back on the menu.' : ($p['name'] ?? 'The dish') . ' was already on.';
            } else {
                $result = "I can't do that one any more.";
            }
            $conn->prepare("UPDATE om_actions SET status = 'done', result = ?, done_at = NOW() WHERE id = ?")->execute([mb_substr($result, 0, 500), $a['id']]);
            try { require_once __DIR__ . '/activity_log.php'; logActivity($conn, $rid, 'ai_manager.action', 'AI manager: ' . $a['label'], ['kind' => $a['kind']], 'ai_manager', (int)$a['id']); } catch (Throwable $e) {}
        } catch (Throwable $e) {
            error_log('ownerMgrRunAction: ' . $e->getMessage());
            $result = 'Sorry, that did not work. Please do it from the dashboard.';
        }
        return $result;
    }

    define('OM_INTENTS', ['sales', 'top_dishes', 'slow_dishes', 'channels', 'customers', 'cancellations', 'lapsed', 'report', 'help']);
    define('OM_PERIODS', ['today', 'yesterday', 'this_week', 'last_week', 'last_7_days', 'this_month', 'last_month', 'last_30_days']);

    /** The owner's question → [intent, period]. Gemini first, then simple keywords. */
    function ownerMgrIntent(PDO $conn, string $rid, string $text): array
    {
        $r = aiGeminiJson(
            "A restaurant owner asks their business assistant a question on WhatsApp (English, Hindi or Hinglish). Pick the ONE report that answers it.\n"
            . "intents: sales (money/revenue/how much sold/kitna bika/kamai), top_dishes (best sellers), slow_dishes (least sold), channels (counter vs website vs WhatsApp), "
            . "customers (new vs returning), cancellations (cancelled/rejected orders), lapsed (regulars who stopped coming), report (today's/morning update), help (anything else).\n"
            . "periods: today, yesterday, this_week, last_week, last_7_days, this_month, last_month, last_30_days. 'aaj' = today, 'kal' = yesterday, 'is hafte' = this_week, 'is mahine' = this_month.",
            $text,
            ['type' => 'OBJECT', 'properties' => ['intent' => ['type' => 'STRING', 'enum' => OM_INTENTS], 'period' => ['type' => 'STRING', 'enum' => OM_PERIODS]], 'required' => ['intent', 'period']],
            [], $conn, $rid);
        if ($r && in_array($r['intent'] ?? '', OM_INTENTS, true)) return [$r['intent'], in_array($r['period'] ?? '', OM_PERIODS, true) ? $r['period'] : 'last_7_days'];
        $t = mb_strtolower($text);
        $period = preg_match('/\b(today|aaj)\b/u', $t) ? 'today' : (preg_match('/\b(yesterday|kal)\b/u', $t) ? 'yesterday' : (preg_match('/last month|pichle mahine/u', $t) ? 'last_month'
            : (preg_match('/month|mahine/u', $t) ? 'this_month' : (preg_match('/last week|pichle hafte/u', $t) ? 'last_week' : (preg_match('/week|hafte/u', $t) ? 'this_week' : 'last_7_days')))));
        $intent = preg_match('/top|best|most|sabse zyada/u', $t) ? 'top_dishes' : (preg_match('/least|slow|worst|kam bik/u', $t) ? 'slow_dishes'
            : (preg_match('/whatsapp|website|counter|channel|online/u', $t) ? 'channels' : (preg_match('/new customer|customer|grahak/u', $t) ? 'customers'
            : (preg_match('/cancel|reject/u', $t) ? 'cancellations' : (preg_match('/sale|sold|bika|kamai|revenue|kitna|how much|total|earn/u', $t) ? 'sales' : 'help')))));
        return [$intent, $period];
    }

    function ownerMgrAnswer(PDO $conn, string $rid, string $intent, string $period): string
    {
        $s = ownerMgrSettings($conn, $rid);
        [$from, $to, $label] = ownerMgrPeriod($s, $period);
        $m = fn($n) => ownerMgrMoney($s, (float)$n);
        switch ($intent) {
            case 'sales':
                $x = ownerMgrSales($conn, $rid, $from, $to);
                return '💰 ' . ucfirst($label) . ': ' . $m($x['amount']) . ' from ' . $x['orders'] . ' orders' . ($x['orders'] ? ', average bill ' . $m($x['avg']) : '') . '.';
            case 'top_dishes':
            case 'slow_dishes':
                $d = ownerMgrDishes($conn, $rid, $from, $to, $intent === 'top_dishes' ? 'DESC' : 'ASC', 5);
                if (!$d) return 'No dishes sold ' . $label . '.';
                return ($intent === 'top_dishes' ? '🥇 Best sellers ' : '🐢 Least sold ') . $label . ":\n" . implode("\n", array_map(fn($x, $i) => ($i + 1) . '. ' . $x['name'] . ' — ' . $x['qty'] . ' (' . $m($x['amount']) . ')', $d, array_keys($d)));
            case 'channels':
                $x = ownerMgrSales($conn, $rid, $from, $to);
                return '🧾 ' . ucfirst($label) . ': counter ' . $x['pos'] . ', website ' . $x['web'] . ', WhatsApp ' . $x['wa'] . ' orders (' . $x['orders'] . ' in all, ' . $m($x['amount']) . ').';
            case 'customers':
                $st = $conn->prepare("SELECT COUNT(*) total, SUM(first_at >= ?) new_c FROM (SELECT o.customer_phone, MIN(o2.created_at) first_at FROM orders o
                    JOIN orders o2 ON o2.restaurant_id = o.restaurant_id AND o2.customer_phone = o.customer_phone AND o2.deleted_at IS NULL
                    WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? AND COALESCE(o.customer_phone, '') <> '' GROUP BY o.customer_phone) x");
                $st->execute([$from, $rid, $from, $to]);
                $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
                $new = (int)($r['new_c'] ?? 0);
                return '👥 ' . ucfirst($label) . ': ' . (int)($r['total'] ?? 0) . ' customers with a phone number, ' . $new . ' new and ' . max(0, (int)($r['total'] ?? 0) - $new) . ' returning.';
            case 'cancellations':
                $st = $conn->prepare("SELECT order_status, COUNT(*) n FROM orders o WHERE o.restaurant_id = ? AND o.deleted_at IS NULL AND o.order_status IN ('Cancelled','Rejected') AND o.created_at >= ? AND o.created_at < ? GROUP BY order_status");
                $st->execute([$rid, $from, $to]);
                $r = array_column($st->fetchAll(PDO::FETCH_ASSOC), 'n', 'order_status');
                return '❌ ' . ucfirst($label) . ': ' . (int)($r['Cancelled'] ?? 0) . ' cancelled and ' . (int)($r['Rejected'] ?? 0) . ' rejected orders.';
            case 'lapsed':
                $st = $conn->prepare("SELECT COUNT(*) FROM customers WHERE restaurant_id = ? AND total_visits >= 3 AND last_visit_date < CURDATE() - INTERVAL 30 DAY");
                $st->execute([$rid]);
                return '😴 ' . (int)$st->fetchColumn() . " regulars (3+ visits) haven't come in 30 days. The \"We miss you\" gift in Growth › Loyalty › Autopilot brings them back automatically.";
            case 'report':
                $f = ownerMgrFacts($conn, $rid, (new DateTime('today', new DateTimeZone($s['tz'])))->format('Y-m-d'));
                $x = $f['sales'] ?? ['amount' => 0, 'orders' => 0];
                return '📊 Today so far: ' . $m($x['amount']) . ' from ' . $x['orders'] . ' orders.' . (!empty($f['top']) ? ' Top: ' . implode(', ', array_map(fn($d) => $d['name'] . ' (' . $d['qty'] . ')', $f['top'])) . '.' : '');
        }
        return "I'm your RestroGrow manager. Ask me things like:\n• sales today / this week / this month\n• top dishes this week\n• least sold dishes\n• WhatsApp vs website orders\n• new customers this month\n• cancelled orders yesterday\nReply 1 or 2 to run a suggestion from the morning report.";
    }

    /**
     * A WhatsApp message from the owner's phone (api/wa_webhook.php). Returns the replies, or null when the
     * AI manager is switched off (the message then goes to the ordering bot, e.g. the owner testing an order).
     */
    function ownerMgrHandleMessage(PDO $conn, string $rid, string $phone, string $text): ?array
    {
        $s = ownerMgrSettings($conn, $rid);
        if (!$s['enabled']) return null;
        $t = trim($text);
        if (preg_match('/^\s*(order|menu)\b/i', $t)) return null;   // the owner trying the ordering bot
        if (preg_match('/^\s*([12])\s*[.!]?\s*$/u', $t, $m)) return [ownerMgrRunAction($conn, $rid, (int)$m[1])];
        if (preg_match('/^\s*(yes|haan|ha|ok|do it|kar do)\s*[.!]*\s*$/iu', $t)) {
            $c = $conn->prepare("SELECT COUNT(*) FROM om_actions WHERE restaurant_id = ? AND status = 'pending'");
            $c->execute([$rid]);
            if ((int)$c->fetchColumn() === 1) return [ownerMgrRunAction($conn, $rid, 1)];
            return ['Which one? Reply 1 or 2.'];
        }
        if (preg_match('/^\s*(no|nahi|nahin|skip)\s*[.!]*\s*$/iu', $t)) {
            $conn->prepare("UPDATE om_actions SET status = 'declined' WHERE restaurant_id = ? AND status = 'pending'")->execute([$rid]);
            return ['👍 Okay, skipped.'];
        }
        [$intent, $period] = ownerMgrIntent($conn, $rid, $t);
        return [ownerMgrAnswer($conn, $rid, $intent, $period)];
    }
}
