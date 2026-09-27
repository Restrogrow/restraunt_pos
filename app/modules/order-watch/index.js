import { Platform } from 'react-native';

// Local Expo module (this package) — a native Android foreground service.
// On web (and pre-build dev) everything is a no-op.
let OrderWatchNative = null;
if (Platform.OS === 'android') {
  try {
    // eslint-disable-next-line import/no-unresolved
    OrderWatchNative = require('expo-modules-core').requireNativeModule('OrderWatch');
  } catch (e) {
    OrderWatchNative = null;
  }
}

export function isOrderWatchSupported() {
  return Platform.OS === 'android' && OrderWatchNative != null;
}

/**
 * Start the background order watcher. Call after a successful login with the
 * API base the app is talking to — the service reuses the app's persisted
 * session cookie, so no credentials are stored or passed. Safe to call
 * repeatedly.
 */
export function startOrderWatch(baseUrl) {
  if (!isOrderWatchSupported()) return;
  try {
    OrderWatchNative.start(baseUrl);
  } catch (e) {
    // native side unavailable (e.g. running from Metro before a prebuild) —
    // in-app alerting still works
  }
}

/** Stop the background watcher (called on logout). */
export function stopOrderWatch() {
  if (!isOrderWatchSupported()) return;
  try {
    OrderWatchNative.stop();
  } catch (e) {
    // ignore
  }
}

/**
 * Tell the service the app is on screen (or not). While foregrounded the
 * service stays silent — the in-app watcher owns alerting.
 */
export function setOrderWatchForeground(foreground) {
  if (!isOrderWatchSupported()) return;
  try {
    OrderWatchNative.setAppForeground(foreground);
  } catch (e) {
    // ignore
  }
}

/** Record an order as handled so the background ring/notif stops for it. */
export function markOrderHandled(orderId) {
  if (!isOrderWatchSupported()) return;
  try {
    OrderWatchNative.markHandled(orderId);
  } catch (e) {
    // ignore
  }
}

/**
 * Pop a pending notification-tap deep link (returns { orderId, orderNumber }
 * or null). The JS side polls this once the navigator is ready.
 */
export function consumePendingOrderDeepLink() {
  if (!isOrderWatchSupported()) return null;
  try {
    const result = OrderWatchNative.consumePendingDeepLink();
    if (result && result.orderId && result.orderId > 0) {
      return { orderId: result.orderId, orderNumber: result.orderNumber || '' };
    }
    return null;
  } catch (e) {
    return null;
  }
}
