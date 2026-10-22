// Browser demo only (built with EXPO_PUBLIC_DEMO_LOGIN=1, restrogrow.com/staff-app-demo): the staff
// app runs against the real demo restaurant (Spice Garden Indiranagar). ?as=waiter|chef|manager logs in
// as that demo staff member, and &screen=tables (etc.) opens that screen, so the demo page
// (restrogrow.com/demo) can link straight to every screen. Does nothing in the phone app.
import { useEffect, useRef, useState } from 'react';
import { Platform } from 'react-native';
import { DEMO_LOGIN, apiGet } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { navigationRef } from '../navigationRef';

export const DEMO_STAFF = {
  // same as DEMO_STAFF in main/config/demo_restaurant.php
  manager: 'neha@spicegardenindiranagar.com',
  waiter: 'ravi@spicegardenindiranagar.com',
  chef: 'suresh@spicegardenindiranagar.com',
  rider: 'arjun@spicegardenindiranagar.com',
  cashier: 'kavita@spicegardenindiranagar.com',
};
export const DEMO_STAFF_PASSWORD = 'demo';

// ?screen= value → the tab or the page inside More (the role decides which one it is)
const SCREENS = {
  home: 'Home', orders: 'Orders', pos: 'POS', menu: 'Menu', more: 'More', kitchen: 'Kitchen', tables: 'Tables',
  'waiter-calls': 'WaiterCalls', bookings: 'Reservations', customers: 'Customers', reports: 'Reports', logs: 'Logs', settings: 'Settings', riders: 'Riders',
};

function fromUrl() {
  if (!DEMO_LOGIN || Platform.OS !== 'web' || typeof window === 'undefined') return {};
  const p = new URLSearchParams(window.location.search);
  return { as: DEMO_STAFF[p.get('as')] ? p.get('as') : null, screen: SCREENS[p.get('screen')] || null };
}

export default function DemoDeepLink() {
  const { user, login, logout, checkingSession } = useAuth();
  const want = useRef(fromUrl());

  // log in as the asked role once (even if another demo role is still signed in), then open the screen.
  // Switching roles: the server replaces the session on login (strict sessions), so a request the old
  // role's screens still have in flight can come back with a fresh empty session and log the new role
  // out. So close the old role first, let its requests settle, and check the new session took.
  const started = useRef(false);
  const switchedAt = useRef(0);
  const relogins = useRef(0);
  const [ready, setReady] = useState(!want.current.as);
  useEffect(() => {
    const as = want.current.as;
    if (!as || checkingSession || started.current) return;
    started.current = true;
    const email = DEMO_STAFF[as];
    const isWanted = (u) => !!u && String(u.email || '').toLowerCase() === email;
    (async () => {
      if (isWanted(user)) return;
      if (user) {
        await logout();
        await new Promise((r) => setTimeout(r, 600));
      }
      for (let i = 0; i < 3; i += 1) {
        try {
          await login(email, DEMO_STAFF_PASSWORD);
          const s = await apiGet('/admin/get_session.php');
          if (s?.success && isWanted(s.data)) return;
        } catch (e) {
          // try again below
        }
        await new Promise((r) => setTimeout(r, 800));
      }
    })().finally(() => {
      switchedAt.current = Date.now();
      setReady(true);
    });
  }, [checkingSession, login, logout, user]);

  // A request the old role sent before the switch can still land afterwards and end the new session.
  // For a short while after a switch, log the asked role back in if that happens.
  useEffect(() => {
    const as = want.current.as;
    if (!as || !ready || user || checkingSession) return;
    if (Date.now() - switchedAt.current > 30000 || relogins.current >= 3) return;
    relogins.current += 1;
    login(DEMO_STAFF[as], DEMO_STAFF_PASSWORD).catch(() => {});
  }, [user, ready, checkingSession, login]);

  useEffect(() => {
    const screen = want.current.screen;
    if (!screen || !user || !ready) return undefined;
    let tries = 0;
    const timer = setInterval(() => {
      tries += 1;
      const state = navigationRef.isReady() ? navigationRef.getRootState() : null;
      if (state && state.routeNames && state.routeNames.includes('Home')) {
        if (state.routeNames.includes(screen)) navigationRef.navigate(screen);
        else navigationRef.navigate('More', { screen });
        want.current.screen = null;
        clearInterval(timer);
      } else if (tries > 80) {
        clearInterval(timer);
      }
    }, 100);
    return () => clearInterval(timer);
  }, [user, ready]);

  return null;
}
