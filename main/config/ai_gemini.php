<?php
/**
 * Gemini for RestroGrow's AI features (WhatsApp ordering, the owner's AI manager).
 *
 *   aiGeminiJson($system, $user, $schema)  → array|null   structured answer (JSON schema enforced)
 *   aiGeminiText($system, $user)           → string|null  short plain text
 *
 * Never throws: on any problem (no key, network, bad answer, daily cap) it returns null and the
 * caller falls back to its non-AI path. The AI never decides money: callers only use it to turn
 * words into ids/actions, or to word facts the caller already computed.
 *
 * .env: GEMINI_API_KEY, GEMINI_MODEL (default gemini-3.5-flash), AI_DAILY_CAP (calls per restaurant
 * per day, default 600), AI_CAINFO (optional CA bundle path, for a local PC whose antivirus
 * re-signs HTTPS; never needed on the live server).
 */
require_once __DIR__ . '/env_loader.php';

if (!function_exists('aiGeminiJson')) {

    function aiGeminiAvailable(): bool
    {
        return trim((string)env('GEMINI_API_KEY', '')) !== '' && function_exists('curl_init');
    }

    /** One more call for this restaurant today, unless the daily cap is reached. */
    function aiGeminiAllow(?PDO $conn, string $rid): bool
    {
        if (!$conn || $rid === '') return true;
        try {
            $conn->exec("CREATE TABLE IF NOT EXISTS ai_usage (restaurant_id VARCHAR(20) NOT NULL, day DATE NOT NULL, calls INT NOT NULL DEFAULT 0,
                PRIMARY KEY (restaurant_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $cap = max(10, (int)env('AI_DAILY_CAP', 600));
            $st = $conn->prepare('SELECT calls FROM ai_usage WHERE restaurant_id = ? AND day = CURDATE()');
            $st->execute([$rid]);
            if ((int)$st->fetchColumn() >= $cap) return false;
            $conn->prepare('INSERT INTO ai_usage (restaurant_id, day, calls) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE calls = calls + 1')->execute([$rid]);
        } catch (Throwable $e) {
            error_log('aiGeminiAllow: ' . $e->getMessage());
        }
        return true;
    }

    function aiGeminiCall(array $body, int $timeout = 20, string $model = ''): ?array
    {
        if (!aiGeminiAvailable()) return null;
        $model = $model !== '' ? $model : trim((string)env('GEMINI_MODEL', 'gemini-3.5-flash'));
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model)) return null;
        $c = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . trim((string)env('GEMINI_API_KEY', ''))],
        ];
        $ca = trim((string)env('AI_CAINFO', ''));
        if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($c, $opts);
        $res = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        $err = curl_error($c);
        curl_close($c);
        if ($res === false || $code < 200 || $code >= 300) {
            error_log('aiGemini: HTTP ' . $code . ' ' . $err . ' ' . substr((string)$res, 0, 300));
            return null;
        }
        $d = json_decode((string)$res, true);
        $parts = $d['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $p) if (empty($p['thought']) && isset($p['text'])) $text .= $p['text'];
        return ['text' => trim($text)];
    }

    /**
     * A structured answer. $schema is a Gemini responseSchema (OpenAPI subset: type/properties/items/enum/required).
     * $history: earlier turns [['role' => 'user'|'model', 'text' => '…'], …] (oldest first).
     */
    function aiGeminiJson(string $system, string $user, array $schema, array $history = [], ?PDO $conn = null, string $rid = ''): ?array
    {
        if (!aiGeminiAvailable() || !aiGeminiAllow($conn, $rid)) return null;
        $contents = [];
        foreach (array_slice($history, -10) as $h) {
            $contents[] = ['role' => $h['role'] === 'model' ? 'model' : 'user', 'parts' => [['text' => mb_substr((string)$h['text'], 0, 1500)]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => mb_substr($user, 0, 4000)]]];
        $r = aiGeminiCall([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => $contents,
            'generationConfig' => ['temperature' => 0.1, 'maxOutputTokens' => 1500, 'responseMimeType' => 'application/json',
                'responseSchema' => $schema, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ]);
        if (!$r || $r['text'] === '') return null;
        $j = json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r['text']), true);
        return is_array($j) ? $j : null;
    }

    /**
     * Natural speech for $text with Gemini's text-to-speech model (GEMINI_TTS_MODEL, default
     * gemini-2.5-flash-preview-tts). $style is a short spoken-style instruction ("warm and friendly").
     * Returns a WAV file's bytes (24 kHz, 16-bit mono), or null on any problem (caller falls back).
     */
    function aiGeminiSpeech(string $text, string $voice = 'Kore', string $style = '', ?PDO $conn = null, string $rid = ''): ?string
    {
        if (!aiGeminiAvailable() || !aiGeminiAllow($conn, $rid)) return null;
        $model = trim((string)env('GEMINI_TTS_MODEL', 'gemini-2.5-flash-preview-tts'));
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model) || !preg_match('/^[A-Za-z]{2,20}$/', $voice)) return null;
        $prompt = ($style !== '' ? 'Say ' . $style . ': ' : '') . mb_substr($text, 0, 1200);
        $c = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent');
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]],
                'generationConfig' => ['responseModalities' => ['AUDIO'], 'speechConfig' => ['voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voice]]]]], JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . trim((string)env('GEMINI_API_KEY', ''))],
        ];
        $ca = trim((string)env('AI_CAINFO', ''));
        if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($c, $opts);
        $res = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        if ($res === false || $code < 200 || $code >= 300) {
            // why it failed, for the AI manager page (e.g. "HTTP 429 quota", "HTTP 404 model")
            $GLOBALS['AI_SPEECH_ERROR'] = 'HTTP ' . $code . ' ' . mb_substr((string)(json_decode((string)$res, true)['error']['message'] ?? curl_error($c) ?? ''), 0, 160);
            error_log('aiGeminiSpeech: ' . $GLOBALS['AI_SPEECH_ERROR']);
            return null;
        }
        curl_close($c);
        $d = json_decode((string)$res, true);
        $inline = null;
        foreach ($d['candidates'][0]['content']['parts'] ?? [] as $p) if (!empty($p['inlineData']['data'])) { $inline = $p['inlineData']; break; }
        if (!$inline) return null;
        $pcm = base64_decode($inline['data']);
        if ($pcm === false || $pcm === '') return null;
        $rate = preg_match('/rate=(\d+)/', (string)($inline['mimeType'] ?? ''), $m) ? (int)$m[1] : 24000;
        // raw 16-bit PCM → a WAV file every browser can play
        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($pcm)) . $pcm;
    }

    /**
     * Speech to text: what is said in a short recording ($audio = file bytes, e.g. a 16 kHz WAV from the page).
     * $lang 'hi-IN' writes Hindi in Devanagari, 'en-IN' in English letters. $words: names it may hear (dishes), spelt
     * as given. '' when nothing was said; null on a problem.
     */
    function aiGeminiTranscribe(string $audio, string $mime = 'audio/wav', string $lang = 'hi-IN', ?PDO $conn = null, string $rid = '', array $words = []): ?string
    {
        if ($audio === '' || !aiGeminiAvailable()) return null;
        // a 16-bit WAV with no voice in it: Gemini invents words for silence, so don't send it
        if ($mime === 'audio/wav' && substr($audio, 0, 4) === 'RIFF' && ($p = strpos($audio, 'data')) !== false) {
            $smp = unpack('v*', substr($audio, $p + 8, 2 * 200000)) ?: [];
            $loud = 0;
            foreach ($smp as $v) { $v = $v >= 32768 ? $v - 65536 : $v; if ($v > 1500 || $v < -1500) $loud++; }
            if ($loud < 800) return '';
        }
        if (!aiGeminiAllow($conn, $rid)) return null;
        $model = trim((string)env('GEMINI_STT_MODEL', ''));
        $how = $lang === 'en-IN'
            ? 'Write it in English letters (Hindi words in Roman script, the way people type Hinglish).'
            : 'Write Hindi words in Devanagari script and English words (dish names, "sales", "report"…) in English letters, the way the speaker would type it.';
        $r = aiGeminiCall([
            'contents' => [['role' => 'user', 'parts' => [
                ['inlineData' => ['mimeType' => $mime, 'data' => base64_encode($audio)]],
                ['text' => "Transcribe exactly what the speaker says in this recording. They are a restaurant owner talking to their business assistant, in Hindi, Hinglish or English (Indian accent). $how Write numbers as digits." . ($words ? ' Names they may say (write them exactly like this): ' . mb_substr(implode(', ', $words), 0, 2500) . '.' : '') . " Reply with only the words said, nothing else. If nobody clearly speaks (silence, noise, music), reply exactly NONE."],
            ]]],
            'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 200, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ], 20, $model);
        if (!$r) return null;
        $t = trim(preg_replace('/\s+/u', ' ', (string)$r['text']), " \t\n\r\"'");
        // "(silence)", "[noise]" and the like mean nothing was said
        if (preg_match('/^[\[(].*[\])]$/u', $t) || mb_strlen($t) < 2 || stripos($t, 'NONE') === 0) return '';
        return mb_substr($t, 0, 300);
    }

    /** Google Cloud Text-to-Speech is set up (GOOGLE_TTS_API_KEY): fast, natural Indian English and Hindi voices. */
    function aiCloudSpeechAvailable(): bool
    {
        return trim((string)env('GOOGLE_TTS_API_KEY', '')) !== '' && function_exists('curl_init');
    }

    /**
     * Natural speech in well under a second with Google Cloud Text-to-Speech (needs GOOGLE_TTS_API_KEY: a Google
     * Cloud API key allowed for the Cloud Text-to-Speech API). $male picks the male voice. Returns MP3 bytes or null.
     */
    function aiCloudSpeech(string $text, bool $male = false): ?string
    {
        if (!aiCloudSpeechAvailable()) return null;
        $hindi = (bool)preg_match('/\p{Devanagari}/u', $text);
        $lang = $hindi ? 'hi-IN' : 'en-IN';
        $name = env($hindi ? ($male ? 'GOOGLE_TTS_VOICE_HI_MALE' : 'GOOGLE_TTS_VOICE_HI') : ($male ? 'GOOGLE_TTS_VOICE_EN_MALE' : 'GOOGLE_TTS_VOICE_EN'), '')
            ?: $lang . '-Neural2-' . ($male ? 'B' : 'A');
        $c = curl_init('https://texttospeech.googleapis.com/v1/text:synthesize?key=' . rawurlencode(trim((string)env('GOOGLE_TTS_API_KEY', ''))));
        $opts = [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['input' => ['text' => mb_substr($text, 0, 1500)], 'voice' => ['languageCode' => $lang, 'name' => $name],
                'audioConfig' => ['audioEncoding' => 'MP3', 'speakingRate' => 1.05]], JSON_UNESCAPED_UNICODE),
        ];
        $ca = trim((string)env('AI_CAINFO', ''));
        if ($ca !== '' && is_file($ca)) $opts[CURLOPT_CAINFO] = $ca;
        curl_setopt_array($c, $opts);
        $res = curl_exec($c);
        $code = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        $d = json_decode((string)$res, true);
        if ($code < 200 || $code >= 300 || empty($d['audioContent'])) {
            $GLOBALS['AI_SPEECH_ERROR'] = 'Cloud TTS HTTP ' . $code . ' ' . mb_substr((string)($d['error']['message'] ?? ''), 0, 160);
            error_log('aiCloudSpeech: ' . $GLOBALS['AI_SPEECH_ERROR']);
            return null;
        }
        $mp3 = base64_decode($d['audioContent']);
        return $mp3 ?: null;
    }

    function aiGeminiText(string $system, string $user, ?PDO $conn = null, string $rid = '', int $maxTokens = 700): ?string
    {
        if (!aiGeminiAvailable() || !aiGeminiAllow($conn, $rid)) return null;
        $r = aiGeminiCall([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => mb_substr($user, 0, 8000)]]]],
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => $maxTokens, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ], 30);
        return $r && $r['text'] !== '' ? mb_substr($r['text'], 0, 4000) : null;
    }
}
