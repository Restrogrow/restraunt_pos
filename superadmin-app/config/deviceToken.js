import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

// "Stay signed in" token from app_auth.php: in the phone's secure storage (Android Keystore),
// in plain storage only for the browser dev build, which has no secure store.
const KEY = 'sa_device_token';
const web = Platform.OS === 'web';

export async function getDeviceToken() {
  try {
    return (web ? await AsyncStorage.getItem(KEY) : await SecureStore.getItemAsync(KEY)) || '';
  } catch (e) {
    return '';
  }
}

export async function setDeviceToken(token) {
  try {
    if (!token) {
      if (web) await AsyncStorage.removeItem(KEY);
      else await SecureStore.deleteItemAsync(KEY);
    } else if (web) await AsyncStorage.setItem(KEY, token);
    else await SecureStore.setItemAsync(KEY, token);
  } catch (e) {
    // signing in still works, the device just won't be remembered
  }
}

export function deviceLabel() {
  if (web) return 'Browser';
  const c = Platform.constants || {};
  return [c.Manufacturer, c.Model].filter(Boolean).join(' ') || Platform.OS;
}
