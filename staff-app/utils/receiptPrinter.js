// Receipt/KOT builder + printer clients for the app's POS screen.
//
// Printing used to send raw ESC/POS text bytes, but thermal printers' text
// mode only speaks a single-byte Latin codepage — there's no byte sequence
// that makes one print Hindi (or any other non-Latin script) as anything
// but garbage/'?'. The fix is to print a *picture* of the receipt instead:
// buildReceiptLines() below renders as real Unicode text on screen (via
// BillPreviewModal, which also owns the actual bitmap capture since that
// needs a live view ref), and convertReceiptImageToEscPos() turns that
// captured bitmap into an ESC/POS raster image, which prints correctly
// regardless of script/language because it was never text to the printer
// in the first place.
import { apiPostJson } from '../config/api';

function padLine(left, right, width) {
  left = String(left == null ? '' : left);
  right = String(right == null ? '' : right);
  const space = Math.max(1, width - left.length - right.length);
  return left + ' '.repeat(space) + right;
}

function wrapText(str, width) {
  const clean = String(str == null ? '' : str);
  if (clean.length <= width) return [clean];
  const words = clean.split(' ');
  const lines = [];
  let cur = '';
  for (let w of words) {
    // A single word longer than the whole width (long compound item/
    // restaurant names) would otherwise never break and just overflow —
    // hard-split it at the width first.
    while (w.length > width) {
      if (cur) { lines.push(cur); cur = ''; }
      lines.push(w.slice(0, width));
      w = w.slice(width);
    }
    if ((cur + ' ' + w).trim().length > width) {
      if (cur) lines.push(cur);
      cur = w;
    } else {
      cur = (cur + ' ' + w).trim();
    }
  }
  if (cur) lines.push(cur);
  return lines;
}

function centerText(str, width) {
  if (str.length >= width) return str;
  const space = Math.floor((width - str.length) / 2);
  return ' '.repeat(space) + str;
}

// Amounts render as plain numbers ("149", "318.50") — no currency symbol —
// inside the item table and on totals, matching the reference bills this
// layout follows. Whole amounts drop the decimals so a ₹149 item reads
// "149", not "149.00".
function fmtAmount(n) {
  const num = Number(n) || 0;
  return Number.isInteger(num) ? String(num) : num.toFixed(2);
}

// dd/mm/yy hh:mm AM/PM — the compact bill-date format on the reference
// receipts ("28/09/26 07:08 PM").
function fmtBillDateTime(value) {
  const d = value ? new Date(String(value).replace(' ', 'T')) : new Date();
  if (Number.isNaN(d.getTime())) return String(value || '');
  const dd = String(d.getDate()).padStart(2, '0');
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const yy = String(d.getFullYear()).slice(-2);
  let h = d.getHours();
  const ampm = h >= 12 ? 'PM' : 'AM';
  h = h % 12 || 12;
  const mi = String(d.getMinutes()).padStart(2, '0');
  return `${dd}/${mm}/${yy} ${h}:${mi} ${ampm}`;
}

// Bill items render as an aligned ITEM | QTY | RATE | TOTAL table, so every
// row shows the unit rate, the quantity, and that item's own total — the old
// "2 x ₹50.00 …  ₹100.00" layout buried the per-item rate and made whoever
// read the bill do the multiplication in their head. Long names wrap within
// their column with the amount columns repeated on the first line only.
function buildItemTableLines({ items, width }) {
  const rows = (items || []).map((it) => ({
    label: it.variationName ? `${it.name} (${it.variationName})` : it.name,
    quantity: Number(it.quantity) || 0,
    rate: Number(it.price) || 0,
  }));
  if (rows.length === 0) return [];

  // Amount columns size themselves to the widest value on the bill; the item
  // name gets whatever's left over. The name floor means a bill full of
  // 10000+ catering items overruns `width` slightly rather than crushing
  // every name to nothing.
  const qtyWidth = 3;
  const rateWidth = Math.max(4, ...rows.map((r) => fmtAmount(r.rate).length));
  const totalWidth = Math.max(5, ...rows.map((r) => fmtAmount(r.rate * r.quantity).length));
  // -3 = the single space between the four columns.
  const nameWidth = Math.max(8, width - qtyWidth - rateWidth - totalWidth - 3);

  const cell = (v, w) => String(v).padStart(w);
  const lines = [];
  lines.push(
    ['ITEM NAME'.padEnd(nameWidth), cell('QTY', qtyWidth), cell('RATE', rateWidth), cell('TOTAL', totalWidth)].join(' ')
  );
  rows.forEach((r) => {
    const nameLines = wrapText(r.label, nameWidth);
    nameLines.forEach((nl, i) => {
      // Amounts only on the first line — continuation lines carry just the
      // rest of the name so a wrapped item doesn't read as a second row.
      lines.push(
        i === 0
          ? `${nl.padEnd(nameWidth)} ${cell(r.quantity, qtyWidth)} ${cell(fmtAmount(r.rate), rateWidth)} ${cell(fmtAmount(r.rate * r.quantity), totalWidth)}`
          : nl
      );
    });
  });
  return lines;
}

