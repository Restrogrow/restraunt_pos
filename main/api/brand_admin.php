<?php
/**
 * The owner's brand settings, on the Branches page (assets/js/brand-studio.js; the brand itself: config/brand.php).
 * Multi-branch owner (branch_admins) only, and only their own brand (Super Admin creates it).
 *
 * GET                                        the brand, outlets, domain, gateway, tracking codes, online payments by outlet
 * GET ?check=1                               … plus a fresh check of the brand's own domain
 * GET ?export=csv&from=YYYY-MM-DD&to=…       online payments through the brand gateway, one row per payment
 * POST {action:'outlets', outlets:[…]}       outlet names, web addresses, order, show in picker, brand / own gateway
 * POST {action:'details', tagline, default_restaurant_id}
 * POST {action:'domain', domain}             save / clear the brand's own domain (switches it off until checked)
 * POST {action:'domain_switch', on, force}   send customers to the domain (needs it to show the brand site)
 * POST {action:'gateway', client_id, client_secret, environment}   the brand's PhonePe keys (empty client_id clears)
 * POST {action:'tracking', ga_id, meta_pixel_id, gsc_verification}  on the brand's pages (picker; outlets keep their own)
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function baOut(int $code, array $d): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (!isSessionValid() || empty($_SESSION['branch_admin_id'])) baOut(401, ['success' => false, 'message' => 'Please log in with the multi-branch owner login.']);
$ownerId = (int)$_SESSION['branch_admin_id'];
session_write_close();

require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/brand.php';
require_once __DIR__ . '/../config/custom_domain_check.php';
require_once __DIR__ . '/../config/phonepe_verify.php';

try {
    $conn = getConnection();
    $brand = brandForOwner($conn, $ownerId);
    if (!$brand) baOut(200, ['success' => true, 'brand' => null]);
    require_once __DIR__ . '/../config/activity_log.php';
    $log = function (string $action, string $summary) use ($conn, $brand) {
        // in each outlet's Activity log: these settings change every outlet's website
        foreach (brandOutlets($conn, (int)$brand['id']) as $o) logActivity($conn, $o['restaurant_id'], 'brand.' . $action, $summary, ['brand' => $brand['name']]);
    };

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        try {
            switch ($in['action'] ?? '') {
                case 'outlets':
                    brandSaveOutletsOwner($conn, $brand, (array)($in['outlets'] ?? []));
                    $log('outlets', 'Changed the brand outlets (names, addresses, order, gateway)');
                    break;
                case 'details':
                    $tag = trim(mb_substr(strip_tags((string)($in['tagline'] ?? '')), 0, 160));
                    $def = (string)($in['default_restaurant_id'] ?? $brand['default_restaurant_id']);
                    if (!in_array($def, array_column(brandOutlets($conn, (int)$brand['id']), 'restaurant_id'), true)) throw new InvalidArgumentException('Pick one of the brand outlets as the main one.');
                    $conn->prepare('UPDATE brands SET tagline = ?, default_restaurant_id = ?, updated_at = NOW() WHERE id = ?')->execute([$tag !== '' ? $tag : null, $def, $brand['id']]);
                    break;
                case 'domain':
                    $d = brandNormalizeDomain(cdNormalize($in['domain'] ?? ''));
                    if ($d !== '') {
                        if (!preg_match('/^(?=.{4,190}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $d)) throw new InvalidArgumentException('That doesn’t look like a domain. Type it like spicegarden.in or order.spicegarden.in');
                        $main = cdMainHost();
                        if ($d === $main || str_ends_with($d, '.' . $main)) throw new InvalidArgumentException('Use a domain you own, not a RestroGrow address.');
                        $taken = $conn->prepare('SELECT COUNT(*) FROM users WHERE custom_domain IN (?, ?)');
                        $taken->execute([$d, 'www.' . $d]);
                        if ((int)$taken->fetchColumn()) throw new InvalidArgumentException('That domain is already connected to a restaurant. Disconnect it there first.');
                    }
                    try {
                        $changed = $d !== (string)$brand['custom_domain'];
                        $conn->prepare('UPDATE brands SET custom_domain = ?' . ($changed ? ', domain_enabled = 0' : '') . ', updated_at = NOW() WHERE id = ?')->execute([$d !== '' ? $d : null, $brand['id']]);
                    } catch (PDOException $e) {
                        if ((int)$e->getCode() === 23000) throw new InvalidArgumentException('That domain is already used by another brand.');
                        throw $e;
                    }
                    $log('domain', $d !== '' ? "Saved the brand domain $d (not switched on yet)" : 'Removed the brand domain');
                    break;
                case 'domain_switch':
                    $on = !empty($in['on']);
                    if ($on) {
                        if (empty($brand['custom_domain'])) throw new InvalidArgumentException('Add your domain first.');
                        $check = cdCheck($brand['custom_domain'], (string)$brand['name']);
                        if (!$check['ready'] && empty($in['force'])) baOut(409, ['success' => false, 'code' => 'not_ready', 'message' => 'Your domain isn’t showing your brand website yet.', 'check' => $check]);
                    }
                    $conn->prepare('UPDATE brands SET domain_enabled = ?, updated_at = NOW() WHERE id = ?')->execute([$on ? 1 : 0, $brand['id']]);
                    $log('domain', $on ? 'Switched on the brand domain ' . $brand['custom_domain'] : 'Switched off the brand domain');
                    break;
                case 'gateway':
                    brandSaveGateway($conn, $brand, (string)($in['client_id'] ?? ''), (string)($in['client_secret'] ?? ''), (string)($in['environment'] ?? 'PRODUCTION'));
                    $log('gateway', trim((string)($in['client_id'] ?? '')) !== '' ? 'Saved the brand payment gateway keys' : 'Removed the brand payment gateway keys');
                    break;
                case 'tracking':
                    $ga = strtoupper(trim((string)($in['ga_id'] ?? '')));
                    if ($ga !== '' && !preg_match('/^G-[A-Z0-9]{4,15}$/', $ga)) throw new InvalidArgumentException('Google Analytics ID should look like G-XXXXXXXXXX.');
                    $px = preg_replace('/\s+/', '', (string)($in['meta_pixel_id'] ?? ''));
                    if ($px !== '' && !preg_match('/^\d{8,20}$/', $px)) throw new InvalidArgumentException('Meta Pixel ID is a number, 15–16 digits long.');
                    $gsc = trim((string)($in['gsc_verification'] ?? ''));
                    if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gsc, $m)) $gsc = $m[1];
                    if ($gsc !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,100}$/', $gsc)) throw new InvalidArgumentException('That Search Console code doesn’t look right. Copy the code from the HTML tag option.');
                    $conn->prepare('UPDATE brands SET ga_id = ?, meta_pixel_id = ?, gsc_verification = ?, updated_at = NOW() WHERE id = ?')->execute([$ga ?: null, $px ?: null, $gsc ?: null, $brand['id']]);
                    break;
                default:
                    baOut(400, ['success' => false, 'message' => 'Unknown action']);
            }
        } catch (InvalidArgumentException $e) {
            baOut(422, ['success' => false, 'message' => $e->getMessage()]);
        }
        $brand = brandGet($conn, (int)$brand['id']);
    }

    $outlets = brandOutlets($conn, (int)$brand['id']);

    // ── online payments through the brand gateway, per outlet ──
    $viaBrand = array_values(array_map(fn($o) => $o['restaurant_id'], array_filter($outlets, fn($o) => $o['gateway'] === 'brand')));
    if (isset($_GET['export'])) {
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $brand['slug'] . '-online-payments-' . $from . '-to-' . $to . '.csv"');
        $label = array_column($outlets, 'label', 'restaurant_id');
        $f = fopen('php://output', 'w');
        fputcsv($f, ['Date', 'Outlet', 'What', 'Order / phone', 'Amount', 'PhonePe transaction']);
        foreach (baPayments($conn, $viaBrand, $from, $to) as $p) fputcsv($f, [$p['at'], $label[$p['rid']] ?? $p['rid'], $p['kind'], $p['ref'], number_format((float)$p['amount'], 2, '.', ''), $p['txn']]);
        fclose($f);
        exit;
    }
    $sum = [];
    foreach ([['today', date('Y-m-d'), date('Y-m-d')], ['last_7_days', date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], ['this_month', date('Y-m-01'), date('Y-m-d')]] as [$k, $f, $t]) {
        foreach (baPayments($conn, $viaBrand, $f, $t) as $p) {
            $sum[$p['rid']][$k]['amount'] = ($sum[$p['rid']][$k]['amount'] ?? 0) + (float)$p['amount'];
            $sum[$p['rid']][$k]['count'] = ($sum[$p['rid']][$k]['count'] ?? 0) + 1;
        }
    }

    $out = brandOut($conn, $brand);
    $gateway = brandGatewayKeys($brand);
    $out['gateway'] = ['set' => (bool)$gateway, 'client_id' => $gateway ? $gateway['id'] : '', 'secret_hint' => $gateway ? ppMask($gateway['secret']) : '',
        'environment' => $brand['pay_environment'], 'webhook' => ppBrandWebhookCreds((int)$brand['id']), 'can_save' => ppSecretKey() !== null];
    $out['domain'] = ['domain' => (string)$brand['custom_domain'], 'enabled' => (int)$brand['domain_enabled'] === 1, 'records' => cdRecords((string)$brand['custom_domain']),
        'check' => !empty($_GET['check']) && $brand['custom_domain'] ? cdCheck($brand['custom_domain'], (string)$brand['name']) : null];
    $out['payments'] = array_map(fn($o) => ['restaurant_id' => $o['restaurant_id'], 'label' => $o['label'], 'gateway' => $o['gateway'],
        'today' => $sum[$o['restaurant_id']]['today'] ?? ['amount' => 0, 'count' => 0], 'last_7_days' => $sum[$o['restaurant_id']]['last_7_days'] ?? ['amount' => 0, 'count' => 0],
        'this_month' => $sum[$o['restaurant_id']]['this_month'] ?? ['amount' => 0, 'count' => 0]], $outlets);
    $out['outlet_details'] = array_map(fn($o) => ['restaurant_id' => $o['restaurant_id'], 'restaurant_name' => $o['restaurant_name'], 'open_now' => $o['open_now'], 'has_location' => $o['lat'] !== null, 'online_payments' => $o['online_payments'], 'website_offline' => $o['website_offline']], $outlets);
    baOut(200, ['success' => true, 'brand' => $out]);
} catch (Throwable $e) {
    error_log('brand_admin: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    baOut(500, ['success' => false, 'message' => 'Could not load the brand settings. Please try again.']);
}

/**
 * Online payments that went through PhonePe for these outlets between two days (inclusive): website orders
 * (payments, PP_ transactions) and wallet top-ups (WT…). → [{rid, at, kind, ref, amount, txn}], newest first.
 */
function baPayments(PDO $conn, array $rids, string $from, string $to): array
{
    if (!$rids) return [];
    $in = implode(',', array_fill(0, count($rids), '?'));
    $args = array_merge($rids, [$from . ' 00:00:00', $to . ' 23:59:59']);
    $rows = [];
    $st = $conn->prepare("SELECT p.restaurant_id rid, p.created_at at, 'Order' kind, COALESCE(o.order_number, CONCAT('#', p.order_id)) ref, p.amount, p.transaction_id txn
        FROM payments p LEFT JOIN orders o ON o.id = p.order_id
        WHERE p.restaurant_id IN ($in) AND p.created_at BETWEEN ? AND ? AND p.payment_status = 'Success' AND p.transaction_id LIKE 'PP\\_%'");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    try {
        $w = $conn->prepare("SELECT restaurant_id rid, COALESCE(completed_at, created_at) at, 'Wallet top-up' kind, phone ref, amount, transaction_id txn
            FROM wallet_topups WHERE restaurant_id IN ($in) AND created_at BETWEEN ? AND ? AND status IN ('Success', 'Paid', 'Completed')");
        $w->execute($args);
        $rows = array_merge($rows, $w->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) { /* no wallet table yet */ }
    usort($rows, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));
    return $rows;
}
