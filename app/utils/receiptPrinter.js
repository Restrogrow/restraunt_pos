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

// items: [{ name, quantity, price, variationName? }]
// type: 'bill' (default) prints the full priced receipt a customer gets;
// 'kot' prints a kitchen ticket — item names/quantities only, no prices —
// mirroring the website's KOT template, which never shows the kitchen a
// subtotal/tax/total/payment method that has nothing to do with cooking.
// Returns an array of { text, bold, size } lines — real Unicode, no ASCII
// substitution, since this is rendered as text/an image, never raw
// single-byte printer bytes. size is 'title' (restaurant name), 'small'
// (address/GSTIN), or 'normal' (everything else) — the caller (BillPreview
// Modal/SettingsScreen) maps these to actual font sizes, and separately
// appends the "Powered by RestroGrow" + logo footer, since that needs a
// real <Image>, which a plain text line can't be.
export function buildReceiptLines({
  type = 'bill',
  restaurantName,
  restaurantAddress,
  gstin,
  kotNumber,
  orderType,
  tableName,
  items,
  subtotal,
  discount = 0,
  couponCode,
  tax,
  total,
  paymentMethod,
  currency = '₹',
  width = 32,
}) {
  const lines = [];
  const push = (text, opts = {}) => lines.push({ text, bold: !!opts.bold, size: opts.size || 'normal' });

  // Wrapped narrower than the body — at title size, 32 characters would run
  // far wider than the receipt itself; long names still wrap, just at a
  // sensible line length for the bigger font.
  const titleWidth = Math.max(12, Math.round(width * 0.6));
  wrapText(restaurantName || 'Receipt', titleWidth).forEach((l) => push(centerText(l, titleWidth), { bold: true, size: 'title' }));
  // Address, then GSTIN right below it (only when actually configured) —
  // the divider always lands directly below whichever of these is last, not
  // fixed right after the address. Bill only, same as the website's own
  // print templates — a kitchen ticket has no use for legal/tax details.
  if (type !== 'kot' && restaurantAddress) {
    wrapText(restaurantAddress, width).forEach((l) => push(centerText(l, width), { size: 'small' }));
  }
  if (type !== 'kot' && gstin) {
    push(centerText(`GSTIN: ${gstin}`, width), { size: 'small' });
  }
  push('-'.repeat(width));
  if (type === 'kot') push(centerText('KOT', width));
  if (kotNumber) push(centerText(kotNumber, width));
  push(centerText(new Date().toLocaleString(), width));
  push('-'.repeat(width));
  push(`${orderType}${tableName ? ' - ' + tableName : ''}`);
  push('-'.repeat(width));

  let itemCount = 0;
  (items || []).forEach((it) => {
    itemCount += Number(it.quantity) || 0;
    const label = it.variationName ? `${it.name} (${it.variationName})` : it.name;
    if (type === 'kot') {
      wrapText(`${it.quantity} x ${label}`, width).forEach((l) => push(l));
    } else {
      wrapText(label, width).forEach((l) => push(l));
      const qtyPrice = `${it.quantity} x ${currency}${Number(it.price).toFixed(2)}`;
      const lineTotal = `${currency}${(Number(it.price) * Number(it.quantity)).toFixed(2)}`;
      push(padLine(qtyPrice, lineTotal, width));
    }
  });

  push('-'.repeat(width));

  if (type === 'kot') {
    push(padLine('Total Items', String(itemCount), width), { bold: true });
  } else {
    push(padLine('Subtotal', `${currency}${Number(subtotal).toFixed(2)}`, width));
    if (Number(discount) > 0) {
      const label = couponCode ? `Coupon (${couponCode})` : 'Discount';
      push(padLine(label, `-${currency}${Number(discount).toFixed(2)}`, width));
    }
    if (Number(tax) > 0) {
      push(padLine('Tax', `${currency}${Number(tax).toFixed(2)}`, width));
    }
    push(padLine('TOTAL', `${currency}${Number(total).toFixed(2)}`, width), { bold: true });
    if (paymentMethod) push(`Payment: ${paymentMethod}`);
  }

  push('-'.repeat(width));
  push(centerText('Thank you!', width));

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
