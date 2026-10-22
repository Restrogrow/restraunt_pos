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

    // voice agent lines are saved with the chat, marked 'voice' (added here: the live database may not have it yet)
    if (!$conn->query("SHOW COLUMNS FROM chatbot_messages LIKE 'channel'")->fetch()) {
        $conn->exec("ALTER TABLE chatbot_messages ADD COLUMN channel ENUM('chat','voice') NOT NULL DEFAULT 'chat' AFTER message");
    }
}

/**
 * The voice agent (talk button in the chat): a friendly salesperson on a call. Replies are spoken aloud, so
 * plain short sentences. When the caller says their name + number and agrees to a call back, 'contact' is
 * filled and api/chatbot.php saves them as a lead.
 * Returns ['reply' => text, 'contact' => ['name','phone','restaurant','agreed'] | null].
 */
function rgChatbotVoiceReply(PDO $conn, int $leadId, string $said, bool $hasContact, string $lang): array
{
    require_once __DIR__ . '/ai_gemini.php';
    $stmt = $conn->prepare('SELECT sender, message FROM chatbot_messages WHERE lead_id = ? ORDER BY id DESC LIMIT 17');
    $stmt->execute([$leadId]);
    $history = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    if ($history && end($history)['sender'] === 'visitor' && end($history)['message'] === $said) array_pop($history);
    $turns = array_map(fn($r) => ['role' => $r['sender'] === 'visitor' ? 'user' : 'model', 'text' => $r['message']], array_slice($history, -16));

    $hindi = $lang === 'hi';
    $system = "You are Riya, RestroGrow's sales consultant, talking to a restaurant owner on a live voice call from restrogrow.com. Your goal: understand their restaurant, show how RestroGrow helps them earn more and work less, and get them to start the free trial or book a free demo call with the team.\n"
        . "HOW TO SELL: be warm, confident and genuinely helpful, never pushy. Early on ask about their restaurant (type, how many tables or outlets, what they use now, what bothers them most). Link 1–2 features to what they said, with a concrete benefit (no commission on own orders, faster billing, repeat customers through loyalty and WhatsApp). Handle doubts honestly (price: compare with commission they pay apps; 'too complicated': setup takes 5 minutes, team helps for free). Close with one clear next step: the 7-day free trial or a free demo call.\n"
        . ($hasContact
            ? "They already shared their contact details: the team will call them. Don't ask again.\n"
            : "To book a demo or call back, ask for their name and mobile number (and restaurant name), read the number back to confirm, and ask if the team may call them on it. Only when they clearly say yes, fill contact with agreed = true.\n")
        . "THIS IS SPOKEN ALOUD: reply in 1–3 short, natural spoken sentences (under 60 words), then usually one short question. No markdown, no bullets, no emoji, no links. Say prices like 'two thousand nine hundred ninety nine rupees a month'. Read phone numbers digit by digit.\n"
        . ($hindi ? "Speak in natural Hindi written in Devanagari script (common English words like POS, WhatsApp, online order are fine).\n" : "Speak in simple Indian English; if the caller speaks Hinglish, reply in Hinglish written in Latin letters.\n")
        . "Answer from the KNOWLEDGE only. Never invent prices, discounts, integrations or features; if unsure, say the team will confirm on the demo call. Never ask for passwords, card details or OTPs.\n\nKNOWLEDGE:\n" . rgChatbotKnowledge();
    $schema = ['type' => 'OBJECT', 'properties' => [
        'reply' => ['type' => 'STRING'],
        'contact' => ['type' => 'OBJECT', 'nullable' => true, 'properties' => [
            'name' => ['type' => 'STRING'], 'phone' => ['type' => 'STRING'], 'restaurant' => ['type' => 'STRING'], 'agreed' => ['type' => 'BOOLEAN'],
        ]],
    ], 'required' => ['reply']];
    $r = aiGeminiJson($system, $said, $schema, $turns, $conn, 'chatbot');
    $reply = trim((string)($r['reply'] ?? ''));
    if ($reply === '') {
        $reply = $hindi
            ? 'माफ़ कीजिए, अभी मैं ठीक से जवाब नहीं दे पा रही। आप हमारी टीम को प्लस नौ एक, छह तीन सात सात पाँच, छह आठ सात चार नौ पर कॉल कर सकते हैं।'
            : 'Sorry, I could not get that just now. You can call our team on plus nine one, six three seven seven five, six eight seven four nine.';
        return ['reply' => $reply, 'contact' => null];
    }
    $c = is_array($r['contact'] ?? null) ? $r['contact'] : null;
    return ['reply' => mb_substr($reply, 0, 1200), 'contact' => $c && !empty($c['agreed']) && !$hasContact ? $c : null];
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
            'text' => 'You are the RestroGrow website product assistant. RestroGrow is restaurant POS and management software. The website lists POS billing, order management, KOT management with real-time order sync to the kitchen, tables, staff dashboards, online ordering, payments, reservations, and sales reports. It offers a 7-day free trial with no credit card required, says setup takes 5 minutes, and has no setup fee. Answer the visitor’s specific question directly; mention the trial or contact details only when relevant. Do not invent current prices, discounts, integrations, policies, guarantees, hardware support, kitchen workflows, or other product capabilities. If a detail is not established above, say you are not sure and suggest contacting support@restrogrow.com or +91 63775 68749. Reply in the visitor’s language when possible. Keep replies to 2–4 short sentences in plain text, with no Markdown or bullet lists. Never ask for passwords, payment card details, or authentication codes.'
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
/** The free trial length the website shows (Super Admin › Settings › trial days; config/onboarding.php). */
function rgChatbotTrialDays(): int
{
    static $d = null;
    if ($d === null) {
        try { require_once __DIR__ . '/onboarding.php'; $d = obTrialDays(getConnection()); } catch (Throwable $e) { $d = 7; }
    }
    return $d;
}

function rgChatbotKnowledge(): string
{
    $trial = rgChatbotTrialDays();
    return <<<TXT
RestroGrow is an all-in-one restaurant system made in India: POS billing, the restaurant's own website with online ordering, kitchen screens, loyalty, WhatsApp and Android apps. Works on any phone, tablet or computer; nothing to install for the website and POS.

PRICING (from the website, per month): Small Restaurant ₹2,999 (1–5 tables: POS, KOT, online ordering, basic reports, email support). Growing Restaurant ₹4,999 (6–20 tables, most popular: adds staff management, advanced analytics, table reservations, priority support, custom training). Multiple Locations ₹9,999 (2+ restaurants: multi-location, central reports, dedicated account manager, phone + WhatsApp support, custom features). {$trial}-day free trial, no credit card, setup takes about 5 minutes, no setup fee. For yearly plans, discounts or a custom quote: talk to the team.

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

CONTACT: email support@restrogrow.com, phone/WhatsApp +91 63775 68749. To try it: start the free trial on the website (Start Free Trial button) or ask for a free demo call.
TXT;
}

/** Common questions without the AI (no key, or the AI is down): never leave a visitor with an error. */
function rgChatbotFallback(string $q): array
{
    $t = mb_strtolower($q);
    $trial = rgChatbotTrialDays();
    $sug = ['What does it cost?', 'Show me the app', 'Book a demo'];
    if (preg_match('/price|pricing|cost|charge|fee|plan|kitna|rate|₹|rs\b|paise/u', $t))
        return ['reply' => "Plans are per month: **Small Restaurant ₹2,999** (1–5 tables), **Growing Restaurant ₹4,999** (6–20 tables, most popular) and **Multiple Locations ₹9,999** (2+ outlets). Every plan starts with a **$trial-day free trial**, no card needed.", 'suggestions' => ['What is included?', 'Show me the app', 'Book a demo'], 'ask_contact' => false, 'show' => 'plans'];
    if (preg_match('/trial|free|try|start|sign ?up/u', $t))
        return ['reply' => "Yes! You get a **$trial-day free trial**, no credit card needed, and setup takes about 5 minutes.", 'suggestions' => ['What does it cost?', 'Show me the app', 'Book a demo'], 'ask_contact' => false, 'show' => 'trial'];
    if (preg_match('/demo|call|meet|talk|contact|sales|number|phone|baat/u', $t))
        return ['reply' => "Happy to show you around! Pick what suits you: we call you, or you WhatsApp or call us right now.", 'suggestions' => ['What does it cost?', 'Show me the app'], 'ask_contact' => false, 'show' => 'demo'];
    if (preg_match('/look|see|show|screen|photo|picture|dikha|app\b/u', $t))
        return ['reply' => "Here's how RestroGrow looks. Swipe through, and tap a picture to see it big.", 'suggestions' => ['What does it cost?', 'Book a demo'], 'ask_contact' => false, 'show' => 'screens', 'screens' => ['dashboard', 'website', 'loyalty', 'reports']];
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
        . "show: a card the chat draws under your reply, with buttons and pictures, so keep the reply short when you pick one:\n"
        . "- plans: they ask about price / plans / cost (shows the 3 plans with a free-trial button; don't list all prices again in the text)\n"
        . "- trial: they want to start, sign up or try it\n"
        . "- demo: buying interest: a demo, a call, talking to someone, setup help, price for their own case"
        . ($hasContact ? " (they already gave their number: the card only shows WhatsApp / call buttons; say the team will call them)" : " (the card has a 'Call me back' button)") . "\n"
        . "- screens: they ask what it looks like, to see the app, or about a feature that has a screen; also set screens (up to 3 of: dashboard = POS & dashboard, website = the restaurant's ordering website, loyalty = loyalty & rewards, customers = customer list, offers = coupons, reports = sales reports, feedback = reviews)\n"
        . "- none: anything else\n"
        . "visitor_name / visitor_restaurant: only if the visitor tells their own name or restaurant name in THIS message, else empty.\n"
        . ($hasContact ? "They ALREADY gave their phone number: never ask for it again or for a call-back request; just say the team will call them.\n"
            : "If they type a phone number, the chat saves it for a call back by itself.\n")
        . "Never ask for passwords, card details or OTPs.\n\nKNOWLEDGE:\n" . rgChatbotKnowledge();
    $schema = ['type' => 'OBJECT', 'properties' => [
        'reply' => ['type' => 'STRING'],
        'suggestions' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        'show' => ['type' => 'STRING', 'enum' => RG_CHAT_CARDS],
        'screens' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => array_keys(RG_CHAT_SCREENS)]],
        'visitor_name' => ['type' => 'STRING'],
        'visitor_restaurant' => ['type' => 'STRING'],
    ], 'required' => ['reply', 'suggestions', 'show']];
    $r = aiGeminiJson($system, $question, $schema, $turns, $conn, 'chatbot');
    if (!$r || trim((string)($r['reply'] ?? '')) === '') return rgChatbotCard(rgChatbotFallback($question), $hasContact);
    $sug = array_values(array_filter(array_map(fn($s) => mb_substr(trim((string)$s), 0, 40), (array)($r['suggestions'] ?? []))));
    return rgChatbotCard(['reply' => mb_substr(trim((string)$r['reply']), 0, 1500), 'suggestions' => array_slice($sug, 0, 3),
        'show' => (string)($r['show'] ?? 'none'), 'screens' => (array)($r['screens'] ?? []),
        'visitor_name' => mb_substr(trim((string)($r['visitor_name'] ?? '')), 0, 120), 'visitor_restaurant' => mb_substr(trim((string)($r['visitor_restaurant'] ?? '')), 0, 160)], $hasContact);
}

