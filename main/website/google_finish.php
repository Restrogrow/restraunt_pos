<?php
/**
 * Last step of "Continue with Google" on a restaurant website (config/google_login.php).
 * Runs on the restaurant's own host (restrogrow.com or its own domain), so the login sticks there.
 *
 *  - Someone who already has an account here with this Google email: logged in straight away.
 *  - Someone new: one short step to confirm their mobile with a WhatsApp code (otp.php,
 *    purpose customer_google). Orders, points and wallet belong to the phone number, so a
 *    guest's earlier orders join the account too. After that, Google alone logs them in.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/google_login.php';
require_once __DIR__ . '/../config/repeatgrow_whatsapp.php';
require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/customer_session.php';
startSecureSession();

$conn = getConnection();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$safeRet = function ($ret) {
    $ret = (string)$ret;
    return ($ret !== '' && $ret[0] === '/' && !str_starts_with($ret, '//') && !preg_match('/[\r\n]/', $ret)) ? $ret : '/';
};

/** The customer's account for this Google email at this restaurant (one with a phone). */
function googleCustomerByEmail(PDO $conn, string $rid, string $email): ?array
{
    $st = $conn->prepare("SELECT id, restaurant_id, customer_name, phone, email FROM customers
        WHERE restaurant_id = ? AND LOWER(email) = ? AND COALESCE(phone, '') <> ''
        ORDER BY password_hash IS NOT NULL DESC, total_visits DESC, id LIMIT 1");
    $st->execute([$rid, $email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── step 2 (new customers): the phone was confirmed with a WhatsApp code ──
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json');
    try {
        $p = $_SESSION['google_pending'] ?? null;
        if (!$p || (int)$p['exp'] < time()) throw new RuntimeException('This sign-in expired. Please tap Continue with Google again.');
        $raw = preg_replace('/\D/', '', (string)($_POST['phone'] ?? ''));
        if (!isPhoneVerifiedRecently($conn, $p['rid'], $raw, 'customer_google')) throw new RuntimeException('Please confirm your number with the WhatsApp code first.');
        $phone = orderAbuseNormalizePhone($raw) ?? $raw;

        // the phone may already have orders (a guest) or an account: join them, keep their name
        $st = $conn->prepare('SELECT id, restaurant_id, customer_name, phone, email FROM customers WHERE restaurant_id = ? AND phone IN (?, ?) ORDER BY password_hash IS NOT NULL DESC, total_visits DESC LIMIT 1');
        $st->execute([$p['rid'], $phone, $raw]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $conn->prepare("UPDATE customers SET email = COALESCE(NULLIF(email, ''), ?), customer_name = COALESCE(NULLIF(customer_name, ''), ?), updated_at = NOW() WHERE id = ?")
                ->execute([$p['email'], $p['name'], $c['id']]);
        } else {
            $conn->prepare('INSERT INTO customers (restaurant_id, customer_name, phone, email, signup_ip) VALUES (?, ?, ?, ?, ?)')
                ->execute([$p['rid'], $p['name'], $phone, $p['email'], $_SERVER['REMOTE_ADDR'] ?? null]);
            $c = ['id' => (int)$conn->lastInsertId(), 'restaurant_id' => $p['rid']];
        }
        unset($_SESSION['google_pending']);
        startCustomerSession($conn, ['id' => (int)$c['id'], 'restaurant_id' => $p['rid']], true);
        echo json_encode(['success' => true, 'redirect' => $safeRet($p['ret'])]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.']);
    }
    exit;
}

// ── step 1: Google's single-use pass ──
$p = googleLoginConsumeToken($conn, (string)($_GET['t'] ?? ''), 'customer');
if (!$p) {
    header('Location: /');
    exit;
}
$ret = $safeRet($p['ret'] ?? '/');
$existing = googleCustomerByEmail($conn, (string)$p['restaurant_id'], $p['email']);
if ($existing) {
    startCustomerSession($conn, $existing, true);
    header('Location: ' . $ret);
    exit;
}
$_SESSION['google_pending'] = ['rid' => $p['restaurant_id'], 'email' => $p['email'], 'name' => $p['name'], 'ret' => $ret, 'exp' => time() + 900];
$st = $conn->prepare('SELECT restaurant_name FROM users WHERE restaurant_id = ? LIMIT 1');
$st->execute([$p['restaurant_id']]);
$restaurantName = (string)($st->fetchColumn() ?: 'the restaurant');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Confirm your number · <?php echo $h($restaurantName); ?></title>
<style>
  :root { --ink:#111827; --soft:#4b5563; --muted:#9ca3af; --line:#e5e7eb; --accent:#ea580c; --accent-dark:#c2410c; --ok:#15803d; --err:#b91c1c; }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 20px 16px; background: #f6f5f3; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: var(--ink); }
  .card { width: 100%; max-width: 400px; background: #fff; border-radius: 20px; padding: 28px 24px; box-shadow: 0 10px 40px rgba(17,24,39,.08); }
  .who { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 14px; background: #f9fafb; margin-bottom: 20px; }
  .who img { width: 40px; height: 40px; border-radius: 50%; }
  .who b { display: block; font-size: 15px; } .who span { font-size: 13px; color: var(--soft); word-break: break-all; }
  h1 { font-size: 21px; margin: 0 0 6px; } p { margin: 0 0 18px; color: var(--soft); font-size: 14.5px; line-height: 1.5; }
  label { display: block; font-size: 13px; font-weight: 600; color: var(--soft); margin-bottom: 6px; }
  input { width: 100%; font: inherit; font-size: 16px; padding: 13px 14px; border: 1.5px solid var(--line); border-radius: 12px; outline: none; }
  input:focus { border-color: var(--accent); }
  .row { margin-bottom: 14px; }
  button { width: 100%; border: 0; border-radius: 12px; padding: 14px; font: 600 15.5px system-ui, sans-serif; background: var(--accent); color: #fff; cursor: pointer; }
  button:hover { background: var(--accent-dark); } button:disabled { opacity: .6; cursor: default; }
  .msg { font-size: 13.5px; margin: -4px 0 14px; min-height: 18px; } .msg.err { color: var(--err); } .msg.ok { color: var(--ok); }
  .link { background: none; color: var(--soft); width: auto; padding: 6px 0; font-weight: 500; font-size: 13.5px; text-decoration: underline; }
  .link:hover { background: none; color: var(--ink); }
  #codeStep { display: none; }
</style>
</head>
<body>
<main class="card">
  <div class="who">
    <?php if (!empty($p['picture'])): ?><img src="<?php echo $h($p['picture']); ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <div><b><?php echo $h($p['name']); ?></b><span><?php echo $h($p['email']); ?></span></div>
  </div>
  <h1>One last step</h1>
  <p>Confirm your mobile number for <?php echo $h($restaurantName); ?>. It's used for your orders and delivery updates, and links any orders you placed before. You only do this once.</p>

  <div id="phoneStep">
    <div class="row"><label for="phone">Mobile number</label><input id="phone" type="tel" inputmode="numeric" autocomplete="tel" placeholder="10-digit mobile number" maxlength="15"></div>
    <div class="msg" id="msg1" role="status"></div>
    <button type="button" id="sendBtn">Send code on WhatsApp</button>
  </div>

  <div id="codeStep">
    <div class="row"><label for="code">Code sent on WhatsApp</label><input id="code" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="6-digit code" maxlength="6"></div>
    <div class="msg" id="msg2" role="status"></div>
    <button type="button" id="verifyBtn">Confirm and continue</button>
    <button type="button" class="link" id="changeBtn">Change number</button>
  </div>
</main>
<script>
  var RID = <?php echo json_encode((string)$p['restaurant_id']); ?>;
  function $(id) { return document.getElementById(id); }
  function digits(v) { return String(v || '').replace(/\D/g, ''); }
  function post(url, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function say(el, text, ok) { el.textContent = text || ''; el.className = 'msg ' + (ok ? 'ok' : 'err'); }

  $('sendBtn').onclick = function () {
    var phone = digits($('phone').value);
    if (phone.length < 10) return say($('msg1'), 'Please enter your mobile number');
    this.disabled = true;
    post('otp.php', { action: 'send', restaurant_id: RID, phone: phone, purpose: 'customer_google' }).then(function (d) {
      $('sendBtn').disabled = false;
      if (!d.success) return say($('msg1'), d.message);
      $('phoneStep').style.display = 'none';
      $('codeStep').style.display = 'block';
      if (d.local_code) $('code').value = d.local_code; // this computer only: WhatsApp isn't used locally
      say($('msg2'), d.message, true);
      $('code').focus();
    }).catch(function () { $('sendBtn').disabled = false; say($('msg1'), 'Could not send the code. Check your internet and try again.'); });
  };
  $('verifyBtn').onclick = function () {
    var phone = digits($('phone').value), code = digits($('code').value);
    if (code.length < 4) return say($('msg2'), 'Please enter the code from WhatsApp');
    this.disabled = true;
    post('otp.php', { action: 'verify', restaurant_id: RID, phone: phone, code: code, purpose: 'customer_google' }).then(function (d) {
      if (!d.success) { $('verifyBtn').disabled = false; return say($('msg2'), d.message); }
      return post('google_finish.php', { phone: phone }).then(function (r) {
        if (!r.success) { $('verifyBtn').disabled = false; return say($('msg2'), r.message); }
        say($('msg2'), 'Done! Taking you back…', true);
        location.replace(r.redirect || '/');
      });
    }).catch(function () { $('verifyBtn').disabled = false; say($('msg2'), 'Something went wrong. Please try again.'); });
  };
  $('changeBtn').onclick = function () { $('codeStep').style.display = 'none'; $('phoneStep').style.display = 'block'; $('code').value = ''; say($('msg1'), ''); };
  $('phone').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('sendBtn').click(); });
  $('code').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('verifyBtn').click(); });
</script>
</body>
</html>
