<?php
/**
 * How a restaurant's bill is worked out after discounts: tax added on top (India) or already inside the menu
 * price (Nepal, where menu prices must include VAT and service charge), and an optional service charge.
 * One rule for the POS (controllers/pos_operations.php + posComputeTotals in assets/js/script.js), the website
 * (api/process_website_order.php + website/cart.php) and WhatsApp orders, so the three can never disagree.
 *
 * Settings live on the restaurant's users row (Settings › Tax and bill):
 *   prices_include_tax      1 = the menu price already holds the tax and service charge
 *   service_charge_percent  0 = none (Nepal: usually 10); charged before tax, tax is on food + service charge
 * orders.service_charge keeps each bill's service charge for the printed bill and reports.
 */

function billRulesEnsure(PDO $conn): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!$conn->query("SHOW COLUMNS FROM users LIKE 'prices_include_tax'")->fetch()) {
            $conn->exec("ALTER TABLE users ADD COLUMN prices_include_tax TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN service_charge_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00");
        }
        if (!$conn->query("SHOW COLUMNS FROM orders LIKE 'service_charge'")->fetch()) {
            $conn->exec("ALTER TABLE orders ADD COLUMN service_charge DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
        if (!$conn->query("SHOW COLUMNS FROM orders LIKE 'buyer_pan'")->fetch()) {
            $conn->exec("ALTER TABLE orders ADD COLUMN buyer_pan VARCHAR(20) DEFAULT NULL, ADD COLUMN buyer_name VARCHAR(120) DEFAULT NULL");
        }
    } catch (Throwable $e) {
        error_log('bill_rules: ' . $e->getMessage());
    }
}

/** The restaurant's bill settings: ['tax_on', 'tax_percent', 'tax_name', 'inclusive', 'service_percent', 'country']. */
function billRules(PDO $conn, string $rid): array
{
    static $cache = [];
    if (isset($cache[$rid])) return $cache[$rid];
    billRulesEnsure($conn);
    $r = [];
    try {
        $st = $conn->prepare('SELECT enable_gst, tax_percent, tax_name, prices_include_tax, service_charge_percent, country FROM users WHERE restaurant_id = ? LIMIT 1');
        $st->execute([$rid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* columns not there yet: India defaults */ }
    return $cache[$rid] = [
        'tax_on' => isset($r['enable_gst']) ? (bool)$r['enable_gst'] : true,
        'tax_percent' => isset($r['tax_percent']) && $r['tax_percent'] !== null ? (float)$r['tax_percent'] : 5.00,
        'tax_name' => trim((string)($r['tax_name'] ?? '')) ?: 'GST',
        'inclusive' => !empty($r['prices_include_tax']),
        'service_percent' => max(0, min(30, (float)($r['service_charge_percent'] ?? 0))),
        'country' => (string)($r['country'] ?? ''),
    ];
}

/**
 * Service charge, tax and total for an amount after discounts ($taxable). Added on top: service charge =
 * taxable × s%, tax = (taxable + service charge) × t%, total = all three. Inclusive: the amount IS the total
 * and the food value, service charge and tax are worked back out of it, so the customer pays the menu price.
 * Returns ['net' => food value, 'service_charge', 'tax', 'total'], rounded to paisa.
 */
function billMath(float $taxable, array $rules): array
{
    $t = $rules['tax_on'] ? $rules['tax_percent'] / 100 : 0;
    $s = $rules['service_percent'] / 100;
    $taxable = max(0, round($taxable, 2));
    if ($rules['inclusive']) {
        $net = round($taxable / ((1 + $s) * (1 + $t)), 2);
        $sc = round($net * $s, 2);
        $tax = round($taxable - $net - $sc, 2);
        return ['net' => $net, 'service_charge' => $sc, 'tax' => $tax, 'total' => $taxable];
    }
    $sc = round($taxable * $s, 2);
    $tax = round(($taxable + $sc) * $t, 2);
    return ['net' => $taxable, 'service_charge' => $sc, 'tax' => $tax, 'total' => round($taxable + $sc + $tax, 2)];
}

/** Keep a bill's service charge (and the buyer's PAN for a Nepal VAT bill) on its order row. */
function billSaveExtras(PDO $conn, int $orderId, float $serviceCharge, ?string $buyerPan = null, ?string $buyerName = null): void
{
    if ($orderId <= 0) return;
    billRulesEnsure($conn);
    $pan = $buyerPan !== null ? mb_substr(preg_replace('/[^0-9A-Za-z-]/', '', $buyerPan), 0, 20) : '';
    $conn->prepare('UPDATE orders SET service_charge = ?, buyer_pan = COALESCE(NULLIF(?, \'\'), buyer_pan), buyer_name = COALESCE(NULLIF(?, \'\'), buyer_name) WHERE id = ?')
        ->execute([round($serviceCharge, 2), $pan, $buyerName !== null ? mb_substr(trim(strip_tags($buyerName)), 0, 120) : '', $orderId]);
}
