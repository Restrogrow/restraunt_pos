import AsyncStorage from '@react-native-async-storage/async-storage';
import * as LocalAuthentication from 'expo-local-authentication';
import { Platform } from 'react-native';

const KEY = 'sa_app_lock_v1';

export async function getAppLock() {
  try {
    return (await AsyncStorage.getItem(KEY)) === '1';
  } catch (e) {
    return false;
  }
}

export async function setAppLock(on) {
  await AsyncStorage.setItem(KEY, on ? '1' : '0');
}

export async function lockSupported() {
  if (Platform.OS === 'web') return false;
  try {
    return (await LocalAuthentication.hasHardwareAsync()) && (await LocalAuthentication.isEnrolledAsync());
  } catch (e) {
    return false;
  }
}

export async function unlock() {
  try {
    const r = await LocalAuthentication.authenticateAsync({ promptMessage: 'Unlock RestroGrow Super Admin' });
    return !!r.success;
  } catch (e) {
    return false;
  }
}
