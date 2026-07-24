<?php
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../config/env_loader.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';

$action = $_GET['action'] ?? '';
$link_id = (int)($_GET['id'] ?? 0);
$debug_log = [];

// JSON polling endpoint
if ($action === 'check' && $link_id > 0) {
    header('Content-Type: application/json');
    try {
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT payment_status FROM payment_links WHERE id = ? LIMIT 1");
        $stmt->execute([$link_id]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'payment_status' => $link['payment_status'] ?? 'Pending']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'payment_status' => 'Pending']);
    }
    exit();
}

// If we have id in URL, this is the initial visit — store in session and redirect to PhonePe
if ($link_id > 0) {
    $_SESSION['pending_payment_link_id'] = $link_id;
    $debug_log[] = 'Stored id=' . $link_id . ' in session';

    try {
        $conn = getConnection();
        $stmt = $conn->prepare("SELECT transaction_id, payment_status, phonepe_checkout_url, payment_url FROM payment_links WHERE id = ? LIMIT 1");
        $stmt->execute([$link_id]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($link && $link['payment_status'] === 'Pending' && !empty($link['phonepe_checkout_url'])) {
            $checkout_url = $link['phonepe_checkout_url'];
            $debug_log[] = 'Redirecting to PhonePe: ' . $checkout_url;
            ?>
            <!DOCTYPE html>
            <html><head><title>Redirecting...</title>
            <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5;margin:0}
            .card{background:#fff;border-radius:16px;padding:40px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,0.08);max-width:420px;width:90%}
            .spinner{display:inline-block;width:32px;height:32px;border:3px solid #e0e0e0;border-top-color:#1a73e8;border-radius:50%;animation:spin .8s linear infinite;margin-bottom:16px}
            @keyframes spin{to{transform:rotate(360deg)}}p{color:#666}
            </style></head>
            <body><div class="card"><div class="spinner"></div><h2>Redirecting to payment...</h2><p>Please wait while we redirect you to the secure payment page.</p></div>
            <script>setTimeout(function(){ window.location.href = '<?= addslashes($checkout_url) ?>'; }, 500);</script></body></html>
            <?php
            exit();
        } else {
            $debug_log[] = 'No checkout URL or already processed';
        }
    } catch (Exception $e) {
        $debug_log[] = 'Error: ' . $e->getMessage();
    }
}

// No id in URL — check session for stored ID (return from PhonePe)
if ($link_id === 0 && isset($_SESSION['pending_payment_link_id'])) {
    $link_id = (int)$_SESSION['pending_payment_link_id'];
    $debug_log[] = 'Got id=' . $link_id . ' from session (return from PhonePe)';
}

$txn_id = '';
$status = 'Pending';

if ($link_id > 0) {
    try {
        $conn = getConnection();
        $debug_log[] = 'Lookup by id=' . $link_id;
        $stmt = $conn->prepare("SELECT * FROM payment_links WHERE id = ? LIMIT 1");
        $stmt->execute([$link_id]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($link) {
            $txn_id = $link['transaction_id'];
            $debug_log[] = 'Found: txn=' . $txn_id . ' status=' . $link['payment_status'];

            if ($link['payment_status'] !== 'Pending') {
                $status = $link['payment_status'];
                $debug_log[] = 'Already ' . $status;
                unset($_SESSION['pending_payment_link_id']);
            } else {
                $debug_log[] = 'Actively checking PhonePe API...';
                $envs = [
                    'sandbox' => [
                        'base' => 'https://api-preprod.phonepe.com/apis/pg-sandbox',
                        'id' => env('PHONEPE_SANDBOX_MERCHANT_ID', ''),
                        'secret' => env('PHONEPE_SANDBOX_SALT_KEY', ''),
                        'version' => env('PHONEPE_SANDBOX_SALT_INDEX', '1'),
                        'type' => 'oauth',
                    ],
                    'production' => [
                        'base' => 'https://api.phonepe.com/apis/pg',
                        'id' => env('PHONEPE_CLIENT_ID', '') ?: env('PHONEPE_MERCHANT_ID', ''),
                        'secret' => env('PHONEPE_CLIENT_SECRET', '') ?: env('PHONEPE_SALT_KEY', ''),
                        'version' => env('PHONEPE_CLIENT_VERSION', '') ?: env('PHONEPE_SALT_INDEX', '1'),
                        'type' => 'hash',
                    ],
                ];

                foreach ($envs as $env_name => $env) {
                    if (empty($env['id']) || empty($env['secret'])) continue;
                    $debug_log[] = 'Trying ' . $env_name . '...';

                    try {
                        if ($env['type'] === 'oauth') {
                            $ch = curl_init($env['base'] . '/v1/oauth/token');
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                                'client_id' => $env['id'], 'client_version' => $env['version'],
                                'client_secret' => $env['secret'], 'grant_type' => 'client_credentials',
                            ]));
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $auth_resp = curl_exec($ch);
                            $auth_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            if ($auth_http !== 200) { $debug_log[] = $env_name . ' auth: HTTP ' . $auth_http; continue; }
                            $auth_data = json_decode($auth_resp, true);
                            if (!isset($auth_data['access_token'])) { $debug_log[] = $env_name . ' auth: no token'; continue; }

                            $status_url = $env['base'] . '/checkout/v2/order/' . urlencode($txn_id) . '/status';
                            $ch = curl_init($status_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Authorization: O-Bearer ' . $auth_data['access_token'], 'Accept: application/json',
                            ]);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $resp = curl_exec($ch);
                            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            $debug_log[] = $env_name . ' status: HTTP ' . $http;

                            if ($http === 200) {
                                $data = json_decode($resp, true);
                                $debug_log[] = $env_name . ' resp: ' . json_encode($data);
                                $state = $data['state'] ?? $data['orderStatus'] ?? $data['responseCode'] ?? '';
                                if (in_array($state, ['COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS'])) {
                                    $conn->prepare("UPDATE payment_links SET payment_status = 'Success' WHERE id = ?")->execute([$link_id]);
                                    $status = 'Success'; $debug_log[] = $env_name . ': SUCCESS'; break;
                                } elseif (in_array($state, ['FAILED', 'REJECTED', 'CANCELLED', 'PAYMENT_FAILED', 'PAYMENT_ERROR'])) {
                                    $conn->prepare("UPDATE payment_links SET payment_status = 'Failed' WHERE id = ?")->execute([$link_id]);
                                    $status = 'Failed'; $debug_log[] = $env_name . ': FAILED'; break;
                                } else { $debug_log[] = $env_name . ': state=' . $state; }
                            } else { $debug_log[] = $env_name . ' body: ' . substr($resp ?? '', 0, 300); }
                        } else {
                            $status_url = $env['base'] . '/pg/v1/status/' . urlencode($env['id']) . '/' . urlencode($txn_id);
                            $x_verify = hash('sha256', '/pg/v1/status/' . $env['id'] . '/' . $txn_id . $env['secret']) . '###' . $env['version'];
                            $ch = curl_init($status_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-VERIFY: ' . $x_verify, 'Accept: application/json']);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $resp = curl_exec($ch);
                            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            $debug_log[] = $env_name . ' v1 status: HTTP ' . $http;
                            if ($http === 200) {
                                $data = json_decode($resp, true);
                                $debug_log[] = $env_name . ' v1 resp: ' . json_encode($data);
                                $state = $data['state'] ?? $data['code'] ?? '';
                                if ($data['success'] === true && in_array($state, ['COMPLETED', 'SUCCESS', 'PAYMENT_SUCCESS'])) {
                                    $conn->prepare("UPDATE payment_links SET payment_status = 'Success' WHERE id = ?")->execute([$link_id]);
                                    $status = 'Success'; $debug_log[] = $env_name . ': SUCCESS'; break;
                                } elseif ($data['success'] === false || in_array($state, ['FAILED', 'REJECTED', 'CANCELLED'])) {
                                    $conn->prepare("UPDATE payment_links SET payment_status = 'Failed' WHERE id = ?")->execute([$link_id]);
                                    $status = 'Failed'; $debug_log[] = $env_name . ': FAILED'; break;
                                }
                            } else { $debug_log[] = $env_name . ' v1 body: ' . substr($resp ?? '', 0, 300); }
                        }
                    } catch (Exception $e) { $debug_log[] = $env_name . ' error: ' . $e->getMessage(); }
                }
                if ($status !== 'Pending') unset($_SESSION['pending_payment_link_id']);
            }
        } else { $debug_log[] = 'No payment link for id=' . $link_id; }
    } catch (Exception $e) { $debug_log[] = 'DB error: ' . $e->getMessage(); }
} else {
    $debug_log[] = 'No id param and no session. GET=' . json_encode($_GET);
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Payment Status</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f5f5f5}
.card{background:#fff;border-radius:16px;padding:40px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,0.08);max-width:420px;width:90%}
.icon{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px}
.s .icon{background:#e8f5e9;color:#2e7d32}
.f .icon{background:#ffebee;color:#c62828}
.p .icon{background:#fff3e0;color:#e65100}
h2{margin-bottom:8px;font-size:20px}
p{color:#666;margin-bottom:24px;font-size:14px;line-height:1.5}
.ref{font-size:12px;color:#999;word-break:break-all;margin-bottom:24px}
.btn{display:inline-block;padding:10px 24px;background:#1a73e8;color:#fff;text-decoration:none;border-radius:8px;font-size:14px}
.btn:hover{background:#1557b0}
.spinner{display:inline-block;width:20px;height:20px;border:3px solid #e0e0e0;border-top-color:#1a73e8;border-radius:50%;animation:spin .8s linear infinite;margin-bottom:12px}
@keyframes spin{to{transform:rotate(360deg)}}
.debug{background:#1a1a1a;color:#0f0;padding:16px;border-radius:8px;font-size:11px;font-family:monospace;text-align:left;max-height:300px;overflow:auto;margin-top:16px;white-space:pre-wrap}
</style></head>
<body>
<div class="card <?= $status === 'Success' ? 's' : ($status === 'Failed' ? 'f' : 'p') ?>">
<?php if ($status === 'Pending'): ?><div class="spinner"></div><?php endif; ?>
<div class="icon"><?= $status === 'Success' ? '&#10003;' : ($status === 'Failed' ? '&#10007;' : '&#9888;') ?></div>
<h2 id="statusTitle"><?= $status === 'Success' ? 'Payment Successful!' : ($status === 'Failed' ? 'Payment Failed' : 'Processing your payment...') ?></h2>
<p id="statusMsg"><?= $status === 'Success' ? 'Your payment has been processed successfully.' : ($status === 'Failed' ? 'The payment could not be completed.' : 'Please wait while we confirm your payment.') ?></p>
<?php if ($txn_id): ?><div class="ref">Ref: <?= htmlspecialchars($txn_id) ?></div><?php endif; ?>
<a href="javascript:window.close()" class="btn">Close</a>
<?php if (!empty($debug_log)): ?>
<details class="debug"><summary>Debug Log</summary><?= htmlspecialchars(implode("\n", $debug_log)) ?></details>
<?php endif; ?>
</div>

<?php if ($status === 'Pending' && $link_id > 0): ?>
<script>
var pollId = <?= $link_id ?>;
var pollCount = 0;
setInterval(function() {
    pollCount++;
    if (pollCount > 30) { clearInterval(pollInterval); return; }
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'payment_link_redirect.php?action=check&id=' + pollId, true);
    xhr.onload = function() {
        if (xhr.status === 200) {
            try {
                var d = JSON.parse(xhr.responseText);
                if (d.payment_status === 'Success' || d.payment_status === 'Failed') {
                    clearInterval(pollInterval);
                    location.reload();
                }
            } catch(e) {}
        }
    };
    xhr.send();
}, 3000);
</script>
<?php endif; ?>
</body>
</html>
