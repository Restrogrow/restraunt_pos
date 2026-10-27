<?php
/**
 * QR kit orders (Super Admin only): Smart QR Menu packages bought on restrogrow.com/qr-menu-ordering/
 * (config/qr_kit.php, api/qr_kit_order.php). Paid ones first; move each along (menu being built, standees sent,
 * delivered), tick when the balance was collected, keep a note. Unpaid tries are listed below to call back.
 * Dashboard logins: people who may open the separate QR kit orders dashboard (main/qrkit/) and see only this.
 * All POST + CSRF token.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/qr_kit.php';
require_once __DIR__ . '/../config/prg.php';

$conn = getConnection();
qkEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$inr = fn($n) => '₹' . number_format((float)$n, 0, '.', ',');
if (empty($_SESSION['qk_csrf'])) $_SESSION['qk_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['qk_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = ''; $error = '';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Your session changed. Please try again.');
        $action = (string)($_POST['action'] ?? 'order');
        if ($action === 'add_user') {
            qkUserCreate($conn, (string)($_POST['name'] ?? ''), (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''));
            $message = 'Login made. Send them the dashboard link with their username and password.';
        } elseif ($action === 'user_active') {
            $conn->prepare('UPDATE qr_kit_users SET active = ? WHERE id = ?')->execute([!empty($_POST['active']) ? 1 : 0, (int)($_POST['id'] ?? 0)]);
            $message = !empty($_POST['active']) ? 'Login switched on.' : 'Login switched off: they are signed out on their next click.';
        } elseif ($action === 'user_password') {
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 8) throw new InvalidArgumentException('The password needs at least 8 characters.');
            $conn->prepare('UPDATE qr_kit_users SET password_hash = ? WHERE id = ?')->execute([password_hash($pw, PASSWORD_DEFAULT), (int)($_POST['id'] ?? 0)]);
            $message = 'Password changed.';
        } else {
            if (!qkUpdateOrder($conn, (int)($_POST['id'] ?? 0), (string)($_POST['stage'] ?? ''), !empty($_POST['balance_paid']), (string)($_POST['team_note'] ?? ''))) {
                throw new InvalidArgumentException('Order not found.');
            }
            $message = 'Saved.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('superadmin qr_kits: ' . $e->getMessage());
        $error = 'Database error. See the server log.';
    }
    prgRedirect('qr_kits', ['message' => $message, 'error' => $error]);
}
['message' => $message, 'error' => $error] = prgTake('qr_kits', ['message' => '', 'error' => '']);

$paid = $conn->query("SELECT * FROM qr_kit_orders WHERE payment_status = 'paid' ORDER BY FIELD(stage, 'paid', 'building', 'shipped', 'delivered', 'cancelled'), paid_at DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
$tries = $conn->query("SELECT * FROM qr_kit_orders WHERE payment_status <> 'paid' AND created_at > NOW() - INTERVAL 30 DAY ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
$sum = $conn->query("SELECT COUNT(*) n, COALESCE(SUM(pay_now), 0) now, COALESCE(SUM(CASE WHEN balance_paid = 0 AND stage <> 'cancelled' THEN pay_on_delivery ELSE 0 END), 0) due FROM qr_kit_orders WHERE payment_status = 'paid'")->fetch(PDO::FETCH_ASSOC);
$users = $conn->query('SELECT id, name, username, active, last_login, created_at FROM qr_kit_users ORDER BY active DESC, name')->fetchAll(PDO::FETCH_ASSOC);
$dashUrl = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'restrogrow.com')
    . rtrim(str_replace(chr(92), '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/main/superadmin/x'))), '/') . '/qrkit/';
$wa = fn($phone, $text) => 'https://wa.me/91' . preg_replace('/\D/', '', $phone) . '?text=' . rawurlencode($text);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>QR kit orders · Super Admin</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 24px 16px; }
  .box { max-width: 1100px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; } h2 { font-size: 15px; margin: 26px 0 8px; }
  p, li, td, th { font-size: 14px; line-height: 1.5; color: #374151; }
  .msg { padding: 12px 14px; border-radius: 10px; margin: 14px 0; font-size: 14px; } .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #991b1b; }
  .tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin: 14px 0; }
  .tile { background: #f9fafb; border-radius: 12px; padding: 12px 14px; } .tile small { display: block; color: #6b7280; font-size: 12px; } .tile b { font-size: 20px; }
  .tbl { overflow-x: auto; } table { border-collapse: collapse; width: 100%; }
  th { text-align: left; color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
  td { padding: 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; } td small { color: #6b7280; display: block; }
  select, input[type=text] { padding: 6px 8px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-family: inherit; }
  .btn { display: inline-block; border: 0; border-radius: 9px; padding: 7px 12px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .primary { background: #7c3aed; color: #fff; } .plain { background: #f3f4f6; color: #111827; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .p-on { background: #ede9fe; color: #5b21b6; } .p-ok { background: #dcfce7; color: #166534; } .p-bad { background: #fee2e2; color: #991b1b; } .p-mute { background: #f3f4f6; color: #6b7280; }
  form.f { display: grid; gap: 6px; min-width: 210px; }
</style>
</head>
<body>
<div class="box">
  <p><a href="dashboard.php">← Super Admin</a></p>
  <h1>QR kit orders</h1>
  <p>Smart QR Menu packages bought on <a href="../../qr-menu-ordering/" target="_blank" rel="noopener">restrogrow.com/qr-menu-ordering</a>. Paid to RestroGrow's PhonePe.
     Each paid order also comes to the team email. Build the menu, send the standees, collect the balance on delivery.</p>
  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?php echo $h($error); ?></div><?php endif; ?>

  <div class="tiles">
    <div class="tile"><small>Paid orders</small><b><?php echo (int)$sum['n']; ?></b></div>
    <div class="tile"><small>Received online</small><b><?php echo $inr($sum['now']); ?></b></div>
    <div class="tile"><small>Balance to collect on delivery</small><b><?php echo $inr($sum['due']); ?></b></div>
  </div>

  <h2>Paid</h2>
  <?php if (!$paid): ?><p>No paid orders yet.</p><?php else: ?>
  <div class="tbl"><table>
    <tr><th>Paid</th><th>Buyer</th><th>Package</th><th>Money</th><th>Deliver to</th><th>Status</th></tr>
    <?php foreach ($paid as $o): ?>
    <tr>
      <td><?php echo $h(date('j M, g:i A', strtotime((string)$o['paid_at']))); ?><small><?php echo $h($o['transaction_id']); ?></small></td>
      <td><b><?php echo $h($o['restaurant']); ?></b><small><?php echo $h($o['name']); ?> · <a href="tel:<?php echo $h($o['phone']); ?>"><?php echo $h($o['phone']); ?></a> ·
        <a href="<?php echo $h($wa($o['phone'], 'Hi ' . $o['name'] . ', this is RestroGrow. Thank you for booking the Smart QR Menu for ' . $o['restaurant'] . '! Please send photos of your menu and your logo here, and we will set it up within 24 hours.')); ?>" target="_blank" rel="noopener">WhatsApp</a></small></td>
      <td><?php echo $h(QK_PACKAGES[$o['package']]['name'] ?? $o['package']); ?><small><?php echo (int)$o['standees']; ?> standees · <?php echo $h($o['design']); ?>
        <?php echo $o['add_delivery'] ? '<br>+ takeaway & delivery' : ''; ?><?php echo $o['add_payments'] ? '<br>+ online payments' : ''; ?></small></td>
      <td><?php echo $inr($o['total']); ?><small>paid <?php echo $inr($o['pay_now']); ?><?php echo $o['pay_on_delivery'] > 0 ? ' · ' . $inr($o['pay_on_delivery']) . ' on delivery' : ' · all paid'; ?></small></td>
      <td><small style="color:#374151"><?php echo $h($o['address'] . ', ' . $o['city'] . ', ' . $o['state'] . ' - ' . $o['pincode']); ?></small></td>
      <td>
        <form class="f" method="post">
          <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>">
          <select name="stage"><?php foreach (QK_STAGES as $k => $l): ?><option value="<?php echo $k; ?>"<?php echo $o['stage'] === $k ? ' selected' : ''; ?>><?php echo $h($l); ?></option><?php endforeach; ?></select>
          <?php if ($o['pay_on_delivery'] > 0): ?><label style="font-size:13px"><input type="checkbox" name="balance_paid" value="1"<?php echo $o['balance_paid'] ? ' checked' : ''; ?>> Balance collected</label><?php endif; ?>
          <input type="text" name="team_note" maxlength="500" placeholder="Note" value="<?php echo $h($o['team_note']); ?>">
          <button class="btn primary" type="submit">Save</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>

  <h2>Dashboard logins</h2>
  <p>A separate dashboard with only these orders: <a href="<?php echo $h($dashUrl); ?>" target="_blank" rel="noopener"><?php echo $h($dashUrl); ?></a>.
     Give a login to a sales or delivery person: they see QR kit orders and nothing else in RestroGrow.</p>
  <?php if ($users): ?>
  <div class="tbl"><table>
    <tr><th>Name</th><th>Username</th><th>Last sign-in</th><th>Status</th><th>New password</th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
      <td><?php echo $h($u['name']); ?></td><td><code><?php echo $h($u['username']); ?></code></td>
      <td><?php echo $u['last_login'] ? $h(date('j M, g:i A', strtotime($u['last_login']))) : '<small>never</small>'; ?></td>
      <td><form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="user_active"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
        <input type="hidden" name="active" value="<?php echo $u['active'] ? 0 : 1; ?>"><span class="pill <?php echo $u['active'] ? 'p-ok' : 'p-mute'; ?>"><?php echo $u['active'] ? 'On' : 'Off'; ?></span>
        <button class="btn plain" type="submit"><?php echo $u['active'] ? 'Switch off' : 'Switch on'; ?></button></form></td>
      <td><form method="post" style="display:flex;gap:6px"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="user_password"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
        <input type="text" name="password" minlength="8" placeholder="8+ characters" autocomplete="new-password"><button class="btn plain" type="submit">Change</button></form></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;align-items:center">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="add_user">
    <input type="text" name="name" placeholder="Name (e.g. Rahul)" required maxlength="80">
    <input type="text" name="username" placeholder="Username" required maxlength="40" autocomplete="off">
    <input type="text" name="password" placeholder="Password (8+ characters)" required minlength="8" autocomplete="new-password">
    <button class="btn primary" type="submit">Add login</button>
  </form>

  <h2>Not paid (last 30 days)</h2>
  <p><small>Started checkout but the payment failed or was left. Worth a call.</small></p>
  <?php if (!$tries): ?><p>None.</p><?php else: ?>
  <div class="tbl"><table>
    <tr><th>When</th><th>Buyer</th><th>Package</th><th>Would pay now</th><th>Payment</th></tr>
    <?php foreach ($tries as $o): ?>
    <tr>
      <td><?php echo $h(date('j M, g:i A', strtotime((string)$o['created_at']))); ?></td>
      <td><b><?php echo $h($o['restaurant']); ?></b><small><?php echo $h($o['name']); ?> · <?php echo $h($o['phone']); ?> · <?php echo $h($o['city']); ?> ·
        <a href="<?php echo $h($wa($o['phone'], 'Hi ' . $o['name'] . ', this is RestroGrow. We saw you were booking the Smart QR Menu for ' . $o['restaurant'] . '. Can we help you finish it?')); ?>" target="_blank" rel="noopener">WhatsApp</a></small></td>
      <td><?php echo $h(QK_PACKAGES[$o['package']]['name'] ?? $o['package']); ?><small><?php echo (int)$o['standees']; ?> standees · total <?php echo $inr($o['total']); ?></small></td>
      <td><?php echo $inr($o['pay_now']); ?></td>
      <td><span class="pill <?php echo $o['payment_status'] === 'failed' ? 'p-bad' : 'p-mute'; ?>"><?php echo $o['payment_status'] === 'failed' ? 'Failed' : 'Not finished'; ?></span></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
</body>
</html>
