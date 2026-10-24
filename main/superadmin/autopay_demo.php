<?php
/**
 * PhonePe Autopay test (Super Admin only): does RestroGrow's own PhonePe account have Autopay?
 * Makes a mandate link: ₹1 now, then up to ₹100 a month (MONTHLY, VARIABLE, max ₹100), on the platform keys.
 * PhonePe never debits by itself: next month press "Notify ₹100", then 24 hours later "Charge ₹100".
 * Every call and PhonePe's raw answer are kept in autopay_demo, so a refusal (Autopay not enabled) is visible.
 * Standard Checkout Autopay: checkout/v2/pay (SUBSCRIPTION_CHECKOUT_SETUP), checkout/v2/subscriptions/notify|redeem.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/phonepe_verify.php';

$conn = getConnection();
$conn->exec("CREATE TABLE IF NOT EXISTS autopay_demo (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sub_id VARCHAR(63) NOT NULL,
    setup_order VARCHAR(63) NOT NULL,
    pay_url TEXT NULL,
    charge_order VARCHAR(63) NULL,
    last_call VARCHAR(40) NULL,
    last_answer TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY (sub_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if (empty($_SESSION['autopay_demo_csrf'])) $_SESSION['autopay_demo_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['autopay_demo_csrf'];

$config = phonepeCallbackConfig(null, true);   // RestroGrow's own account, never sandbox on the live site

/** One call to PhonePe: ['http' => code, 'body' => decoded answer or raw text]. */
function apdCall(array $config, string $method, string $path, ?array $body = null): array {
    $token = phonepeCallbackAccessToken($config);
    if (!$token) return ['http' => 0, 'body' => 'PhonePe login failed (check PHONEPE_CLIENT_ID / SECRET)'];
    $ch = curl_init($config['api_url'] . $path);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: O-Bearer ' . $token]];
    if ($method === 'POST') { $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = json_encode($body); }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) return ['http' => 0, 'body' => 'connection: ' . $err];
    $d = json_decode((string)$response, true);
    return ['http' => $http, 'body' => is_array($d) ? $d : (string)$response];
}

function apdSave(PDO $conn, string $subId, string $call, array $res): void {
    $conn->prepare('UPDATE autopay_demo SET last_call = ?, last_answer = ? WHERE sub_id = ?')
        ->execute([$call, json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $subId]);
}

function apdPageUrl(array $q = []): string {
    $app = rtrim((string)env('APP_URL', ''), '/');
    if ($app === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $app = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname(dirname($_SERVER['SCRIPT_NAME'])));
        $app = rtrim($app, '/\\');
    }
    return $app . '/main/superadmin/autopay_demo.php' . ($q ? '?' . http_build_query($q) : '');
}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(403); exit('Page expired, reload it.'); }
    $do = (string)($_POST['do'] ?? '');
    $subId = (string)($_POST['sub'] ?? '');

    if (!$config['ready']) {
        $flash = 'No PhonePe keys in .env on this server.';
    } elseif ($do === 'create') {
        $subId = ppTxnId('APDS_');
        $orderId = ppTxnId('APDO_');
        $res = apdCall($config, 'POST', '/checkout/v2/pay', [
            'merchantOrderId' => $orderId,
            'amount' => 100,                                   // ₹1 now (first debit)
            'expireAfter' => 3600,                             // the link works for 1 hour
            'paymentFlow' => [
                'type' => 'SUBSCRIPTION_CHECKOUT_SETUP',
                'merchantUrls' => ['redirectUrl' => apdPageUrl(['sub' => $subId])],
                'subscriptionDetails' => [
                    'subscriptionType' => 'RECURRING',
                    'merchantSubscriptionId' => $subId,
                    'authWorkflowType' => 'TRANSACTION',
                    'amountType' => 'VARIABLE',
                    'maxAmount' => 10000,                      // up to ₹100 a month
                    'frequency' => 'MONTHLY',
                    'expireAt' => (time() + 365 * 86400) * 1000,
                ],
            ],
        ]);
        $url = is_array($res['body']) ? (string)($res['body']['redirectUrl'] ?? '') : '';
        $conn->prepare('INSERT INTO autopay_demo (sub_id, setup_order, pay_url) VALUES (?, ?, ?)')->execute([$subId, $orderId, $url ?: null]);
        apdSave($conn, $subId, 'create', $res);
        $flash = $url ? 'Link made. Open it on your phone and pay ₹1 to approve the mandate.' : 'PhonePe refused. Its answer is below (Autopay may not be on for this account).';
    } else {
        $st = $conn->prepare('SELECT * FROM autopay_demo WHERE sub_id = ?');
        $st->execute([$subId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $flash = 'Unknown test.';
        } elseif ($do === 'notify') {
            // pre-debit notice to the customer; the charge itself may only run 24 hours later
            $chargeId = ppTxnId('APDR_');
            $res = apdCall($config, 'POST', '/checkout/v2/subscriptions/notify', [
                'merchantOrderId' => $chargeId,
                'amount' => 10000,
                'expireAt' => (time() + 48 * 3600) * 1000,
                'paymentFlow' => ['type' => 'SUBSCRIPTION_CHECKOUT_REDEMPTION', 'merchantSubscriptionId' => $subId,
                    'redemptionRetryStrategy' => 'STANDARD', 'autoDebit' => false],
            ]);
            if ($res['http'] === 200) $conn->prepare('UPDATE autopay_demo SET charge_order = ? WHERE sub_id = ?')->execute([$chargeId, $subId]);
            apdSave($conn, $subId, 'notify ₹100', $res);
            $flash = $res['http'] === 200 ? 'Notice sent. Press "Charge ₹100" after 24 hours.' : 'Notify refused, see the answer below.';
        } elseif ($do === 'redeem' && $row['charge_order']) {
            apdSave($conn, $subId, 'charge ₹100', apdCall($config, 'POST', '/checkout/v2/subscriptions/redeem', ['merchantOrderId' => $row['charge_order']]));
            $flash = 'Charge asked. Press "Check" for the result.';
        } elseif ($do === 'check') {
            $out = [
                'setup_payment (₹1)' => apdCall($config, 'GET', '/checkout/v2/order/' . rawurlencode($row['setup_order']) . '/status'),
                'mandate' => apdCall($config, 'GET', '/checkout/v2/subscriptions/' . rawurlencode($subId) . '/status'),
            ];
            if ($row['charge_order']) $out['charge (₹100)'] = apdCall($config, 'GET', '/checkout/v2/order/' . rawurlencode($row['charge_order']) . '/status');
            apdSave($conn, $subId, 'check', $out);
        }
    }
    header('Location: ' . apdPageUrl(array_filter(['sub' => $subId, 'msg' => $flash])));
    exit();
}

