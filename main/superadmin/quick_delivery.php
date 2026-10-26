<?php
/**
 * Shiprocket Quick (Super Admin only): local riders booked through RestroGrow's Shiprocket account
 * (config/quick_delivery.php). Every ride is paid from that account's wallet, so a restaurant books
 * only once it is switched on here.
 *
 *   API user          typed here (saved sealed) when .env has no SHIPROCKET_EMAIL / SHIPROCKET_PASSWORD
 *   Test connection   logs in with that API user
 *   Test price        a rider from a restaurant to a point ~1.5 km away (adds its pickup address; books nothing)
 *   Restaurants       on / off per restaurant (India, with a map pin), rides and spend in the last 30 days
 *   Rides             the last 60 bookings, with Shiprocket's status and any error
 * All POST + CSRF token.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/quick_delivery.php';
require_once __DIR__ . '/../config/demo_restaurant.php';
require_once __DIR__ . '/../config/prg.php';

$conn = getConnection();
qdEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['qd_csrf'])) $_SESSION['qd_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['qd_csrf'];

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $rid = (string)($_POST['rid'] ?? '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Your session changed. Please try again.');
        if ($action === 'save_login') {
            $email = trim((string)($_POST['sr_email'] ?? '')); $pass = (string)($_POST['sr_password'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') throw new InvalidArgumentException('Enter the API user\'s email and password.');
            qdSaveCreds($conn, $email, $pass);
            qdToken($conn, true);
            $message = 'Saved and logged in to Shiprocket as ' . $email . '. Now try "Test price" on a restaurant below.';
        } elseif ($action === 'clear_login') {
            qdSaveCreds($conn, '', '');
            $message = 'Removed the saved Shiprocket API user.';
        } elseif ($action === 'test_login') {
            qdToken($conn, true);
            $p = qdApi($conn, 'GET', '/settings/company/pickup');
            $n = count($p['data']['shipping_address'] ?? []);
            $message = "Connected to Shiprocket. The account has $n pickup address" . ($n === 1 ? '' : 'es') . '.';
        } elseif ($action === 'toggle' || $action === 'test_price') {
            $st = $conn->prepare('SELECT restaurant_id, restaurant_name, restaurant_lat, restaurant_lng FROM users WHERE restaurant_id = ?');
            $st->execute([$rid]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new InvalidArgumentException('Restaurant not found');
            if ($action === 'toggle') {
                if ($rid === DEMO_RESTAURANT_ID) throw new InvalidArgumentException('The demo restaurant never books real riders.');
                $on = !qdSettings($conn, $rid)['enabled'];
                qdSaveSettings($conn, $rid, ['enabled' => $on ? 1 : 0]);
                qdLog($conn, $rid, 'delivery.quick', 'RestroGrow ' . ($on ? 'switched on' : 'switched off') . ' Shiprocket Quick riders', 'RestroGrow support');
                $message = $r['restaurant_name'] . ': Shiprocket Quick ' . ($on ? 'on. Its delivery orders can book riders now.' : 'off.');
            } else {
                [, $pin] = qdPickup($conn, $rid);
                $lat2 = (float)$r['restaurant_lat'] + 0.012; $lng2 = (float)$r['restaurant_lng'] + 0.008;
                $drop = qdReverse($lat2, $lng2)['pincode'] ?? '';
                $ask = ['pickup_postcode' => $pin, 'delivery_postcode' => $drop ?: $pin, 'cod' => 0, 'weight' => 1, 'is_new_hyperlocal' => 1,
                    'lat_from' => (float)$r['restaurant_lat'], 'long_from' => (float)$r['restaurant_lng'], 'lat_to' => $lat2, 'long_to' => $lng2];
                // cash on delivery too: Shiprocket may serve a prepaid ride and not a COD one
                try { $c = qdServiceability($conn, ['cod' => 1] + $ask); $cod = 'COD ₹' . number_format($c['rate'], 2); }
                catch (Throwable $e) { $cod = 'COD: ' . $e->getMessage(); }
                $codSeen = $GLOBALS['qd_last_service'] ?? null;
                $q = qdServiceability($conn, $ask);
                $GLOBALS['qd_last_service'] = ['prepaid' => $GLOBALS['qd_last_service'] ?? null, 'cod' => $codSeen];
                $message = $r['restaurant_name'] . ' → ~1.5 km away: ' . $q['courier_name'] . ' prepaid ₹' . number_format($q['rate'], 2) . ($q['eta'] !== '' ? ' (' . $q['eta'] . ')' : '') . '; ' . $cod . '. Nothing was booked.';
            }
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (PDOException $e) {
        error_log('superadmin quick_delivery: ' . $e->getMessage());
        $error = 'Database error. See the server log.';
    } catch (Throwable $e) {
        $error = 'Shiprocket: ' . $e->getMessage();
    }
    // back to a GET, so a refresh doesn't switch a restaurant again (config/prg.php)
    // Test price: what was asked and what Shiprocket answered (to see why it says no)
    $raw = $action === 'test_price' && isset($GLOBALS['qd_last_service']) ? mb_substr(json_encode($GLOBALS['qd_last_service'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '', 0, 6000) : '';
    prgRedirect('quick_delivery', ['message' => $message, 'error' => $error, 'raw' => $raw]);
}
['message' => $message, 'error' => $error, 'raw' => $raw] = prgTake('quick_delivery', ['message' => '', 'error' => '', 'raw' => '']);

// restaurants that could use it: India (or no country), not deleted; the ones with a map pin first
$restaurants = $conn->query("SELECT u.restaurant_id, u.restaurant_name, u.city, u.restaurant_lat, u.restaurant_lng, u.address,
        COALESCE(q.enabled, 0) AS enabled, COALESCE(q.auto_mode, 'accept') AS auto_mode, q.pickup_name,
        (SELECT COUNT(*) FROM qd_bookings b WHERE b.restaurant_id = u.restaurant_id AND b.status IN ('booked','picked_up','on_the_way','delivered') AND b.created_at > NOW() - INTERVAL 30 DAY) AS rides,
        (SELECT COALESCE(SUM(b.rate), 0) FROM qd_bookings b WHERE b.restaurant_id = u.restaurant_id AND b.status IN ('booked','picked_up','on_the_way','delivered') AND b.created_at > NOW() - INTERVAL 30 DAY) AS spend
    FROM users u LEFT JOIN qd_restaurants q ON q.restaurant_id = u.restaurant_id
    WHERE (u.country IS NULL OR u.country = '' OR u.country = 'India') AND u.restaurant_id IS NOT NULL AND u.restaurant_id <> ''
    ORDER BY COALESCE(q.enabled, 0) DESC, (u.restaurant_lat IS NOT NULL AND u.restaurant_lat <> 0) DESC, u.restaurant_name
    LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$rides = $conn->query("SELECT b.*, o.order_number, u.restaurant_name FROM qd_bookings b
    LEFT JOIN orders o ON o.id = b.order_id LEFT JOIN users u ON u.restaurant_id = b.restaurant_id
    ORDER BY b.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC);
$cronAt = (string)$conn->query("SELECT v FROM sa_settings WHERE k = 'qd_cron_at'")->fetchColumn();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$hookUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/api/quick_delivery_hook.php';
$hookToken = trim((string)env('QUICK_DELIVERY_WEBHOOK_TOKEN', '')) !== '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Shiprocket Quick · Super Admin</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 24px 16px; }
  .box { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; }
  h2 { font-size: 15px; margin: 26px 0 8px; }
  p, li, td, th { font-size: 14px; line-height: 1.5; color: #374151; }
  .msg { padding: 12px 14px; border-radius: 10px; margin: 14px 0; font-size: 14px; }
  .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #991b1b; }
  .warn { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 5px; font-size: 13px; word-break: break-all; }
  .tbl { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; }
  th { text-align: left; color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
  td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  .kv td:first-child { width: 190px; color: #6b7280; padding-left: 0; }
  .btn { display: inline-block; border: 0; border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .primary { background: #7c3aed; color: #fff; } .plain { background: #f3f4f6; color: #111827; } .off { background: #fff; color: #b91c1c; border: 1.5px solid #fca5a5; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .p-on { background: #ede9fe; color: #5b21b6; } .p-ok { background: #dcfce7; color: #166534; } .p-bad { background: #fee2e2; color: #991b1b; } .p-mute { background: #f3f4f6; color: #6b7280; }
  form { margin: 0; display: inline; }
  small { color: #6b7280; }
</style>
</head>
<body>
<div class="box">
  <h1>Shiprocket Quick</h1>
  <p>Local riders for delivery orders, booked through RestroGrow's Shiprocket account. Every ride is paid from that
     account's wallet, so a restaurant can book only after you switch it on below. The owner then picks in
     Order details › Delivery whether riders are booked by themselves (when the order is accepted, or when the food is ready) or by hand.</p>

  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?php echo $h($error); ?></div><?php endif; ?>
  <?php if ($raw): ?><details<?php echo $error ? ' open' : ''; ?>><summary style="cursor:pointer;font-size:13px;color:#4b5563">Shiprocket's answer (what was asked and what came back)</summary>
    <pre style="white-space:pre-wrap;word-break:break-all;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;font-size:12px;max-height:340px;overflow:auto"><?php echo $h($raw); ?></pre></details><?php endif; ?>

  <h2>Setup</h2>
  <table class="kv">
    <tr><td>API user</td><td><?php $cr = qdCreds(); echo qdConfigured() ? '<span class="pill p-ok">Set</span> <code>' . $h($cr['email']) . '</code> <small>' . ($cr['from'] === 'env' ? 'from .env' : 'saved here') . '</small>' : '<span class="pill p-bad">Missing</span> enter it below (or <code>SHIPROCKET_EMAIL</code> / <code>SHIPROCKET_PASSWORD</code> in <code>main/.env</code>)'; ?>
      <?php if ($cr['from'] !== 'env'): ?>
      <form method="post" autocomplete="off" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="save_login">
        <input type="email" name="sr_email" placeholder="API user email" value="<?php echo $h($cr['email']); ?>" required style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;min-width:220px">
        <input type="password" name="sr_password" placeholder="API user password" autocomplete="new-password" required style="padding:7px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;min-width:200px">
        <button class="btn primary" type="submit">Save &amp; test login</button></form>
      <?php if ($cr['from'] === 'saved'): ?><form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="clear_login"><button class="btn off" type="submit" style="margin-top:6px">Remove saved login</button></form><?php endif; ?>
      <br><small>Shiprocket › Settings › API › Configure › Create API User. Its email must differ from your main Shiprocket login.<?php echo qdKey() ? ' The password is stored encrypted.' : ' <b>WA_KEY_SECRET is missing, so the password is stored as plain text.</b>'; ?></small>
      <?php endif; ?></td></tr>
    <tr><td>Map pins → pincodes</td><td><?php echo trim((string)env('GEOAPIFY_API_KEY', '')) !== '' ? '<span class="pill p-ok">Geoapify key set</span>' : '<span class="pill p-bad">Missing</span> <code>GEOAPIFY_API_KEY</code> (drops without a pincode in the address can\'t be booked)'; ?></td></tr>
    <tr><td>Status updates (cron)</td><td><?php echo $cronAt !== '' ? 'Last ran ' . $h(date('j M, g:i A', strtotime($cronAt))) : '<span class="pill p-bad">Not running</span>'; ?>
      <br><small>hPanel › Cron Jobs, every 2 minutes: <code>/usr/bin/php &lt;HOME&gt;/main/cron/quick_delivery.php</code></small></td></tr>
    <tr><td>Webhook (faster updates)</td><td><?php echo $hookToken ? '<span class="pill p-ok">Token set</span>' : '<span class="pill p-mute">Optional</span> set <code>QUICK_DELIVERY_WEBHOOK_TOKEN</code> in .env'; ?>
      <br><small>Shiprocket › Settings › API › Webhooks: URL <code><?php echo $h($hookUrl); ?></code>, security token = that value.</small></td></tr>
  </table>
  <div class="row" style="margin-top:12px">
    <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="test_login">
      <button class="btn primary" type="submit"<?php echo qdConfigured() ? '' : ' disabled'; ?>>Test connection</button></form>
    <a class="btn plain" href="dashboard.php">Back to dashboard</a>
  </div>

  <h2>Restaurants</h2>
  <p><small>India only. A restaurant needs its address, phone and map pin (Settings › Location) for the rider to find it.
     "Test price" adds its pickup address to Shiprocket and asks for a ride ~1.5 km away; nothing is booked.</small></p>
  <div class="tbl"><table>
    <tr><th>Restaurant</th><th>Map pin</th><th>Riders</th><th>Books by itself</th><th>Last 30 days</th><th></th></tr>
    <?php foreach ($restaurants as $r): $pin = (float)$r['restaurant_lat'] && (float)$r['restaurant_lng']; $demo = $r['restaurant_id'] === DEMO_RESTAURANT_ID; ?>
    <tr>
      <td><b><?php echo $h($r['restaurant_name']); ?></b><br><small><?php echo $h($r['restaurant_id'] . ($r['city'] ? ' · ' . $r['city'] : '')); ?></small></td>
      <td><?php echo $pin ? '<span class="pill p-ok">Yes</span>' : '<span class="pill p-bad">No pin</span>'; ?></td>
      <td><?php echo $demo ? '<span class="pill p-mute">Demo</span>' : ($r['enabled'] ? '<span class="pill p-on">On</span>' : '<span class="pill p-mute">Off</span>'); ?></td>
      <td><?php echo $r['enabled'] ? $h(QD_AUTO_MODES[$r['auto_mode']] ?? $r['auto_mode']) : '—'; ?></td>
      <td><?php echo (int)$r['rides'] ? (int)$r['rides'] . ' rides · ₹' . number_format((float)$r['spend'], 2) : '—'; ?></td>
      <td><div class="row">
        <?php if (!$demo): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="rid" value="<?php echo $h($r['restaurant_id']); ?>">
          <button class="btn <?php echo $r['enabled'] ? 'off' : 'primary'; ?>" type="submit"><?php echo $r['enabled'] ? 'Switch off' : 'Switch on'; ?></button></form>
        <?php endif; ?>
        <?php if ($pin && qdConfigured()): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="test_price"><input type="hidden" name="rid" value="<?php echo $h($r['restaurant_id']); ?>">
          <button class="btn plain" type="submit">Test price</button></form>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>

  <h2>Rides</h2>
  <?php if (!$rides): ?><p>No rides yet.</p><?php else: ?>
  <div class="tbl"><table>
    <tr><th>When</th><th>Restaurant · order</th><th>Status</th><th>Cost</th><th>Rider</th><th>Booked by</th></tr>
    <?php foreach ($rides as $b): $p = qdPublic($b); ?>
    <tr>
      <td><small><?php echo $h(date('j M, g:i A', strtotime($b['created_at']))); ?></small></td>
      <td><?php echo $h($b['restaurant_name'] ?: $b['restaurant_id']); ?><br><small><?php echo $h($b['order_number'] ?: '#' . $b['order_id']); ?><?php echo $b['awb'] ? ' · AWB ' . $h($b['awb']) : ''; ?></small></td>
      <td><span class="pill <?php echo in_array($b['status'], ['failed', 'cancelled'], true) ? 'p-bad' : ($b['status'] === 'delivered' ? 'p-ok' : 'p-on'); ?>"><?php echo $h($p['label']); ?></span>
        <?php if ($b['sr_status']): ?><br><small><?php echo $h($b['sr_status']); ?></small><?php endif; ?>
        <?php if ($b['error']): ?><br><small style="color:#991b1b"><?php echo $h($b['error']); ?></small><?php endif; ?></td>
      <td><?php echo $b['rate'] !== null ? '₹' . number_format((float)$b['rate'], 2) : '—'; ?></td>
      <td><?php echo $h($b['rider_name'] ?: '—'); ?><?php echo $b['rider_phone'] ? '<br><small>' . $h($b['rider_phone']) . '</small>' : ''; ?></td>
      <td><small><?php echo $h(($b['auto'] ? 'Automatic' : '') . ($b['booked_by'] && $b['booked_by'] !== 'Automatic' ? ($b['auto'] ? ' · ' : '') . $b['booked_by'] : '')); ?></small></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
</body>
</html>
