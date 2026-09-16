// In dev (Expo web on this machine), talk to the local XAMPP backend — it
// already has the CORS allow-list for this dev origin and needs no deploy.
// Production/native builds keep hitting the live site.
export const BASE_URL = __DEV__ ? 'http://localhost/menuwebsite/main' : 'https://restrogrow.com/main';

async function request(path, options = {}) {
  const res = await fetch(`${BASE_URL}${path}`, {
    credentials: 'include',
    ...options,
    headers: {
      Accept: 'application/json',
      ...options.headers,
    },
  });

  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    throw new Error('Server returned an unexpected response');
  }

  if (!res.ok && !('success' in data)) {
    throw new Error(data.message || `Request failed (${res.status})`);
  }

  return data;
}

export function apiGet(path) {
  return request(path, { method: 'GET' });
}

// Menu item images are stored/served by reference (db: id, legacy file path,
// or a plain external URL) — this mirrors the same lookup the website's
// admin panel does against api/image.php.
export function imageUrl(path) {
  if (!path) return null;
  // Already a directly-renderable URI (e.g. a freshly picked photo's local
  // preview, before the server has assigned it a real db: reference).
  if (/^(https?:|data:|blob:|file:)/.test(path)) return path;
  return `${BASE_URL}/api/image.php?path=${encodeURIComponent(path)}`;
}

// The restaurant logo/profile photo is looked up by user id (image.php's
// type=logo branch, which queries the users table), not by the generic
// path= lookup imageUrl() uses — that one only searches menu_items, so a
// restaurant_logo db: reference would 404 through it.
export function restaurantLogoUrl(user) {
  const id = user?.user_id || user?.id;
  if (!user?.restaurant_logo || !id) return null;
  return `${BASE_URL}/api/image.php?type=logo&id=${encodeURIComponent(id)}`;
}

export function apiPostForm(path, fields) {
  const body = Object.entries(fields)
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&');

  return request(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
  });
}

// A handful of endpoints (print_network.php) read a raw JSON body via
// php://input instead of $_POST, so they need real JSON, not urlencoded form
// fields.
export function apiPostJson(path, payload) {
  return request(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
}
