<?php
/**
 * Demo restaurant (Super Admin only): "Spice Garden Indiranagar", login demo / demo, one-tap at /demo.
 *
 * "Reset demo" builds it from scratch (main/config/demo_restaurant.php): menu with photos, tables,
 * staff, customers, six weeks of orders, live orders, bookings and coupons. Use it the first time on
 * a new server, before showing it to someone (fresh orders for today) and whenever visitors have
 * messed it up. "Remove" deletes it. Both are POST + CSRF token.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/demo_restaurant.php';

$conn = getConnection();
$assetDir = realpath(__DIR__ . '/../assets/demo_restaurant');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$mainPath = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
$siteRoot = rtrim(str_replace('\\', '/', dirname($mainPath)), '/');
$origin = $scheme . '://' . $_SERVER['HTTP_HOST'];
$mainUrl = $origin . $mainPath;
$links = [
    'One-tap demo page' => $origin . $siteRoot . '/demo/',
    'Customer website' => $origin . $siteRoot . '/' . demoRestaurantSlug(),
    'Admin login' => $mainUrl . '/admin/login.php',
    'App demo (browser)' => $origin . $siteRoot . '/app-demo/',
];

if (empty($_SESSION['demo_setup_csrf'])) $_SESSION['demo_setup_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['demo_setup_csrf'];

/** Another restaurant that already uses the demo's login username, if any. */
function demoUsernameOwner(PDO $conn): ?array {
    $st = $conn->prepare('SELECT id, restaurant_id, restaurant_name, email, phone, created_at FROM users WHERE username = ? AND restaurant_id <> ? LIMIT 1');
    $st->execute([DEMO_USERNAME, DEMO_RESTAURANT_ID]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $c = $conn->prepare('SELECT COUNT(*) FROM orders WHERE restaurant_id = ?');
    $c->execute([$r['restaurant_id']]);
    $r['orders'] = (int)$c->fetchColumn();
    return $r;
}

$message = ''; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session changed. Please try again.';
    } elseif (in_array($action, ['free_username', 'delete_owner'], true)) {
        // The demo's username belongs to another restaurant: rename that login (its data stays)
        // or delete that restaurant, then build the demo. The restaurant ID must match the one shown.
        $owner = demoUsernameOwner($conn);
        if (!$owner || $owner['restaurant_id'] !== (string)($_POST['owner_rid'] ?? '')) {
            $error = 'That restaurant changed in the meantime. Look at it again below.';
        } else {
            try {
                if ($action === 'free_username') {
                    $newName = 'demo_old_' . $owner['restaurant_id'];
                    $conn->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$newName, $owner['id']]);
                    $done = "{$owner['restaurant_id']} now logs in as \"$newName\" (its password and data are unchanged).";
                } else {
                    $conn->exec('SET FOREIGN_KEY_CHECKS=0');
                    demoRemoveRestaurant($conn, $owner['restaurant_id']);
                    $conn->exec('SET FOREIGN_KEY_CHECKS=1');
                    $done = "{$owner['restaurant_id']} ({$owner['restaurant_name']}) was deleted.";
                }
                @set_time_limit(180);
                resetDemoRestaurant($conn, $assetDir, $mainUrl);
                $message = "$done Demo restaurant is ready.";
            } catch (Throwable $e) {
                error_log('Demo restaurant username fix failed: ' . $e->getMessage());
                $error = 'Could not finish: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'reset') {
        if (!$assetDir || !is_file("$assetDir/hero.jpg")) {
            $error = 'Demo photos are missing on the server (main/assets/demo_restaurant). Deploy them and try again.';
        } else {
            try {
                @set_time_limit(180);
                resetDemoRestaurant($conn, $assetDir, $mainUrl);
                $message = 'Demo restaurant is ready.';
            } catch (Throwable $e) {
                error_log('Demo restaurant reset failed: ' . $e->getMessage());
                $error = 'Could not build the demo restaurant: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'remove') {
        try {
            demoAssertSafe($conn);
            $conn->exec('SET FOREIGN_KEY_CHECKS=0');
            demoRemoveRestaurant($conn, DEMO_RESTAURANT_ID);
            $conn->exec('SET FOREIGN_KEY_CHECKS=1');
            $message = 'Demo restaurant removed, including everything placed on it.';
        } catch (Throwable $e) {
            error_log('Demo restaurant removal failed: ' . $e->getMessage());
            $error = 'Could not remove the demo restaurant: ' . $e->getMessage();
        }
    }
}

$owner = demoUsernameOwner($conn);
$st = $conn->prepare('SELECT username, created_at FROM users WHERE restaurant_id = ? LIMIT 1');
$st->execute([DEMO_RESTAURANT_ID]);
$row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
$stats = [];
if ($row) {
    foreach (['Dishes' => 'menu_items', 'Orders' => 'orders', 'Customers' => 'customers', 'Tables' => 'tables'] as $label => $table) {
        $s = $conn->prepare("SELECT COUNT(*) FROM `$table` WHERE restaurant_id = ?");
        $s->execute([DEMO_RESTAURANT_ID]);
        $stats[$label] = (int)$s->fetchColumn();
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Demo restaurant · Super Admin</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 24px 16px; }
  .box { max-width: 680px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; }
  h2 { font-size: 15px; margin: 22px 0 8px; }
  p, li, td { font-size: 14px; line-height: 1.55; color: #374151; }
  .msg { padding: 12px 14px; border-radius: 10px; margin: 14px 0; font-size: 14px; }
  .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #991b1b; }
  .warn { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; } .warn td { color: #78350f; border-color: #fde68a; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 5px; font-size: 13px; }
  table { border-collapse: collapse; width: 100%; }
  td { padding: 6px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  td:first-child { width: 170px; color: #6b7280; }
  .stats { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
  .stat { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 10px; padding: 10px 14px; min-width: 110px; font-size: 13px; color: #6b7280; }
  .stat b { display: block; font-size: 18px; color: #111827; }
  .btn { display: inline-block; border: 0; border-radius: 10px; padding: 11px 18px; font-size: 14px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .primary { background: #ea580c; color: #fff; } .danger { background: #fff; color: #b91c1c; border: 1.5px solid #fca5a5; }
  .plain { background: #f3f4f6; color: #111827; }
  .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
  a { color: #c2410c; word-break: break-all; }
</style>
</head>
<body>
<div class="box">
  <h1>Demo restaurant</h1>
  <p><b><?php echo $h(DEMO_RESTAURANT_NAME); ?></b> (<?php echo $h(DEMO_RESTAURANT_ID); ?>) is the restaurant we show to people:
     a full menu with photos, tables, staff, six weeks of sales and orders coming in. Anyone with the link can log in,
     so messages, payment keys, passwords and uploads are switched off for it. Press <b>Reset demo</b> before showing it to someone:
     it undoes visitors' changes and puts fresh orders in today.</p>

  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?php echo $h($error); ?></div><?php endif; ?>

  <?php if ($row): ?>
    <div class="stats">
      <?php foreach ($stats as $k => $v): ?><div class="stat"><b><?php echo number_format($v); ?></b><?php echo $h($k); ?></div><?php endforeach; ?>
    </div>
    <p>Last built: <b><?php echo $h(date('j M Y, g:i A', strtotime($row['created_at']))); ?></b></p>
  <?php else: ?>
    <p>Status: <b>not created on this server yet.</b> Press <b>Create demo</b> to build it.</p>
  <?php endif; ?>

  <?php if ($owner): ?>
  <div class="msg warn">
    <b>The login name "<?php echo $h(DEMO_USERNAME); ?>" is already used by another restaurant</b>, so the demo can't use it yet:
    <table style="margin:10px 0">
      <tr><td>Restaurant</td><td><b><?php echo $h($owner['restaurant_name']); ?></b> (<?php echo $h($owner['restaurant_id']); ?>)</td></tr>
      <tr><td>Email / phone</td><td><?php echo $h($owner['email'] ?: '—'); ?> · <?php echo $h($owner['phone'] ?: '—'); ?></td></tr>
      <tr><td>Created</td><td><?php echo $h($owner['created_at'] ? date('j M Y', strtotime($owner['created_at'])) : '—'); ?></td></tr>
      <tr><td>Orders</td><td><?php echo number_format($owner['orders']); ?></td></tr>
    </table>
    <div class="row" style="margin-top:6px">
      <form method="post">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="free_username">
        <input type="hidden" name="owner_rid" value="<?php echo $h($owner['restaurant_id']); ?>">
        <button class="btn primary" type="submit">Rename its login and build the demo</button>
      </form>
      <form method="post" onsubmit="return confirm('Delete <?php echo $h(addslashes($owner['restaurant_name'])); ?> (<?php echo $h($owner['restaurant_id']); ?>) and everything in it? This cannot be undone.');">
        <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
        <input type="hidden" name="action" value="delete_owner">
        <input type="hidden" name="owner_rid" value="<?php echo $h($owner['restaurant_id']); ?>">
        <button class="btn danger" type="submit">Delete that restaurant and build the demo</button>
      </form>
    </div>
    <p style="margin:10px 0 0;font-size:13px">Rename keeps everything: that restaurant then logs in as <code>demo_old_<?php echo $h($owner['restaurant_id']); ?></code> with its old password.
       Delete is for an old test account you don't need.</p>
  </div>
  <?php endif; ?>

  <h2>Logins (password for all: <code><?php echo $h(DEMO_PASSWORD); ?></code>)</h2>
  <table>
    <tr><td>Owner</td><td><code><?php echo $h(DEMO_USERNAME); ?></code></td></tr>
    <tr><td>Manager</td><td><code>manager.demo@spicegarden.test</code></td></tr>
    <tr><td>Waiters</td><td><code>waiter.demo@spicegarden.test</code>, <code>waiter2.demo@spicegarden.test</code></td></tr>
    <tr><td>Kitchen</td><td><code>chef.demo@spicegarden.test</code>, <code>chef2.demo@spicegarden.test</code></td></tr>
    <tr><td>Coupons to try</td><td><code>WELCOME50</code> <code>SPICE20</code> <code>FEAST100</code> <code>WEEKEND15</code></td></tr>
  </table>

  <h2>Links</h2>
  <table>
    <?php foreach ($links as $label => $url): ?>
      <tr><td><?php echo $h($label); ?></td><td><a href="<?php echo $h($url); ?>" target="_blank" rel="noopener"><?php echo $h($url); ?></a></td></tr>
    <?php endforeach; ?>
  </table>
  <p>The one-tap page isn't linked from the public website. Share it only with people you're showing the product to.</p>

  <div class="row">
    <?php if (!$owner): ?>
    <form method="post" onsubmit="this.querySelector('button').textContent='Building… (up to a minute)';">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="reset">
      <button class="btn primary" type="submit"><?php echo $row ? 'Reset demo' : 'Create demo'; ?></button>
    </form>
    <?php endif; ?>
    <?php if ($row): ?>
    <form method="post" onsubmit="return confirm('Remove the demo restaurant and everything on it?');">
      <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>">
      <input type="hidden" name="action" value="remove">
      <button class="btn danger" type="submit">Remove</button>
    </form>
    <?php endif; ?>
    <a class="btn plain" href="dashboard.php">Back to dashboard</a>
  </div>
</div>
</body>
</html>
