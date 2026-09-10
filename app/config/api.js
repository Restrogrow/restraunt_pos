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
