<?php
/**
 * restrogrow.com assistant (the chat bubble on the home page; config/chatbot_store.php).
 *
 *   POST {action:'start'}                       a new chat (no details needed to ask questions)
 *   POST {action:'message', token, message}     → {reply, suggestions[], ask_contact}
 *   POST {action:'lead', token, name, restaurant_name, phone, email?, consent:true}
 *                                               the visitor shares their details (demo / call back)
 *   POST {action:'resume', token}               the chat so far (+ has_contact)
 *
 * Chats without details are saved as "Website visitor" so Super Admin › Chat leads still sees the
 * questions; they become real leads once details are shared.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const CHAT_ANON = 'Website visitor';

function chatbotOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function chatbotRateLimit(string $key, int $max, int $window): void
{
    require_once __DIR__ . '/../config/rate_limit.php';
    $result = checkRateLimit($key . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), $max, $window);
    if (!$result['allowed']) {
        header('Retry-After: ' . (int)$result['retry_after']);
        chatbotOut(['success' => false, 'message' => 'Please wait a little before trying again.'], 429);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    chatbotOut(['success' => false, 'message' => 'POST required.'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    chatbotOut(['success' => false, 'message' => 'Invalid request.'], 400);
}

try {
    require_once __DIR__ . '/../db_connection.php';
    require_once __DIR__ . '/../config/chatbot_store.php';
    $conn = getConnection();
    rgEnsureChatbotTables($conn);

    $action = (string)($input['action'] ?? '');
    if ($action === 'start') {
        chatbotRateLimit('chat_start', 12, 3600);
        $token = bin2hex(random_bytes(32));
        $conn->prepare("INSERT INTO chatbot_leads (session_token_hash, name, restaurant_name, email, phone) VALUES (?, ?, '', '', '')")
            ->execute([hash('sha256', $token), CHAT_ANON]);
        chatbotOut(['success' => true, 'token' => $token]);
    }

    $token = strtolower(trim((string)($input['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        chatbotOut(['success' => false, 'message' => 'Chat session is invalid. Please start a new chat.'], 400);
    }
    $leadStmt = $conn->prepare('SELECT id, name, phone FROM chatbot_leads WHERE session_token_hash = ?');
    $leadStmt->execute([hash('sha256', $token)]);
    $lead = $leadStmt->fetch(PDO::FETCH_ASSOC);
    if (!$lead) {
        chatbotOut(['success' => false, 'message' => 'Chat session was not found. Please start a new chat.'], 404);
    }
    $leadId = (int)$lead['id'];
    $hasContact = trim((string)$lead['phone']) !== '';

    if ($action === 'resume') {
        chatbotRateLimit('chat_resume', 30, 600);
        $stmt = $conn->prepare('SELECT sender, message, created_at FROM chatbot_messages WHERE lead_id = ? ORDER BY id ASC');
        $stmt->execute([$leadId]);
        chatbotOut(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'has_contact' => $hasContact,
            'name' => $lead['name'] !== CHAT_ANON ? $lead['name'] : '']);
    }

    if ($action === 'lead') {
        chatbotRateLimit('chat_lead', 5, 3600);
        $name = trim((string)($input['name'] ?? ''));
        $restaurant = trim((string)($input['restaurant_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phone);
        if (($input['consent'] ?? null) !== true) chatbotOut(['success' => false, 'message' => 'Please agree to be contacted.'], 400);
        if ($name === '' || mb_strlen($name) > 120) chatbotOut(['success' => false, 'message' => 'Please enter your name.'], 400);
        if (mb_strlen($restaurant) > 160) chatbotOut(['success' => false, 'message' => 'Restaurant name is too long.'], 400);
        if (!preg_match('/^[+0-9().\-\s]{7,30}$/D', $phone) || strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) chatbotOut(['success' => false, 'message' => 'Please enter a valid phone number.'], 400);
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254)) chatbotOut(['success' => false, 'message' => 'Please check the email address.'], 400);
        $conn->prepare('UPDATE chatbot_leads SET name = ?, restaurant_name = ?, email = ?, phone = ?, consent_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$name, $restaurant, $email, $phone, $leadId]);
        try {
            require_once __DIR__ . '/../config/mail_engine.php';
            $said = $conn->prepare("SELECT message FROM chatbot_messages WHERE lead_id = ? AND sender = 'visitor' ORDER BY id DESC LIMIT 4");
            $said->execute([$leadId]);
            mailNewLead($conn, 'website chatbot', ['name' => $name, 'restaurant' => $restaurant, 'phone' => $phone, 'email' => $email,
                'message' => implode("\n", array_map(fn($m) => '• ' . $m, array_reverse($said->fetchAll(PDO::FETCH_COLUMN))))]);
        } catch (Throwable $e) { error_log('chatbot lead email: ' . $e->getMessage()); }
        $first = explode(' ', $name)[0];
        $reply = "Thanks $first! 🙌 Our team will call you on **$phone** soon, usually within a few hours. Meanwhile, ask me anything else about RestroGrow.";
        $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'assistant', ?)")->execute([$leadId, $reply]);
        chatbotOut(['success' => true, 'reply' => $reply, 'suggestions' => ['What is included?', 'Is there a free trial?']]);
    }

    if ($action === 'message') {
        chatbotRateLimit('chat_message', 40, 600);
        $message = trim((string)($input['message'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($message === '' || $length > 500) {
            chatbotOut(['success' => false, 'message' => 'Messages must be between 1 and 500 characters.'], 400);
        }
        $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'visitor', ?)")->execute([$leadId, $message]);
        $r = rgChatbotSmartReply($conn, $leadId, $message, $hasContact);
        $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'assistant', ?)")->execute([$leadId, $r['reply']]);
        $conn->prepare('UPDATE chatbot_leads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?')->execute([$leadId]);
        chatbotOut(['success' => true] + $r);
    }

    chatbotOut(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Chatbot API error: ' . $e->getMessage());
    chatbotOut(['success' => false, 'message' => 'We could not save that right now. Please try again.'], 500);
}
