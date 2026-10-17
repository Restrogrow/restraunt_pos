<?php
/**
 * Growth › AI manager (dashboard + Partner app). Data for config/owner_manager.php.
 *
 * GET                       settings, the latest report with its actions, the last 14 reports
 *                           (also sends today's report if it's due and the cron hasn't yet)
 * POST {action:'save', enabled, send_time, language, phone}
 * POST {action:'preview'}   builds a fresh report (not sent)
 * POST {action:'send'}      builds and sends it to the owner's WhatsApp now
 * POST {action:'run', id}   runs a suggested action    POST {action:'decline', id}
 * POST {action:'ask', text} asks the manager a question (same answers as on WhatsApp)
 *
 * Owner / manager only (manage settings or view reports). Always the session's restaurant.
 */
require_once __DIR__ . '/../config/cors_helper.php';
allowAppOrigin();
require_once __DIR__ . '/../config/session_config.php';
startSecureSession();
require_once __DIR__ . '/../config/authorization_config.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (!isLoggedIn() || empty($_SESSION['restaurant_id']) || !(hasPermission(PERMISSION_MANAGE_SETTINGS) || hasPermission(PERMISSION_VIEW_REPORTS))) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in again.']);
    exit;
}
$rid = (string)$_SESSION['restaurant_id'];
$canEdit = hasPermission(PERMISSION_MANAGE_SETTINGS);
session_write_close();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/owner_manager.php';

function omOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

