<?php
/**
 * Payment link page (Super Admin › Payment Links): pay_link.php?t=<token>
 *
 * The link is permanent. Each "Pay" starts a fresh PhonePe payment on RestroGrow's own account
 * (config/phonepe_verify.php, platform keys only), so it never expires and a failed try can be paid again.
 * Paid / not paid is decided only by PhonePe's status API (config/payment_settle.php, ppSettleLink),
 * the same as the webhook and the safety net. Paying adds plan_months to the restaurant's plan if set.
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/payment_settle.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$conn = getConnection();
ppLinksEnsure($conn);
ppAttemptsEnsure($conn);

$link = null;
if (preg_match('/^[a-f0-9]{32}$/', $token)) {
    $st = $conn->prepare('SELECT * FROM payment_links WHERE token = ? LIMIT 1');
    $st->execute([$token]);
    $link = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function plReload(PDO $conn, int $id): array {
    $st = $conn->prepare('SELECT * FROM payment_links WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC);
}

/** Ask PhonePe about this link's open tries (newest first). */
function plSettleOpen(PDO $conn, array $link): array {
    if ($link['payment_status'] !== 'Pending') return $link;
    $tries = $conn->prepare("SELECT txn FROM payment_attempts WHERE kind = 'link' AND ref_id = ? AND status = 'Pending' ORDER BY created_at DESC LIMIT 5");
    $tries->execute([$link['id']]);
    foreach ($tries->fetchAll(PDO::FETCH_COLUMN) as $t) {
        try { if (in_array(ppSettle($conn, $t)['outcome'], ['paid', 'already'], true)) break; }
        catch (Throwable $e) { error_log('pay_link settle: ' . $e->getMessage()); }
    }
    return plReload($conn, (int)$link['id']);
}

// polled while coming back from PhonePe
if (isset($_GET['check'])) {
    header('Content-Type: application/json');
    if (!$link) { echo json_encode(['status' => 'unknown']); exit(); }
    $link = plSettleOpen($conn, $link);
    echo json_encode(['status' => $link['payment_status']]);
    exit();
}