/** Cards the chat can draw under a reply (index.html) and the product screenshots they can show (assets/images/chat/). */
const RG_CHAT_CARDS = ['none', 'plans', 'trial', 'demo', 'screens'];
const RG_CHAT_SCREENS = ['dashboard' => 'POS & dashboard', 'website' => 'Your ordering website', 'loyalty' => 'Loyalty & rewards', 'customers' => 'Customer list',
    'offers' => 'Coupons & offers', 'reports' => 'Sales reports', 'feedback' => 'Reviews & feedback'];

/** Clean up a reply's card: a known card, real screens (a few by default), ask_contact kept for older pages. */
function rgChatbotCard(array $r, bool $hasContact): array
{
    $show = in_array($r['show'] ?? '', RG_CHAT_CARDS, true) ? $r['show'] : 'none';
    $screens = array_values(array_unique(array_filter((array)($r['screens'] ?? []), fn($s) => isset(RG_CHAT_SCREENS[$s]))));
    if ($show === 'screens' && !$screens) $screens = ['dashboard', 'website', 'loyalty'];
    $r['show'] = $show;
    $r['screens'] = $show === 'screens' ? array_slice($screens, 0, 4) : [];
    $r['ask_contact'] = $show === 'demo' && !$hasContact;
    $r['trial_days'] = rgChatbotTrialDays();
    return $r;
}

