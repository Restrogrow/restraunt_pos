// Talks to the same backend as the Super Admin panel in the browser:
//   superadmin/app_auth.php       sign in / session / sign out (JSON)
//   superadmin/api.php            restaurants, subscriptions, coupons, payments ...
//   superadmin/api_platform.php   restaurant 360, analytics, renewals, leads, tickets, health ...
import { Platform } from 'react-native';
import Constants from 'expo-constants';

// In dev the app calls the address it was loaded from (localhost:8082 in the browser, the LAN IP
// or --tunnel host on a phone) and metro.config.js forwards /menuwebsite/* to XAMPP on this PC.
// Same origin means the PHP session cookie just works, with no CORS. Release builds use the live site.
function devHost() {
  if (Platform.OS === 'web' && typeof window !== 'undefined') return window.location.host;
  return (Constants.expoConfig && Constants.expoConfig.hostUri) || 'localhost:8082';
}
// A browser build (restrogrow.com/superadmin-app-web, or its local copy under /menuwebsite/) talks to the
// site it was opened from, so the login cookie works; phone builds always use the live site.
function webSite() {
  const loc = window.location;
  return loc.origin + (loc.pathname.indexOf('/menuwebsite/') === 0 ? '/menuwebsite' : '');
}
export const SITE_URL = __DEV__
  ? 'http://' + devHost() + '/menuwebsite'
  : (Platform.OS === 'web' && typeof window !== 'undefined' ? webSite() : 'https://restrogrow.com');
export const BASE_URL = SITE_URL + '/main';

// Called when any request comes back "session expired". AuthContext first tries to resume the
// session with the saved device token (resolves true = retry the request), else signs out.
let onSessionExpired = null;
export function setSessionExpiredHandler(fn) {
  onSessionExpired = fn;
}
let resuming = null; // one resume at a time, shared by every request that hit the expiry

// Is the server reachable? Flips off on a network failure and back on with the next answer,
// so the app can show a quiet "offline" strip instead of every screen erroring.
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

function friendlyStatusMessage(status) {
  if (status === 401) return 'Your session has expired — please sign in again.';
  if (status === 403) return "You don't have permission to do that.";
  if (status === 404) return "That couldn't be found.";
  if (status === 429) return 'Too many attempts. Wait a minute and try again.';
  if (status >= 500) return 'Something went wrong on the server. Please try again in a moment.';
  return `Something went wrong (error ${status}). Please try again.`;
}

async function request(path, options = {}, retried = false) {
  let res;
  try {
    res = await fetch(`${BASE_URL}${path}`, {
      credentials: 'include',
      ...options,
      headers: { Accept: 'application/json', ...options.headers },
    });
  } catch (e) {
    setOnline(false);
    throw new Error("Can't reach the server — check your internet connection and try again.");
  }

  setOnline(true);
  const text = await res.text();
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    if (res.status === 401) data = { success: false, session_expired: true };
    else throw new Error('The server sent back something unexpected. Please try again in a moment.');
  }

  if (res.status === 401 || data.session_expired) {
    if (onSessionExpired && !path.startsWith('/superadmin/app_auth.php')) {
      if (!retried) {
        resuming = resuming || Promise.resolve(onSessionExpired()).finally(() => setTimeout(() => (resuming = null), 0));
        if (await resuming) return request(path, options, true);
      }
    }
    const err = new Error(data.message || friendlyStatusMessage(401));
    err.sessionExpired = true;
    throw err;
  }
  if (!res.ok && !('success' in data)) throw new Error(data.message || friendlyStatusMessage(res.status));
  return data;
}

// Every superadmin action returns { success, message, ... }: a false success is an error.
async function call(path, options) {
  const data = await request(path, options);
  if (data && data.success === false) throw new Error(data.message || 'That did not work. Please try again.');
  return data;
}

const qs = (params = {}) =>
  Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== null && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`)
    .join('&');

const jsonPost = (body) => ({
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(body || {}),
});

// ── auth ──
export const auth = {
  session: () => request('/superadmin/app_auth.php?action=session'),
  login: (username, password, device) => request('/superadmin/app_auth.php?action=login', jsonPost({ username, password, device })),
  resume: (device_token) => request('/superadmin/app_auth.php?action=resume', jsonPost({ device_token })),
  logout: (device_token) => request('/superadmin/app_auth.php?action=logout', jsonPost({ device_token })),
};

// ── api.php ──
export const sa = {
  get: (action, params) => call(`/superadmin/api.php?${qs({ action, ...params })}`),
  post: (action, body) => call(`/superadmin/api.php?action=${action}`, jsonPost(body)),
  // a few api.php actions (createPaymentLink, setPhonePeMode) read $_POST, not JSON
  form: (action, fields) =>
    call(`/superadmin/api.php?action=${action}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: qs(fields),
    }),
};

// ── api_platform.php ──
export const pf = {
  get: (action, params) => call(`/superadmin/api_platform.php?${qs({ action, ...params })}`),
  post: (action, body) => call(`/superadmin/api_platform.php?action=${action}`, jsonPost(body)),
};
