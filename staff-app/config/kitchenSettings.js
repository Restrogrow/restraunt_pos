import AsyncStorage from '@react-native-async-storage/async-storage';

const KEY = 'kitchenStation';
const STATIONS = ['all', 'kitchen', 'bar'];

// Which tickets the Kitchen screen shows on this device when the restaurant has a bar:
// 'all' (default), 'kitchen' (KOTs) or 'bar' (BOTs), so a phone at the bar stays on Bar.
export async function getKitchenStation() {
  try {
    const raw = await AsyncStorage.getItem(KEY);
    return STATIONS.includes(raw) ? raw : 'all';
  } catch (e) {
    return 'all';
  }
}

export async function setKitchenStation(station) {
  await AsyncStorage.setItem(KEY, STATIONS.includes(station) ? station : 'all');
}
