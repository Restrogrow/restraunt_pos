// In dev, talk to the local XAMPP backend. Production/native builds keep hitting the live site.
import { Platform } from 'react-native';
import Constants from 'expo-constants';

// Dev calls go to the same address the app loaded from (localhost:8083 in the browser, the LAN
// IP or --tunnel host on a phone), and metro.config.js forwards /menuwebsite/* to XAMPP on this
// PC. Same origin in the browser, so no CORS allow-list entry is needed for this app's port.
const devHost = Platform.OS === 'web'
  ? (typeof window !== 'undefined' && window.location ? window.location.host : 'localhost:8083')
  : ((Constants.expoConfig && Constants.expoConfig.hostUri) || 'localhost:8083');
// A browser build (the demo at restrogrow.com/staff-app-demo, or its local copy under /menuwebsite/) talks to
// the site it was opened from, so the login cookie works; phone builds always use the live site.
function webBase() {
  const loc = window.location;
  return loc.origin + (loc.pathname.indexOf('/menuwebsite/') === 0 ? '/menuwebsite' : '') + '/main';
}
export const BASE_URL = __DEV__
  ? 'http://' + devHost + '/menuwebsite/main'
  : (Platform.OS === 'web' && typeof window !== 'undefined' ? webBase() : 'https://restrogrow.com/main');
// The browser demo build (EXPO_PUBLIC_DEMO_LOGIN=1): one-tap demo staff logins, links to screens.
export const DEMO_LOGIN = process.env.EXPO_PUBLIC_DEMO_LOGIN === '1';

// Maps a bad HTTP status to something a restaurant staff member can
// actually act on — "Request failed (500)" doesn't tell them anything.
function friendlyStatusMessage(status) {
  if (status === 401) return 'Your session has expired — please log in again.';
  if (status === 403) return "You don't have permission to do that.";
  if (status === 404) return "That couldn't be found — it may have been removed.";
  if (status >= 500) return 'Something went wrong on the server. Please try again in a moment.';
  return `Something went wrong (error ${status}). Please try again.`;
}

// The staff app has no offline browser demo (the partner app's demo/mockApi.js); kept so the
// shared screens that check it keep working.
export const DEMO = false;

// Is the server reachable? Flips off on a network failure and back on with the next answer,
// so the app shows a quiet "offline" strip and screens reload by themselves when it's back.
let online = true;
const netListeners = new Set();
function setOnline(v) {
  if (online === v) return;
  online = v;
  netListeners.forEach((l) => l());
}
export const network = {
  isOnline: () => online,
  subscribe: (l) => {
    netListeners.add(l);
    return () => netListeners.delete(l);
  },
};

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
    setOnline(false);
    throw new Error("Can't reach the server — check your internet connection and try again.");
  }

  setOnline(true);
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
