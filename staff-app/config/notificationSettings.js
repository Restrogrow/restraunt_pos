import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'orderSoundEnabled';

// Defaults to on, matching the website's kotOrdersSoundEnabled default.
export async function getOrderSoundEnabled() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    return raw === null ? true : raw === '1';
  } catch (e) {
    return true;
  }
}

export async function setOrderSoundEnabled(enabled) {
  await AsyncStorage.setItem(KEY, enabled ? '1' : '0');
}
