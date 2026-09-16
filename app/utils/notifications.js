import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';

// Foreground behavior: show the banner, but don't let the OS layer its own
// tone on top — the app already plays its own new-order sound
// (orderAlerts.playNewOrderSound) once per event, same as the website.
Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: false,
    shouldSetBadge: false,
  }),
});

let channelReady = false;
async function ensureAndroidChannel() {
  if (Platform.OS !== 'android' || channelReady) return;
  channelReady = true;
  try {
    await Notifications.setNotificationChannelAsync('orders', {
      name: 'New orders',
      importance: Notifications.AndroidImportance.HIGH,
    });
  } catch (e) {
    // non-fatal — notifications just fall back to default channel behavior
  }
}

export async function ensureNotificationPermission() {
  try {
    const { status } = await Notifications.getPermissionsAsync();
    if (status === 'granted') {
      await ensureAndroidChannel();
      return true;
    }
    const { status: next } = await Notifications.requestPermissionsAsync();
    await ensureAndroidChannel();
    return next === 'granted';
  } catch (e) {
    return false;
  }
}

export async function notifyNewOrder(order) {
  try {
    await Notifications.scheduleNotificationAsync({
      content: {
        title: 'New order',
        body: order?.order_number
          ? `${order.order_number} · ${order.customer_name || order.order_type || 'Order'}`
          : 'A new order has come in',
      },
      trigger: null,
    });
  } catch (e) {
    // non-fatal — the sound alert already covers the "something happened" signal
  }
}
