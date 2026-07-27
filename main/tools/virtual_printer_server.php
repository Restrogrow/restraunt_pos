<?php
/**
 * Virtual thermal printer - lets you test the "Network Printer" print mode
 * without owning real hardware.
 *
 * Run it from a terminal (needs the PHP CLI, e.g. XAMPP's php.exe):
 *   php virtual_printer_server.php [port]
 *
 * Then in the dashboard's Printer Settings, set:
 *   Mode:    Network Printer
 *   Address: 127.0.0.1
 *   Port:    9100 (or whatever you passed above)
 *
 * Every "Test Print" / real KOT / invoice print sent in Network mode opens a
 * plain TCP connection and writes raw ESC/POS bytes to it - exactly what a
 * real 58mm/80mm thermal printer receives on its network port. This script
 * accepts that connection, decodes the ESC/POS control codes, and prints a
 * readable rendering of the receipt to the terminal, so you can verify
 * formatting, column widths, and paper-size handling before buying hardware.
 */

$port = isset($argv[1]) ? (int)$argv[1] : 9100;
if ($port < 1 || $port > 65535) {
    $port = 9100;
}

$address = '0.0.0.0';
$server = @stream_socket_server("tcp://{$address}:{$port}", $errno, $errstr);

if (!$server) {
    fwrite(STDERR, "Failed to start virtual printer on port {$port}: {$errstr} ({$errno})\n");
    fwrite(STDERR, "Is another process (maybe another instance of this script) already using that port?\n");
    exit(1);
}

echo "==================================================================\n";
echo " Virtual thermal printer listening on 0.0.0.0:{$port}\n";
echo " Point the dashboard's Printer Settings (Network mode) at:\n";
echo "   Address: 127.0.0.1 (or this machine's LAN IP)   Port: {$port}\n";
echo " Press Ctrl+C to stop.\n";
echo "==================================================================\n\n";

while (true) {
    $conn = @stream_socket_accept($server, -1);
    if (!$conn) {
        continue;
    }

    $raw = '';
    stream_set_timeout($conn, 3);
    while (!feof($conn)) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    fclose($conn);

    $timestamp = date('Y-m-d H:i:s');
    $rendered = render_escpos($raw);

    echo "---- Print job received at {$timestamp} (" . strlen($raw) . " bytes) ----\n";
    echo $rendered;
    echo "---- End of job ----\n\n";

    $logLine = "\n===== {$timestamp} =====\n" . $rendered;
    file_put_contents(__DIR__ . '/virtual_printer_output.log', $logLine, FILE_APPEND);
}

/**
 * Decodes just enough ESC/POS to give a faithful terminal preview: text,
 * line feeds, bold/double-height markers, alignment hints, and the cut.
 */
function render_escpos(string $raw): string
{
    $len = strlen($raw);
    $out = '';

    for ($i = 0; $i < $len; $i++) {
        $b = ord($raw[$i]);

        if ($b === 0x1B && $i + 1 < $len) { // ESC
            $next = ord($raw[$i + 1]);
            if ($next === 0x40) { $i += 1; continue; } // init
            if ($next === 0x61 && $i + 2 < $len) { $i += 2; continue; } // align n
            if ($next === 0x45 && $i + 2 < $len) { $i += 2; continue; } // bold n
            if ($next === 0x64 && $i + 2 < $len) { // feed n lines
                $n = ord($raw[$i + 2]);
                $out .= str_repeat("\n", max(0, $n));
                $i += 2;
                continue;
            }
        }

        if ($b === 0x1D && $i + 1 < $len) { // GS
            $next = ord($raw[$i + 1]);
            if ($next === 0x21 && $i + 2 < $len) { $i += 2; continue; } // double height n
            if ($next === 0x56 && $i + 2 < $len) { // cut
                $out .= "\n[-------------- PAPER CUT --------------]\n";
                $i += 2;
                continue;
            }
        }

        if ($b === 0x0A) {
            $out .= "\n";
            continue;
        }

        $out .= chr($b);
    }

    return $out . "\n";
}
