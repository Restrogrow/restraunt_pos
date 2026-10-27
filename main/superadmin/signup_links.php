<?php
/**
 * Sign-up links (Super Admin only): a private link for one client, restrogrow.com/join/<code>
 * (config/signup_invites.php). It opens the normal sign-up page, where the client fills in all their own
 * details (the country can be picked here); the link skips the WhatsApp code and gives its own free trial
 * (7 days, none, or a custom number).
 * Each link works a set number of
 * times until its expiry date; "Switch off" stops it at once.
 * All POST + CSRF token.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/countries.php';
require_once __DIR__ . '/../config/signup_invites.php';
require_once __DIR__ . '/../config/prg.php';

$conn = getConnection();
inviteEnsure($conn);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['inv_csrf'])) $_SESSION['inv_csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['inv_csrf'];
const INVITE_TRIAL = 7;   // a link's free days unless "Custom" is picked

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = ''; $error = ''; $newCode = '';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new InvalidArgumentException('Your session changed. Please try again.');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $pick = (string)($_POST['trial'] ?? '');
            $trial = $pick === 'custom' ? (int)($_POST['trial_days'] ?? -1) : ($pick === '0' ? 0 : INVITE_TRIAL);
            if ($trial < 0 || $trial > 365) throw new InvalidArgumentException('Free days must be between 0 and 365.');
            $country = trim((string)($_POST['country'] ?? ''));
            if ($country !== '' && !getCountryByName($country)) $country = '';
            $uses = (int)($_POST['max_uses'] ?? 1);
            if ($uses < 1 || $uses > 50) throw new InvalidArgumentException('A link can be used 1 to 50 times.');
            $valid = (int)($_POST['valid_days'] ?? 14);
            $newCode = inviteCreate($conn, [
                // the client fills in all their own details on the sign-up page
                'note' => mb_substr(trim(strip_tags((string)($_POST['note'] ?? ''))), 0, 120),
                'restaurant_name' => '', 'phone' => '', 'email' => '', 'country' => $country, 'business_type' => '', 'city' => '',
                'trial_days' => $trial, 'max_uses' => $uses,
                'expires_at' => $valid > 0 ? date('Y-m-d 23:59:59', strtotime('+' . min($valid, 365) . ' days')) : null,
                'created_by' => $_SESSION['superadmin_username'] ?? null,
            ]);
            $message = 'Link ready. Copy it or send it on WhatsApp below.';
        } elseif ($action === 'toggle') {
            $conn->prepare('UPDATE signup_invites SET active = 1 - active WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
            $message = 'Saved.';
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('superadmin signup_links: ' . $e->getMessage());
        $error = 'Could not save. See the server log.';
    }
    prgRedirect('signup_links', ['message' => $message, 'error' => $error, 'new' => $newCode]);
}
['message' => $message, 'error' => $error, 'new' => $newCode] = prgTake('signup_links', ['message' => '', 'error' => '', 'new' => '']);

$links = $conn->query('SELECT * FROM signup_invites ORDER BY id DESC LIMIT 200')->fetchAll(PDO::FETCH_ASSOC);
$names = [];
$ids = array_filter(explode(',', implode(',', array_column($links, 'restaurant_ids'))));
if ($ids) {
    $st = $conn->prepare('SELECT restaurant_id, restaurant_name FROM users WHERE restaurant_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $st->execute(array_values($ids));
    $names = $st->fetchAll(PDO::FETCH_KEY_PAIR);
}
$menus = inviteMenuFiles($conn, $ids);   // the menu photos / PDFs each new restaurant sent
$waText = fn($r) => 'Hi' . ($r['note'] ? ' ' . $r['note'] : '') . ', here is your private link to set up '
    . ($r['restaurant_name'] ?: 'your restaurant') . ' on RestroGrow. It takes about a minute: ' . inviteUrl($r['code']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Sign-up links · Super Admin</title>
<style>
  body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; margin: 0; padding: 24px 16px; }
  .box { max-width: 980px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  h1 { font-size: 20px; margin: 0 0 6px; }
  h2 { font-size: 15px; margin: 26px 0 8px; }
  p, li, td, th { font-size: 14px; line-height: 1.5; color: #374151; }
  .msg { padding: 12px 14px; border-radius: 10px; margin: 14px 0; font-size: 14px; }
  .ok { background: #ecfdf5; color: #065f46; } .err { background: #fef2f2; color: #991b1b; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 5px; font-size: 13px; word-break: break-all; }
  .tbl { overflow-x: auto; }
  table { border-collapse: collapse; width: 100%; }
  th { text-align: left; color: #6b7280; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
  td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  .btn { display: inline-block; border: 0; border-radius: 9px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; }
  .primary { background: #7c3aed; color: #fff; } .plain { background: #f3f4f6; color: #111827; } .off { background: #fff; color: #b91c1c; border: 1.5px solid #fca5a5; }
  .wa { background: #16a34a; color: #fff; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .pill { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 12px; font-weight: 600; }
  .p-ok { background: #dcfce7; color: #166534; } .p-bad { background: #fee2e2; color: #991b1b; } .p-mute { background: #f3f4f6; color: #6b7280; }
  form { margin: 0; display: inline; }
  small { color: #6b7280; }
  .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 12px; }
  .grid label { display: block; font-size: 13px; font-weight: 600; color: #374151; }
  .grid input, .grid select { width: 100%; box-sizing: border-box; margin-top: 4px; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; font-weight: 400; }
  .new { border: 2px solid #c4b5fd; background: #f5f3ff; border-radius: 12px; padding: 14px; margin: 14px 0; }
</style>
</head>
<body>
<div class="box">
  <h1>Sign-up links</h1>
  <p>A private link for a client you are bringing on yourself. It opens the normal sign-up page without the WhatsApp code.
     The client fills in their details, sets a password and lands in their new dashboard.</p>

  <?php if ($message): ?><div class="msg ok"><?php echo $h($message); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg err"><?php echo $h($error); ?></div><?php endif; ?>

  <?php foreach ($links as $r): if ($r['code'] !== $newCode) continue; ?>
  <div class="new">
    <b>New link<?php echo $r['note'] ? ' for ' . $h($r['note']) : ''; ?></b>
    <p style="margin:6px 0 10px"><code id="newLink"><?php echo $h(inviteUrl($r['code'])); ?></code></p>
    <div class="row">
      <button class="btn primary" type="button" data-copy="<?php echo $h(inviteUrl($r['code'])); ?>">Copy link</button>
      <a class="btn wa" target="_blank" rel="noopener" href="https://wa.me/<?php echo $h($r['phone'] && ($c = getCountryByName($r['country'] ?: 'India')) ? preg_replace('/\D/', '', $c['dial_code']) . $r['phone'] : ''); ?>?text=<?php echo rawurlencode($waText($r)); ?>">Send on WhatsApp</a>
    </div>
  </div>
  <?php endforeach; ?>

  <h2>Make a link</h2>
  <p><small>The client fills in all their own details (restaurant, phone, email, password…) on the link.</small></p>
  <form method="post" style="display:block">
    <input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="create">
    <div class="grid">
      <label>Client name (only for you)<input name="note" maxlength="120" placeholder="e.g. Rahul, Sharma Dhaba"></label>
      <label>Country<select name="country"><option value="">Let the client pick</option>
        <?php foreach (getCountryData() as $iso2 => $c): ?>
        <option value="<?php echo $h($c['name']); ?>"<?php echo $iso2 === 'IN' ? ' selected' : ''; ?>><?php echo $h($c['name'] . ' (' . $c['dial_code'] . ')'); ?></option>
        <?php endforeach; ?>
      </select></label>
      <label>Free trial<select name="trial" id="trialPick"><option value="7" selected>7 days</option><option value="0">0 days (no free trial)</option><option value="custom">Custom…</option></select></label>
      <label id="trialCustom" hidden>Free days<input name="trial_days" type="number" min="0" max="365" value="7"></label>
      <label>Can be used<select name="max_uses"><option value="1">Once</option><option value="5">5 times</option><option value="20">20 times</option></select></label>
      <label>Works for<select name="valid_days"><option value="7">7 days</option><option value="14" selected>14 days</option><option value="30">30 days</option><option value="0">No end date</option></select></label>
    </div>
    <div class="row" style="margin-top:14px">
      <button class="btn primary" type="submit">Make link</button>
      <a class="btn plain" href="dashboard.php">Back to dashboard</a>
    </div>
  </form>

  <h2>Links</h2>
  <?php if (!$links): ?><p>No links yet.</p><?php else: ?>
  <div class="tbl"><table>
    <tr><th>Made</th><th>For</th><th>Link</th><th>Status</th><th>Restaurant made · menu</th><th></th></tr>
    <?php foreach ($links as $r): $why = inviteProblem($r); $made = array_filter(explode(',', (string)$r['restaurant_ids'])); ?>
    <tr>
      <td><small><?php echo $h(date('j M Y', strtotime($r['created_at']))); ?><?php echo $r['created_by'] ? '<br>' . $h($r['created_by']) : ''; ?></small></td>
      <td><?php echo $h($r['note'] ?: '—'); ?><?php echo $r['restaurant_name'] ? '<br><small>' . $h($r['restaurant_name']) . '</small>' : ''; ?>
        <br><small><?php echo $r['trial_days'] === null ? 'usual free days' : ((int)$r['trial_days'] ? (int)$r['trial_days'] . ' free days' : 'no free trial'); ?><?php echo $r['country'] ? ' · ' . $h($r['country']) : ''; ?></small></td>
      <td><code><?php echo $h(inviteUrl($r['code'])); ?></code></td>
      <td><?php echo $why === '' ? '<span class="pill p-ok">Open</span>' : '<span class="pill ' . ((int)$r['active'] ? 'p-mute' : 'p-bad') . '">' . $h(rtrim(str_replace('This sign-up link ', '', $why), '.')) . '</span>'; ?>
        <br><small>Used <?php echo (int)$r['uses']; ?> of <?php echo (int)$r['max_uses']; ?><?php echo $r['expires_at'] ? ' · until ' . $h(date('j M', strtotime($r['expires_at']))) : ''; ?></small></td>
      <td><?php if (!$made): ?>—<?php else: foreach ($made as $rid): ?><a href="usage.php?rid=<?php echo $h(urlencode($rid)); ?>"><?php echo $h($names[$rid] ?? $rid); ?></a><br>
        <?php if (empty($menus[$rid])): ?><small>No menu sent</small><br><?php else: foreach ($menus[$rid] as $i => $mf): ?>
        <small><a href="signup_menu_file.php?id=<?php echo (int)$mf['id']; ?>" target="_blank" rel="noopener"><?php echo $mf['mime'] === 'application/pdf' ? '📄' : '🖼️'; ?> <?php echo $h($mf['name']); ?></a>
          · <a href="signup_menu_file.php?id=<?php echo (int)$mf['id']; ?>&amp;download=1">download</a></small><br>
        <?php endforeach; endif; ?><?php endforeach; endif; ?></td>
      <td><div class="row">
        <?php if ($why === ''): ?><button class="btn plain" type="button" data-copy="<?php echo $h(inviteUrl($r['code'])); ?>">Copy</button><?php endif; ?>
        <?php if ((int)$r['uses'] < (int)$r['max_uses']): ?>
        <form method="post"><input type="hidden" name="csrf" value="<?php echo $h($csrf); ?>"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
          <button class="btn <?php echo (int)$r['active'] ? 'off' : 'primary'; ?>" type="submit"><?php echo (int)$r['active'] ? 'Switch off' : 'Switch on'; ?></button></form>
        <?php endif; ?>
      </div></td>
    </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
</div>
<script>
document.getElementById('trialPick').addEventListener('change', function () {
  document.getElementById('trialCustom').hidden = this.value !== 'custom';
});
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]');
  if (!b) return;
  navigator.clipboard.writeText(b.dataset.copy).then(function () {
    var t = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = t; }, 1500);
  }).catch(function () { b.textContent = 'Select the link and copy it'; });
});
</script>
</body>
</html>
