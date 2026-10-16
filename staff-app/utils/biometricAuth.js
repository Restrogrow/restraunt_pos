import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';

// Fingerprint/Face ID is a native-only capability — the web preview has no
// equivalent, so the app-lock feature is simply unavailable there rather
// than faking success.
export async function isBiometricSupported() {
  if (Platform.OS === 'web') return false;
  try {
    const hasHardware = await LocalAuthentication.hasHardwareAsync();
    if (!hasHardware) return false;
    return await LocalAuthentication.isEnrolledAsync();
  } catch (e) {
    return false;
  }
}

export async function authenticate(promptMessage = 'Unlock RestroGrow') {
  const result = await LocalAuthentication.authenticateAsync({
    promptMessage,
    cancelLabel: 'Cancel',
    disableDeviceFallback: false,
  });
  return result.success;
}
