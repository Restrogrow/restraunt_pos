// Web stub — classic Bluetooth (SPP) printers aren't reachable from a
// browser at all, and react-native-bluetooth-classic is a native-only
// module, so it's never imported here. Same exports as bluetoothPrinter.js
// so calling code doesn't need to special-case the platform.
export async function isBluetoothSupported() {
  return false;
}

export async function listPairedPrinters() {
  return [];
}

export async function isPrinterConnected() {
  return false;
}

export async function connectToPrinter() {
  throw new Error('Bluetooth printing is only available in the native app, not the web preview.');
}

export async function disconnectPrinter() {}

export async function writeToPrinter() {
  throw new Error('Bluetooth printing is only available in the native app, not the web preview.');
}

export async function printToBluetoothPrinter() {
  throw new Error('Bluetooth printing is only available in the native app, not the web preview.');
}
