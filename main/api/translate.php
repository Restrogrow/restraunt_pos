<?php
header('Content-Type: application/json');

$text = $_POST['text'] ?? $_GET['text'] ?? '';
$target = $_POST['target'] ?? $_GET['target'] ?? 'hi';
$source = $_POST['source'] ?? $_GET['source'] ?? 'en';

if (!$text || !$target) {
    echo json_encode(['success' => false, 'message' => 'text and target required']);
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
        CURLOPT_SSL_VERIFYPEER => false,
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
