<?php
/**
 * A stay's room bill, ready to print or save as PDF (config/rooms.php roomBill).
 *
 *   ?b=ID                     the owner / staff, logged in to the dashboard (Rooms › a booking › Bill)
 *   ?r=RID&b=ID&k=CODE        the guest's link, sent on WhatsApp (roomBillCode); no login
 *   &size=80                  narrow, for an 80 mm bill printer (A4 otherwise)
 *
 * The bill gets its number (prefix + 1, 2, 3… per restaurant, Rooms › Settings) the first time it is opened.
 * Look and words come from Rooms › Settings: title, prefix, footer, signature line, house rules, room service orders.
 */
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/rooms.php';

$id = (int)($_GET['b'] ?? 0);
$code = (string)($_GET['k'] ?? '');
$rid = '';
$staff = false;
if ($code !== '') {
    $rid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($_GET['r'] ?? ''));
    if ($rid === '' || !hash_equals(roomBillCode($rid, $id), $code)) $rid = '';
} elseif (!empty($_SESSION['restaurant_id']) && (!empty($_SESSION['user_id']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['branch_admin_id']))) {
    $rid = (string)$_SESSION['restaurant_id'];
    $staff = true;
}
session_write_close();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$bill = null;
if ($rid !== '' && $id > 0) {
    try {
        $bill = roomBill(getConnection(), $rid, $id, true);
    } catch (Throwable $e) {
        error_log('room_bill.php: ' . $e->getMessage());
    }
}
if (!$bill) {
    http_response_code(404);
    echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Bill not found</title>'
       . '<p style="font-family:sans-serif;padding:40px;text-align:center;color:#555">This bill could not be found. Please ask the hotel for a new link.</p>';
    exit;
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$cur = $bill['currency'];
$m = fn($n) => $h($cur . number_format((float)$n, 2));
$day = fn($d) => date('j M Y', strtotime($d));
$narrow = ($_GET['size'] ?? '') === '80';
$logo = $bill['hotel']['logo'];
$logoSrc = '';
if ($logo !== '') {
    if (strpos($logo, 'http') === 0) $logoSrc = $logo;
    elseif (strpos($logo, 'db:') === 0) $logoSrc = 'image.php?type=logo&id=' . urlencode((string)$bill['hotel']['user_id']) . '&v=' . rawurlencode(substr($logo, 3));
    else $logoSrc = 'image.php?type=file&path=' . urlencode('../' . $logo);
}
$s = $bill['stay'];
$self = 'room_bill.php?' . http_build_query(array_filter(['r' => $code !== '' ? $rid : null, 'b' => $id, 'k' => $code ?: null]));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $h($bill['title'] . ' ' . $bill['bill_label'] . ' - ' . $bill['hotel']['name']); ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: #111827; background: #eef0f4; font-size: 13px; }
.bar { display: flex; gap: 8px; justify-content: center; flex-wrap: wrap; padding: 12px; }
.bar a, .bar button { border: 0; border-radius: 9px; padding: 9px 14px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; background: #fff; color: #111827; font-family: inherit; }
.bar .pri { background: #7c3aed; color: #fff; }
.paper { background: #fff; width: 210mm; max-width: 100%; min-height: 200mm; margin: 0 auto 24px; padding: 14mm 14mm 12mm; box-shadow: 0 2px 16px rgba(0,0,0,.08); }
.top { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; border-bottom: 2px solid #111827; padding-bottom: 12px; }
.hotel { display: flex; gap: 12px; align-items: flex-start; }
.hotel img { width: 58px; height: 58px; object-fit: contain; border-radius: 8px; }
.hotel h1 { font-size: 20px; }
.hotel p { color: #4b5563; font-size: 12px; line-height: 1.5; white-space: pre-line; }
.ttl { text-align: right; }
.ttl h2 { font-size: 18px; text-transform: uppercase; letter-spacing: .04em; }
.ttl p { font-size: 12px; color: #4b5563; line-height: 1.6; }
.paid { display: inline-block; margin-top: 6px; border: 2px solid #16a34a; color: #16a34a; border-radius: 6px; padding: 2px 10px; font-weight: 800; letter-spacing: .08em; }
.two { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 14px 0; }
.box { background: #f8f9fb; border-radius: 10px; padding: 10px 12px; }
.box small { display: block; font-size: 10.5px; text-transform: uppercase; letter-spacing: .06em; color: #6b7280; margin-bottom: 4px; font-weight: 700; }
.box p { line-height: 1.55; }
table { width: 100%; border-collapse: collapse; margin-top: 4px; }
th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 7px 6px; }
td { padding: 8px 6px; border-bottom: 1px solid #eef0f4; vertical-align: top; }
td small { color: #6b7280; display: block; }
.r { text-align: right; white-space: nowrap; }
.sum { margin-left: auto; width: 290px; max-width: 100%; margin-top: 10px; }
.sum div { display: flex; justify-content: space-between; padding: 4px 6px; }
.sum .g { border-top: 2px solid #111827; margin-top: 4px; padding-top: 8px; font-size: 15px; font-weight: 800; }
.sum .due { background: #111827; color: #fff; border-radius: 8px; margin-top: 6px; padding: 8px; font-weight: 800; font-size: 15px; }
h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #374151; margin: 18px 0 4px; }
.rules { font-size: 11.5px; color: #4b5563; line-height: 1.6; padding-left: 16px; }
.foot { display: flex; justify-content: space-between; align-items: flex-end; gap: 20px; margin-top: 26px; }
.foot p { color: #4b5563; font-size: 12px; max-width: 60%; }
.sign { text-align: center; font-size: 11.5px; color: #4b5563; min-width: 170px; }
.sign i { display: block; height: 36px; border-bottom: 1px solid #9ca3af; margin-bottom: 4px; }
.made { text-align: center; color: #9ca3af; font-size: 10.5px; margin-top: 18px; }
/* 80 mm bill printer */
body.n .paper { width: 80mm; min-height: 0; padding: 4mm 3mm; font-size: 11.5px; }
body.n .top { display: block; text-align: center; }
body.n .hotel { display: block; }
body.n .hotel img { width: 40px; height: 40px; margin-bottom: 4px; }
body.n .hotel h1 { font-size: 15px; }
body.n .ttl { text-align: center; margin-top: 8px; }
body.n .ttl h2 { font-size: 13px; }
body.n .two { display: block; margin: 8px 0; }
body.n .box { background: none; padding: 4px 0; border-bottom: 1px dashed #9ca3af; border-radius: 0; }
body.n th:nth-child(2), body.n td:nth-child(2), body.n th:nth-child(3), body.n td:nth-child(3) { display: none; }
body.n td, body.n th { padding: 4px 2px; }
body.n .sum { width: 100%; }
body.n .foot { display: block; text-align: center; }
body.n .foot p { max-width: none; margin-bottom: 14px; }
@media (max-width: 640px) { .paper { padding: 16px; } .two { grid-template-columns: 1fr; } .top { flex-direction: column; } .ttl { text-align: left; } }
@media print {
  body { background: #fff; }
  .bar { display: none; }
  .paper { box-shadow: none; margin: 0; width: auto; min-height: 0; }
  @page { size: <?php echo $narrow ? '80mm auto' : 'A4'; ?>; margin: <?php echo $narrow ? '2mm' : '10mm'; ?>; }
}
</style>
</head>
<body<?php echo $narrow ? ' class="n"' : ''; ?>>
<div class="bar">
  <button type="button" class="pri" onclick="window.print()">Print / Save as PDF</button>
  <a href="<?php echo $h($self . ($narrow ? '' : '&size=80')); ?>"><?php echo $narrow ? 'A4 size' : '80 mm bill printer'; ?></a>
</div>
<div class="paper">
  <div class="top">
    <div class="hotel">
      <?php if ($logoSrc): ?><img src="<?php echo $h($logoSrc); ?>" alt=""><?php endif; ?>
      <div>
        <h1><?php echo $h($bill['hotel']['name']); ?></h1>
        <p><?php echo $h(trim($bill['hotel']['address'])); ?></p>
        <p><?php echo $h(implode(' · ', array_filter([$bill['hotel']['phone'], $bill['hotel']['email']]))); ?></p>
        <?php if ($bill['hotel']['tax_id']): ?><p><b><?php echo $h($bill['hotel']['tax_id_label']); ?>:</b> <?php echo $h($bill['hotel']['tax_id']); ?></p><?php endif; ?>
      </div>
    </div>
    <div class="ttl">
      <h2><?php echo $h($bill['title']); ?></h2>
      <p>Bill no. <b><?php echo $h($bill['bill_label']); ?></b><br>Date: <?php echo $h($day($bill['paid_at'] ?: date('Y-m-d'))); ?></p>
      <?php if ($bill['paid'] && $bill['balance'] <= 0): ?><span class="paid">PAID</span><?php endif; ?>
    </div>
  </div>

  <div class="two">
    <div class="box">
      <small>Guest</small>
      <p><b><?php echo $h($bill['guest']['name']); ?></b><br><?php echo $h($bill['guest']['phone']); ?><?php echo $bill['guest']['email'] ? '<br>' . $h($bill['guest']['email']) : ''; ?>
      <?php if ($bill['guest']['company']): ?><br><?php echo $h($bill['guest']['company']); ?><?php endif; ?>
      <?php if ($bill['guest']['company_tax_id']): ?><br><?php echo $h(($bill['hotel']['tax_id_label'] ?: 'Tax ID') . ': ' . $bill['guest']['company_tax_id']); ?><?php endif; ?></p>
    </div>
    <div class="box">
      <small>Stay</small>
      <p><b><?php echo $h($s['type']); ?></b><?php echo $s['room_numbers'] ? ' · Room ' . $h($s['room_numbers']) : ''; ?><br>
      Check-in: <?php echo $h($day($s['in']) . ', ' . $s['checkin_time']); ?><br>
      Check-out: <?php echo $h($day($s['out']) . ', ' . $s['checkout_time']); ?><br>
      <?php echo $s['nights'] . ' night' . ($s['nights'] === 1 ? '' : 's') . ' · ' . $s['guests'] . ' guest' . ($s['guests'] === 1 ? '' : 's') . ($s['rooms'] > 1 ? ' · ' . $s['rooms'] . ' rooms' : ''); ?></p>
    </div>
  </div>

  <table>
    <thead><tr><th>Item</th><th class="r">Rate</th><th class="r">Qty</th><th class="r">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($bill['lines'] as $l): ?>
      <tr><td><?php echo $h($l['label']); ?><?php echo $l['detail'] ? '<small>' . $h($l['detail']) . '</small>' : ''; ?></td>
        <td class="r"><?php echo $m($l['rate']); ?></td><td class="r"><?php echo (int)$l['qty']; ?></td><td class="r"><?php echo $m($l['amount']); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sum">
    <?php if ($bill['discount'] > 0): ?><div><span>Discount</span><span>−<?php echo $m($bill['discount']); ?></span></div><?php endif; ?>
    <?php if ($bill['tax'] > 0): ?>
      <div><span><?php echo $bill['tax_inclusive'] ? 'Room value (before tax)' : 'Taxable value'; ?></span><span><?php echo $m($bill['net']); ?></span></div>
      <?php foreach ($bill['tax_lines'] as $t): ?><div><span><?php echo $h($t['label']); ?></span><span><?php echo $m($t['amount']); ?></span></div><?php endforeach; ?>
    <?php endif; ?>
    <div class="g"><span>Room total</span><span><?php echo $m($bill['stay_total']); ?></span></div>
  </div>

  <?php if ($bill['food'] && $bill['show_food']): ?>
    <h3>Room service</h3>
    <table>
      <thead><tr><th>Order</th><th class="r">Room</th><th class="r">Date</th><th class="r">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($bill['food'] as $f): ?>
        <tr><td>#<?php echo $h($f['number']); ?><small><?php echo $h($f['items']); ?></small></td><td class="r"><?php echo $h($f['room']); ?></td>
          <td class="r"><?php echo $h(date('j M, g:i A', strtotime($f['at']))); ?></td><td class="r"><?php echo $m($f['total']); ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="sum"><div><span>Room service (taxes included)</span><span><?php echo $m($bill['food_total']); ?></span></div></div>
  <?php elseif ($bill['food_total'] > 0): ?>
    <div class="sum"><div><span>Room service (taxes included)</span><span><?php echo $m($bill['food_total']); ?></span></div></div>
  <?php endif; ?>

  <div class="sum">
    <div class="g"><span>Grand total</span><span><?php echo $m($bill['grand_total']); ?></span></div>
    <?php if ($bill['advance'] > 0): ?><div><span>Advance received</span><span>−<?php echo $m($bill['advance']); ?></span></div><?php endif; ?>
    <?php if ($bill['paid']): ?>
      <div><span>Paid<?php echo $bill['pay_method'] ? ' by ' . $h($bill['pay_method']) : ''; ?></span><span><?php echo $m($bill['grand_total'] - $bill['advance'] - $bill['balance']); ?></span></div>
      <?php if ($bill['balance'] > 0): ?><div class="due"><span>Still to pay</span><span><?php echo $m($bill['balance']); ?></span></div><?php endif; ?>
    <?php else: ?>
      <div class="due"><span>Balance to pay</span><span><?php echo $m($bill['balance']); ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($bill['policies']): $p = $bill['policies']; ?>
    <h3>Stay policies</h3>
    <ul class="rules">
      <li>Check-in from <?php echo $h($p['checkin']); ?>, check-out by <?php echo $h($p['checkout']); ?>.</li>
      <?php if ($p['cancel']): ?><li><?php echo $h($p['cancel']); ?></li><?php endif; ?>
      <?php foreach ($p['rules'] as $r): ?><li><?php echo $h($r); ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="foot">
    <p><?php echo nl2br($h($bill['footer'])); ?></p>
    <?php if ($bill['signature']): ?><div class="sign"><i></i>For <?php echo $h($bill['hotel']['name']); ?></div><?php endif; ?>
  </div>
  <p class="made">This is a computer-made bill.</p>
</div>
</body>
</html>
