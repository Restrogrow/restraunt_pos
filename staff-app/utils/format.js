// Shared display helpers so money, times and names read the same on every screen.

export function money(value, currency = '₹') {
  const n = Math.round((Number(value) || 0) * 100) / 100;
  const opts = { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 };
  let text;
  try {
    text = n.toLocaleString('en-IN', opts);
  } catch (e) {
    text = String(n);
  }
  return `${currency}${text}`;
}

// "1.4L" / "12.3k" for tight spots like chart labels.
export function moneyShort(value, currency = '₹') {
  const n = Number(value) || 0;
  if (n >= 100000) return `${currency}${(n / 100000).toFixed(n >= 1000000 ? 0 : 1)}L`;
  if (n >= 1000) return `${currency}${(n / 1000).toFixed(n >= 10000 ? 0 : 1)}k`;
  return `${currency}${Math.round(n)}`;
}

export function parseDate(value) {
  if (!value) return null;
  const d = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(d.getTime()) ? null : d;
}

export function timeAgo(value, now = Date.now()) {
  const d = parseDate(value);
  if (!d) return '';
  const min = Math.max(0, Math.round((now - d.getTime()) / 60000));
  if (min < 1) return 'Just now';
  if (min < 60) return `${min} min ago`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h}h ${min % 60}m ago`;
  const days = Math.floor(h / 24);
  return days === 1 ? 'Yesterday' : `${days} days ago`;
}

// Minutes elapsed, for kitchen timers.
export function minutesSince(value, now = Date.now()) {
  const d = parseDate(value);
  return d ? Math.max(0, Math.floor((now - d.getTime()) / 60000)) : 0;
}

export function durationLabel(min) {
  if (min < 60) return `${min} min`;
  const h = Math.floor(min / 60);
  if (h < 24) return `${h}h ${min % 60}m`;
  return `${Math.floor(h / 24)}d ${h % 24}h`;
}

export function clock(value) {
  const d = parseDate(value);
  return d ? d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' }) : '';
}

export function dayLabel(value) {
  const d = parseDate(value);
  if (!d) return '';
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  const x = new Date(d);
  x.setHours(0, 0, 0, 0);
  const diff = Math.round((x - t) / 86400000);
  if (diff === 0) return 'Today';
  if (diff === -1) return 'Yesterday';
  if (diff === 1) return 'Tomorrow';
  return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
}

// The POS stores a placeholder ("Takeaway", "Table Customer") as the customer name; those
// aren't names and shouldn't be shown as one next to the order type.
const PLACEHOLDER_NAMES = ['takeaway', 'table customer', 'walk-in', 'walk in', 'guest', 'dine-in', 'dine in', 'pos combo test'];
export function realName(name) {
  const n = String(name || '').trim();
  return n && !PLACEHOLDER_NAMES.includes(n.toLowerCase()) ? n : '';
}

// Order notes carry machine tags like "[KOT: KOT-20261015-4111]" and
// "[Rejected (too_busy): Kitchen is too busy]". Split those out so people see plain text.
export function splitNotes(notes) {
  const raw = String(notes || '');
  const kot = (raw.match(/\[KOT:\s*([^\]]+)\]/i) || [])[1] || '';
  const stop = (raw.match(/\[(?:Rejected|Cancelled)(?:\s*\([a-z_]+\))?:\s*([^\]]+)\]/i) || [])[1] || '';
  const text = raw
    .replace(/\[KOT:[^\]]*\]/gi, '')
    .replace(/\[(?:Rejected|Cancelled)[^\]]*\]/gi, '')
    .replace(/\s{2,}/g, ' ')
    .trim();
  return { text, kot: kot.trim(), stopReason: stop.trim() };
}

export function itemsSummary(items, max = 3) {
  const list = Array.isArray(items) ? items : [];
  const shown = list.slice(0, max).map((i) => `${Number(i.quantity) || 1}× ${i.item_name || i.name || 'Item'}`);
  const more = list.length - max;
  return shown.join(', ') + (more > 0 ? ` +${more} more` : '');
}
