<?php
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

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
        chatbotRateLimit('chat_lead', 5, 3600);

        $name = trim((string)($input['name'] ?? ''));
        $restaurant = trim((string)($input['restaurant_name'] ?? ''));
        $email = trim((string)($input['email'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phone);

        if (($input['consent'] ?? null) !== true) {
            chatbotOut(['success' => false, 'message' => 'Please agree to be contacted before starting the chat.'], 400);
        }
        if ($name === '' || strlen($name) > 120 || $restaurant === '' || strlen($restaurant) > 160) {
            chatbotOut(['success' => false, 'message' => 'Please enter your name and restaurant name.'], 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
            chatbotOut(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
        }
        if (!preg_match('/^[+0-9().\-\s]{7,30}$/D', $phone) || strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
            chatbotOut(['success' => false, 'message' => 'Please enter a valid phone number, including country code if needed.'], 400);
        }

        $token = bin2hex(random_bytes(32));
        $greeting = 'Thanks for reaching out! Ask me anything about RestroGrow, and our team can follow up using the details you shared.';
        $conn->beginTransaction();
        $stmt = $conn->prepare('INSERT INTO chatbot_leads (session_token_hash, name, restaurant_name, email, phone) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([hash('sha256', $token), $name, $restaurant, $email, $phone]);
        $leadId = (int)$conn->lastInsertId();
        $messageStmt = $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'assistant', ?)");
        $messageStmt->execute([$leadId, $greeting]);
        $conn->commit();

        chatbotOut(['success' => true, 'token' => $token, 'reply' => $greeting]);
    }

    $token = strtolower(trim((string)($input['token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        chatbotOut(['success' => false, 'message' => 'Chat session is invalid. Please start a new chat.'], 400);
    }
    $leadStmt = $conn->prepare('SELECT id FROM chatbot_leads WHERE session_token_hash = ?');
    $leadStmt->execute([hash('sha256', $token)]);
    $leadId = (int)$leadStmt->fetchColumn();
    if ($leadId < 1) {
        chatbotOut(['success' => false, 'message' => 'Chat session was not found. Please start a new chat.'], 404);
    }

    if ($action === 'resume') {
        chatbotRateLimit('chat_resume', 30, 600);
        $stmt = $conn->prepare('SELECT sender, message, created_at FROM chatbot_messages WHERE lead_id = ? ORDER BY id ASC');
        $stmt->execute([$leadId]);
        chatbotOut(['success' => true, 'messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($action === 'message') {
        chatbotRateLimit('chat_message', 40, 600);
        $message = trim((string)($input['message'] ?? ''));
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($message === '' || $length > 500) {
            chatbotOut(['success' => false, 'message' => 'Messages must be between 1 and 500 characters.'], 400);
        }

        $stmt = $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'visitor', ?)");
        $stmt->execute([$leadId, $message]);
        $stmt = $conn->prepare('UPDATE chatbot_leads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$leadId]);

        try {
            $reply = rgGeminiChatbotReply($conn, $leadId, $message);
        } catch (RuntimeException $e) {
            error_log('Chatbot Gemini error: ' . $e->getMessage());
            chatbotOut(['success' => false, 'message' => 'The AI assistant is temporarily unavailable. Your question has been saved; please try again or contact restrogrow@gmail.com.'], 502);
        }

        $stmt = $conn->prepare("INSERT INTO chatbot_messages (lead_id, sender, message) VALUES (?, 'assistant', ?)");
        $stmt->execute([$leadId, $reply]);
        $stmt = $conn->prepare('UPDATE chatbot_leads SET updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$leadId]);
        chatbotOut(['success' => true, 'reply' => $reply]);
    }

    chatbotOut(['success' => false, 'message' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Chatbot API error: ' . $e->getMessage());
    chatbotOut(['success' => false, 'message' => 'We could not save that right now. Please try again.'], 500);
}
