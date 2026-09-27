import { useEffect } from 'react';
import { AppState } from 'react-native';
import { apiGet, BASE_URL } from '../config/api';
import { useAuth } from '../context/AuthContext';
import { navigationRef } from '../navigationRef';
import {
  consumePendingOrderDeepLink,
  setOrderWatchForeground,
  startOrderWatch,
  stopOrderWatch,
} from 'order-watch';

const DEEP_LINK_POLL_MS = 1000;

/**
 * Mounted at the app root alongside OrderAlertWatcher. The native side
 * (modules/order-watch) runs an Android foreground service that keeps
 * polling get_orders.php while the app is closed/killed — this bridge:
 *
 *  - starts the service on login, stops it on logout
 *  - tells the service when the app is foregrounded (it goes quiet then —
 *    the in-app watcher owns alerting while someone is looking at the app)
 *  - drains notification-tap deep links and opens the tapped order
 */
export default function OrderWatchBridge() {
  const { user } = useAuth();
  const loggedIn = !!user;

  useEffect(() => {
    if (loggedIn) {
      startOrderWatch(BASE_URL);
    } else {
      stopOrderWatch();
    }
    return () => {
      if (!loggedIn) return;
      // unmount with a live session (dev reload) — keep watching
    };
  }, [loggedIn]);

  useEffect(() => {
    const sub = AppState.addEventListener('change', (state) => {
      setOrderWatchForeground(state === 'active');
    });
    setOrderWatchForeground(AppState.currentState === 'active');
    return () => sub.remove();
  }, []);

  useEffect(() => {
    if (!loggedIn) return undefined;
    // Deep links can't be pushed as an event on cold start (JS isn't up
    // yet when MainActivity receives the intent), so MainActivity parks
    // them in a native bus and this poll drains it once the navigator is
    // ready. One-second polling of an in-memory native read is negligible.
    const id = setInterval(async () => {
      const link = consumePendingOrderDeepLink();
      if (!link) return;
      let order = { id: link.orderId, order_number: link.orderNumber };
      try {
        const res = await apiGet(`/api/get_order_details_by_id.php?id=${link.orderId}`);
        if (res?.success && res.order) order = res.order;
      } catch (e) {
        // fall back to the stub — OrderDetail re-fetches by id anyway
      }
      if (navigationRef.isReady()) {
        navigationRef.navigate('Orders', {
          screen: 'OrderDetail',
          params: { order },
        });
      }
    }, DEEP_LINK_POLL_MS);
    return () => clearInterval(id);
  }, [loggedIn]);

  return null;
}
