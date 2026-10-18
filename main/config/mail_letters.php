<?php
/**
 * Proposals & letters (Super Admin › Email, first tab): emails you write and send by hand to a restaurant —
 * proposals, the service agreement, follow-ups, welcome, payment reminders. Each one is a template you can
 * change; {{words}} in braces become boxes you fill in before sending.
 *
 * Template text (simple, no HTML):
 *   a blank line          new paragraph          ## Heading      a heading
 *   - item                bullet list            **bold**        bold
 *   1. item               numbered list          [text](https://…)  link
 *   ---                   a divider line
 * Saved in sa_letters; the starter templates (letterDefaults) are added once and can be changed or deleted.
 */
require_once __DIR__ . '/mail_engine.php';

if (!function_exists('letterEnsure')) {

    function letterEnsure(PDO $conn): void {
        static $done = false;
        if ($done) return;
        $done = true;
        if ($conn->inTransaction()) return;   // CREATE TABLE would commit someone's open transaction
        if (!$conn->query("SHOW TABLES LIKE 'sa_letters'")->fetchColumn()) {
            $conn->exec("CREATE TABLE IF NOT EXISTS sa_letters (
                id INT AUTO_INCREMENT PRIMARY KEY,
                seed VARCHAR(40) NULL UNIQUE,
                category VARCHAR(40) NOT NULL DEFAULT 'Other',
                name VARCHAR(120) NOT NULL,
                subject VARCHAR(200) NOT NULL,
                title VARCHAR(200) NOT NULL DEFAULT '',
                body MEDIUMTEXT NOT NULL,
                button_text VARCHAR(60) NULL,
                button_url VARCHAR(300) NULL,
                sort INT NOT NULL DEFAULT 100,
                sent_count INT NOT NULL DEFAULT 0,
                last_sent_at DATETIME NULL,
                deleted_at DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // starter templates: added once (a deleted one keeps its row, so it never comes back by itself)
        $have = array_flip($conn->query('SELECT seed FROM sa_letters WHERE seed IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN));
        $ins = $conn->prepare('INSERT INTO sa_letters (seed, category, name, subject, title, body, button_text, button_url, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (letterDefaults() as $i => $d) {
            if (isset($have[$d['seed']])) continue;
            $ins->execute([$d['seed'], $d['category'], $d['name'], $d['subject'], $d['title'], $d['body'], $d['button_text'] ?? null, $d['button_url'] ?? null, ($i + 1) * 10]);
        }
    }

    function letterCategories(): array {
        return ['Proposal', 'Agreement', 'Follow-up', 'Onboarding', 'Billing', 'Other'];
    }

    /** Values every letter can use without typing: today's date, your name and phone, plan prices from Super Admin › Settings. */
    function letterAutoValues(PDO $conn): array {
        $s = [];
        try { require_once __DIR__ . '/platform_engine.php'; $s = pfSettings($conn); } catch (Throwable $e) {}
        $fmt = fn($n) => $n !== '' && $n !== null ? '₹' . number_format((float)$n) : '';
        return [
            'date'         => date('d M Y'),
            'my_name'      => 'RestroGrow Team',
            'my_phone'     => '+91 63775 68749',
            'my_email'     => (string)(env('MAIL_FROM_SALES', '') ?: SMTP_FROM_EMAIL),
            'price'        => $fmt($s['plan_price'] ?? '') . ' / month',
            'price_yearly' => $fmt($s['plan_price_yearly'] ?? '') . ' / year',
            'trial_days'   => (string)($s['trial_days'] ?? '30'),
            'website'      => 'restrogrow.com',
        ];
    }

    /** The {{words}} a template uses, in order of first use. */
    function letterFields(array $t): array {
        preg_match_all('/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i', $t['subject'] . "\n" . $t['title'] . "\n" . $t['body'] . "\n" . ($t['button_url'] ?? '') . "\n" . ($t['button_text'] ?? ''), $m);
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    function letterFill(string $s, array $vals): string {
        return preg_replace_callback('/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i', function ($m) use ($vals) {
            $k = strtolower($m[1]);
            return isset($vals[$k]) && trim((string)$vals[$k]) !== '' ? (string)$vals[$k] : $m[0];
        }, $s);
    }

    /** Inline formatting on already-escaped text: **bold**, [text](url). */
    function letterInline(string $esc): string {
        $esc = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $esc);
        return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+|mailto:[^\s)]+)\)/', fn($m) => '<a href="' . $m[2] . '" style="color:#c1440e">' . $m[1] . '</a>', $esc);
    }

    /** The simple text format → email HTML. Unfilled {{words}} are highlighted (preview only; sending stops on them). */
    function letterBodyHtml(string $body): string {
        $out = '';
        $blocks = preg_split('/\n\s*\n/', str_replace("\r", '', trim($body)));
        foreach ($blocks as $b) {
            $lines = array_values(array_filter(array_map('rtrim', explode("\n", $b)), fn($l) => trim($l) !== ''));
            if (!$lines) continue;
            $esc = fn($s) => letterInline(htmlspecialchars($s, ENT_QUOTES, 'UTF-8'));
            if (count($lines) === 1 && trim($lines[0]) === '---') { $out .= '<hr style="border:0;border-top:1px solid #f0e6d8;margin:18px 0">'; continue; }
            $i = 0;
            $para = [];
            $flush = function () use (&$para, &$out, $esc) {
                if ($para) $out .= '<p style="margin:0 0 14px">' . implode('<br>', array_map($esc, $para)) . '</p>';
                $para = [];
            };
            while ($i < count($lines)) {
                $l = trim($lines[$i]);
                if (preg_match('/^#{1,3}\s+(.+)$/', $l, $m)) { $flush(); $out .= '<h2 style="margin:20px 0 8px;font-size:17px;color:#241d16">' . $esc($m[1]) . '</h2>'; $i++; continue; }
                if (preg_match('/^(-|\*|\d+[.)])\s+/', $l)) {
                    $flush();
                    $ol = (bool)preg_match('/^\d/', $l);
                    $items = '';
                    while ($i < count($lines) && preg_match('/^(-|\*|\d+[.)])\s+(.+)$/', trim($lines[$i]), $m)) { $items .= '<li style="margin:0 0 6px">' . $esc($m[2]) . '</li>'; $i++; }
                    $out .= '<' . ($ol ? 'ol' : 'ul') . ' style="margin:0 0 14px;padding-left:22px">' . $items . '</' . ($ol ? 'ol' : 'ul') . '>';
                    continue;
                }
                if ($l === '---') { $flush(); $out .= '<hr style="border:0;border-top:1px solid #f0e6d8;margin:18px 0">'; $i++; continue; }
                $para[] = $lines[$i];
                $i++;
            }
            $flush();
        }
        return preg_replace('/\{\{\s*([a-z0-9_]{1,40})\s*\}\}/i', '<mark style="background:#fde68a;border-radius:4px;padding:0 3px">{{$1}}</mark>', $out);
    }

    /** Plain text (for WhatsApp / copy). */
    function letterBodyText(string $body): string {
        $t = str_replace("\r", '', trim($body));
        $t = preg_replace('/^#{1,3}\s+(.+)$/m', '*$1*', $t);
        $t = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $t);
        $t = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1: $2', $t);
        return preg_replace('/^---$/m', '────────', $t);
    }

    /** Fill and render a template: ['subject', 'html', 'text', 'missing' => [words still empty]]. */
    function letterRender(PDO $conn, array $t, array $vals): array {
        $vals = array_change_key_case(array_map(fn($v) => is_scalar($v) ? trim((string)$v) : '', $vals)) + letterAutoValues($conn);
        $t2 = $t;
        foreach (['subject', 'title', 'body', 'button_text', 'button_url'] as $k) $t2[$k] = letterFill((string)($t[$k] ?? ''), $vals);
        $missing = letterFields($t2);
        $btn = trim((string)$t2['button_text']) !== '' && preg_match('#^(https?://|mailto:)#', (string)$t2['button_url']) ? [$t2['button_text'], $t2['button_url']] : null;
        $title = trim((string)$t2['title']) !== '' ? $t2['title'] : $t2['subject'];
        $plainFirst = trim(strtok(letterBodyText($t2['body']), "\n"));
        $html = mailLayout(mailPlatformBrand(), ['title' => $title, 'preheader' => mb_substr($plainFirst, 0, 120),
            'intro' => letterBodyHtml($t2['body']), 'button' => $btn]);
        $text = ($title !== '' ? $title . "\n\n" : '') . letterBodyText($t2['body']) . ($btn ? "\n\n" . $btn[0] . ': ' . $btn[1] : '');
        return ['subject' => $t2['subject'], 'html' => $html, 'text' => $text, 'missing' => $missing];
    }

    /** The starter templates. Change them in Super Admin › Email › Proposals & letters (not here). */
    function letterDefaults(): array {
        return [
            ['seed' => 'proposal_ordering', 'category' => 'Proposal', 'name' => 'Proposal 1: Commission-free online ordering',
             'subject' => 'Proposal for {{restaurant}}: your own online ordering, zero commission',
             'title' => 'Keep the commission. Keep your customers.',
             'button_text' => 'See a live demo', 'button_url' => 'https://restrogrow.com/demo',
             'body' => <<<'TXT'
Hi {{owner}},

Thank you for your time today. As promised, here is how {{restaurant}} can take orders online without paying 20–30% commission on every order.

## What you get
- **Your own ordering website** with your name, logo and full menu, live in one day
- **QR code menus** for tables, so dine-in guests order and pay from their phone
- **WhatsApp ordering and updates**: customers order on WhatsApp and get order updates there
- **Your customer list stays yours**: names, numbers and order history, to bring them back with offers
- **Loyalty points, coupons and gift offers** that run on their own
- Orders go straight to your kitchen screen (KOT), no re-typing

## What it saves you
If {{restaurant}} gets **{{monthly_orders}} online orders a month** at an average of **{{avg_order}}**, delivery app commission alone costs you about **{{commission_cost}} every month**. With RestroGrow you pay one flat fee and keep the rest.

## Price
- **{{price}}**, everything included
- or **{{price_yearly}}** if you pay for the year
- **{{trial_days}}-day free trial**, no card needed, cancel any time

## Next step
Reply to this email or call me on {{my_phone}}, and we will set up your ordering website this week.

Warm regards,
{{my_name}}
RestroGrow
TXT],
            ['seed' => 'proposal_complete', 'category' => 'Proposal', 'name' => 'Proposal 2: Complete restaurant software',
             'subject' => '{{restaurant}} × RestroGrow: proposal for your complete restaurant system',
             'title' => 'One system for your whole restaurant',
             'button_text' => 'Start the free trial', 'button_url' => 'https://restrogrow.com/main/admin/login.php?tab=signup',
             'body' => <<<'TXT'
Dear {{owner}},

Thank you for considering RestroGrow for {{restaurant}}. Below is our proposal, put together from what you told us about your restaurant.

## What you told us
{{their_needs}}

## What RestroGrow covers
1. **Billing & POS**: fast billing, split bills, GST invoices, cash / UPI / card
2. **Kitchen (KOT & kitchen screen)**: orders reach the kitchen instantly, nothing gets lost
3. **Tables & QR menu**: live table status, guests order and call the waiter from the table
4. **Online ordering website & WhatsApp ordering**, with zero commission
5. **Staff app & roles**: waiter, cashier, chef and manager each see only their part
6. **Inventory & recipes**: stock goes down with every order, low-stock alerts
7. **Customers & loyalty**: points, coupons, birthday offers, WhatsApp campaigns
8. **Reports & AI manager**: daily sales report on WhatsApp, best sellers, busy hours

## Investment
- Plan: **{{price}}** (or **{{price_yearly}}** yearly)
- Setup, menu upload and staff training: **{{setup_fee}}**
- **{{trial_days}}-day free trial** before you pay anything

## Timeline
- Day 1: account, menu and tables set up
- Day 2: staff training (about 1 hour)
- Day 3: you go live, and we stay on call for the first week

This proposal is valid until **{{valid_until}}**. Reply "Yes" to this email and we will start, or call me on {{my_phone}} with any questions.

Regards,
{{my_name}}
RestroGrow · {{my_phone}}
TXT],
            ['seed' => 'proposal_chain', 'category' => 'Proposal', 'name' => 'Proposal 3: Multiple outlets / chain',
             'subject' => 'Proposal for {{restaurant}}: all {{outlets}} outlets on one system',
             'title' => 'All your outlets, one dashboard',
             'button_text' => 'Book a call', 'button_url' => 'https://wa.me/916377568749',
             'body' => <<<'TXT'
Dear {{owner}},

Running {{outlets}} outlets means you need to see everything in one place, without calling each manager. Here is how RestroGrow does that for {{restaurant}}.

## For you (the owner)
- **One login for every outlet**: switch between them, or see them all together
- **Combined reports**: sales, best sellers and staff performance across outlets, compared side by side
- **One menu, outlet prices**: change a dish once, it updates everywhere; prices can differ per outlet
- **Central customer list and loyalty**: points earned at one outlet work at all of them
- **Daily owner report on WhatsApp** for every outlet

## For each outlet
- Billing & POS, KOT and kitchen screen, tables and QR menu
- Own ordering website and WhatsApp ordering, zero commission
- Inventory with low-stock alerts, staff roles and attendance

## Commercials
- **{{price_per_outlet}} per outlet per month**
- One-time setup and training for all outlets: **{{setup_fee}}**
- A dedicated account manager and priority WhatsApp support

## Rollout plan
1. Pilot at one outlet for {{pilot_days}} days
2. Move the remaining outlets, one or two a week
3. Monthly review call with you

Proposal valid until **{{valid_until}}**. I would be happy to walk your team through it; just reply with a good time.

Best regards,
{{my_name}}
RestroGrow · {{my_phone}}
TXT],
            ['seed' => 'agreement', 'category' => 'Agreement', 'name' => 'Service agreement',
             'subject' => 'Service agreement: {{restaurant}} and RestroGrow',
             'title' => 'RestroGrow service agreement',
             'body' => <<<'TXT'
This agreement is between **RestroGrow** ("we", "us") and **{{restaurant}}**, {{restaurant_address}} ("you"), represented by **{{owner}}**, dated **{{date}}**.

## 1. Services
We provide the RestroGrow restaurant software as a service: billing & POS, kitchen orders, table and QR menu, online ordering website, staff accounts, customer and loyalty tools, reports, and the features in the plan below, plus updates as they are released.

## 2. Plan and fees
- Plan: **{{plan_name}}**
- Fee: **{{price}}**, billed {{billing_cycle}}, plus GST where it applies
- One-time setup and training: **{{setup_fee}}**
- Start date: **{{start_date}}**
Fees are paid in advance. If a payment is more than 7 days late, we may pause the account after a reminder; nothing is deleted while it is paused.

## 3. Online payments
Customer payments made through your own payment account go directly to you. Where you use the RestroGrow payment account, collected amounts are settled to your bank account, less the gateway's charges, usually within 2 working days.

## 4. Your data
Your menu, orders, customers and reports belong to you. We keep them safe, use them only to run the service, and never sell them. You can ask for an export at any time, and for deletion when you leave.

## 5. Support
Help on WhatsApp and email ({{my_phone}}, {{my_email}}) during business hours. Urgent problems that stop billing are handled first.

## 6. Your part
Keep your login details private, give staff only the access they need, and use the service lawfully. You are responsible for your menu prices, taxes and the orders you accept.

## 7. Cancellation
Either side can end this agreement with **{{notice_days}} days'** written notice (email is enough). Fees already paid for the current period are not refunded, unless the service failed on our side.

## 8. Liability
We work hard to keep RestroGrow running at all times but cannot promise it will never be interrupted. Our total liability is limited to the fees you paid in the last 3 months.

## 9. Acceptance
Reply **"I agree"** to this email (or sign and return a copy) to accept this agreement. Any changes must be agreed in writing by both sides.

---

For RestroGrow: **{{my_name}}**, {{date}}

For {{restaurant}}: **{{owner}}**, signature: ____________________, date: __________
TXT],
            ['seed' => 'followup', 'category' => 'Follow-up', 'name' => 'Follow-up after a demo',
             'subject' => 'Great talking to you, {{owner}}: next steps for {{restaurant}}',
             'title' => 'Thanks for your time, {{owner}}',
             'button_text' => 'Start the free trial', 'button_url' => 'https://restrogrow.com/main/admin/login.php?tab=signup',
             'body' => <<<'TXT'
Hi {{owner}},

Thank you for the demo on {{demo_date}}. It was great to learn about {{restaurant}}.

A quick recap of what we discussed:
- {{point_1}}
- {{point_2}}
- {{point_3}}

You can start the **{{trial_days}}-day free trial** today, no card needed. We will load your menu and set up your tables for you.

Any questions? Just reply here or WhatsApp me on {{my_phone}}.

Thanks,
{{my_name}}
TXT],
            ['seed' => 'welcome', 'category' => 'Onboarding', 'name' => 'Welcome on board (after they say yes)',
             'subject' => 'Welcome to RestroGrow, {{restaurant}}! Your next steps',
             'title' => 'Welcome on board, {{owner}}!',
             'button_text' => 'Open your dashboard', 'button_url' => 'https://restrogrow.com/main/admin/login.php',
             'body' => <<<'TXT'
Hi {{owner}},

We are excited to have **{{restaurant}}** with us. Here is what happens next:

1. **Your account**: log in with **{{login_email}}** (the button below)
2. **Menu**: send us your menu (photo or PDF) on WhatsApp, and we will upload it for you
3. **Tables & QR codes**: we set up your tables and send the QR codes, ready to print
4. **Staff training** on **{{training_date}}**, about 1 hour
5. **Go live** on **{{go_live_date}}**

Your account manager is **{{my_name}}**, on WhatsApp {{my_phone}}.

Welcome again!
Team RestroGrow
TXT],
            ['seed' => 'payment_reminder', 'category' => 'Billing', 'name' => 'Payment reminder',
             'subject' => 'Payment reminder: {{amount}} for {{restaurant}}',
             'title' => 'A friendly payment reminder',
             'button_text' => 'Pay now', 'button_url' => '{{payment_link}}',
             'body' => <<<'TXT'
Hi {{owner}},

This is a friendly reminder that **{{amount}}** for {{restaurant}}'s RestroGrow plan was due on **{{due_date}}**.

You can pay securely with UPI, card or net banking using the button below. If you have already paid, thank you, and please ignore this email.

Questions about the bill? Reply here or call {{my_phone}}.

Thank you,
{{my_name}}
RestroGrow
TXT],
        ];
    }
}
