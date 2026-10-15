<?php

function rgEnsureChatbotTables(PDO $conn): void
{
    $conn->exec("CREATE TABLE IF NOT EXISTS chatbot_leads (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_token_hash CHAR(64) NOT NULL,
        name VARCHAR(120) NOT NULL,
        restaurant_name VARCHAR(160) NOT NULL,
        email VARCHAR(254) NOT NULL,
        phone VARCHAR(30) NOT NULL,
        consent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        status ENUM('new','contacted','qualified','closed') NOT NULL DEFAULT 'new',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_chatbot_token_hash (session_token_hash),
        KEY idx_chatbot_leads_status_updated (status, updated_at),
        KEY idx_chatbot_leads_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->exec("CREATE TABLE IF NOT EXISTS chatbot_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        lead_id BIGINT UNSIGNED NOT NULL,
        sender ENUM('visitor','assistant') NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_chatbot_messages_lead_created (lead_id, created_at, id),
        CONSTRAINT fk_chatbot_messages_lead FOREIGN KEY (lead_id) REFERENCES chatbot_leads(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function rgGeminiChatbotReply(PDO $conn, int $leadId, string $question): string
{
    require_once __DIR__ . '/env_loader.php';
    $apiKey = trim((string)env('GEMINI_API_KEY', ''));
    if ($apiKey === '') {
        throw new RuntimeException('GEMINI_API_KEY is not configured');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL is required for Gemini chatbot requests');
    }

    $model = trim((string)env('GEMINI_MODEL', 'gemini-3.5-flash'));
    if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $model)) {
        throw new RuntimeException('GEMINI_MODEL has an invalid format');
    }
    $stmt = $conn->prepare("SELECT sender, message FROM chatbot_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 13");
    $stmt->execute([$leadId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($history && end($history)['sender'] === 'visitor' && end($history)['message'] === $question) {
        array_pop($history);
    }
    if (count($history) > 12) {
        $history = array_slice($history, -12);
    }
    $contents = [];
    foreach ($history as $row) {
        $contents[] = [
            'role' => $row['sender'] === 'visitor' ? 'user' : 'model',
            'parts' => [['text' => $row['message']]]
        ];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];

    $requestBody = [
        'systemInstruction' => ['parts' => [[
            'text' => 'You are the RestroGrow website product assistant. RestroGrow is restaurant POS and management software. The website lists POS billing, order management, KOT management with real-time order sync to the kitchen, tables, staff dashboards, online ordering, payments, reservations, and sales reports. It offers a 7-day free trial with no credit card required, says setup takes 5 minutes, and has no setup fee. Answer the visitor’s specific question directly; mention the trial or contact details only when relevant. Do not invent current prices, discounts, integrations, policies, guarantees, hardware support, kitchen workflows, or other product capabilities. If a detail is not established above, say you are not sure and suggest contacting restrogrow@gmail.com or +91 63775 68749. Reply in the visitor’s language when possible. Keep replies to 2–4 short sentences in plain text, with no Markdown or bullet lists. Never ask for passwords, payment card details, or authentication codes.'
        ]]],
        'contents' => $contents,
        'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => 300, 'thinkingConfig' => ['thinkingBudget' => 0]]
    ];
    $encodedBody = json_encode($requestBody, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $encodedBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]
    ]);
    $response = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($response === false) {
        throw new RuntimeException('Gemini request failed: ' . $curlError);
    }
    if ($httpStatus < 200 || $httpStatus >= 300) {
        throw new RuntimeException('Gemini returned HTTP ' . $httpStatus);
    }
    $data = json_decode($response, true);
    $reply = trim((string)($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
    if ($reply === '') {
        throw new RuntimeException('Gemini returned an empty chatbot reply');
    }
    if (function_exists('mb_substr')) {
        $reply = mb_substr($reply, 0, 4000, 'UTF-8');
    } else {
        $reply = substr($reply, 0, 4000);
    }
    return $reply;
}
