import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'printerSettings';

const DEFAULTS = { type: 'network', ip: '', port: '9100', btAddress: '', btName: '' };

export async function getPrinterSettings() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    if (!raw) return { ...DEFAULTS };
    return { ...DEFAULTS, ...JSON.parse(raw) };
  } catch (e) {
    return { ...DEFAULTS };
  }
}

export async function savePrinterSettings(settings) {
  await AsyncStorage.setItem(KEY, JSON.stringify(settings));
}
