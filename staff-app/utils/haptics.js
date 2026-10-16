import * as Haptics from 'expo-haptics';
import { Platform } from 'react-native';

// Light, consistent touch feedback. Browsers have no haptics, so web is a no-op,
// and a device without a vibration motor just ignores the call.
const on = Platform.OS !== 'web';

export const haptic = {
  tap: () => on && Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {}),
  press: () => on && Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Medium).catch(() => {}),
  select: () => on && Haptics.selectionAsync().catch(() => {}),
  success: () => on && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {}),
  warning: () => on && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Warning).catch(() => {}),
  error: () => on && Haptics.notificationAsync(Haptics.NotificationFeedbackType.Error).catch(() => {}),
};
