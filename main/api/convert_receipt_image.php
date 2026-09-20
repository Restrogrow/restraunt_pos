<?php
// Converts a captured receipt bitmap (PNG, base64) into an ESC/POS raster
// image (GS v 0) ready to write straight to a Bluetooth or network thermal
// printer. Printing an image instead of raw ESC/POS text bytes is what
// makes any script/language print correctly — thermal printers' built-in
// text mode only speaks a single-byte Latin codepage, so Hindi (or any
// other non-Latin text) previously had no way to come out as anything but
// '?'. A picture of the already-correctly-rendered text has no such limit.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// The app's JSON POST body is a non-"simple" request, so the browser/RN
// fetch sends a credential-less OPTIONS preflight first — answer it before
// session/auth runs, same as print_network.php.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

require_once __DIR__ . '/../config/authorization_config.php';

if (ob_get_level()) {
    ob_clean();
}
header('Content-Type: application/json');

requirePermission(PERMISSION_MANAGE_ORDERS);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!function_exists('imagecreatefromstring')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server is missing the GD image library needed for receipt printing']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$imageB64 = (string)($input['image'] ?? '');
$dotWidth = (int)($input['dot_width'] ?? 384);
if ($dotWidth < 128) $dotWidth = 128;
if ($dotWidth > 1024) $dotWidth = 1024;

if ($imageB64 === '') {
    echo json_encode(['success' => false, 'message' => 'Missing receipt image']);
    exit;
}

// Strip a data: URI prefix if the client sent one (data:image/png;base64,...).
if (stripos($imageB64, 'base64,') !== false) {
    $imageB64 = substr($imageB64, strpos($imageB64, ',') + 1);
}

$raw = base64_decode($imageB64, true);
if ($raw === false || $raw === '') {
    echo json_encode(['success' => false, 'message' => 'Invalid receipt image']);
    exit;
}

// A receipt screenshot is at most a few hundred KB — cap generously against
// accidental/abusive oversized uploads.
if (strlen($raw) > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'Receipt image too large']);
    exit;
}

$src = @imagecreatefromstring($raw);
if (!$src) {
    echo json_encode(['success' => false, 'message' => 'Could not read receipt image']);
    exit;
}

try {
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    $dstW = $dotWidth;
    $dstH = max(1, (int)round($srcH * ($dstW / $srcW)));

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Flatten onto white first — the capture may have a transparent
    // background, and thresholding against anything but a clean white
    // background could turn parts of it black.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
    imagedestroy($src);

    // Pack into ESC/POS raster format: 1 bit per pixel, MSB first, each row
    // padded to a whole byte. The threshold leans dark (200, not the
    // midpoint 128) so thin strokes and anti-aliased text edges — already
    // faint after resampling down to printer resolution — don't disappear
    // entirely on the low-resolution thermal output.
    $bytesPerRow = (int)ceil($dstW / 8);
    $raster = '';
    for ($y = 0; $y < $dstH; $y++) {
        $rowBits = array_fill(0, $bytesPerRow, 0);
        for ($x = 0; $x < $dstW; $x++) {
            $rgb = imagecolorat($dst, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $luminance = (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
            if ($luminance < 200) {
                $byteIndex = intdiv($x, 8);
                $bitIndex = 7 - ($x % 8);
                $rowBits[$byteIndex] |= (1 << $bitIndex);
            }
        }
        foreach ($rowBits as $byte) {
            $raster .= chr($byte);
        }
    }
    imagedestroy($dst);
} catch (Throwable $e) {
    error_log('Error in convert_receipt_image.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not process receipt image']);
    exit;
}

$xL = $bytesPerRow & 0xFF;
$xH = ($bytesPerRow >> 8) & 0xFF;
$yL = $dstH & 0xFF;
$yH = ($dstH >> 8) & 0xFF;

$escInit = chr(0x1B) . chr(0x40); // ESC @ — initialize printer
$escAlignCenter = chr(0x1B) . chr(0x61) . chr(0x01); // ESC a 1 — center the raster image
$rasterHeader = chr(0x1D) . chr(0x76) . chr(0x30) . chr(0x00) . chr($xL) . chr($xH) . chr($yL) . chr($yH); // GS v 0
$feed = chr(0x1B) . chr(0x64) . chr(3); // ESC d 3 — feed 3 lines
$cut = chr(0x1D) . chr(0x56) . chr(0x00); // GS V 0 — full cut

$bytes = $escInit . $escAlignCenter . $rasterHeader . $raster . $feed . $cut;

echo json_encode([
    'success' => true,
    'data' => base64_encode($bytes),
    'width' => $dstW,
    'height' => $dstH,
]);
