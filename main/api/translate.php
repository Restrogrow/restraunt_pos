<?php
/**
 * Translation proxy for menu-item auto-translation.
 *
 * SECURITY: this used to be a fully open proxy — anyone on the internet
 * could relay unlimited requests through our server to Google Translate.
 * It's now restricted to logged-in admin/staff sessions (translation only
 * runs from the menu editor, never from the public site) and rate-limited
 * per session.
 */

require_once __DIR__ . '/../config/session_config.php';
startSecureSession();

// Logged-in admin/staff only — the public website never calls this.
if (!function_exists('isSessionValid') || !isSessionValid()
    || (!isset($_SESSION['user_id']) && !isset($_SESSION['staff_id'])
        && !isset($_SESSION['branch_admin_id']) && !isset($_SESSION['superadmin_id']))) {
    http_response_code(404);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}

// Rate limit: 30 translations per minute per session — far above any real
// menu-editing session, far below what a scripted relay would push.
require_once __DIR__ . '/../config/rate_limit.php';
$rlId = 'translate_' . ($_SESSION['user_id'] ?? $_SESSION['staff_id'] ?? $_SESSION['branch_admin_id'] ?? $_SESSION['superadmin_id'] ?? 'anon');
$rl = checkRateLimit($rlId, 30, 60);
if (!$rl['allowed']) {
    http_response_code(429);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'message' => 'Too many translation requests. Please wait a moment.']);
    exit;
}

header('Content-Type: application/json');

$text = $_POST['text'] ?? $_GET['text'] ?? '';
$target = $_POST['target'] ?? $_GET['target'] ?? 'hi';
$source = $_POST['source'] ?? $_GET['source'] ?? 'en';

if (!$text || !$target) {
    echo json_encode(['success' => false, 'message' => 'text and target required']);
    exit;
}

// Bound the workload: translation is for short menu-item strings, not
// documents. Anything past 500 chars is rejected rather than relayed.
if (mb_strlen($text) > 500) {
    echo json_encode(['success' => false, 'message' => 'Text too long to translate']);
    exit;
}

$result = autoTranslate($text, $target, $source);
echo json_encode(['success' => true, 'translated' => $result]);

function autoTranslate($text, $target, $source = 'en') {
    if (empty(trim($text))) return $text;
    if ($target === $source) return $text;

    $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=' . urlencode($source) . '&tl=' . urlencode($target) . '&dt=t&q=' . urlencode($text);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return $text;

    $decoded = json_decode($response, true);
    if (is_array($decoded) && isset($decoded[0])) {
        $translated = '';
        foreach ($decoded[0] as $segment) {
            if (is_array($segment) && isset($segment[0])) {
                $translated .= $segment[0];
            }
        }
        return $translated ?: $text;
    }
    return $text;
}
