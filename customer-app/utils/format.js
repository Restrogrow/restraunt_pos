import { BRAND } from '../config/brand';

export function money(n) {
  const v = Math.round((Number(n) || 0) * 100) / 100;
  const whole = Number.isInteger(v);
  const text = v.toLocaleString('en-IN', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2 });
  return BRAND.currency + text;
}

export function greeting(now = new Date()) {
  const h = now.getHours();
  if (h < 5) return 'Late-night cravings?';
  if (h < 12) return 'Good morning';
  if (h < 17) return 'Good afternoon';
  if (h < 22) return 'Good evening';
  return 'Late-night cravings?';
}

export function clockTime(ts) {
  const d = new Date(ts);
  let h = d.getHours();
  const m = String(d.getMinutes()).padStart(2, '0');
  const s = h >= 12 ? 'PM' : 'AM';
  h = h % 12 === 0 ? 12 : h % 12;
  return `${h}:${m} ${s}`;
}

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
export function shortDate(ts) {
  const d = new Date(ts);
  return `${d.getDate()} ${MONTHS[d.getMonth()]}`;
}
export function dateTime(ts) {
  return `${shortDate(ts)}, ${clockTime(ts)}`;
}

export const initialsOf = (name) =>
  String(name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w[0].toUpperCase())
    .join('');
