import AsyncStorage from '@react-native-async-storage/async-storage';

// A random id generated once per install and kept in AsyncStorage — this is
// what the server's new-device WhatsApp OTP gate (auth.php) keys trust to.
// Reinstalling the app or logging in from a different phone means a new id,
// so the OTP step runs again exactly once for that install.
const KEY = 'appDeviceId';

function generateId() {
  const part = () => Math.random().toString(36).slice(2, 10);
  return `dev_${Date.now().toString(36)}_${part()}${part()}`;
}

export async function getDeviceId() {
  try {
    let id = await AsyncStorage.getItem(KEY);
    if (!id) {
      id = generateId();
      await AsyncStorage.setItem(KEY, id);
    }
    return id;
  } catch (e) {
    // AsyncStorage unavailable for some reason — fall back to a per-session
    // id rather than crashing login. The server fails open when it never
    // sees a device_id consistently anyway (first-run edge case only).
    return generateId();
  }
}