// items: [{ name, quantity, price, variationName? }]
// type: 'bill' (default) prints the full priced receipt a customer gets;
// 'kot' prints a kitchen ticket — item names/quantities only, no prices —
// mirroring the website's KOT template, which never shows the kitchen a
// subtotal/tax/total/payment method that has nothing to do with cooking.
// Returns an array of { text, bold, size } lines — real Unicode, no ASCII
// substitution, since this is rendered as text/an image, never raw
// single-byte printer bytes. size is 'title' (restaurant name), 'small'
// (address/business ID), or 'normal' (everything else) — the caller (BillPreview
// Modal/SettingsScreen) maps these to actual font sizes, and separately
// appends the "Powered by RestroGrow" + logo footer, since that needs a
// real <Image>, which a plain text line can't be.
export function buildReceiptLines({
  type = 'bill',
  restaurantName,
  restaurantAddress,
  restaurantPhone,
  restaurantEmail,
  restaurantWebsite,
  businessIdLabel,
  businessIdNo,
  orderNumber,
  kotNumber,
  orderType,
  tableName,
  customerName,
  items,
  subtotal,
  discount = 0,
  couponCode,
  tax,
  total,
  paymentMethod,
  currency = '₹',
  createdAt,
  width = 32,
}) {
  const lines = [];
  const push = (text, opts = {}) => lines.push({ text, bold: !!opts.bold, size: opts.size || 'normal' });

  // Wrapped narrower than the body — at title size, 32 characters would run
  // far wider than the receipt itself; long names still wrap, just at a
  // sensible line length for the bigger font.
  const titleWidth = Math.max(12, Math.round(width * 0.7));

  if (type === 'kot') {
    // ── KOT: kitchen ticket, no prices/legal details ──
    // Header block like the reference KOT: "KOT No: 1", "Date: ...",
    // "Bill No: ...", "Table: ..." as compact left-aligned key rows.
    if (kotNumber) push(`KOT No: ${kotNumber}`);
    push(`Date: ${fmtBillDateTime(createdAt)}`);
    if (orderNumber) push(`Bill No: ${orderNumber}`);
    push(`Table: ${tableName || orderType || 'Sale'}`);
    push('-'.repeat(width));
    let itemCount = 0;
    (items || []).forEach((it) => {
      itemCount += Number(it.quantity) || 0;
      // Quantity rides with the LAST wrapped line ("Chole Bhature 2 Pcs (Pure Veg)\n(1)" in the
      // reference) — actually keep it simple and read like the reference: name on its
      // own wrapped lines, quantity in parens on the following line when the name wraps.
      const label = it.variationName ? `${it.name} (${it.variationName})` : it.name;
      const nameLines = wrapText(label, width - 4);
      nameLines.forEach((nl) => push(nl));
      push(`(${itemCount ? '' : ''}${Number(it.quantity) || 0})`);
    });
    push('-'.repeat(width));
    push(`Total Items: ${itemCount}`, { bold: true });
    push('-'.repeat(width));
    push(centerText('Thank you!', width));
    return lines;
  }

  // ── Bill (customer receipt) — mirrors the reference layout ──
  // Restaurant name, tagline-free, centered; address/phone/email/site lines
  // centered beneath; then the Bill No / Created On / Bill To block.
  wrapText(restaurantName || 'Receipt', titleWidth).forEach((l) => push(centerText(l, titleWidth), { bold: true, size: 'title' }));
  wrapText(restaurantAddress || '', width).forEach((l) => push(centerText(l, width), { size: 'small' }));
  if (restaurantPhone) push(centerText(`Phone: ${restaurantPhone}`, width), { size: 'small' });
  if (restaurantEmail) push(centerText(`Email: ${restaurantEmail}`, width), { size: 'small' });
  if (restaurantWebsite) push(centerText(`Website Menu: ${restaurantWebsite}`, width), { size: 'small' });
  if (businessIdNo) {
    push(centerText(`${businessIdLabel || 'PAN'}: ${businessIdNo}`, width), { size: 'small' });
  }

  // Bill metadata block — left-aligned key rows, no divider between them so
  // it reads as one block like the reference.
  push('-'.repeat(width));
  if (orderNumber) push(`Bill No: ${orderNumber}`, { bold: true });
  push(`Created On: ${fmtBillDateTime(createdAt)}`);
  push(`Bill To: ${customerName || (paymentMethod === 'Cash' ? 'Cash Sale' : orderType || 'Customer')}`);
  push('-'.repeat(width));

  // Items — plain-number columns (no ₹ in the grid), aligned header.
  buildItemTableLines({ items, width }).forEach((text) => push(text));

  push('-'.repeat(width));

  const totalQty = (items || []).reduce((s, it) => s + (Number(it.quantity) || 0), 0);
  push(`Total Items: ${(items || []).length}`);
  push(`Total Quantity: ${totalQty}`);
  // Sub Total / Total — amount column right-aligned like the item table, so
  // the money reads down one edge instead of drifting after the label.
  push(padLine('  Sub Total', fmtAmount(subtotal), width), { bold: true });
  if (Number(discount) > 0) {
    const label = couponCode ? `  Coupon (${couponCode})` : '  Discount';
    push(padLine(label, `-${fmtAmount(discount)}`, width));
  }
  if (Number(tax) > 0) {
    push(padLine('  Tax', fmtAmount(tax), width));
  }
  push(padLine(`  Total`, fmtAmount(total), width), { bold: true });
  if (paymentMethod) {
    push('-'.repeat(width));
    push(padLine('Mode of Payment', paymentMethod, width));
  }

  push('-'.repeat(width));
  push(centerText('Thank You! Visit Again! ', width));

  return lines;
}

