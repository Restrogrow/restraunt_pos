// In dev (Expo web on this machine), talk to the local XAMPP backend — it
// already has the CORS allow-list for this dev origin and needs no deploy.
// Production/native builds keep hitting the live site.
export const BASE_URL = __DEV__ ? 'http://localhost/menuwebsite/main' : 'https://restrogrow.com/main';

// Maps a bad HTTP status to something a restaurant staff member can
// actually act on — "Request failed (500)" doesn't tell them anything.
function friendlyStatusMessage(status) {
  if (status === 401) return 'Your session has expired — please log in again.';
  if (status === 403) return "You don't have permission to do that.";
  if (status === 404) return "That couldn't be found — it may have been removed.";
  if (status >= 500) return 'Something went wrong on the server. Please try again in a moment.';
  return `Something went wrong (error ${status}). Please try again.`;
}

async function request(path, options = {}) {
  let res;
  try {
    res = await fetch(`${BASE_URL}${path}`, {
      credentials: 'include',
      ...options,
      headers: {
        Accept: 'application/json',
        ...options.headers,
      },
    });
  } catch (e) {
    // fetch() only throws for network-level failures (no connection, DNS
    // failure, request timeout) — a bad HTTP status is handled below
    // instead. Left unwrapped, this surfaces as a raw "Network request
    // failed" or "Failed to fetch", which means nothing to someone on
    // shaky wifi mid-shift.
    throw new Error("Can't reach the server — check your internet connection and try again.");
  }

  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    throw new Error('The server sent back something unexpected. Please try again in a moment.');
  }

  if (!res.ok && !('success' in data)) {
    throw new Error(data.message || friendlyStatusMessage(res.status));
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
