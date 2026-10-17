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
        // the report can also come by email
        try { $conn->exec('ALTER TABLE om_settings ADD COLUMN email_on TINYINT NOT NULL DEFAULT 0'); } catch (Throwable $e) { /* already there */ }
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
            'email_on' => (int)($r['email_on'] ?? 0) === 1,
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
        $conn->prepare('INSERT INTO om_settings (restaurant_id, enabled, send_time, language, phone, email_on) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), send_time = VALUES(send_time), language = VALUES(language), phone = VALUES(phone), email_on = VALUES(email_on)')
            ->execute([$rid, empty($in['enabled']) ? 0 : 1, $time, $lang, $phone !== '' ? substr($phone, -12) : null, empty($in['email_on']) ? 0 : 1]);
    }

    function ownerMgrMoney(array $s, float $n): string
    {
        $n = (int)round($n);
        // rupee countries write 3,22,271 (lakh grouping); everyone else 322,271
        if (!preg_match('/₹|^Rs\.?$/u', $s['currency'])) return $s['currency'] . number_format($n);
        $neg = $n < 0 ? '-' : '';
        $d = (string)abs($n);
        if (strlen($d) > 3) { $last = substr($d, -3); $rest = substr($d, 0, -3); $d = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last; }
        return $neg . $s['currency'] . $d;
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
        ownerMgrUseTz($conn, $s);
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
        if (!empty($f['falling'])) $L[] = '📉 ' . $f['falling']['name'] . ($f['falling']['now'] === 0 ? " didn't sell at all this week (" . $f['falling']['prev'] . ' the week before).' : ' sold ' . $f['falling']['drop'] . '% less this week (' . $f['falling']['now'] . ' vs ' . $f['falling']['prev'] . ').');
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
            . "Keep the numbered action lines, and the reply instruction with exactly the same numbers as the DRAFT (if the DRAFT says 'Reply 1', never write 'Reply 1 or 2'). Plain WhatsApp text (*bold* allowed), no markdown headings.";
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
        if ($send && $s['email_on']) {
            try {
                require_once __DIR__ . '/mail_engine.php';
                mailDailySummary($conn, $rid, $text, (string)$f['day']);
            } catch (Throwable $e) { error_log('owner report email: ' . $e->getMessage()); }
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
        if (!$s['enabled'] || ($s['phone'] === '' && !$s['email_on'])) return false;
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

    define('OM_INTENTS', ['sales', 'compare', 'top_dishes', 'slow_dishes', 'dish', 'peak_hours', 'payments', 'channels', 'customers', 'cancellations', 'lapsed', 'report', 'advice', 'help']);
    define('OM_PERIODS', ['today', 'yesterday', 'this_week', 'last_week', 'last_7_days', 'this_month', 'last_month', 'last_30_days']);

    /** The restaurant's own clock for this request: PHP dates and MySQL NOW()/HOUR() (orders.created_at is a TIMESTAMP). */
    function ownerMgrUseTz(PDO $conn, array $s): void
    {
        require_once __DIR__ . '/timezone_utils.php';
        applyRestaurantTimezone($s['tz'], $conn);
    }

    /** The language a question is written in: Devanagari = Hindi, common Hindi words in Roman letters = Hinglish. */
    function ownerMgrGuessLang(string $t): string
    {
        if (preg_match('/\p{Devanagari}/u', $t)) return 'hi';
        return preg_match('/\b(kitna|kitne|kitni|aaj|kal|hafte|mahine|bika|bike|kya|kaise|kaun|sabse|zyada|kam|hua|hue|batao|bata|kamai|grahak|wala|wale|mein|ka|ki|ke)\b/iu', $t) ? 'hinglish' : 'en';
    }

    /**
     * The owner's question → [intent, period, extra]. extra: dish (for "how many paneer tikka"), lang (en / hi / hinglish).
     * Gemini first (it only picks from fixed lists), then simple keywords.
     */
    function ownerMgrIntent(PDO $conn, string $rid, string $text): array
    {
        $r = aiGeminiJson(
            "A restaurant owner asks their business assistant a question on WhatsApp (English, Hindi or Hinglish). Pick the ONE report that answers it.\n"
            . "intents: sales (money / revenue / orders count / average bill / kitna bika / kamai), compare (this period vs the one before, growth, better or worse), "
            . "top_dishes (best sellers), slow_dishes (least sold), dish (how much ONE named dish sold: put its name in dish), peak_hours (busiest hours or days, rush time), "
            . "payments (cash vs UPI vs card vs online), channels (counter vs website vs WhatsApp), customers (new vs returning), cancellations (cancelled / rejected orders), "
            . "lapsed (regulars who stopped coming), report (today's / morning update, how is the day going), advice (what should I do, how to increase sales, ideas, tips), help (greetings or anything else).\n"
            . "periods: today, yesterday, this_week, last_week, last_7_days, this_month, last_month, last_30_days. 'aaj' = today, 'kal' = yesterday, 'is hafte' = this_week, 'is mahine' = this_month. "
            . "If the question names no time, use last_7_days (today only for report or when it says today/aaj).\n"
            . "lang: the language the question is written in: en, hi (Devanagari) or hinglish (Hindi in Roman letters).",
            $text,
            ['type' => 'OBJECT', 'properties' => [
                'intent' => ['type' => 'STRING', 'enum' => OM_INTENTS], 'period' => ['type' => 'STRING', 'enum' => OM_PERIODS],
                'dish' => ['type' => 'STRING'], 'lang' => ['type' => 'STRING', 'enum' => ['en', 'hi', 'hinglish']],
            ], 'required' => ['intent', 'period', 'lang']],
            [], $conn, $rid);
        if ($r && in_array($r['intent'] ?? '', OM_INTENTS, true)) {
            return [$r['intent'], in_array($r['period'] ?? '', OM_PERIODS, true) ? $r['period'] : 'last_7_days',
                ['dish' => trim(mb_substr((string)($r['dish'] ?? ''), 0, 60)), 'lang' => in_array($r['lang'] ?? '', ['en', 'hi', 'hinglish'], true) ? $r['lang'] : ownerMgrGuessLang($text)]];
        }
        $t = mb_strtolower($text);
        $period = preg_match('/\b(today|aaj)\b|आज/u', $t) ? 'today' : (preg_match('/\b(yesterday|kal)\b|कल/u', $t) ? 'yesterday' : (preg_match('/last month|pichle mahine|पिछले महीने/u', $t) ? 'last_month'
            : (preg_match('/month|mahine|महीने/u', $t) ? 'this_month' : (preg_match('/last week|pichle hafte|पिछले हफ्ते/u', $t) ? 'last_week' : (preg_match('/week|hafte|हफ्ते/u', $t) ? 'this_week' : 'last_7_days')))));
        $intent = preg_match('/compare|vs last|than last|growth|better|worse/u', $t) ? 'compare'
            : (preg_match('/busiest|busy|rush|peak|hour|time|ghante/u', $t) ? 'peak_hours'
            : (preg_match('/\b(cash|upi|card|payment)/u', $t) ? 'payments'
            : (preg_match('/top|best|most|sabse zyada/u', $t) ? 'top_dishes' : (preg_match('/least|slow|worst|kam bik/u', $t) ? 'slow_dishes'
            : (preg_match('/whatsapp|website|counter|channel|online/u', $t) ? 'channels' : (preg_match('/new customer|customer|grahak|ग्राहक/u', $t) ? 'customers'
            : (preg_match('/cancel|reject/u', $t) ? 'cancellations' : (preg_match('/increase|grow|improve|idea|tip|advice|should i|kya karu|kaise badh/u', $t) ? 'advice'
            : (preg_match('/sale|sold|bika|kamai|revenue|kitna|how much|total|earn|average|order/u', $t) ? 'sales' : 'help')))))))));
        return [$intent, $intent === 'help' ? 'today' : $period, ['dish' => '', 'lang' => ownerMgrGuessLang($text)]];
    }

    /** The period to compare with: the same stretch before (so far vs same time last week / month). [from, to, label] */
    function ownerMgrPrevPeriod(array $s, string $period): array
    {
        $tz = new DateTimeZone($s['tz']);
        $now = new DateTime('now', $tz);
        [$from, $to] = ownerMgrPeriod($s, $period);
        $F = new DateTime($from, $tz);
        $T = new DateTime($to, $tz);
        $f = fn(DateTime $x) => $x->format('Y-m-d H:i:s');
        switch ($period) {
            case 'today': return [$f((clone $F)->modify('-7 days')), $f((clone $now)->modify('-7 days')), 'same time last ' . $now->format('l')];
            case 'this_week': return [$f((clone $F)->modify('-7 days')), $f((clone $now)->modify('-7 days')), 'same days last week'];
            case 'this_month': return [$f((clone $F)->modify('-1 month')), $f((clone $now)->modify('-1 month')), 'same days last month'];
            case 'yesterday': return [$f((clone $F)->modify('-7 days')), $f((clone $T)->modify('-7 days')), 'the same day last week'];
            case 'last_week': return [$f((clone $F)->modify('-7 days')), $f((clone $T)->modify('-7 days')), 'the week before'];
            case 'last_month': return [$f((clone $F)->modify('-1 month')), $f((clone $F)), 'the month before'];
            default:
                $days = (int)$F->diff($T)->days;
                return [$f((clone $F)->modify("-$days days")), $f($F), "the $days days before"];
        }
    }

    /** "+12% vs same days last week" (or '' when there is nothing to compare with). */
    function ownerMgrChange(float $now, float $prev, string $label): string
    {
        if ($prev <= 0) return '';
        $p = (int)round(($now - $prev) / $prev * 100);
        return ($p >= 0 ? '📈 +' : '📉 ') . $p . '% vs ' . $label;
    }

    /** A handful of tips with real numbers, from the restaurant's own data. */
    function ownerMgrAdvice(PDO $conn, string $rid, array $s): string
    {
        $m = fn($n) => ownerMgrMoney($s, (float)$n);
        $f = ownerMgrFacts($conn, $rid);
        [$mf, $mt] = ownerMgrPeriod($s, 'last_30_days');
        $tips = [];
        if (($f['lapsed'] ?? 0) >= 3) $tips[] = '🎁 ' . $f['lapsed'] . " regulars (3+ visits) haven't come in 30 days." . (empty($f['winback_on']) ? ' Turn on the "We miss you" gift (Growth › Loyalty › Autopilot) to bring them back.' : ' The "We miss you" gift is on and working on them.');
        foreach (array_slice($f['off_dishes'] ?? [], 0, 1) as $d) $tips[] = '🍽️ ' . $d['name'] . ' sold ' . $d['qty'] . ' in 30 days but is switched off. Switch it back on if you can make it.';
        $ch = ownerMgrSales($conn, $rid, $mf, $mt);
        if ($ch['orders'] >= 20) {
            $direct = $ch['web'] + $ch['wa'];
            $share = (int)round($direct / $ch['orders'] * 100);
            if ($share < 30) $tips[] = '📲 Only ' . $share . '% of your ' . $ch['orders'] . ' orders in 30 days came online (website + WhatsApp). Put your QR on every table and parcel so regulars order direct, with no commission.';
        }
        $top = ownerMgrDishes($conn, $rid, $mf, $mt, 'DESC', 1);
        if ($top) $tips[] = '⭐ ' . $top[0]['name'] . ' is your best seller (' . $top[0]['qty'] . ' in 30 days). Make a combo or a "today\'s special" around it to lift the average bill (' . $m($ch['avg']) . ' now).';
        $slow = ownerMgrDishes($conn, $rid, $mf, $mt, 'ASC', 2);
        if (count($slow) === 2 && $slow[0]['qty'] <= 3) $tips[] = '🐢 ' . $slow[0]['name'] . ' (' . $slow[0]['qty'] . ') and ' . $slow[1]['name'] . ' (' . $slow[1]['qty'] . ') barely sold in 30 days: drop them or show them with a photo and offer.';
        if (!empty($f['falling'])) $tips[] = '📉 ' . $f['falling']['name'] . ($f['falling']['now'] === 0 ? " didn't sell at all this week" : ' sold ' . $f['falling']['drop'] . '% less this week') . ' (' . $f['falling']['prev'] . ' the week before). Check its taste, price and photo.';
        if (!$tips) return "📊 Things look steady. Keep an eye on your best sellers and ask me \"compare this week with last week\" any time.";
        return "💡 What I'd do next, from your own numbers:\n" . implode("\n", array_slice($tips, 0, 4));
    }

    function ownerMgrAnswer(PDO $conn, string $rid, string $intent, string $period, array $extra = []): string
    {
        $s = ownerMgrSettings($conn, $rid);
        ownerMgrUseTz($conn, $s);
        [$from, $to, $label] = ownerMgrPeriod($s, $period);
        $m = fn($n) => ownerMgrMoney($s, (float)$n);
        switch ($intent) {
            case 'sales':
            case 'compare':
                $x = ownerMgrSales($conn, $rid, $from, $to);
                [$pf, $pt, $pl] = ownerMgrPrevPeriod($s, $period);
                $p = ownerMgrSales($conn, $rid, $pf, $pt);
                $chg = ownerMgrChange($x['amount'], $p['amount'], $pl);
                $out = '💰 ' . ucfirst($label) . ': ' . $m($x['amount']) . ' from ' . $x['orders'] . ' orders' . ($x['orders'] ? ', average bill ' . $m($x['avg']) : '') . '.';
                if ($intent === 'compare' || $chg !== '') $out .= "\n" . ($chg !== '' ? $chg . ' (' . $m($p['amount']) . ', ' . $p['orders'] . ' orders).' : 'Nothing to compare with yet.');
                return $out;
            case 'top_dishes':
            case 'slow_dishes':
                $d = ownerMgrDishes($conn, $rid, $from, $to, $intent === 'top_dishes' ? 'DESC' : 'ASC', 5);
                if (!$d) return 'No dishes sold ' . $label . '.';
                return ($intent === 'top_dishes' ? '🥇 Best sellers ' : '🐢 Least sold ') . $label . ":\n" . implode("\n", array_map(fn($x, $i) => ($i + 1) . '. ' . $x['name'] . ' — ' . $x['qty'] . ' (' . $m($x['amount']) . ')', $d, array_keys($d)));
            case 'dish':
                $name = trim((string)($extra['dish'] ?? ''));
                if ($name === '') return 'Which dish? For example: "how many Paneer Tikka sold this week".';
                $words = array_filter(preg_split('/\s+/u', $name), fn($w) => mb_strlen($w) >= 3);
                if (!$words) $words = [$name];
                $cond = implode(' AND ', array_fill(0, count($words), 'oi.item_name LIKE ?'));
                $st = $conn->prepare("SELECT oi.item_name name, SUM(oi.quantity) qty, COALESCE(SUM(oi.total_price), 0) amount FROM order_items oi JOIN orders o ON o.id = oi.order_id
                    WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? AND $cond GROUP BY oi.item_name ORDER BY qty DESC LIMIT 4");
                $st->execute(array_merge([$rid, $from, $to], array_map(fn($w) => '%' . $w . '%', array_values($words))));
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) return "🔎 No \"$name\" sold " . $label . '. Check the name as it is on your menu.';
                return '🍽️ ' . ucfirst($label) . ":\n" . implode("\n", array_map(fn($r) => '• ' . $r['name'] . ' — ' . (int)$r['qty'] . ' sold (' . $m($r['amount']) . ')', $rows));
            case 'peak_hours':
                $st = $conn->prepare("SELECT HOUR(o.created_at) h, COUNT(*) n, COALESCE(SUM(o.total), 0) amount FROM orders o WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? GROUP BY h ORDER BY n DESC LIMIT 3");
                $st->execute([$rid, $from, $to]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) return 'No orders ' . $label . ' yet.';
                $hr = fn($h) => date('g a', mktime((int)$h, 0, 0)) . '–' . date('g a', mktime(((int)$h + 1) % 24, 0, 0));
                $out = '⏰ Busiest hours ' . $label . ":\n" . implode("\n", array_map(fn($r, $i) => ($i + 1) . '. ' . $hr($r['h']) . ' — ' . (int)$r['n'] . ' orders (' . $m($r['amount']) . ')', $rows, array_keys($rows)));
                if (!in_array($period, ['today', 'yesterday'], true)) {
                    $dw = $conn->prepare("SELECT DAYNAME(o.created_at) d, COUNT(*) n FROM orders o WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? GROUP BY d ORDER BY n DESC LIMIT 1");
                    $dw->execute([$rid, $from, $to]);
                    if ($d = $dw->fetch(PDO::FETCH_ASSOC)) $out .= "\n📅 Busiest day: " . $d['d'] . ' (' . (int)$d['n'] . ' orders).';
                }
                return $out;
            case 'payments':
                $st = $conn->prepare("SELECT COALESCE(NULLIF(o.payment_method, ''), 'Other') pm, COUNT(*) n, COALESCE(SUM(o.total), 0) amount FROM orders o WHERE o.restaurant_id = ? AND " . OM_LIVE . " AND o.created_at >= ? AND o.created_at < ? GROUP BY pm ORDER BY amount DESC");
                $st->execute([$rid, $from, $to]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC);
                if (!$rows) return 'No orders ' . $label . ' yet.';
                $total = array_sum(array_column($rows, 'amount')) ?: 1;
                return '💳 Payments ' . $label . ":\n" . implode("\n", array_map(fn($r) => '• ' . $r['pm'] . ': ' . $m($r['amount']) . ' (' . (int)$r['n'] . ' orders, ' . (int)round($r['amount'] / $total * 100) . '%)', $rows));
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
                $tz = new DateTimeZone($s['tz']);
                $f = ownerMgrFacts($conn, $rid, (new DateTime('today', $tz))->format('Y-m-d'));
                $x = $f['sales'] ?? ['amount' => 0, 'orders' => 0];
                [$pf, $pt, $pl] = ownerMgrPrevPeriod($s, 'today');
                $chg = ownerMgrChange((float)$x['amount'], ownerMgrSales($conn, $rid, $pf, $pt)['amount'], $pl);
                return '📊 Today so far: ' . $m($x['amount']) . ' from ' . $x['orders'] . ' orders.' . ($chg !== '' ? "\n" . $chg . '.' : '')
                    . (!empty($f['top']) ? "\n🥇 Top: " . implode(', ', array_map(fn($d) => $d['name'] . ' (' . $d['qty'] . ')', $f['top'])) . '.' : '');
            case 'advice':
                return ownerMgrAdvice($conn, $rid, $s);
        }
        return "👋 I'm your RestroGrow manager. Ask me things like:\n• sales today / this week / this month\n• compare this week with last week\n• top dishes this week · least sold dishes\n• how many Paneer Tikka sold this week\n• busiest hours · cash vs UPI\n• new customers this month · cancelled orders yesterday\n• what should I do to increase sales?\nReply 1 or 2 to run a suggestion from the morning report.";
    }

    /** Re-word an answer in Hindi or Hinglish with Gemini; every number must stay the same, else the English text is kept. */
    function ownerMgrLocalize(PDO $conn, string $rid, string $text, string $lang): string
    {
        if ($lang === 'en' || !aiGeminiAvailable()) return $text;
        $want = $lang === 'hi' ? 'simple Hindi in Devanagari script (keep dish names as written)' : 'Hinglish (Hindi in Roman letters mixed with English, the way Indian restaurant owners text)';
        $t = aiGeminiText("Rewrite this short WhatsApp reply from a restaurant's business assistant in $want. Keep every number, amount, percentage, emoji and line break exactly as they are. "
            . "Do not add advice, numbers or greetings. Plain WhatsApp text.", $text, $conn, $rid, 600);
        if (!$t) return $text;
        $allowed = ownerMgrNumbers($text);
        foreach (ownerMgrNumbers($t) as $n) if (!in_array($n, $allowed, true)) { error_log("ownerMgrLocalize: number $n not in the answer, English kept"); return $text; }
        return trim($t);
    }

    /** Question → answer in the asker's language (dashboard chat and WhatsApp use the same path). */
    function ownerMgrAsk(PDO $conn, string $rid, string $text): array
    {
        [$intent, $period, $extra] = ownerMgrIntent($conn, $rid, $text);
        $answer = ownerMgrAnswer($conn, $rid, $intent, $period, $extra);
        return ['answer' => ownerMgrLocalize($conn, $rid, $answer, $extra['lang'] ?? 'en'), 'intent' => $intent, 'period' => $period];
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
        if (preg_match('/^\s*(yes|haan|ha|ok|do it|kar do|हाँ|हां)\s*[.!]*\s*$/iu', $t)) {
            $c = $conn->prepare("SELECT COUNT(*) FROM om_actions WHERE restaurant_id = ? AND status = 'pending'");
            $c->execute([$rid]);
            $n = (int)$c->fetchColumn();
            if ($n === 1) return [ownerMgrRunAction($conn, $rid, 1)];
            return [$n ? 'Which one? Reply 1 or 2.' : "There's nothing waiting right now. Ask me anything, e.g. \"sales this week\"."];
        }
        if (preg_match('/^\s*(no|nahi|nahin|skip|नहीं)\s*[.!]*\s*$/iu', $t)) {
            $conn->prepare("UPDATE om_actions SET status = 'declined' WHERE restaurant_id = ? AND status = 'pending'")->execute([$rid]);
            return ['👍 Okay, skipped.'];
        }
        return [ownerMgrAsk($conn, $rid, $t)['answer']];
    }

    /** The numbers strip on the AI manager page: today, yesterday, this week, this month, each against the period before. */
    function ownerMgrKpis(PDO $conn, string $rid): array
    {
        $s = ownerMgrSettings($conn, $rid);
        ownerMgrUseTz($conn, $s);
        $out = [];
        foreach (['today' => 'Today so far', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month'] as $p => $title) {
            [$f, $t] = ownerMgrPeriod($s, $p);
            [$pf, $pt, $pl] = ownerMgrPrevPeriod($s, $p);
            $x = ownerMgrSales($conn, $rid, $f, $t);
            $y = ownerMgrSales($conn, $rid, $pf, $pt);
            $out[] = ['key' => $p, 'title' => $title, 'amount' => ownerMgrMoney($s, $x['amount']), 'orders' => $x['orders'],
                'change' => $y['amount'] > 0 ? (int)round(($x['amount'] - $y['amount']) / $y['amount'] * 100) : null, 'vs' => $pl];
        }
        return $out;
    }
}
