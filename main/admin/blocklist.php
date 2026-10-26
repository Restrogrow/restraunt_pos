<?php
// Blocked Customers (order abuse blocklist) — admin management page.
// Embedded in the admin dashboard via iframe (same pattern as addons.php).

// Include secure session configuration
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Check if user is logged in
if (!isSessionValid() || !isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['restaurant_id'])) {
    header('Location: login.php');
    exit();
}

require_once __DIR__ . '/../config/authorization_config.php';
requirePermission(PERMISSION_MANAGE_ORDERS);

require_once __DIR__ . '/../config/order_abuse_guard.php';
require_once __DIR__ . '/../config/prg.php';

$restaurant_id = $_SESSION['restaurant_id'];
$restaurant_name = $_SESSION['restaurant_name'] ?? 'Restaurant';
$username = $_SESSION['username'];

require_once __DIR__ . '/../db_connection.php';
$conn = getConnection();
orderAbuseEnsureTables($conn);

$message = '';
$messageType = 'ok';

// ── Actions ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $type = ($_POST['type'] ?? 'phone') === 'ip' ? 'ip' : 'phone';
        $value = trim($_POST['value'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $days = (int)($_POST['days'] ?? 0); // 0 = permanent

        if ($type === 'phone') {
            $norm = orderAbuseNormalizePhone($value);
            $ok = $norm !== null && strlen($norm) === 10;
            if (!$ok) {
                $message = 'Enter a valid 10-digit mobile number.';
            } else {
                $value = $norm;
            }
        } else {
            $ok = filter_var($value, FILTER_VALIDATE_IP) !== false;
            if (!$ok) {
                $message = 'Enter a valid IP address.';
            }
        }

        if ($ok) {
            try {
                $stmt = $conn->prepare(
                    "INSERT INTO order_blocklist (restaurant_id, type, value, reason, source, blocked_by, expires_at)
                     VALUES (?, ?, ?, ?, 'manual', ?, IF(? > 0, DATE_ADD(NOW(), INTERVAL ? DAY), NULL))
                     ON DUPLICATE KEY UPDATE
                       reason = VALUES(reason),
                       source = 'manual',
                       blocked_by = VALUES(blocked_by),
                       blocked_at = NOW(),
                       expires_at = IF(? > 0, DATE_ADD(NOW(), INTERVAL ? DAY), NULL)"
                );
                $stmt->execute([$restaurant_id, $type, $value, $reason, $username, $days, $days, $days, $days]);
                $message = ($type === 'phone' ? 'Number ' : 'IP ') . $value . ' blocked.';
            } catch (Exception $e) {
                $message = 'Could not save block: ' . $e->getMessage();
                $messageType = 'err';
            }
        } else {
            $messageType = 'err';
        }
    } elseif ($action === 'unblock') {
        $type = ($_POST['type'] ?? 'phone') === 'ip' ? 'ip' : 'phone';
        $value = trim($_POST['value'] ?? '');
        if (orderAbuseRemoveBlock($conn, $restaurant_id, $type, $value)) {
            $message = 'Entry removed.';
        } else {
            $message = 'Entry not found or already removed.';
            $messageType = 'err';
        }
    }
    // back to a GET, so a refresh doesn't block / unblock again (config/prg.php)
    prgRedirect('blocklist', ['message' => $message, 'messageType' => $messageType]);
}
['message' => $message, 'messageType' => $messageType] = prgTake('blocklist', ['message' => '', 'messageType' => 'ok']);

// ── List ─────────────────────────────────────────────────────────────────────
$blocks = [];
try {
    $stmt = $conn->prepare(
        "SELECT id, type, value, reason, source, strikes, last_strike_at, blocked_at, expires_at, blocked_by
         FROM order_blocklist WHERE restaurant_id = ?
         ORDER BY blocked_at DESC LIMIT 500"
    );
    $stmt->execute([$restaurant_id]);
    $blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $message = 'Could not load blocklist: ' . $e->getMessage();
    $messageType = 'err';
}

function blocklistActive($row) {
    return $row['expires_at'] === null || strtotime($row['expires_at']) > time();
}

