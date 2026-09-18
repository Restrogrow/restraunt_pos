// Native-only implementation — Metro resolves bluetoothPrinter.web.js
// instead of this file when bundling for web, so react-native-bluetooth-
// classic (which touches NativeModules at import time) is never pulled into
// the web bundle at all. Classic Bluetooth (SPP), the profile most cheap
// thermal receipt printers use, isn't reachable from a browser regardless.
import { Buffer } from 'buffer';
import { PermissionsAndroid, Platform } from 'react-native';
import RNBluetoothClassic from 'react-native-bluetooth-classic';

// Android 12+ (API 31+) reclassified classic Bluetooth as a runtime-dangerous
// permission — BLUETOOTH_CONNECT in the manifest alone isn't enough, the app
// must ask at runtime or getBondedDevices()/connectToDevice() throw a
// SecurityException. react-native-bluetooth-classic itself never requests
// this (checked its source — no PermissionsAndroid usage at all), so every
// entry point below asks first.
//
// Only BLUETOOTH_CONNECT is requested — this app only reads already-bonded
// devices and opens an RFCOMM connection to one, it never calls
// startDiscovery(), so BLUETOOTH_SCAN (Android's discovery permission) is
// never actually needed. Requesting it anyway used to make this whole check
// fail (and every "Connect" tap error out with "permission denied") whenever
// the OS didn't grant that unrelated permission for its own reasons.
//
// Below Android 12 this previously requested ACCESS_FINE_LOCATION, which
// isn't declared in this app's manifest — an undeclared runtime permission
// is auto-denied by the OS every time, so Bluetooth connect/print was
// silently broken on every pre-12 device. Bonded-device lookup and RFCOMM
// connect were never gated behind location there anyway (only live
// discovery is, which this app doesn't do), so there's nothing to request.
async function ensureBluetoothPermissions() {
  if (Platform.OS !== 'android' || Platform.Version < 31) return true;
  const granted = await PermissionsAndroid.request(
    PermissionsAndroid.PERMISSIONS.BLUETOOTH_CONNECT
  );
  return granted === PermissionsAndroid.RESULTS.GRANTED;
}

export async function isBluetoothSupported() {
  try {
    await ensureBluetoothPermissions();
    return await RNBluetoothClassic.isBluetoothAvailable();
  } catch (e) {
    return false;
  }
}

// Classic Bluetooth devices must already be paired via the phone's system
// Bluetooth settings — this library (like the OS) only talks to bonded
// devices, it doesn't do the pairing handshake itself.
export async function listPairedPrinters() {
  const ok = await ensureBluetoothPermissions();
  if (!ok) {
    throw new Error('Bluetooth permission denied — enable it in phone Settings > Apps > Restrogrow Partner > Permissions.');
  }
  const devices = await RNBluetoothClassic.getBondedDevices();
  return (devices || []).map((d) => ({ address: d.address, name: d.name || d.address }));
}

export async function isPrinterConnected(address) {
  if (!address) return false;
  try {
    return await RNBluetoothClassic.isDeviceConnected(address);
  } catch (e) {
    return false;
  }
}

// Connects and leaves the SPP connection open, so the bill preview can show
// a real "Connected" state before the user commits to printing.
export async function connectToPrinter(address) {
  if (!address) throw new Error('No Bluetooth printer selected — pick one first');
  const ok = await ensureBluetoothPermissions();
  if (!ok) {
    throw new Error('Bluetooth permission denied — enable it in phone Settings > Apps > Restrogrow Partner > Permissions.');
  }
  const alreadyConnected = await isPrinterConnected(address);
  if (alreadyConnected) return true;
  await RNBluetoothClassic.connectToDevice(address);
  // Cheap thermal printers' SPP sockets commonly aren't ready for data the
  // instant connectToDevice() resolves — writing immediately after a fresh
  // connect is a well-known source of dropped/garbled first prints. A short
  // settle delay here (not needed on the already-connected path above) fixes
  // that without the caller having to know about it.
  await new Promise((resolve) => setTimeout(resolve, 300));
  return true;
}

export async function disconnectPrinter(address) {
  if (!address) return;
  await RNBluetoothClassic.disconnectFromDevice(address).catch(() => {});
}

// Assumes the device is already connected (via connectToPrinter) — does not
// disconnect afterwards, so the same connection can be reused.
export async function writeToPrinter(address, bytes) {
  if (!address) throw new Error('No Bluetooth printer selected — pick one first');
  await RNBluetoothClassic.writeToDevice(address, Buffer.from(bytes));
}

export async function printToBluetoothPrinter({ address, bytes }) {
  if (!address) throw new Error('No Bluetooth printer selected — pick one in Settings first');
  try {
    await connectToPrinter(address);
    await writeToPrinter(address, bytes);
  } finally {
    disconnectPrinter(address);
  }
}
