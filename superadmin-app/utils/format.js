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

// "16 Oct 2026" (or "16 Oct" in the current year)
export function shortDate(value, withYear) {
  const d = parseDate(value);
  if (!d) return '—';
  const sameYear = d.getFullYear() === new Date().getFullYear();
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', ...(withYear || !sameYear ? { year: 'numeric' } : {}) });
}

export function dateTime(value) {
  const d = parseDate(value);
  return d ? `${shortDate(value)}, ${clock(value)}` : '—';
}

export function num(value) {
  const n = Number(value) || 0;
  try {
    return n.toLocaleString('en-IN');
  } catch (e) {
    return String(n);
  }
}

// One place that decides how a restaurant's plan reads: Active, Trial, Expiring, Expired, Disabled.
export function planState(r) {
  if (!r) return { label: '—', tone: 'muted' };
  const days = r.days_left === null || r.days_left === undefined ? null : Number(r.days_left);
  if (Number(r.is_active) === 0 || r.subscription_status === 'disabled') return { label: 'Disabled', tone: 'danger' };
  if (r.subscription_status === 'expired' || (days !== null && days < 0) || r.trial_status === 'Expired') return { label: 'Expired', tone: 'danger' };
  if (days !== null && days <= 7) return { label: r.subscription_status === 'trial' ? 'Trial ending' : 'Expiring', tone: 'warning' };
  if (r.subscription_status === 'trial') return { label: 'Trial', tone: 'info' };
  return { label: 'Active', tone: 'success' };
}

export function daysLeftLabel(days) {
  if (days === null || days === undefined || days === '') return '';
  const d = Number(days);
  if (d < 0) return `Expired ${Math.abs(d)}d ago`;
  if (d === 0) return 'Ends today';
  if (d === 1) return '1 day left';
  return `${d} days left`;
}

export function initials(name) {
  const parts = String(name || '?').trim().split(/\s+/);
  return ((parts[0] || '?')[0] + (parts[1] ? parts[1][0] : '')).toUpperCase();
}
