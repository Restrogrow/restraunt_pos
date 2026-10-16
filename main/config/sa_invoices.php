<?php
/**
 * Superadmin invoices: the "Invoice generator" (pick restaurant, plan, months, add-ons → numbered
 * invoice + branded PDF) and the same template for subscription-payment invoices.
 *
 *  - Numbers are given out by the server, never typed: {prefix}/{financial year}/M{0001}, a series of
 *    their own per Indian financial year (Apr–Mar), so they never clash with the payment invoices
 *    ({prefix}/{FY}/{payment id}). Max 16 characters, as GST asks.
 *  - Every generated invoice is saved (sa_invoices) so it can be opened, downloaded, marked paid or
 *    cancelled later. A cancelled invoice keeps its number (no gaps in the series).
 *  - Prices follow Settings › "Plan prices include GST / GST added on top". CGST + SGST when the
 *    customer is in our state (or has no GSTIN), IGST when their GSTIN is from another state.
 *  - Plans and add-ons with their prices: sa_settings('invoice_catalog'), editable in the generator.
 *
 * Used by superadmin/api_platform.php.
 */

/** Creates sa_invoices on first use (live databases included). */
function saInvEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    $conn->exec("CREATE TABLE IF NOT EXISTS sa_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_no VARCHAR(40) NOT NULL,
        fy VARCHAR(9) NOT NULL, seq INT NOT NULL,
        restaurant_id VARCHAR(10) NOT NULL, restaurant_name VARCHAR(190) NULL,
        billed_to TEXT NULL, items TEXT NOT NULL,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0, discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        taxable DECIMAL(12,2) NOT NULL DEFAULT 0, tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
        cgst DECIMAL(12,2) NOT NULL DEFAULT 0, sgst DECIMAL(12,2) NOT NULL DEFAULT 0, igst DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'paid',
        method VARCHAR(30) NULL, reference VARCHAR(100) NULL,
        invoice_date DATE NOT NULL, due_date DATE NULL, paid_on DATE NULL,
        period_from DATE NULL, period_to DATE NULL,
        notes TEXT NULL, created_by VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_no (invoice_no), UNIQUE KEY uq_fy_seq (fy, seq),
        INDEX (restaurant_id), INDEX (invoice_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // join with users without "Illegal mix of collations"
    try {
        $coll = $conn->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'restaurant_id'")->fetchColumn();
        $mine = $conn->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sa_invoices' AND COLUMN_NAME = 'restaurant_id'")->fetchColumn();
        if ($coll && $coll !== $mine && preg_match('/^utf8mb4_[a-z0-9_]+$/', $coll)) $conn->exec("ALTER TABLE sa_invoices CONVERT TO CHARACTER SET utf8mb4 COLLATE $coll");
    } catch (Throwable $e) {
        error_log('saInvEnsure collation: ' . $e->getMessage());
    }
}

/** Indian financial year of a date, e.g. 2026-27. */
function saInvFy(?string $date = null): string
{
    $t = $date ? strtotime($date) : time();
    $y = (int)date('Y', $t);
    return (int)date('n', $t) >= 4 ? $y . '-' . substr((string)($y + 1), 2) : ($y - 1) . '-' . substr((string)$y, 2);
}

function saInvPrefix(array $set): string
{
    return preg_replace('/[^A-Za-z0-9-]/', '', $set['invoice_prefix'] ?? 'RG') ?: 'RG';
}

function saInvFormatNo(array $set, string $fy, int $seq): string
{
    return saInvPrefix($set) . '/' . $fy . '/M' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}

/** The number the next invoice will get (shown in the generator; assigned for real on create). */
function saInvPeekNo(PDO $conn, array $set, ?string $date = null): string
{
    saInvEnsure($conn);
    $fy = saInvFy($date);
    $st = $conn->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM sa_invoices WHERE fy = ?');
    $st->execute([$fy]);
    return saInvFormatNo($set, $fy, (int)$st->fetchColumn());
}

/** Plans and add-ons with prices (defaults, overridden by sa_settings('invoice_catalog')). */
function saInvCatalog(array $set): array
{
    $cat = [
        'plans' => [
            ['key' => 'small',    'name' => 'Small Restaurant',   'sub' => 'For 1–5 tables',       'price' => 2999],
            ['key' => 'growing',  'name' => 'Growing Restaurant', 'sub' => 'For 6–20 tables',      'price' => 4999],
            ['key' => 'multi',    'name' => 'Multiple Locations', 'sub' => 'For 2+ restaurants',   'price' => 9999],
            ['key' => 'standard', 'name' => 'Online plan',        'sub' => 'The plan paid in the app', 'price' => (float)($set['plan_price'] ?? 999)],
        ],
        'addons' => [
            ['key' => 'setup',    'name' => 'Onboarding & setup',              'sub' => 'Account, menu, tables, printers',  'price' => 1999, 'sac' => '998314', 'per' => ''],
            ['key' => 'menu',     'name' => 'Menu upload & photo styling',     'sub' => 'Dishes, categories, photos',       'price' => 1499, 'sac' => '998314', 'per' => ''],
            ['key' => 'website',  'name' => 'Custom domain & website setup',   'sub' => 'Your own web address',             'price' => 2499, 'sac' => '998314', 'per' => ''],
            ['key' => 'whatsapp', 'name' => 'WhatsApp ordering setup',         'sub' => 'Order alerts & messages',          'price' => 999,  'sac' => '998314', 'per' => ''],
            ['key' => 'training', 'name' => 'Staff training',                  'sub' => 'On-site or video session',         'price' => 999,  'sac' => '999293', 'per' => ''],
            ['key' => 'standee',  'name' => 'QR table standees',               'sub' => 'Printed, one per table',           'price' => 149,  'sac' => '998912', 'per' => 'table'],
        ],
        'sac' => '998314',
    ];
    $saved = json_decode((string)($set['invoice_catalog'] ?? ''), true);
    if (is_array($saved)) {
        foreach (['plans', 'addons'] as $grp) {
            if (empty($saved[$grp]) || !is_array($saved[$grp])) continue;
            $byKey = [];
            foreach ($saved[$grp] as $x) if (is_array($x) && isset($x['key'])) $byKey[$x['key']] = $x;
            foreach ($cat[$grp] as &$x) {
                if (!isset($byKey[$x['key']])) continue;
                $s = $byKey[$x['key']];
                if (isset($s['price']) && is_numeric($s['price'])) $x['price'] = max(0, (float)$s['price']);
                if (isset($s['name']) && trim((string)$s['name']) !== '') $x['name'] = mb_substr(trim((string)$s['name']), 0, 80);
                if (isset($s['sub'])) $x['sub'] = mb_substr(trim((string)$s['sub']), 0, 80);
                if (array_key_exists('hidden', $s)) $x['hidden'] = !empty($s['hidden']);
            }
            unset($x);
        }
    }
    // the online plan always follows Settings › monthly price
    foreach ($cat['plans'] as &$p) if ($p['key'] === 'standard') $p['price'] = (float)($set['plan_price'] ?? $p['price']);
    unset($p);
    return $cat;
}

/** Saves prices / names / hidden flags of the catalogue. */
function saInvSaveCatalog(PDO $conn, array $in): void
{
    $keep = ['plans' => [], 'addons' => []];
    foreach ($keep as $grp => $_) {
        foreach ((array)($in[$grp] ?? []) as $x) {
            if (!is_array($x) || !preg_match('/^[a-z0-9_]{1,20}$/', (string)($x['key'] ?? ''))) continue;
            $keep[$grp][] = ['key' => $x['key'], 'price' => max(0, min(10000000, (float)($x['price'] ?? 0))),
                'name' => mb_substr(trim((string)($x['name'] ?? '')), 0, 80), 'sub' => mb_substr(trim((string)($x['sub'] ?? '')), 0, 80),
                'hidden' => !empty($x['hidden'])];
        }
    }
    $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('invoice_catalog', ?)")->execute([json_encode($keep, JSON_UNESCAPED_UNICODE)]);
}

/** Two-digit GST state code from a GSTIN ('' if none). */
function saInvStateCode(?string $gstin): string
{
    $g = strtoupper(trim((string)$gstin));
    return preg_match('/^([0-9]{2})[A-Z0-9]{13}$/', $g, $m) ? $m[1] : '';
}

const SA_INV_STATES = ['01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana',
    '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland',
    '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand',
    '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat', '26' => 'Dadra & Nagar Haveli and Daman & Diu',
    '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry',
    '35' => 'Andaman & Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'];

/**
 * Totals for a list of lines [desc, sub, sac, qty, rate] with a discount (₹).
 * $inclusive: line rates already include GST. $inter: IGST instead of CGST + SGST.
 */
function saInvCompute(array $lines, float $discount, float $rate, bool $inclusive, bool $inter): array
{
    $items = [];
    $subtotal = 0.0;
    foreach ($lines as $l) {
        $qty = max(0, (float)($l['qty'] ?? 0));
        $r = max(0, (float)($l['rate'] ?? 0));
        $desc = trim((string)($l['desc'] ?? ''));
        if ($desc === '' || $qty <= 0) continue;
        $amt = round($qty * $r, 2);
        $items[] = ['desc' => mb_substr($desc, 0, 160), 'sub' => mb_substr(trim((string)($l['sub'] ?? '')), 0, 200),
            'sac' => preg_replace('/[^0-9]/', '', (string)($l['sac'] ?? '')) ?: '998314', 'qty' => $qty, 'unit' => mb_substr((string)($l['unit'] ?? ''), 0, 20), 'rate' => $r, 'amount' => $amt];
        $subtotal += $amt;
    }
    $subtotal = round($subtotal, 2);
    $discount = round(max(0, min($discount, $subtotal)), 2);
    $net = round($subtotal - $discount, 2);
    $rate = max(0, min(40, $rate));
    if ($inclusive) { $taxable = $rate > 0 ? round($net * 100 / (100 + $rate), 2) : $net; $tax = round($net - $taxable, 2); $total = $net; }
    else { $taxable = $net; $tax = round($net * $rate / 100, 2); $total = round($net + $tax, 2); }
    $cgst = $sgst = $igst = 0.0;
    if ($inter) $igst = $tax; else { $cgst = round($tax / 2, 2); $sgst = round($tax - $cgst, 2); }
    return ['items' => $items, 'subtotal' => $subtotal, 'discount' => $discount, 'taxable' => $taxable, 'tax_rate' => $rate,
        'tax' => $tax, 'cgst' => $cgst, 'sgst' => $sgst, 'igst' => $igst, 'total' => $total, 'inclusive' => $inclusive, 'inter' => $inter];
}

/** Saves a new invoice and gives it the next number of its financial year. Returns the saved row. */
function saInvCreate(PDO $conn, array $set, array $row): array
{
    saInvEnsure($conn);
    $fy = saInvFy($row['invoice_date']);
    for ($try = 0; $try < 5; $try++) {
        try {
            $conn->beginTransaction();
            $st = $conn->prepare('SELECT COALESCE(MAX(seq), 0) + 1 FROM sa_invoices WHERE fy = ? FOR UPDATE');
            $st->execute([$fy]);
            $seq = (int)$st->fetchColumn();
            $no = saInvFormatNo($set, $fy, $seq);
            $conn->prepare('INSERT INTO sa_invoices (invoice_no, fy, seq, restaurant_id, restaurant_name, billed_to, items, subtotal, discount, taxable, tax_rate,
                    cgst, sgst, igst, total, status, method, reference, invoice_date, due_date, paid_on, period_from, period_to, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$no, $fy, $seq, $row['restaurant_id'], $row['restaurant_name'], json_encode($row['billed_to'], JSON_UNESCAPED_UNICODE),
                    json_encode($row['items'], JSON_UNESCAPED_UNICODE), $row['subtotal'], $row['discount'], $row['taxable'], $row['tax_rate'],
                    $row['cgst'], $row['sgst'], $row['igst'], $row['total'], $row['status'], $row['method'], $row['reference'],
                    $row['invoice_date'], $row['due_date'], $row['paid_on'], $row['period_from'], $row['period_to'], $row['notes'], $row['created_by']]);
            $id = (int)$conn->lastInsertId();
            $conn->commit();
            return saInvGet($conn, $id);
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            if ((string)$e->getCode() !== '23000') throw $e;   // duplicate number: someone else took it, try the next
        }
    }
    throw new Exception('Could not give the invoice a number, please try again');
}

