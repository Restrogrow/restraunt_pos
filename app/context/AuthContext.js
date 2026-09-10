import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { apiGet, apiPostForm } from '../config/api';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [checkingSession, setCheckingSession] = useState(true);

  const checkSession = useCallback(async () => {
    try {
      const res = await apiGet('/admin/get_session.php');
      setUser(res.success ? res.data : null);
    } catch (e) {
      setUser(null);
    } finally {
      setCheckingSession(false);
    }
  }, []);

  useEffect(() => {
    checkSession();
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
