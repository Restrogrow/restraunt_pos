import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { AppState, Platform } from 'react-native';
import { apiGet, apiPostForm } from '../config/api';

const AuthContext = createContext(null);

const SESSION_POLL_MS = 20000;

function isForeground() {
  if (Platform.OS === 'web') {
    return typeof document === 'undefined' || document.visibilityState !== 'hidden';
  }
  return AppState.currentState === 'active';
}

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [checkingSession, setCheckingSession] = useState(true);

  // silent=true is used by the background poll below — a network blip there
  // shouldn't boot the owner to the login screen mid-shift, only an explicit
  // "not logged in" response from the server should.
  const checkSession = useCallback(async (silent = false) => {
    try {
      const res = await apiGet('/admin/get_session.php');
      setUser(res.success ? res.data : null);
    } catch (e) {
      if (!silent) setUser(null);
    } finally {
      setCheckingSession(false);
    }
  }, []);

  useEffect(() => {
    checkSession();
  }, [checkSession]);

  // Settings like the dine-in/delivery toggles can change from another
  // device (the website, or a second POS tablet) — poll softly so a change
  // shows up here without the owner having to log out/in or reload.
  useEffect(() => {
    const id = setInterval(() => {
      if (isForeground()) checkSession(true);
    }, SESSION_POLL_MS);
    return () => clearInterval(id);
  }, [checkSession]);

  const login = useCallback(async (username, password) => {
    const res = await apiPostForm('/admin/auth.php', { action: 'login', username, password });
    if (!res.success) {
      throw new Error(res.message || 'Incorrect username or password');
    }
    setUser(res.data);
    return res.data;
  }, []);

  const logout = useCallback(async () => {
    try {
      await apiPostForm('/admin/auth.php', { action: 'logout' });
    } catch (e) {
      // ignore network errors on logout — clear local state regardless
    }
    setUser(null);
  }, []);

  return (
    <AuthContext.Provider value={{ user, checkingSession, login, logout, refresh: checkSession }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used within AuthProvider');
  return ctx;
}
