import { Platform } from 'react-native';
import * as Haptics from 'expo-haptics';

// A light tick on buttons. Silent on the web and anywhere haptics are unavailable.
export function tap() {
  if (Platform.OS === 'web') return;
  Haptics.impactAsync(Haptics.ImpactFeedbackStyle.Light).catch(() => {});
}

export function success() {
  if (Platform.OS === 'web') return;
  Haptics.notificationAsync(Haptics.NotificationFeedbackType.Success).catch(() => {});
}