$flash = (string)($_GET['msg'] ?? '');
$tests = $conn->query('SELECT * FROM autopay_demo ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
$open = (string)($_GET['sub'] ?? '');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PhonePe Autopay test</title>
<style>
  body { font-family: system-ui, sans-serif; background: #f5f6f8; color: #1d2330; margin: 0; padding: 16px; }
  .wrap { max-width: 760px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid #e3e6ec; border-radius: 10px; padding: 16px; margin-bottom: 14px; }
  h1 { font-size: 20px; margin: 4px 0 12px; }
  button { background: #5f259f; color: #fff; border: 0; border-radius: 6px; padding: 9px 14px; font-size: 14px; cursor: pointer; margin: 4px 4px 0 0; }
  button.light { background: #eceaf3; color: #5f259f; }
  .flash { background: #fff7e0; border: 1px solid #f1d58a; padding: 10px 12px; border-radius: 8px; margin-bottom: 14px; }
  .link { word-break: break-all; font-size: 13px; background: #f3f0fa; padding: 8px; border-radius: 6px; }
  pre { background: #11151c; color: #d7e0ea; padding: 10px; border-radius: 6px; font-size: 12px; overflow-x: auto; max-height: 360px; }
  .muted { color: #687083; font-size: 13px; }
  .open { border-color: #5f259f; }
  a { color: #5f259f; }
</style>
</head>
<body>
<div class="wrap">
  <p class="muted"><a href="dashboard.php">← Super Admin</a></p>
  <h1>PhonePe Autopay test</h1>
  <?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

  <div class="card">
    <p>Makes a real mandate on RestroGrow's <?= $config['sandbox'] ? '<b>SANDBOX</b>' : 'live' ?> PhonePe account:
      <b>₹1 now</b>, then <b>up to ₹100 every month</b> (for 1 year).</p>
    <p class="muted">Next month: press "Notify ₹100", then "Charge ₹100" 24 hours later (PhonePe never charges by itself).
      Cancel the mandate any time in the PhonePe app › Autopay.</p>
    <?php if (!$config['ready']): ?>
      <p><b>No PhonePe keys on this server.</b></p>
    <?php else: ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="create">
        <button>Make a new ₹1 + ₹100/month link</button></form>
    <?php endif; ?>
  </div>

  <?php foreach ($tests as $t): ?>
    <div class="card<?= $t['sub_id'] === $open ? ' open' : '' ?>">
      <div class="muted"><?= $h($t['created_at']) ?> · <?= $h($t['sub_id']) ?></div>
      <?php if ($t['pay_url']): ?>
        <p>Payment link (works 1 hour from creation):</p>
        <div class="link" id="l<?= (int)$t['id'] ?>"><?= $h($t['pay_url']) ?></div>
        <a href="<?= $h($t['pay_url']) ?>" target="_blank" rel="noopener"><button type="button">Open link</button></a>
        <button type="button" class="light" onclick="navigator.clipboard.writeText(document.getElementById('l<?= (int)$t['id'] ?>').textContent).then(()=>this.textContent='Copied')">Copy link</button>
      <?php else: ?>
        <p><b>No link: PhonePe refused.</b></p>
      <?php endif; ?>
      <form method="post" style="margin-top:6px">
        <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="sub" value="<?= $h($t['sub_id']) ?>">
        <button name="do" value="check" class="light">Check</button>
        <button name="do" value="notify" class="light" onclick="return confirm('Send the ₹100 pre-debit notice?')">Notify ₹100</button>
        <?php if ($t['charge_order']): ?><button name="do" value="redeem" class="light" onclick="return confirm('Charge ₹100 now? Only works 24 hours after Notify.')">Charge ₹100</button><?php endif; ?>
      </form>
      <?php if ($t['last_answer']): ?>
        <p class="muted">Last: <?= $h($t['last_call']) ?></p>
        <pre><?= $h($t['last_answer']) ?></pre>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
