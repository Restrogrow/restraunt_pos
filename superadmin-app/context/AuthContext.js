import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { auth, setSessionExpiredHandler } from '../config/api';
import { clearApiCache } from '../hooks/useApiData';
import { onAppForeground } from '../utils/foreground';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [checkingSession, setCheckingSession] = useState(true);

  // silent: a network blip while the app is open shouldn't sign the person out,
  // only an explicit "not signed in" from the server should.
  const checkSession = useCallback(async (silent = false) => {
    try {
      const res = await auth.session();
      setUser(res.success ? res.data : null);
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

  // any API call that finds the session gone drops back to the sign-in screen
  useEffect(() => {
    setSessionExpiredHandler(() => {
      clearApiCache();
      setUser(null);
    });
    return () => setSessionExpiredHandler(null);
  }, []);

  const login = useCallback(async (username, password) => {
    const res = await auth.login(username, password);
    if (!res.success) throw new Error(res.message || 'Incorrect username or password');
    clearApiCache();
    setUser(res.data);
    return res.data;
  }, []);

  const logout = useCallback(async () => {
    try {
      await auth.logout();
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
