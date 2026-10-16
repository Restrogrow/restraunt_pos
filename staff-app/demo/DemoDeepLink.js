// Browser demo only (built with EXPO_PUBLIC_DEMO_LOGIN=1, restrogrow.com/staff-app-demo): the staff
// app runs against the real demo restaurant (Spice Garden Indiranagar). ?as=waiter|chef|manager logs in
// as that demo staff member, and &screen=tables (etc.) opens that screen, so the demo page
// (restrogrow.com/demo) can link straight to every screen. Does nothing in the phone app.
import { useEffect, useRef, useState } from 'react';
import { Platform } from 'react-native';
import { DEMO_LOGIN } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { navigationRef } from '../navigationRef';

export const DEMO_STAFF = {
  manager: 'manager.demo@spicegarden.test',
  waiter: 'waiter.demo@spicegarden.test',
  chef: 'chef.demo@spicegarden.test',
  rider: 'rider.demo@spicegarden.test',
  cashier: 'cashier.demo@spicegarden.test',
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
  const { user, login, checkingSession } = useAuth();
  const want = useRef(fromUrl());

  // log in as the asked role once (even if another demo role is still signed in), then open the screen
  const started = useRef(false);
  const [ready, setReady] = useState(!want.current.as);
  useEffect(() => {
    const as = want.current.as;
    if (!as || checkingSession || started.current) return;
    started.current = true;
    login(DEMO_STAFF[as], DEMO_STAFF_PASSWORD).catch(() => {}).finally(() => setReady(true));
  }, [checkingSession, login]);

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
