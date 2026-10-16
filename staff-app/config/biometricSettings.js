import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'biometricLockEnabled';

export async function getBiometricLockEnabled() {
  try {
    return (await AsyncStorage.getItem(KEY)) === '1';
  } catch (e) {
    return false;
  }
}

export async function setBiometricLockEnabled(enabled) {
  await AsyncStorage.setItem(KEY, enabled ? '1' : '0');
}
