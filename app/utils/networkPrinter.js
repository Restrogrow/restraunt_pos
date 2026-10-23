// Wi-Fi / LAN thermal printer, straight from the phone: open a TCP socket to
// the printer's IP (port 9100) and write the ESC/POS bytes. The phone sits on
// the restaurant's Wi-Fi next to the printer; the RestroGrow server does not,
// so relaying through api/print_network.php can never reach a 192.168.x.x
// printer from the live site. The web build can't open sockets, so it keeps
// the server relay (networkPrinter.web.js).
import TcpSocket from 'react-native-tcp-socket';

const CONNECT_TIMEOUT_MS = 6000;

function openSocket(ip, port) {
  return new Promise((resolve, reject) => {
    let done = false;
    const finish = (err, sock) => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      if (err) {
        try { sock && sock.destroy(); } catch (e) {}
        reject(err);
      } else {
        resolve(sock);
      }
    };
    const sock = TcpSocket.createConnection({ host: ip, port }, () => finish(null, sock));
    sock.on('error', (e) => finish(new Error(`Could not reach the printer at ${ip}:${port}. Check that the phone and printer are on the same Wi-Fi. (${(e && e.message) || e})`), sock));
    const timer = setTimeout(
      () => finish(new Error(`Printer at ${ip}:${port} did not answer. Check the IP and that the printer is on the same Wi-Fi as this phone.`), sock),
      CONNECT_TIMEOUT_MS
    );
  });
}

// Writes in slices and waits for each to drain, so a long bill doesn't flood
// a small printer's buffer.
function writeAll(sock, bytes) {
  const SLICE = 4096;
  return new Promise((resolve, reject) => {
    let offset = 0;
    sock.on('error', reject);
    const next = () => {
      while (offset < bytes.length) {
        const part = bytes.subarray(offset, offset + SLICE);
        offset += part.length;
        if (!sock.write(part)) {
          sock.once('drain', next);
          return;
        }
      }
      resolve();
    };
    next();
  });
}

export async function sendToNetworkPrinter({ ip, port, bytes }) {
  const sock = await openSocket(ip, port);
  try {
    await writeAll(sock, bytes);
  } finally {
    // end() flushes what is still queued before closing
    setTimeout(() => { try { sock.end(); } catch (e) {} }, 300);
  }
}

export async function checkNetworkPrinter({ ip, port }) {
  const sock = await openSocket(ip, port);
  try { sock.destroy(); } catch (e) {}
}
