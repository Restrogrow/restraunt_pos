// Web build: browsers can't open raw TCP sockets, so the server relays the
// bytes (api/print_network.php). That only reaches printers on the server's
// own network, e.g. a local test setup; the installed app prints directly
// (networkPrinter.js). Same exports as networkPrinter.js.
import { apiPostJson } from '../config/api';
import { bytesToBase64 } from './receiptPrinter';

export async function sendToNetworkPrinter({ ip, port, bytes }) {
  const res = await apiPostJson('/api/print_network.php', { ip, port, data: bytesToBase64(bytes) });
  if (!res.success) throw new Error(res.message || 'Print failed');
  return res;
}

export async function checkNetworkPrinter({ ip, port }) {
  const res = await apiPostJson('/api/print_network.php', { ip, port, test: true });
  if (!res.success) throw new Error(res.message || 'Could not connect to printer');
  return res;
}