$error = '';
if ($link && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay') {
    // a try that already went through must never be paid twice
    $link = plSettleOpen($conn, $link);
    if ($link['payment_status'] === 'Pending') {
        $recent = $conn->prepare("SELECT COUNT(*) FROM payment_attempts WHERE kind = 'link' AND ref_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
        $recent->execute([$link['id']]);
        $config = phonepeCallbackConfig(null, true);
        // a try started in the last 15 minutes that PhonePe still calls pending (open in another tab or
        // phone): a second one could be paid too, and the second payment would not be recorded
        $open = $conn->prepare("SELECT COUNT(*) FROM payment_attempts WHERE kind = 'link' AND ref_id = ? AND status = 'Pending' AND created_at > NOW() - INTERVAL 15 MINUTE");
        $open->execute([$link['id']]);
        if ((int)$recent->fetchColumn() >= 12) {
            $error = 'Too many tries. Please wait a few minutes and try again.';
        } elseif (!$config['demo'] && (int)$open->fetchColumn() > 0) {
            $error = 'A payment for this link was started in the last 15 minutes and is not finished yet. Finish it on that screen, or try again in 15 minutes.';
        } elseif (!$config['ready'] && !$config['demo']) {
            $error = 'Online payment is not available right now. Please contact RestroGrow.';
        } else {
            $txn = ppTxnId('SPL_', (string)$link['restaurant_id']);
            ppAttemptAdd($conn, $txn, 'link', (int)$link['id'], (string)$link['restaurant_id'], (float)$link['amount']);
            $back = ppLinkUrl($token) . '&back=1';
            if ($config['demo']) {
                header('Location: ' . $back . '&demo=1');
                exit();
            }
            $res = phonepeCreatePayment($config, $txn, (float)$link['amount'], $back, 1200, 'RestroGrow payment');
            if ($res['ok']) {
                header('Location: ' . $res['url']);
                exit();
            }
            $conn->prepare("UPDATE payment_attempts SET status = 'Failed', settled_at = NOW() WHERE txn = ?")->execute([$txn]);
            error_log('pay_link PhonePe refused: ' . $res['error']);
            $error = 'Could not open the payment page. Please try again in a minute.';
        }
    }
}

$back = isset($_GET['back']) && $link;
if ($back) $link = plSettleOpen($conn, $link);
$status = $link['payment_status'] ?? '';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$amountText = $link ? '₹' . number_format((float)$link['amount'], 2) : '';
if (!$link) http_response_code(404);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pay RestroGrow</title>
<style>
:root{--bg:#f4f5f7;--card:#fff;--text:#111827;--muted:#6b7280;--line:#e5e7eb;--brand:#5f259f;--ok:#059669;--bad:#dc2626}
@media (prefers-color-scheme:dark){:root{--bg:#0f1115;--card:#181b21;--text:#f3f4f6;--muted:#9ca3af;--line:#2a2e36;--brand:#a78bfa;--ok:#34d399;--bad:#f87171}}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.card{width:100%;max-width:420px;background:var(--card);border:1px solid var(--line);border-radius:16px;padding:28px 24px}
.brand{font-weight:700;letter-spacing:.2px;color:var(--muted);font-size:14px;margin-bottom:18px}
h1{font-size:20px;margin:0 0 4px}
.amount{font-size:36px;font-weight:800;margin:14px 0 4px}
.row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-top:1px solid var(--line);font-size:14px}
.row span:first-child{color:var(--muted)}
.btn{display:block;width:100%;margin-top:20px;padding:14px;border:0;border-radius:10px;background:var(--brand);color:#fff;font-size:16px;font-weight:700;cursor:pointer}
.btn[disabled]{opacity:.6;cursor:default}
.msg{margin-top:16px;padding:12px;border-radius:10px;font-size:14px;border:1px solid var(--line)}
.ok{color:var(--ok)}.bad{color:var(--bad)}
.small{color:var(--muted);font-size:13px;margin-top:14px;text-align:center}
</style>
</head>
<body>
<main class="card">
  <div class="brand">RestroGrow</div>
<?php if (!$link): ?>
  <h1>Link not found</h1>
  <p class="small" style="text-align:left">This payment link is not valid. Please ask RestroGrow for a new one.</p>
<?php elseif ($status === 'Success'): ?>
  <h1 class="ok">Paid, thank you</h1>
  <div class="amount"><?= $h($amountText) ?></div>
  <div class="row"><span>For</span><span><?= $h($link['restaurant_name']) ?></span></div>
  <?php if ($link['paid_at']): ?><div class="row"><span>Paid on</span><span><?= $h(date('d M Y, g:i a', strtotime($link['paid_at']))) ?></span></div><?php endif; ?>
  <?php if ((int)$link['plan_months'] > 0): ?><div class="row"><span>Plan</span><span><?= (int)$link['plan_months'] ?> month<?= (int)$link['plan_months'] === 1 ? '' : 's' ?> added</span></div><?php endif; ?>
<?php elseif ($status === 'Failed'): ?>
  <h1>This link is closed</h1>
  <p class="small" style="text-align:left">Please ask RestroGrow for a new payment link.</p>
<?php else: ?>
  <h1>Payment to RestroGrow</h1>
  <div class="amount"><?= $h($amountText) ?></div>
  <div class="row"><span>For</span><span><?= $h($link['restaurant_name']) ?></span></div>
  <?php if ($link['notes'] !== null && $link['notes'] !== ''): ?><div class="row"><span>Note</span><span><?= $h($link['notes']) ?></span></div><?php endif; ?>
  <?php if ((int)$link['plan_months'] > 0): ?><div class="row"><span>Plan</span><span>+<?= (int)$link['plan_months'] ?> month<?= (int)$link['plan_months'] === 1 ? '' : 's' ?></span></div><?php endif; ?>
  <?php if ($error): ?><div class="msg bad"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($back): ?>
    <div class="msg" id="checking"><?= isset($_GET['demo']) ? 'Demo mode on this computer: no real payment was made.' : 'Checking your payment with PhonePe…' ?></div>
  <?php endif; ?>
  <form id="payForm" method="post"<?= $back && !isset($_GET['demo']) ? ' style="display:none"' : '' ?> action="<?= $h(ppLinkUrl($token)) ?>" onsubmit="this.querySelector('button').disabled=true">
    <input type="hidden" name="t" value="<?= $h($token) ?>">
    <input type="hidden" name="action" value="pay">
    <button class="btn" type="submit"><?= $back ? 'Pay again' : 'Pay ' . $h($amountText) ?></button>
  </form>
  <p class="small">UPI, cards and net banking via PhonePe. Secure payment.</p>
  <?php if ($back && !isset($_GET['demo'])): ?>
  <script>
  (function () {
    var n = 0, box = document.getElementById('checking');
    function check() {
      fetch(location.pathname + '?t=<?= $h($token) ?>&check=1', { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d.status === 'Success' || d.status === 'Failed') { location.replace(location.pathname + '?t=<?= $h($token) ?>'); return; }
          // "Pay again" only once the last try had time to finish (no paying twice)
          if (++n === 10) document.getElementById('payForm').style.display = '';
          if (n < 30) setTimeout(check, n < 10 ? 3000 : 8000);
          else box.textContent = 'Not confirmed yet. If money left your account, it will show here in a few minutes. You can also pay again.';
        })
        .catch(function () { if (++n < 30) setTimeout(check, 5000); });
    }
    setTimeout(check, 2000);
  })();
  </script>
  <?php endif; ?>
<?php endif; ?>
</main>
</body>
</html>