function blocklistEscape($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
?>
<?php
$activeCount = 0; $autoCount = 0; $manualCount = 0; $expiredCount = 0;
foreach ($blocks as $b) {
    if (blocklistActive($b)) { $activeCount++; if ($b['source'] === 'auto') $autoCount++; else $manualCount++; } else { $expiredCount++; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blocked Customers - <?php echo blocklistEscape($restaurant_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root { --o: #F97316; --o-d: #EA580C; --o-l: #FFF7ED; --ink: #111111; --line: #E5E7EB; --muted: #6B7280; --bg: #F8FAFC; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Poppins', system-ui, sans-serif; background: var(--bg); color: var(--ink); padding: 16px; }
        .wrap { max-width: 1180px; margin: 0 auto; display: flex; flex-direction: column; gap: 14px; }
        .stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; }
        .stat { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
        .stat i { width: 38px; height: 38px; display: grid; place-items: center; border-radius: 11px; background: #F3F4F6; color: var(--muted); font-size: 16px; }
        .stat small { display: block; color: var(--muted); font-size: 12px; font-weight: 600; }
        .stat b { font-size: 20px; font-weight: 800; }
        .stat.red i { background: #FEE2E2; color: #DC2626; }
        .stat.org i { background: var(--o-l); color: var(--o); }
        .stat.ink i { background: #111; color: #fff; }
        .card { padding: 18px; border: 1px solid var(--line); border-radius: 18px; background: #fff; box-shadow: 0 1px 2px rgba(17,17,17,.04); }
        .card-h { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; }
        .card-h h2 { display: flex; align-items: center; gap: 8px; font-size: 16px; font-weight: 800; }
        .card-h h2 i { color: var(--o); }
        .card-h p { color: var(--muted); font-size: 12.5px; }
        .form { display: grid; grid-template-columns: auto minmax(180px, 1fr) minmax(180px, 1.3fr) auto auto; gap: 10px; align-items: end; }
        .f { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .f label { color: #374151; font-size: 12px; font-weight: 700; }
        .seg { display: inline-flex; gap: 3px; padding: 3px; border-radius: 12px; background: #F3F4F6; }
        .seg label { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px; border-radius: 9px; color: #4B5563; font-size: 13px; font-weight: 700; cursor: pointer; white-space: nowrap; }
        .seg input { display: none; }
        .seg input:checked + label { background: #fff; color: var(--o-d); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .in { position: relative; }
        .in i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9CA3AF; font-size: 13px; }
        .in input, .f select { width: 100%; height: 42px; padding: 0 12px 0 34px; border: 1.5px solid var(--line); border-radius: 12px; font: inherit; font-size: 14px; color: var(--ink); background: #fff; }
        .f select { padding-left: 12px; }
        .in input:focus, .f select:focus { outline: 0; border-color: #FDBA74; box-shadow: 0 0 0 3px rgba(249,115,22,.15); }
        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 42px; padding: 0 18px; border: 0; border-radius: 12px; background: var(--o); color: #fff; font: inherit; font-size: 14px; font-weight: 800; cursor: pointer; box-shadow: 0 8px 16px -10px rgba(249,115,22,.9); white-space: nowrap; }
        .btn:hover { background: var(--o-d); }
        .flash { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border-radius: 12px; font-size: 13.5px; font-weight: 600; }
        .flash.ok { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
        .flash.err { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }
        .toolbar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .toolbar .in { flex: 1; min-width: 200px; }
        .chips { display: inline-flex; gap: 3px; padding: 3px; border-radius: 12px; background: #F3F4F6; }
        .chips button { height: 34px; padding: 0 12px; border: 0; border-radius: 9px; background: none; color: #4B5563; font: inherit; font-size: 12.5px; font-weight: 700; cursor: pointer; }
        .chips button.on { background: #fff; color: var(--o-d); box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        .list { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
        .row { display: grid; grid-template-columns: 44px minmax(150px, 1.1fr) minmax(0, 1.6fr) minmax(130px, .9fr) auto; gap: 14px; align-items: center; padding: 12px 14px; border: 1px solid var(--line); border-radius: 14px; background: #fff; }
        .row:hover { border-color: #FDBA74; }
        .row.off { opacity: .55; }
        .av { width: 44px; height: 44px; display: grid; place-items: center; border-radius: 12px; background: #FEE2E2; color: #DC2626; font-size: 17px; }
        .av.ip { background: #F3F4F6; color: #374151; }
        .who b { display: block; font-size: 15px; font-weight: 800; letter-spacing: .01em; }
        .tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 3px; }
        .tag { display: inline-flex; align-items: center; gap: 4px; height: 22px; padding: 0 8px; border-radius: 999px; font-size: 11px; font-weight: 800; }
        .tag.auto { background: #FEE2E2; color: #B91C1C; } .tag.manual { background: var(--o-l); color: #C2410C; }
        .tag.type { background: #F3F4F6; color: #374151; } .tag.strk { background: #111; color: #fff; } .tag.exp { background: #F3F4F6; color: #6B7280; }
        .why { color: #4B5563; font-size: 13px; line-height: 1.45; overflow-wrap: anywhere; white-space: pre-wrap; }
        .why.none { color: #9CA3AF; }
        .when { color: var(--muted); font-size: 12px; line-height: 1.5; }
        .when b { color: var(--ink); font-weight: 700; }
        .unb { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 14px; border: 1.5px solid #FECACA; border-radius: 11px; background: #fff; color: #DC2626; font: inherit; font-size: 13px; font-weight: 800; cursor: pointer; white-space: nowrap; }
        .unb:hover { background: #FEF2F2; }
        .empty { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 40px 16px; border: 1.5px dashed var(--line); border-radius: 14px; color: var(--muted); text-align: center; font-size: 13.5px; }
        .empty i { font-size: 32px; color: #FDBA74; }
        .empty b { color: var(--ink); }
        .hint { display: flex; gap: 8px; padding: 10px 12px; border-radius: 12px; background: var(--o-l); color: #9A3412; font-size: 12.5px; line-height: 1.45; }
        /* confirm modal */
        .mo { position: fixed; inset: 0; z-index: 50; display: none; place-items: center; padding: 16px; background: rgba(17,17,17,.45); backdrop-filter: blur(3px); }
        .mo.show { display: grid; }
        .mo-c { width: 100%; max-width: 400px; padding: 24px 22px 18px; border-radius: 20px; background: #fff; text-align: center; box-shadow: 0 30px 70px -24px rgba(17,17,17,.5); }
        .mo-ic { width: 54px; height: 54px; margin: 0 auto 10px; display: grid; place-items: center; border-radius: 16px; background: var(--o-l); color: var(--o); font-size: 22px; }
        .mo-c h3 { font-size: 18px; font-weight: 800; margin-bottom: 6px; }
        .mo-c p { color: #4B5563; font-size: 13.5px; }
        .mo-f { display: flex; gap: 8px; margin-top: 18px; }
        .mo-f button { flex: 1; height: 44px; border: 0; border-radius: 12px; font: inherit; font-weight: 800; cursor: pointer; }
        .mo-no { background: #F3F4F6; color: #374151; } .mo-yes { background: var(--o); color: #fff; }
        @media (max-width: 900px) {
            .form { grid-template-columns: 1fr 1fr; }
            .form .seg-f, .form .btn { grid-column: 1 / -1; }
            .row { grid-template-columns: 44px minmax(0, 1fr) auto; }
            .why, .when { grid-column: 2 / -1; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 520px) { .form { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<div class="wrap">
    <?php if ($message): ?>
        <div class="flash <?php echo blocklistEscape($messageType); ?>"><i class="fa-solid <?php echo $messageType === 'err' ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i><?php echo blocklistEscape($message); ?></div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat red"><i class="fa-solid fa-ban"></i><div><small>Blocked now</small><b><?php echo $activeCount; ?></b></div></div>
        <div class="stat ink"><i class="fa-solid fa-robot"></i><div><small>Auto-blocked (3 strikes)</small><b><?php echo $autoCount; ?></b></div></div>
        <div class="stat org"><i class="fa-solid fa-hand"></i><div><small>Blocked by you</small><b><?php echo $manualCount; ?></b></div></div>
        <div class="stat"><i class="fa-solid fa-clock-rotate-left"></i><div><small>Expired</small><b><?php echo $expiredCount; ?></b></div></div>
    </div>

    <div class="card">
        <div class="card-h"><h2><i class="fa-solid fa-user-slash"></i>Block a customer</h2><p>They will not be able to place website orders.</p></div>
        <form method="POST" action="blocklist.php" class="form" id="blockForm">
            <input type="hidden" name="action" value="add">
            <div class="f seg-f"><label>Block by</label>
                <div class="seg"><input type="radio" name="type" id="tPhone" value="phone" checked><label for="tPhone"><i class="fa-solid fa-mobile-screen"></i>Mobile</label><input type="radio" name="type" id="tIp" value="ip"><label for="tIp"><i class="fa-solid fa-network-wired"></i>IP address</label></div>
            </div>
            <div class="f"><label for="value" id="valueLabel">Mobile number</label><div class="in"><i class="fa-solid fa-hashtag"></i><input type="text" id="value" name="value" placeholder="10-digit mobile number" required inputmode="numeric" autocomplete="off"></div></div>
            <div class="f"><label for="reason">Reason (optional)</label><div class="in"><i class="fa-regular fa-note-sticky"></i><input type="text" id="reason" name="reason" placeholder="e.g. repeated fake orders" maxlength="250"></div></div>
            <div class="f"><label for="days">For</label><select name="days" id="days"><option value="0">Always</option><option value="1">1 day</option><option value="7">7 days</option><option value="30">30 days</option></select></div>
            <button type="submit" class="btn"><i class="fa-solid fa-ban"></i>Block</button>
        </form>
    </div>

    <div class="card">
        <div class="card-h"><h2><i class="fa-solid fa-list"></i>Blocked list</h2><p>Numbers with 3 fake-order strikes are blocked automatically.</p></div>
        <?php if (empty($blocks)): ?>
            <div class="empty"><i class="fa-regular fa-face-smile"></i><b>No blocked customers</b>Nobody is blocked right now.</div>
        <?php else: ?>
            <div class="toolbar">
                <div class="in"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="q" placeholder="Search number, IP or reason"></div>
                <div class="chips" id="flt"><button type="button" class="on" data-f="all">All</button><button type="button" data-f="active">Blocked now</button><button type="button" data-f="auto">Auto</button><button type="button" data-f="manual">By you</button><button type="button" data-f="expired">Expired</button></div>
            </div>
            <div class="list" id="list">
            <?php foreach ($blocks as $b):
                $active = blocklistActive($b);
                $isIp = $b['type'] === 'ip';
                $search = strtolower($b['value'] . ' ' . ($b['reason'] ?? '') . ' ' . ($b['blocked_by'] ?? ''));
            ?>
                <div class="row<?php echo $active ? '' : ' off'; ?>" data-s="<?php echo blocklistEscape($search); ?>" data-src="<?php echo blocklistEscape($b['source']); ?>" data-act="<?php echo $active ? '1' : '0'; ?>">
                    <span class="av<?php echo $isIp ? ' ip' : ''; ?>"><i class="fa-solid <?php echo $isIp ? 'fa-network-wired' : 'fa-mobile-screen'; ?>"></i></span>
                    <div class="who"><b><?php echo blocklistEscape($b['value']); ?></b>
                        <div class="tags">
                            <span class="tag type"><?php echo $isIp ? 'IP' : 'Mobile'; ?></span>
                            <span class="tag <?php echo blocklistEscape($b['source']); ?>"><?php echo $b['source'] === 'auto' ? 'Auto' : 'By you'; ?></span>
                            <?php if ((int)$b['strikes'] > 0): ?><span class="tag strk" title="Fake-order strikes"><?php echo (int)$b['strikes']; ?> strike<?php echo (int)$b['strikes'] === 1 ? '' : 's'; ?></span><?php endif; ?>
                            <?php if (!$active): ?><span class="tag exp">Expired</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="why<?php echo ($b['reason'] ?? '') === '' ? ' none' : ''; ?>"><?php echo ($b['reason'] ?? '') !== '' ? blocklistEscape($b['reason']) : 'No reason given'; ?></div>
                    <div class="when">Blocked <b><?php echo blocklistEscape(date('d M Y, h:i A', strtotime($b['blocked_at']))); ?></b><br>
                        <?php echo $b['expires_at'] ? ($active ? 'Until ' : 'Ended ') . '<b>' . blocklistEscape(date('d M Y', strtotime($b['expires_at']))) . '</b>' : 'Until <b>you unblock</b>'; ?> · by <?php echo blocklistEscape($b['blocked_by'] ?: 'System'); ?>
                        <?php if ($b['last_strike_at']): ?><br>Last strike <?php echo blocklistEscape(date('d M', strtotime($b['last_strike_at']))); ?><?php endif; ?>
                    </div>
                    <form method="POST" action="blocklist.php" class="unblock-form">
                        <input type="hidden" name="action" value="unblock">
                        <input type="hidden" name="type" value="<?php echo blocklistEscape($b['type']); ?>">
                        <input type="hidden" name="value" value="<?php echo blocklistEscape($b['value']); ?>">
                        <button type="submit" class="unb"><i class="fa-solid fa-unlock"></i>Unblock</button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="empty" id="noMatch" style="display:none;margin-top:12px"><i class="fa-solid fa-filter"></i><b>No entries match</b></div>
        <?php endif; ?>
    </div>
    <div class="hint"><i class="fa-solid fa-circle-info"></i><span>When you reject an order with “Couldn’t reach the customer”, that number gets a strike. After 3 strikes it is blocked automatically. Unblock anyone here at any time.</span></div>
</div>

<div class="mo" id="mo" role="dialog" aria-modal="true" aria-labelledby="moT">
    <div class="mo-c"><div class="mo-ic"><i class="fa-solid fa-unlock"></i></div><h3 id="moT">Unblock this customer?</h3><p id="moP">They will be able to place website orders again.</p>
        <div class="mo-f"><button type="button" class="mo-no" id="moNo">No, keep blocked</button><button type="button" class="mo-yes" id="moYes">Yes, unblock</button></div></div>
</div>
<script>
(function () {
    // Block by: switch the label / placeholder between mobile and IP
    var val = document.getElementById('value'), lab = document.getElementById('valueLabel');
    document.querySelectorAll('input[name=type]').forEach(function (r) {
        r.addEventListener('change', function () {
            var ip = r.value === 'ip' && r.checked;
            if (!r.checked) return;
            lab.textContent = ip ? 'IP address' : 'Mobile number';
            val.placeholder = ip ? 'e.g. 103.25.10.4' : '10-digit mobile number';
            val.inputMode = ip ? 'text' : 'numeric';
        });
    });
    // search + filter
    var q = document.getElementById('q'), f = 'all';
    function apply() {
        var term = (q && q.value || '').trim().toLowerCase(), n = 0;
        document.querySelectorAll('#list .row').forEach(function (row) {
            var ok = (!term || row.dataset.s.indexOf(term) >= 0) &&
                (f === 'all' || (f === 'active' && row.dataset.act === '1') || (f === 'expired' && row.dataset.act === '0') || ((f === 'auto' || f === 'manual') && row.dataset.src === f && row.dataset.act === '1'));
            row.style.display = ok ? '' : 'none'; if (ok) n++;
        });
        var nm = document.getElementById('noMatch'); if (nm) nm.style.display = n ? 'none' : 'flex';
    }
    if (q) q.addEventListener('input', apply);
    var flt = document.getElementById('flt');
    if (flt) flt.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; f = b.dataset.f; flt.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); apply(); });
    // Unblock: our own confirm window instead of the browser box
    var mo = document.getElementById('mo'), pending = null;
    document.querySelectorAll('.unblock-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (form.dataset.ok === '1') return;
            e.preventDefault(); pending = form;
            document.getElementById('moP').textContent = form.querySelector('[name=value]').value + ' will be able to place website orders again.';
            mo.classList.add('show'); document.getElementById('moYes').focus();
        });
    });
    function closeMo() { mo.classList.remove('show'); pending = null; }
    document.getElementById('moNo').addEventListener('click', closeMo);
    mo.addEventListener('click', function (e) { if (e.target === mo) closeMo(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && mo.classList.contains('show')) closeMo(); });
    document.getElementById('moYes').addEventListener('click', function () { if (!pending) return; pending.dataset.ok = '1'; var p = pending; closeMo(); p.submit(); });
})();
</script>
</body>
</html>