const BASE64_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';

// RN's JS engine doesn't reliably expose btoa/Buffer for binary data, so this
// is a small dependency-free base64 encoder for raw byte arrays.
export function bytesToBase64(bytes) {
  let result = '';
  for (let i = 0; i < bytes.length; i += 3) {
    const b1 = bytes[i];
    const b2 = i + 1 < bytes.length ? bytes[i + 1] : undefined;
    const b3 = i + 2 < bytes.length ? bytes[i + 2] : undefined;

    result += BASE64_CHARS[b1 >> 2];
    result += BASE64_CHARS[((b1 & 0x03) << 4) | (b2 === undefined ? 0 : b2 >> 4)];
    result += b2 === undefined ? '=' : BASE64_CHARS[((b2 & 0x0f) << 2) | (b3 === undefined ? 0 : b3 >> 6)];
    result += b3 === undefined ? '=' : BASE64_CHARS[b3 & 0x3f];
  }
  return result;
}

// The counterpart decoder — used to turn the ESC/POS raster bytes the
// server computes (see convertReceiptImageToEscPos) back into a Uint8Array
// the Bluetooth/network print clients below can send as-is.
export function base64ToBytes(b64) {
  const clean = String(b64 || '').replace(/[^A-Za-z0-9+/=]/g, '');
  const bytes = [];
  for (let i = 0; i < clean.length; i += 4) {
    const e1 = BASE64_CHARS.indexOf(clean[i]);
    const e2 = BASE64_CHARS.indexOf(clean[i + 1]);
    const e3 = BASE64_CHARS.indexOf(clean[i + 2]);
    const e4 = BASE64_CHARS.indexOf(clean[i + 3]);
    if (e1 < 0 || e2 < 0) break;
    bytes.push((e1 << 2) | (e2 >> 4));
    if (e3 >= 0) bytes.push(((e2 & 15) << 4) | (e3 >> 2));
    if (e4 >= 0) bytes.push(((e3 & 3) << 6) | e4);
  }
  return new Uint8Array(bytes);
}

// Sends a captured receipt PNG (base64, no data: URI prefix needed) to the
// server, which resizes/thresholds it to a 1-bit bitmap and packs it into an
// ESC/POS raster image (GS v 0) — ready to write straight to a Bluetooth or
// network printer. dotWidth matches the printer's dot width, not the app's
// old 32-char text width — 384 is the standard for 58mm/203dpi printers.
export async function convertReceiptImageToEscPos({ base64Png, dotWidth = 384 }) {
  const res = await apiPostJson('/api/convert_receipt_image.php', {
    image: base64Png,
    dot_width: dotWidth,
  });
  if (!res.success) throw new Error(res.message || 'Could not process receipt image');
  return base64ToBytes(res.data);
}

export async function printToNetworkPrinter({ ip, port, bytes }) {
  if (!ip) throw new Error('No printer configured — set the printer IP in Settings first');
  const res = await apiPostJson('/api/print_network.php', {
    ip,
    port: port ? Number(port) : 9100,
    data: bytesToBase64(bytes),
  });
  if (!res.success) throw new Error(res.message || 'Print failed');
  return res;
}

// Opens and immediately closes the TCP socket — no data sent — so the UI can
// show a real "Connected" state before committing to a print.
export async function testNetworkPrinter({ ip, port }) {
  if (!ip) throw new Error('Enter the printer IP first');
  const res = await apiPostJson('/api/print_network.php', {
    ip,
    port: port ? Number(port) : 9100,
    test: true,
  });
  if (!res.success) throw new Error(res.message || 'Could not connect to printer');
  return res;
}