function saInvGet(PDO $conn, int $id): array
{
    saInvEnsure($conn);
    $st = $conn->prepare('SELECT * FROM sa_invoices WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new Exception('Invoice not found');
    $r['billed_to'] = json_decode((string)$r['billed_to'], true) ?: [];
    $r['items'] = json_decode((string)$r['items'], true) ?: [];
    return $r;
}

/** "Rupees Fourteen Thousand Nine Hundred Ninety-Seven and Fifty Paise Only" (Indian grouping). */
function saInvWords(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen',
        'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens) { return $n < 20 ? $ones[$n] : $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : ''); };
    $three = function (int $n) use ($two, $ones) { return ($n >= 100 ? $ones[intdiv($n, 100)] . ' Hundred' . ($n % 100 ? ' ' : '') : '') . ($n % 100 ? $two($n % 100) : ''); };
    $amount = round(max(0, $amount), 2);
    $r = (int)floor($amount);
    $p = (int)round(($amount - $r) * 100);
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $name]) {
        if ($r >= $div) { $q = intdiv($r, $div); $parts[] = ($q >= 100 ? $three($q) : $two($q)) . ' ' . $name; $r %= $div; }
    }
    if ($r) $parts[] = $three($r);
    $s = 'Rupees ' . ($parts ? implode(' ', $parts) : 'Zero');
    if ($p) $s .= ' and ' . $two($p) . ' Paise';
    return $s . ' Only';
}