/** A phone number typed in the chat ("call me on 98765 43210"): the number as typed, or '' (10–15 digits). */
function rgChatbotFindPhone(string $text): string
{
    if (!preg_match_all('/\+?\d[\d\s\-().]{7,20}\d/u', $text, $m)) return '';
    foreach ($m[0] as $cand) {
        $digits = preg_replace('/\D+/', '', $cand);
        if (strlen($digits) >= 10 && strlen($digits) <= 15) return trim($cand);
    }
    return '';
}

/** "I'm Rahul from Spice Hut, 98765 43210" → ['Rahul', 'Spice Hut'] (only the clear ways of saying it). */
function rgChatbotFindName(string $text): array
{
    $name = preg_match('/\b(?:i am|i\'m|im|my name is|name is|this is|mera naam|naam)\s+([a-z][a-z.\' ]{1,40}?)(?=\s*(?:,|\.|from|and|here|hai|\d|$))/iu', $text, $m) ? trim($m[1]) : '';
    $rest = preg_match('/\b(?:from|restaurant(?: name)?(?: is)?|my restaurant is|hotel|cafe)\s+([a-z0-9][a-z0-9&\'. ]{1,60}?)(?=\s*(?:,|\.|and|\d|$))/iu', $text, $m) ? trim($m[1]) : '';
    return [mb_convert_case($name, MB_CASE_TITLE, 'UTF-8'), $rest];
}
