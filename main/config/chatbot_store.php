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

/**
 * What the restrogrow.com assistant knows. Kept to what the public website says (pricing section, trial)
 * and features that are built (docs/FEATURES.md). Update this when prices or plans change.
 */
function rgChatbotKnowledge(): string
{
    return <<<TXT
RestroGrow is an all-in-one restaurant system made in India: POS billing, the restaurant's own website with online ordering, kitchen screens, loyalty, WhatsApp and Android apps. Works on any phone, tablet or computer; nothing to install for the website and POS.

PRICING (from the website, per month): Small Restaurant ₹2,999 (1–5 tables: POS, KOT, online ordering, basic reports, email support). Growing Restaurant ₹4,999 (6–20 tables, most popular: adds staff management, advanced analytics, table reservations, priority support, custom training). Multiple Locations ₹9,999 (2+ restaurants: multi-location, central reports, dedicated account manager, phone + WhatsApp support, custom features). 7-day free trial, no credit card, setup takes about 5 minutes, no setup fee. For yearly plans, discounts or a custom quote: talk to the team.

FEATURES:
- POS & orders: fast billing, dine-in/takeaway/delivery, KOT to the kitchen, hold and resume bills, split payments, discounts, add-ons, combos, GST bills on thermal printers (58/80mm, Bluetooth or network), works offline and syncs later.
- Kitchen & staff: kitchen display with timers and sounds, waiter dashboard, manager dashboard, staff logins with roles, waiter calls from the table ("call waiter" button on the restaurant website).
- Tables: table layout with areas, table QR codes for ordering, reservations.
- Restaurant website: each restaurant gets its own ordering website (custom domain possible) with menu photos, cart, online payment (PhonePe/UPI) or cash, order tracking, Google-friendly pages (SEO).
- Customers & loyalty: customer list, points / cashback / stamp cards, wallet, VIP membership, automatic birthday and "we miss you" gifts, scratch card games, referral rewards.
- Marketing & WhatsApp: WhatsApp messages and campaigns, posters and social post designer, campaign calendar.
- NEW WhatsApp ordering with AI: customers order by chatting on WhatsApp in English, Hindi or Hinglish; the order goes straight to the kitchen; status updates in the chat. No commission.
- NEW AI manager: every morning the owner gets a WhatsApp report (yesterday's sales, what changed, low stock) with one-tap fixes, and can ask "aaj kitna bika?".
- Reports & analytics: sales, dishes, payments, staff, GST export.
- Inventory: stock, recipes, low-stock alerts, purchase orders, auto sold-out.
- Apps: Partner app for owners (Android), staff app, works on tablets.
- Swiggy/Zomato order sync: not available yet (planned).

CONTACT: email restrogrow@gmail.com, phone/WhatsApp +91 63775 68749. To try it: start the free trial on the website (Start Free Trial button) or ask for a free demo call.
TXT;
}

/** Common questions without the AI (no key, or the AI is down): never leave a visitor with an error. */
function rgChatbotFallback(string $q): array
{
    $t = mb_strtolower($q);
    $sug = ['What does it cost?', 'Is there a free trial?', 'Book a demo'];
    if (preg_match('/price|pricing|cost|charge|fee|plan|kitna|rate|₹|rs\b|paise/u', $t))
        return ['reply' => "Plans are per month: **Small Restaurant ₹2,999** (1–5 tables), **Growing Restaurant ₹4,999** (6–20 tables, most popular) and **Multiple Locations ₹9,999** (2+ outlets). Every plan starts with a **7-day free trial**, no card needed.", 'suggestions' => ['What is included?', 'Book a demo', 'Is there a free trial?'], 'ask_contact' => true];
    if (preg_match('/trial|free|try/u', $t))
        return ['reply' => "Yes! You get a **7-day free trial**, no credit card needed, and setup takes about 5 minutes. Tap **Start Free Trial** at the top of the page.", 'suggestions' => ['What does it cost?', 'What is included?', 'Book a demo'], 'ask_contact' => false];
    if (preg_match('/demo|call|meet|talk|contact|sales|number|phone/u', $t))
        return ['reply' => "Happy to show you around! Leave your number here and our team will call you, or reach us at **+91 63775 68749** (call/WhatsApp) or restrogrow@gmail.com.", 'suggestions' => ['What does it cost?', 'What is included?'], 'ask_contact' => true];
    if (preg_match('/whatsapp/u', $t))
        return ['reply' => "RestroGrow works with WhatsApp: customers can **order by chatting** (English, Hindi or Hinglish) and the order goes straight to your kitchen, plus order updates, campaigns and loyalty gifts on WhatsApp.", 'suggestions' => ['What does it cost?', 'Book a demo'], 'ask_contact' => false];
    if (preg_match('/swiggy|zomato/u', $t))
        return ['reply' => "Swiggy and Zomato order sync isn't available yet, it's planned. Meanwhile your own website and WhatsApp ordering take orders with **no commission**.", 'suggestions' => ['WhatsApp ordering?', 'What does it cost?'], 'ask_contact' => false];
    return ['reply' => "RestroGrow is an all-in-one system for restaurants: **POS billing, your own ordering website, kitchen screens, loyalty and WhatsApp**, on any phone, tablet or computer. What would you like to know?", 'suggestions' => $sug, 'ask_contact' => false];
}

/**
 * The assistant's answer: ['reply' => text (light markdown: **bold**, - lists), 'suggestions' => up to 3
 * follow-up questions, 'ask_contact' => true when it's a good moment to offer a call / demo].
 * Gemini through config/ai_gemini.php; falls back to rgChatbotFallback().
 */
function rgChatbotSmartReply(PDO $conn, int $leadId, string $question, bool $hasContact): array
{
    require_once __DIR__ . '/ai_gemini.php';
    $stmt = $conn->prepare('SELECT sender, message FROM chatbot_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 13');
    $stmt->execute([$leadId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($history && end($history)['sender'] === 'visitor' && end($history)['message'] === $question) array_pop($history);
    $turns = array_map(fn($r) => ['role' => $r['sender'] === 'visitor' ? 'user' : 'model', 'text' => $r['message']], array_slice($history, -12));

    $system = "You are RestroGrow's friendly product expert on restrogrow.com, chatting with restaurant owners in India.\n"
        . "Answer from the KNOWLEDGE only. Never invent prices, discounts, integrations or features; if something isn't there, say you'll check with the team.\n"
        . "Style: warm, short and clear: 1–3 short sentences, or a short '- ' list when listing features. Use **bold** for key facts (prices, trial). No headings, at most one emoji. "
        . "Reply in the visitor's language (Hindi / Hinglish if they write that way).\n"
        . "suggestions: 2–3 short follow-up questions the visitor might tap next (max 32 characters each, in their language).\n"
        . "ask_contact: true only when they show buying interest (price for their case, demo, setup, 'how do I start', talking to someone)"
        . ($hasContact ? ", but they already shared their contact details, so set it false and say the team will reach out." : ".") . "\n"
        . "Never ask for passwords, card details or OTPs.\n\nKNOWLEDGE:\n" . rgChatbotKnowledge();
    $schema = ['type' => 'OBJECT', 'properties' => [
        'reply' => ['type' => 'STRING'],
        'suggestions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'ask_contact' => ['type' => 'BOOLEAN'],
    ], 'required' => ['reply', 'suggestions', 'ask_contact']];
    $r = aiGeminiJson($system, $question, $schema, $turns, $conn, 'chatbot');
    if (!$r || trim((string)($r['reply'] ?? '')) === '') {
        $f = rgChatbotFallback($question);
        if ($hasContact) $f['ask_contact'] = false;
        return $f;
    }
    $sug = array_values(array_filter(array_map(fn($s) => mb_substr(trim((string)$s), 0, 40), (array)($r['suggestions'] ?? []))));
    return ['reply' => mb_substr(trim((string)$r['reply']), 0, 1500), 'suggestions' => array_slice($sug, 0, 3), 'ask_contact' => !$hasContact && !empty($r['ask_contact'])];
}