/** Our details from Settings with sensible fallbacks. */
function saInvSeller(array $set): array
{
    return [
        'name' => trim($set['biz_name'] ?? '') ?: 'RestroGrow',
        'address' => trim($set['biz_address'] ?? ''),
        'gstin' => strtoupper(trim($set['biz_gstin'] ?? '')),
        'email' => trim($set['biz_email'] ?? ''),
        'phone' => trim($set['biz_phone'] ?? ''),
        'upi' => trim($set['biz_upi'] ?? ''),
        'bank' => trim($set['biz_bank'] ?? ''),
        'web' => 'restrogrow.com',
    ];
}

/**
 * The branded invoice page (A4, prints and downloads as PDF).
 * $inv: invoice_no, invoice_date, due_date, paid_on, status (paid|due|void), method, reference, period_from, period_to,
 *       billed_to {name, owner, address, gstin, email, phone, rid}, items[], subtotal, discount, taxable, tax_rate,
 *       cgst, sgst, igst, total, notes. $opt: autodownload (bool), back (url).
 */
function saInvRender(array $inv, array $set, array $opt = []): string
{
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $m = fn($n) => '₹' . number_format((float)$n, 2);
    $d = fn($s) => $s ? date('d M Y', strtotime((string)$s)) : '';
    $q = function ($n) { $n = (float)$n; return $n == (int)$n ? (string)(int)$n : rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'); };
    $seller = saInvSeller($set);
    $b = $inv['billed_to'] ?? [];
    $status = $inv['status'] ?? 'paid';
    $isTax = $seller['gstin'] !== '';
    $title = $isTax ? 'Tax Invoice' : 'Invoice';
    $rate = (float)($inv['tax_rate'] ?? 0);
    $inter = (float)($inv['igst'] ?? 0) > 0;
    $buyerState = saInvStateCode($b['gstin'] ?? '');
    $sellerState = saInvStateCode($seller['gstin']);
    $pos = $buyerState ? (SA_INV_STATES[$buyerState] ?? '') . ' (' . $buyerState . ')' : ($sellerState ? (SA_INV_STATES[$sellerState] ?? '') . ' (' . $sellerState . ')' : '');
    $logoFile = __DIR__ . '/../assets/images/logo-transparent.png';
    $logo = is_file($logoFile) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoFile)) : '';
    $file = 'Invoice-' . preg_replace('/[^A-Za-z0-9-]+/', '-', (string)$inv['invoice_no']) . '.pdf';

    $rows = '';
    foreach ($inv['items'] as $i => $it) {
        $rows .= '<tr><td class="n">' . ($i + 1) . '</td><td><b>' . $h($it['desc']) . '</b>' . (!empty($it['sub']) ? '<small>' . $h($it['sub']) . '</small>' : '') . '</td>'
            . '<td class="c">' . $h($it['sac'] ?? '') . '</td><td class="c">' . $h($q($it['qty'])) . (!empty($it['unit']) ? ' <span class="u">' . $h($it['unit']) . '</span>' : '') . '</td>'
            . '<td class="r">' . $m($it['rate']) . '</td><td class="r"><b>' . $m($it['amount']) . '</b></td></tr>';
    }
    $tot = '';
    if ((float)$inv['discount'] > 0) $tot .= '<tr><td>Subtotal</td><td>' . $m($inv['subtotal']) . '</td></tr><tr class="disc"><td>Discount</td><td>−' . $m($inv['discount']) . '</td></tr>';
    $tot .= '<tr><td>Taxable value</td><td>' . $m($inv['taxable']) . '</td></tr>';
    if ($rate > 0) {
        $tot .= $inter
            ? '<tr><td>IGST ' . $h($q($rate)) . '%</td><td>' . $m($inv['igst']) . '</td></tr>'
            : '<tr><td>CGST ' . $h($q($rate / 2)) . '%</td><td>' . $m($inv['cgst']) . '</td></tr><tr><td>SGST ' . $h($q($rate / 2)) . '%</td><td>' . $m($inv['sgst']) . '</td></tr>';
    }

    $stamp = ['paid' => ['Paid', 'paid'], 'due' => ['Payment due', 'due'], 'void' => ['Cancelled', 'void']][$status] ?? ['Paid', 'paid'];
    $amountLabel = $status === 'paid' ? 'Amount paid' : ($status === 'void' ? 'Cancelled' : 'Amount due');

    // payment block: how it was paid, or how to pay
    $pay = '';
    if ($status === 'paid') {
        $pay = '<div class="pay"><div class="pay-h">Payment received</div><div class="pay-g">'
            . '<div><span>Method</span><b>' . $h($inv['method'] ?: '—') . '</b></div>'
            . '<div><span>Paid on</span><b>' . $h($d($inv['paid_on'] ?: $inv['invoice_date'])) . '</b></div>'
            . ($inv['reference'] ? '<div><span>Reference</span><b>' . $h($inv['reference']) . '</b></div>' : '')
            . '</div></div>';
    } elseif ($status === 'due') {
        $upi = $seller['upi'];
        $upiLink = $upi ? 'upi://pay?pa=' . rawurlencode($upi) . '&pn=' . rawurlencode($seller['name']) . '&am=' . number_format((float)$inv['total'], 2, '.', '') . '&cu=INR&tn=' . rawurlencode('Invoice ' . $inv['invoice_no']) : '';
        $pay = '<div class="pay due"><div class="pay-h">How to pay' . ($inv['due_date'] ? ' · by ' . $h($d($inv['due_date'])) : '') . '</div><div class="pay-row">'
            . ($upi ? '<div class="qr" id="upiQr" data-upi="' . $h($upiLink) . '"></div>' : '')
            . '<div class="pay-g">'
            . ($upi ? '<div><span>UPI</span><b>' . $h($upi) . '</b></div>' : '')
            . ($seller['bank'] ? '<div class="wide"><span>Bank transfer</span><b>' . nl2br($h($seller['bank'])) . '</b></div>' : '')
            . (!$upi && !$seller['bank'] ? '<div class="wide"><span>Payment</span><b>Please pay using the link or details shared by the RestroGrow team.</b></div>' : '')
            . '<div class="wide"><span>Please mention</span><b>' . $h($inv['invoice_no']) . '</b></div>'
            . '</div></div></div>';
    }

    $period = ($inv['period_from'] ?? '') && ($inv['period_to'] ?? '') ? $d($inv['period_from']) . ' – ' . $d($inv['period_to']) : '';
    $sellerLines = array_filter([nl2br($h($seller['address'])), $seller['gstin'] ? 'GSTIN <b>' . $h($seller['gstin']) . '</b>' : '',
        implode(' · ', array_filter([$h($seller['email']), $h($seller['phone'])]))]);
    $buyerLines = array_filter([$b['owner'] ?? '' ? $h($b['owner']) : '', nl2br($h($b['address'] ?? '')),
        ($b['gstin'] ?? '') ? 'GSTIN <b>' . $h($b['gstin']) . '</b>' : '', implode(' · ', array_filter([$h($b['phone'] ?? ''), $h($b['email'] ?? '')])),
        ($b['rid'] ?? '') ? 'Restaurant ID ' . $h($b['rid']) : '']);
    $back = $opt['back'] ?? '';
    $auto = !empty($opt['autodownload']);

    ob_start(); ?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title . ' ' . $inv['invoice_no']) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root { --acc:#ff5a14; --acc-d:#d9440a; --ink:#16130f; --ink2:#4b4540; --mute:#8a827a; --line:#ece7e1; --soft:#faf7f3; --ok:#15803d; --warn:#b45309; }
  * { box-sizing: border-box; }
  html, body { margin: 0; background: #e9e5df; color: var(--ink); font-family: 'Plus Jakarta Sans', 'Segoe UI', system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: rgba(22,19,15,.92); color: #fff; }
  .bar .t { font-weight: 700; font-size: 14px; margin-right: auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .bar .t small { opacity: .6; font-weight: 500; margin-left: 6px; }
  .bar a, .bar button { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 16px; border: 0; border-radius: 10px; font: 700 13.5px 'Plus Jakarta Sans', sans-serif; cursor: pointer; text-decoration: none; }
  .bar .pri { background: var(--acc); color: #fff; } .bar .sec { background: rgba(255,255,255,.12); color: #fff; }
  .bar button:disabled { opacity: .6; cursor: progress; }
  .wrap { padding: 24px 12px 48px; overflow-x: auto; }
  .page { position: relative; width: 794px; min-height: 1123px; margin: 0 auto; background: #fff; box-shadow: 0 20px 60px -20px rgba(0,0,0,.25); overflow: hidden; display: flex; flex-direction: column; }
  .top { position: relative; padding: 44px 48px 30px; background: linear-gradient(135deg, #fff 0%, #fff 55%, #fff4ec 100%); }
  .top::after { content: ''; position: absolute; left: 0; right: 0; bottom: 0; height: 5px; background: linear-gradient(90deg, var(--acc) 0%, #ff8a3d 60%, #ffc08a 100%); }
  .top-g { display: flex; justify-content: space-between; align-items: flex-start; gap: 24px; }
  .logo { height: 34px; display: block; }
  .seller { margin-top: 16px; font-size: 11.5px; line-height: 1.65; color: var(--ink2); max-width: 340px; }
  .seller .nm { font-size: 13px; font-weight: 800; color: var(--ink); }
  .ttl { text-align: right; }
  .ttl h1 { margin: 0; font-size: 30px; line-height: 1; font-weight: 800; letter-spacing: -.02em; }
  .ttl .no { margin-top: 10px; display: inline-block; padding: 6px 12px; border-radius: 8px; background: var(--ink); color: #fff; font-size: 12.5px; font-weight: 700; letter-spacing: .02em; }
  .ttl .dt { margin-top: 10px; font-size: 11.5px; color: var(--ink2); line-height: 1.7; }
  .ttl .dt b { color: var(--ink); }
  .pill { display: inline-block; margin-top: 8px; padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; }
  .pill.paid { background: #dcfce7; color: var(--ok); } .pill.due { background: #fef3c7; color: var(--warn); } .pill.void { background: #fee2e2; color: #b91c1c; }
  .body { padding: 30px 48px 0; flex: 1; }
  .cards { display: grid; grid-template-columns: 1.35fr 1fr 1fr; gap: 12px; }
  .card { padding: 14px 16px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
  .card .k { font-size: 9.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--mute); margin-bottom: 7px; }
  .card .v { font-size: 14px; font-weight: 800; line-height: 1.3; }
  .card .s { margin-top: 5px; font-size: 11px; line-height: 1.6; color: var(--ink2); }
  .card.amt { background: var(--ink); border-color: var(--ink); color: #fff; }
  .card.amt .k { color: rgba(255,255,255,.55); } .card.amt .v { font-size: 22px; letter-spacing: -.01em; } .card.amt .s { color: rgba(255,255,255,.7); }
  table.items { width: 100%; margin-top: 24px; border-collapse: separate; border-spacing: 0; font-size: 12px; }
  .items th { padding: 10px 10px; background: var(--soft); color: var(--mute); font-size: 9.5px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; text-align: left; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
  .items th:first-child { border-left: 1px solid var(--line); border-radius: 10px 0 0 10px; } .items th:last-child { border-right: 1px solid var(--line); border-radius: 0 10px 10px 0; }
  .items td { padding: 13px 10px; border-bottom: 1px solid var(--line); vertical-align: top; color: var(--ink); }
  .items td small { display: block; margin-top: 3px; color: var(--mute); font-size: 10.5px; font-weight: 500; line-height: 1.5; }
  .items td.n { color: var(--mute); width: 28px; } .items .u { color: var(--mute); font-size: 10px; }
  .items .c { text-align: center; } .items .r { text-align: right; white-space: nowrap; }
  .sum { display: flex; justify-content: space-between; gap: 28px; margin-top: 18px; }
  .words { flex: 1; padding-top: 6px; }
  .words .k { font-size: 9.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--mute); margin-bottom: 6px; }
  .words p { margin: 0; font-size: 12px; font-weight: 600; line-height: 1.55; color: var(--ink2); }
  .tax-note { margin-top: 10px; font-size: 10.5px; color: var(--mute); line-height: 1.55; }
  table.tot { width: 290px; border-collapse: collapse; font-size: 12.5px; }
  .tot td { padding: 6px 2px; color: var(--ink2); } .tot td:last-child { text-align: right; font-weight: 700; color: var(--ink); white-space: nowrap; }
  .tot .disc td:last-child { color: var(--ok); }
  .grand { margin-top: 8px; display: flex; justify-content: space-between; align-items: center; padding: 13px 16px; border-radius: 12px; background: linear-gradient(135deg, var(--acc), #ff7a2e); color: #fff; }
  .grand span { font-size: 11px; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; opacity: .9; } .grand b { font-size: 20px; letter-spacing: -.01em; }
  .pay { margin-top: 24px; padding: 16px 18px; border-radius: 14px; background: #f0fdf4; border: 1px solid #bbf7d0; }
  .pay.due { background: #fffbeb; border-color: #fde68a; }
  .pay-h { font-size: 10px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: var(--ok); margin-bottom: 10px; }
  .pay.due .pay-h { color: var(--warn); }
  .pay-row { display: flex; gap: 18px; align-items: flex-start; }
  .pay-g { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px 18px; flex: 1; }
  .pay-g div { display: flex; flex-direction: column; gap: 2px; font-size: 12px; } .pay-g .wide { grid-column: 1 / -1; }
  .pay-g span { font-size: 10px; color: var(--mute); font-weight: 600; } .pay-g b { font-weight: 700; line-height: 1.5; word-break: break-word; }
  .qr { width: 96px; height: 96px; padding: 6px; background: #fff; border-radius: 10px; flex: none; } .qr img, .qr canvas { width: 84px !important; height: 84px !important; display: block; }
  .notes { margin-top: 18px; padding: 14px 16px; border-radius: 12px; background: var(--soft); font-size: 11.5px; line-height: 1.65; color: var(--ink2); }
  .notes b { display: block; margin-bottom: 4px; font-size: 9.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--mute); }
  .foot { margin-top: 30px; padding: 22px 48px 26px; display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; border-top: 1px solid var(--line); background: var(--soft); }
  .foot .thx { font-size: 17px; font-weight: 800; letter-spacing: -.01em; } .foot .thx em { font-style: normal; color: var(--acc); }
  .foot .sm { margin-top: 4px; font-size: 10.5px; color: var(--mute); line-height: 1.6; }
  .foot .sig { text-align: right; font-size: 10.5px; color: var(--mute); line-height: 1.6; }
  .foot .sig b { display: block; font-size: 12px; color: var(--ink); }
  .stamp { display: inline-flex; flex-direction: column; align-items: center; margin-top: 18px; padding: 7px 16px 6px; border: 3px double; border-radius: 10px; transform: rotate(-7deg); font-size: 19px; font-weight: 800; letter-spacing: .16em; text-transform: uppercase; line-height: 1.1; opacity: .85; }
  .stamp small { font-size: 9px; letter-spacing: .12em; font-weight: 700; }
  .stamp.paid { color: var(--ok); } .stamp.due { color: var(--warn); } .stamp.void { color: #b91c1c; }
  @page { size: A4; margin: 0; }
  @media print { html, body { background: #fff; } .bar { display: none; } .wrap { padding: 0; } .page { box-shadow: none; margin: 0; } }
  @media (max-width: 820px) { .wrap { padding: 12px 0 40px; } .page { transform-origin: top left; } }
</style></head>
<body>
<div class="bar">
  <?php if ($back): ?><a class="sec" href="<?= $h($back) ?>">← Back</a><?php endif; ?>
  <div class="t"><?= $h($title) ?> <?= $h($inv['invoice_no']) ?><small><?= $h($b['name'] ?? '') ?></small></div>
  <button type="button" class="sec" onclick="window.print()">Print</button>
  <button type="button" class="pri" id="dl">Download PDF</button>
</div>
<div class="wrap"><div class="page" id="inv">
  <div class="top"><div class="top-g">
    <div>
      <?php if ($logo): ?><img class="logo" src="<?= $logo ?>" alt="RestroGrow"><?php else: ?><div style="font-size:26px;font-weight:800">Restro<span style="color:var(--acc)">Grow</span></div><?php endif; ?>
      <div class="seller"><div class="nm"><?= $h($seller['name']) ?></div><?= implode('<br>', $sellerLines) ?></div>
    </div>
    <div class="ttl">
      <h1><?= $h($title) ?></h1>
      <div class="no"># <?= $h($inv['invoice_no']) ?></div>
      <div class="dt">Issued <b><?= $h($d($inv['invoice_date'])) ?></b><?php if ($status === 'due' && $inv['due_date']): ?><br>Due by <b><?= $h($d($inv['due_date'])) ?></b><?php endif; ?></div>
      <span class="pill <?= $stamp[1] ?>"><?= $h($stamp[0]) ?></span>
    </div>
  </div></div>

  <div class="body">
    <div class="cards">
      <div class="card"><div class="k">Billed to</div><div class="v"><?= $h($b['name'] ?? '') ?></div><div class="s"><?= implode('<br>', $buyerLines) ?></div></div>
      <div class="card"><div class="k"><?= $period ? 'Service period' : 'Details' ?></div>
        <div class="v" style="font-size:12.5px"><?= $period ? $h($period) : $h($d($inv['invoice_date'])) ?></div>
        <div class="s"><?= $pos ? 'Place of supply<br><b>' . $h($pos) . '</b>' : '' ?></div></div>
      <div class="card amt"><div class="k"><?= $h($amountLabel) ?></div><div class="v"><?= $m($inv['total']) ?></div><div class="s"><?= $rate > 0 ? 'Incl. ' . $h($q($rate)) . '% GST' : 'No GST' ?></div></div>
    </div>

    <table class="items"><thead><tr><th>#</th><th>Description</th><th class="c">SAC</th><th class="c">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead>
      <tbody><?= $rows ?></tbody></table>

    <div class="sum">
      <div class="words"><div class="k">Amount in words</div><p><?= $h(saInvWords((float)$inv['total'])) ?></p>
        <div class="tax-note"><?= $rate > 0 ? ($inter ? 'IGST applies: supply to another state.' : 'CGST + SGST: supply within the state.') . ' Prices ' . (($set['prices_include_gst'] ?? '1') === '1' ? 'include' : 'exclude') . ' GST.' : '' ?><?= $isTax ? '' : ' Not a tax invoice: add a GSTIN in Superadmin › Settings.' ?></div>
        <div class="stamp <?= $stamp[1] ?>"><?= $h($stamp[0]) ?><small><?= $h($status === 'paid' ? $d($inv['paid_on'] ?: $inv['invoice_date']) : ($status === 'due' && $inv['due_date'] ? 'by ' . $d($inv['due_date']) : $inv['invoice_no'])) ?></small></div></div>
      <div><table class="tot"><?= $tot ?></table><div class="grand"><span>Total</span><b><?= $m($inv['total']) ?></b></div></div>
    </div>

    <?= $pay ?>
    <?php if (trim((string)($inv['notes'] ?? '')) !== ''): ?><div class="notes"><b>Notes</b><?= nl2br($h($inv['notes'])) ?></div><?php endif; ?>
  </div>

  <div class="foot">
    <div><div class="thx">Thank you for growing with <em>RestroGrow</em>.</div>
      <div class="sm">Questions about this invoice? <?= $h(implode(' · ', array_filter([$seller['email'], $seller['phone']]))) ?: 'Reply to the message it came with.' ?><br><?= $h($seller['web']) ?> · This is a computer-generated invoice and needs no signature.</div></div>
    <div class="sig"><b><?= $h($seller['name']) ?></b>Authorised by RestroGrow</div>
  </div>
</div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<?php if ($status === 'due' && $seller['upi']): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><?php endif; ?>
<script>
(function () {
  var q = document.getElementById('upiQr');
  if (q && window.QRCode) new QRCode(q, { text: q.getAttribute('data-upi'), width: 168, height: 168, correctLevel: QRCode.CorrectLevel.M });
  // on a phone the A4 page is scaled down to fit
  var page = document.getElementById('inv');
  function fit() { var w = window.innerWidth; page.style.transform = w < 820 ? 'scale(' + (w / 810) + ')' : ''; page.style.marginBottom = w < 820 ? (-(1 - w / 810) * page.offsetHeight) + 'px' : ''; }
  fit(); window.addEventListener('resize', fit);

  // the page drawn once, put on A4: one page (shrunk a little if it runs long), split only when much longer
  function build() {
    var keep = page.style.transform, mb = page.style.marginBottom;
    page.style.transform = ''; page.style.marginBottom = '';
    return (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
      return html2canvas(page, { scale: 2.5, backgroundColor: '#ffffff', useCORS: true, windowWidth: 900, scrollX: 0, scrollY: -window.scrollY });
    }).then(function (c) {
      page.style.transform = keep; page.style.marginBottom = mb;
      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait', compress: true });
      pdf.setProperties({ title: document.title, author: 'RestroGrow', creator: 'RestroGrow' });
      var W = 210, H = 297, imgH = W * c.height / c.width;
      if (imgH <= H * 1.3) {
        var h = Math.min(imgH, H), w = h === imgH ? W : W * H / imgH;
        pdf.addImage(c.toDataURL('image/jpeg', 0.94), 'JPEG', (W - w) / 2, 0, w, h);
      } else {
        var slice = Math.floor(c.width * H / W);
        for (var y = 0, n = 0; y < c.height; y += slice, n++) {
          var part = document.createElement('canvas');
          part.width = c.width; part.height = Math.min(slice, c.height - y);
          var ctx = part.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, part.width, part.height);
          ctx.drawImage(c, 0, y, c.width, part.height, 0, 0, c.width, part.height);
          if (n) pdf.addPage();
          pdf.addImage(part.toDataURL('image/jpeg', 0.94), 'JPEG', 0, 0, W, W * part.height / c.width);
        }
      }
      return pdf;
    }, function (e) { page.style.transform = keep; page.style.marginBottom = mb; throw e; });
  }
  window.rgInvoicePdf = build;
  var btn = document.getElementById('dl');
  function download() {
    if (!window.html2canvas || !window.jspdf) { window.print(); return; }
    btn.disabled = true; btn.textContent = 'Preparing…';
    build().then(function (pdf) { pdf.save(<?= json_encode($file) ?>); })
      .catch(function () { window.print(); })
      .then(function () { btn.disabled = false; btn.textContent = 'Download PDF'; });
  }
  btn.addEventListener('click', download);
  <?php if ($auto): ?>window.addEventListener('load', function () { setTimeout(download, 300); });<?php endif; ?>
})();
</script>
</body></html>
<?php
    return (string)ob_get_clean();
}
