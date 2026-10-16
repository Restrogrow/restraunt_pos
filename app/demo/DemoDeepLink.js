// Browser demo only (EXPO_PUBLIC_DEMO=1): /app-demo/app/?screen=pos logs in with the demo account
// and opens that screen, so the demo page (restrogrow.com/demo) can link straight to every screen.
// Does nothing in the phone app or without ?screen=.
import { useEffect, useRef } from 'react';
import { Platform } from 'react-native';
import { DEMO } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { navigationRef } from '../navigationRef';

// ?screen= value → [tab, screen inside that tab's stack]
export const DEMO_SCREENS = {
  home: ['Home'],
  orders: ['Orders', 'OrdersList'],
  pos: ['POS'],
  menu: ['Menu', 'MenuList'],
  more: ['More', 'MoreHome'],
  kitchen: ['More', 'Kitchen'],
  tables: ['More', 'Tables'],
  'waiter-calls': ['More', 'WaiterCalls'],
  bookings: ['More', 'Reservations'],
  customers: ['More', 'Customers'],
  reports: ['More', 'Reports'],
  loyalty: ['More', 'Loyalty'],
  logs: ['More', 'Logs'],
  settings: ['More', 'Settings'],
};

function wantedScreen() {
  if (!DEMO || Platform.OS !== 'web' || typeof window === 'undefined') return null;
  const m = /[?&]screen=([\w-]+)/.exec(window.location.search);
  return m && DEMO_SCREENS[m[1]] ? DEMO_SCREENS[m[1]] : null;
}

export default function DemoDeepLink() {
  const { user, login, checkingSession } = useAuth();
  const target = useRef(wantedScreen());

  // skip the login screen: any details work in the demo
  useEffect(() => {
    if (target.current && !checkingSession && !user) login('demo', 'demo1234').catch(() => {});
  }, [checkingSession, user, login]);

  // once the tabs are there, open the screen
  useEffect(() => {
    if (!target.current || !user) return undefined;
    const [tab, screen] = target.current;
    let tries = 0;
    const timer = setInterval(() => {
      tries += 1;
      const state = navigationRef.isReady() ? navigationRef.getRootState() : null;
      if (state && state.routeNames && state.routeNames.includes(tab)) {
        navigationRef.navigate(tab, screen ? { screen } : undefined);
        target.current = null;
        clearInterval(timer);
      } else if (tries > 80) {
        clearInterval(timer);
      }
    }, 100);
    return () => clearInterval(timer);
  }, [user]);

  return null;
}
