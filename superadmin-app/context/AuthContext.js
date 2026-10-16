import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { auth, setSessionExpiredHandler } from '../config/api';
import { deviceLabel, getDeviceToken, setDeviceToken } from '../config/deviceToken';
import { clearApiCache } from '../hooks/useApiData';
import { onAppForeground } from '../utils/foreground';

const AuthContext = createContext(null);

// Trades the saved device token for a fresh server session (the PHP session lasts 24 hours).
// Returns the user, or null when there is no token or the server refused it.
export async function resumeSession() {
  const token = await getDeviceToken();
  if (!token) return null;
  try {
    const res = await auth.resume(token);
    if (res.success) return res.data;
    await setDeviceToken('');
  } catch (e) {
    // offline: keep the token and try again later
  }
  return null;
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [checkingSession, setCheckingSession] = useState(true);

  // silent: a network blip while the app is open shouldn't sign the person out,
  // only an explicit "not signed in" (and no device to resume with) should.
  const checkSession = useCallback(async (silent = false) => {
    try {
      const res = await auth.session();
      if (res.success) setUser(res.data);
      else setUser(await resumeSession());
    } catch (e) {
      if (!silent) setUser(null);
    } finally {
      setCheckingSession(false);
    }
  }, []);

  useEffect(() => {
    checkSession();
    return onAppForeground(() => checkSession(true));
  }, [checkSession]);

  // a request found the session gone: resume quietly (the request is retried), else sign out
  useEffect(() => {
    setSessionExpiredHandler(async () => {
      const u = await resumeSession();
      if (u) return true;
      clearApiCache();
      setUser(null);
      return false;
    });
    return () => setSessionExpiredHandler(null);
  }, []);

  const login = useCallback(async (username, password) => {
    const res = await auth.login(username, password, deviceLabel());
    if (!res.success) throw new Error(res.message || 'Incorrect username or password');
    await setDeviceToken(res.device_token || '');
    clearApiCache();
    setUser(res.data);
    return res.data;
  }, []);

  const logout = useCallback(async () => {
    const token = await getDeviceToken();
    await setDeviceToken('');
    try {
      await auth.logout(token);
    } catch (e) {
      // signed out locally either way
    }
    clearApiCache();
    setUser(null);
  }, []);

  return <AuthContext.Provider value={{ user, checkingSession, login, logout, refresh: checkSession }}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
