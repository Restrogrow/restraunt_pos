// Native-only implementation — Metro resolves bluetoothPrinter.web.js
// instead of this file when bundling for web, so react-native-bluetooth-
// classic (which touches NativeModules at import time) is never pulled into
// the web bundle at all. Classic Bluetooth (SPP), the profile most cheap
// thermal receipt printers use, isn't reachable from a browser regardless.
import { Buffer } from 'buffer';
import RNBluetoothClassic from 'react-native-bluetooth-classic';

export async function isBluetoothSupported() {
  try {
    return await RNBluetoothClassic.isBluetoothAvailable();
  } catch (e) {
    return false;
  }
}

// Classic Bluetooth devices must already be paired via the phone's system
// Bluetooth settings — this library (like the OS) only talks to bonded
// devices, it doesn't do the pairing handshake itself.
export async function listPairedPrinters() {
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
  const alreadyConnected = await isPrinterConnected(address);
  if (alreadyConnected) return true;
  await RNBluetoothClassic.connectToDevice(address);
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