try {
    $conn = getConnection();
    ownerMgrEnsure($conn);
    $actionsFor = function (int $briefId) use ($conn, $rid) {
        $st = $conn->prepare('SELECT id, code, kind, label, status, result, done_at FROM om_actions WHERE restaurant_id = ? AND brief_id = ? ORDER BY code');
        $st->execute([$rid, $briefId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $sentNow = false;
        try { $sentNow = ownerMgrSendIfDue($conn, $rid); } catch (Throwable $e) { error_log('owner_manager_admin due: ' . $e->getMessage()); }
        $s = ownerMgrSettings($conn, $rid);
        $st = $conn->prepare('SELECT id, day, text, ai, sent_at, created_at FROM om_briefs WHERE restaurant_id = ? ORDER BY id DESC LIMIT 14');
        $st->execute([$rid]);
        $briefs = $st->fetchAll(PDO::FETCH_ASSOC);
        foreach ($briefs as &$b) $b['actions'] = $actionsFor((int)$b['id']);
        unset($b);
        // what has to be in place for the report to arrive: WhatsApp sending, the 15-minute job, the AI key
        $status = ['whatsapp' => false, 'whatsapp_test' => false, 'cron' => null, 'email' => false];
        try {
            require_once __DIR__ . '/../config/whatsapp_engine.php';
            $status['whatsapp'] = waConnection(waSettings($conn, $rid)) !== null;
            $status['whatsapp_test'] = waDryRun();
        } catch (Throwable $e) { /* WhatsApp not set up */ }
        try {
            $c = $conn->query("SELECT v FROM sa_settings WHERE k = 'cron_owner_brief_at'")->fetchColumn();
            $status['cron'] = $c ? (strtotime((string)$c) > time() - 3600 ? 'running' : 'stopped') : 'never';
        } catch (Throwable $e) { $status['cron'] = 'never'; }
        $status['email'] = filter_var(env('SMTP_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN) || (string)env('MAIL_SINK_DIR', '') !== '';
        // the number as WhatsApp sees it (+91 98…, +977 98…)
        $intl = '';
        try { require_once __DIR__ . '/../config/countries.php'; $intl = $s['phone'] !== '' ? (string)phoneToInternational($s['phone'], restaurantCountryInfo($conn, $rid)) : ''; } catch (Throwable $e) { $intl = ''; }
        $kpis = [];
        try { $kpis = ownerMgrKpis($conn, $rid); } catch (Throwable $e) { error_log('owner_manager_admin kpis: ' . $e->getMessage()); }
        omOut(['success' => true, 'can_edit' => $canEdit, 'ai' => aiGeminiAvailable(), 'sent_now' => $sentNow,
            'settings' => ['enabled' => $s['enabled'], 'send_time' => $s['send_time'], 'language' => $s['language'], 'phone' => $s['phone'],
                'phone_override' => $s['phone_override'], 'email_on' => $s['email_on'], 'last_sent_on' => $s['last_sent_on'], 'restaurant' => $s['restaurant'], 'tz' => $s['tz'], 'phone_intl' => $intl ?: ($s['phone'] !== '' ? '+' . $s['phone'] : '')],
            'status' => $status, 'kpis' => $kpis, 'briefs' => $briefs]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');
    if (in_array($action, ['save', 'send', 'run', 'decline'], true) && !$canEdit) omOut(['success' => false, 'message' => 'Only the owner or a manager can do this.'], 403);

    if ($action === 'save') {
        ownerMgrSaveSettings($conn, $rid, $in);
        omOut(['success' => true, 'message' => !empty($in['enabled']) ? 'Saved. The report arrives every day at ' . ($in['send_time'] ?? '09:00') . '.' : 'Saved']);
    }
    if ($action === 'preview' || $action === 'send') {
        $s = ownerMgrSettings($conn, $rid);
        if ($action === 'send' && $s['phone'] === '') omOut(['success' => false, 'message' => 'Add the WhatsApp number the report should go to.']);
        $b = ownerMgrBrief($conn, $rid, $action === 'send');
        omOut(['success' => true, 'brief' => ['id' => $b['id'], 'text' => $b['text'], 'ai' => $b['ai'], 'sent' => $b['sent'], 'actions' => $actionsFor((int)$b['id'])],
            'message' => $action === 'send' ? ($b['sent'] ? 'Sent to your WhatsApp' : 'Saved, but it could not be sent. Check the WhatsApp connection.') : '']);
    }
    if ($action === 'run') {
        $msg = ownerMgrRunAction($conn, $rid, 0, (int)($in['id'] ?? 0));
        omOut(['success' => true, 'message' => $msg]);
    }
    if ($action === 'decline') {
        $conn->prepare("UPDATE om_actions SET status = 'declined' WHERE restaurant_id = ? AND id = ? AND status = 'pending'")->execute([$rid, (int)($in['id'] ?? 0)]);
        omOut(['success' => true, 'message' => 'Skipped']);
    }
    if ($action === 'ask') {
        $t = trim(mb_substr((string)($in['text'] ?? ''), 0, 300));
        if ($t === '') omOut(['success' => false, 'message' => 'Type a question']);
        // same path as a WhatsApp question: answer in the language it was asked in (+ the spoken version in voice mode)
        omOut(['success' => true] + ownerMgrAsk($conn, $rid, $t, !empty($in['voice'])));
    }
    if ($action === 'tts') {
        // voice mode: the manager's natural voice (Gemini speech); the page falls back to the phone's own voice
        $t = trim(mb_substr((string)($in['text'] ?? ''), 0, 900));
        if ($t === '') omOut(['success' => false, 'message' => 'Nothing to say']);
        $voiceName = in_array($in['voice_name'] ?? '', ['Kore', 'Charon', 'Aoede', 'Puck'], true) ? $in['voice_name'] : 'Kore';
        $style = preg_match('/\p{Devanagari}/u', $t) ? 'in a warm, friendly, natural Hindi voice, at a relaxed pace' : 'in a warm, friendly, natural Indian voice, at a relaxed pace';
        $wav = aiGeminiSpeech($t, $voiceName, $style, $conn, $rid);
        if (!$wav) omOut(['success' => false, 'message' => 'Voice not available', 'why' => $GLOBALS['AI_SPEECH_ERROR'] ?? (aiGeminiAvailable() ? 'daily AI limit reached' : 'no GEMINI_API_KEY')]);
        omOut(['success' => true, 'audio' => 'data:audio/wav;base64,' . base64_encode($wav)]);
    }
    omOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('owner_manager_admin: ' . $e->getMessage());
    omOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
