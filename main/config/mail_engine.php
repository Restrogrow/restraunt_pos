<?php
/**
 * Every automatic email RestroGrow sends (plan: docs/EMAIL_PLAN.md).
 *
 *  - mailQueue(): puts one email in `email_outbox` (with a de-dupe key so the same reminder never goes twice).
 *    It is sent seconds later, after the current page has finished (mailKick), so a slow mail server never
 *    slows down checkout; cron/email_dispatch.php also sends anything left over. 3 tries, then "failed".
 *  - mailSendNow(): the same, but sent right away and logged (password resets: the person is waiting).
 *  - mailLayout(): the one email design. Platform emails carry the RestroGrow brand; customer emails carry
 *    the restaurant's name, logo and phone ("Spice Garden via RestroGrow").
 *  - mailSweep(): the date-based emails (trial ending, renewal, payment receipts, loyalty gifts, booking
 *    reminders), at most once an hour.
 *  - Each email type can be switched off in Super Admin › Email (sa_settings 'email_off').
 *  - MAIL_SINK_DIR in .env (local PCs only): emails are saved there as .html files instead of being sent.
 * Sending itself is config/email_config.php (Google Workspace SMTP).
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/email_config.php';

if (!function_exists('mailQueue')) {

    /** type => [label, who, purpose (sender address), on by default] */
    function mailTypes(): array
    {
        return [
            'owner_welcome'   => ['Welcome after signup', 'owner', 'sales', true],
            'trial_ending'    => ['Trial ending (3 days, 1 day, today)', 'owner', 'billing', true],
            'trial_ended'     => ['Trial ended', 'owner', 'billing', true],
            'renewal_due'     => ['Plan renewal reminder (7 days, 1 day)', 'owner', 'billing', true],
            'plan_expired'    => ['Plan expired', 'owner', 'billing', true],
            'payment_receipt' => ['Payment received', 'owner', 'billing', true],
            'invoice'         => ['Invoice from the invoice generator', 'owner', 'billing', true],
            'new_device'      => ['Login from a new device', 'owner', 'accounts', true],
            'support_reply'   => ['Support ticket replied', 'owner', 'support', true],
            'daily_summary'   => ['Daily sales summary (AI manager, if the owner turns it on)', 'owner', 'alerts', true],
            'password_reset'  => ['Password reset links (always on)', 'owner', 'accounts', true],
            'team_lead'       => ['New lead to the RestroGrow team (contact form, chatbot)', 'team', 'sales', true],
            'team_signup'     => ['New restaurant signed up (to the team)', 'team', 'sales', true],
            'setup_nudge'     => ['Setup reminders on day 1, 3 and 7 after sign-up (until the guide is done)', 'owner', 'sales', true],
            'lead_autoreply'  => ['"We got your message" to people who use the contact form', 'team', 'sales', true],
            'order_confirmed' => ['Order confirmed (to the customer)', 'customer', 'orders', true],
            'order_alert'     => ['New order (to the restaurant)', 'customer', 'alerts', true],
            'order_status'    => ['Order updates: accepted, ready / on the way, cancelled', 'customer', 'orders', true],
            'order_receipt'   => ['E-bill after the order is completed', 'customer', 'orders', true],
            'booking'         => ['Table booking received / confirmed / reminder', 'customer', 'orders', true],
            'customer_welcome' => ['Welcome when a customer creates a website account', 'customer', 'accounts', true],
            'loyalty_gift'    => ['Loyalty gifts (birthday, we miss you…) for customers without WhatsApp', 'customer', 'sales', true],
        ];
    }

    /** Preview mode (Super Admin › Email › All emails): the sample data, or null when sending for real. */
    function mailPreviewing(): ?array
    {
        return $GLOBALS['MAIL_PREVIEW'] ?? null;
    }

    function mailEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS email_outbox (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(30) NOT NULL,
            dedupe VARCHAR(120) NULL,
            restaurant_id VARCHAR(50) NULL,
            to_email VARCHAR(254) NOT NULL,
            to_name VARCHAR(120) NULL,
            subject VARCHAR(255) NOT NULL,
            html MEDIUMTEXT NOT NULL,
            text TEXT NULL,
            from_name VARCHAR(120) NULL,
            reply_to VARCHAR(254) NULL,
            status VARCHAR(10) NOT NULL DEFAULT 'queued',
            attempts TINYINT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            send_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_dedupe (type, dedupe),
            INDEX (status, send_after), INDEX (created_at), INDEX (restaurant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // join with users without "Illegal mix of collations"
        try {
            $coll = $conn->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'restaurant_id'")->fetchColumn();
            $mine = $conn->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_outbox' AND COLUMN_NAME = 'restaurant_id'")->fetchColumn();
            if ($coll && $coll !== $mine && preg_match('/^utf8mb4_[a-z0-9_]+$/', $coll)) $conn->exec("ALTER TABLE email_outbox CONVERT TO CHARACTER SET utf8mb4 COLLATE $coll");
        } catch (Throwable $e) {
            error_log('mailEnsure collation: ' . $e->getMessage());
        }
    }

    function mailTypeOn(PDO $conn, string $type): bool
    {
        static $off = null;
        if ($off === null) {
            $off = [];
            try {
                $v = $conn->query("SELECT v FROM sa_settings WHERE k = 'email_off'")->fetchColumn();
                $off = json_decode((string)$v, true) ?: [];
            } catch (Throwable $e) { $off = []; }
        }
        if ($type === 'password_reset' || $type === 'test') return true;   // never switched off: people would be locked out
        $t = mailTypes()[$type] ?? null;
        return $t !== null && !in_array($type, $off, true);
    }

    function mailAppUrl(): string
    {
        return rtrim((string)(env('APP_URL', '') ?: 'https://restrogrow.com'), '/');
    }

    /** Where the RestroGrow team's alerts go (new leads, signups). */
    function mailTeamAddress(): string
    {
        $t = trim((string)env('MAIL_TEAM', ''));
        return filter_var($t, FILTER_VALIDATE_EMAIL) ? $t : SMTP_FROM_EMAIL;
    }

    // ── brands ──
    function mailPlatformBrand(): array
    {
        return ['name' => 'RestroGrow', 'logo' => mailAppUrl() . '/main/assets/images/logo-transparent.png', 'logo_wide' => true,
            'color' => '#c1440e', 'from_name' => 'RestroGrow', 'reply_to' => (string)(env('MAIL_FROM_SUPPORT', '') ?: SMTP_FROM_EMAIL),
            'footer' => 'RestroGrow · Restaurant software made in India · restrogrow.com', 'phone' => '+91 63775 68749'];
    }

    function mailRestaurantBrand(PDO $conn, string $rid): array
    {
        if (($pv = mailPreviewing()) !== null) $r = $pv['restaurant'];
        else {
            $st = $conn->prepare('SELECT * FROM users WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$rid]);
            $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        if (!empty($r['logo_url'])) $r['_logo'] = $r['logo_url'];
        $site = !empty($r['custom_domain']) ? 'https://' . $r['custom_domain'] : (!empty($r['username']) ? mailAppUrl() . '/' . rawurlencode($r['username']) : '');
        return ['name' => (string)($r['restaurant_name'] ?? 'Your restaurant'), 'logo' => !empty($r['_logo']) ? $r['_logo'] : ((!empty($r['restaurant_logo']) || !empty($r['logo_data'])) ? mailAppUrl() . '/main/api/image.php?type=logo&id=' . (int)$r['id'] : ''),
            'logo_wide' => false, 'color' => '#c1440e', 'from_name' => mb_substr((string)($r['restaurant_name'] ?? 'Restaurant'), 0, 60) . ' via RestroGrow',
            'reply_to' => filter_var((string)($r['email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string)$r['email'] : '',
            'footer' => trim(($r['restaurant_name'] ?? '') . ($r['address'] ? ' · ' . $r['address'] : '')), 'phone' => (string)($r['phone'] ?? ''), 'site' => $site,
            'owner_email' => (string)($r['email'] ?? '')];
    }

    function mailE(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

    /**
     * The one email design. $c: title, preheader, intro (HTML allowed, escape yourself), rows [[label, value]],
     * table (HTML), button [label, url], after (HTML), small (footnote text).
     */
    function mailLayout(array $brand, array $c): string
    {
        $col = $brand['color'] ?? '#c1440e';
        $logo = !empty($brand['logo'])
            ? '<img src="' . mailE($brand['logo']) . '" alt="' . mailE($brand['name']) . '" style="display:block;margin:0 auto;' . (!empty($brand['logo_wide']) ? 'height:34px;width:auto' : 'width:64px;height:64px;border-radius:14px;object-fit:cover') . '">'
            : '<div style="font:800 22px Arial,sans-serif;color:#241d16;text-align:center">' . mailE($brand['name']) . '</div>';
        $rows = '';
        foreach ($c['rows'] ?? [] as $r) {
            $rows .= '<tr><td style="padding:7px 0;color:#7a6e61;font-size:14px">' . mailE((string)$r[0]) . '</td><td style="padding:7px 0;color:#241d16;font-size:14px;font-weight:700;text-align:right">' . mailE((string)$r[1]) . '</td></tr>';
        }
        $btn = !empty($c['button']) ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:22px auto 6px"><tr><td style="border-radius:12px;background:' . $col . '"><a href="' . mailE($c['button'][1]) . '" style="display:inline-block;padding:14px 26px;color:#fff;font:700 15px Arial,sans-serif;text-decoration:none;border-radius:12px">' . mailE($c['button'][0]) . '</a></td></tr></table>' : '';
        $pre = mailE((string)($c['preheader'] ?? ''));
        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . mailE((string)($c['title'] ?? '')) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#f5efe2;-webkit-text-size-adjust:100%">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0">' . $pre . '&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5efe2;padding:24px 12px"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px">'
            . '<tr><td style="padding:6px 0 18px">' . $logo . '</td></tr>'
            . '<tr><td style="background:#fff;border-radius:20px;padding:30px 28px;border:1px solid #ecdfcd;font-family:Arial,Helvetica,sans-serif">'
            . '<div style="height:4px;width:56px;border-radius:4px;background:' . $col . ';margin-bottom:18px"></div>'
            . '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.3;color:#241d16">' . mailE((string)($c['title'] ?? '')) . '</h1>'
            . '<div style="font-size:15px;line-height:1.6;color:#4b4036">' . ($c['intro'] ?? '') . '</div>'
            . ($rows ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;border-top:1px solid #f0e6d8;border-bottom:1px solid #f0e6d8">' . $rows . '</table>' : '')
            . ($c['table'] ?? '') . $btn . ($c['after'] ?? '')
            . (!empty($c['small']) ? '<p style="margin:18px 0 0;font-size:12.5px;line-height:1.5;color:#9a8e80">' . mailE($c['small']) . '</p>' : '')
            . '</td></tr>'
            . '<tr><td style="padding:18px 10px;text-align:center;font:12px/1.6 Arial,sans-serif;color:#9a8e80">' . mailE((string)($brand['footer'] ?? ''))
            . (!empty($brand['phone']) ? '<br>' . mailE($brand['phone']) : '') . (!empty($c['unsubscribe']) ? '<br><a href="' . mailE($c['unsubscribe']) . '" style="color:#9a8e80">Unsubscribe</a>' : '')
            . '<br><span style="color:#b8ab9b">Sent by RestroGrow</span></td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    /** Items table for order emails: [[name, qty, amount], …] */
    function mailItemsTable(array $lines, string $cur, array $totals): string
    {
        $h = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:16px;font-size:14px">';
        foreach ($lines as $l) {
            $h .= '<tr><td style="padding:6px 0;color:#241d16">' . mailE((string)$l[0]) . ' <span style="color:#9a8e80">× ' . mailE((string)$l[1]) . '</span></td><td style="padding:6px 0;text-align:right;color:#241d16">' . $cur . number_format((float)$l[2], 2) . '</td></tr>';
        }
        foreach ($totals as $t) {
            $big = !empty($t[2]);
            $h .= '<tr><td style="padding:' . ($big ? '10px' : '4px') . ' 0 4px;' . ($big ? 'border-top:2px solid #241d16;font-weight:800;font-size:16px;color:#241d16' : 'color:#7a6e61') . '">' . mailE((string)$t[0]) . '</td><td style="padding:' . ($big ? '10px' : '4px') . ' 0 4px;text-align:right;' . ($big ? 'border-top:2px solid #241d16;font-weight:800;font-size:16px;color:#241d16' : 'color:#4b4036') . '">' . mailE((string)$t[1]) . '</td></tr>';
        }
        return $h . '</table>';
    }

    /**
     * Queue one email. $o: restaurant_id, to_name, brand (from mail*Brand: from_name / reply_to), text, dedupe,
     * send_after ('Y-m-d H:i:s'). Returns the outbox id, or null (switched off, bad address, already sent).
     */
    function mailQueue(PDO $conn, string $type, string $to, string $subject, string $html, array $o = []): ?int
    {
        if (mailPreviewing() !== null) {
            $GLOBALS['MAIL_CAPTURED'][] = ['type' => $type, 'to' => $to, 'subject' => $subject, 'html' => $html,
                'from_name' => ($o['brand']['from_name'] ?? '') ?: rgMailFromName(), 'reply_to' => $o['brand']['reply_to'] ?? ''];
            return 1;
        }
        mailEnsure($conn);
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL) || preg_match('/@(example\.(com|org)|restrogrow\.invalid|test)$/i', $to)) return null;
        if (!mailTypeOn($conn, $type)) return null;
        $b = $o['brand'] ?? [];
        try {
            $conn->prepare('INSERT INTO email_outbox (type, dedupe, restaurant_id, to_email, to_name, subject, html, text, from_name, reply_to, send_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))')
                ->execute([$type, isset($o['dedupe']) ? mb_substr((string)$o['dedupe'], 0, 120) : null, $o['restaurant_id'] ?? null, $to, isset($o['to_name']) ? mb_substr((string)$o['to_name'], 0, 120) : null,
                    mb_substr($subject, 0, 255), $html, $o['text'] ?? null, $b['from_name'] ?? null, ($b['reply_to'] ?? '') ?: null, $o['send_after'] ?? null]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') return null;   // same dedupe key: already queued / sent
            throw $e;
        }
        $id = (int)$conn->lastInsertId();
        mailKick($conn);
        return $id;
    }

    /** Send queued emails after this request has answered (the visitor never waits for the mail server). */
    function mailKick(PDO $conn): void
    {
        static $queued = false;
        if ($queued) return;
        $queued = true;
        register_shutdown_function(function () use ($conn) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            try { mailDispatch($conn, 10); } catch (Throwable $e) { error_log('mailKick: ' . $e->getMessage()); }
        });
    }

    /** Send up to $limit due emails. Returns [sent, failed]. */
    function mailDispatch(PDO $conn, int $limit = 20): array
    {
        mailEnsure($conn);
        $sent = $failed = 0;
        $st = $conn->prepare("SELECT * FROM email_outbox WHERE status = 'queued' AND send_after <= NOW() ORDER BY id LIMIT " . max(1, $limit));
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $claim = $conn->prepare("UPDATE email_outbox SET status = 'sending', attempts = attempts + 1, send_after = NOW() WHERE id = ? AND status = 'queued'");
            $claim->execute([$m['id']]);
            if ($claim->rowCount() === 0) continue;   // another run took it
            [$ok, $err] = mailDeliver($m);
            if ($ok) {
                $conn->prepare("UPDATE email_outbox SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE id = ?")->execute([$m['id']]);
                $sent++;
            } else {
                $tries = (int)$m['attempts'] + 1;
                $conn->prepare("UPDATE email_outbox SET status = ?, last_error = ?, send_after = NOW() + INTERVAL ? MINUTE WHERE id = ?")
                    ->execute([$tries >= 3 ? 'failed' : 'queued', mb_substr((string)$err, 0, 500), $tries * 10, $m['id']]);
                $failed++;
            }
        }
        // anything stuck in "sending" (the process died) goes back in the queue
        $conn->exec("UPDATE email_outbox SET status = 'queued' WHERE status = 'sending' AND send_after < NOW() - INTERVAL 15 MINUTE");
        return [$sent, $failed];
    }

    /** One email out through SMTP (or into MAIL_SINK_DIR on a local PC). Returns [ok, error]. */
    function mailDeliver(array $m): array
    {
        $sink = trim((string)env('MAIL_SINK_DIR', ''));
        if ($sink !== '') {
            if (!is_dir($sink)) @mkdir($sink, 0777, true);
            $f = rtrim($sink, '/\\') . '/' . date('Ymd-His') . '-' . $m['type'] . '-' . $m['id'] . '.html';
            $ok = @file_put_contents($f, '<!-- To: ' . $m['to_email'] . ' | From: ' . ($m['from_name'] ?: rgMailFromName()) . ' | Subject: ' . $m['subject'] . " -->\n" . $m['html']) !== false;
            return [$ok, $ok ? null : 'Could not write to MAIL_SINK_DIR'];
        }
        $purpose = mailTypes()[$m['type']][2] ?? null;
        $r = rgMailWith(['from_name' => $m['from_name'] ?? '', 'reply_to' => $m['reply_to'] ?? '', 'text' => $m['text'] ?? ''],
            fn() => sendEmail($m['to_email'], $m['subject'], $m['html'], '', $purpose));
        $ok = is_array($r) ? !empty($r['success']) && empty($r['dev_mode']) : (bool)$r;
        return [$ok, is_array($r) ? ($r['error'] ?? ($r['message'] ?? null)) : null];
    }

    /** Queue and send right now (the person is waiting, e.g. a password reset). Returns ['success' => bool, 'error' => …]. */
    function mailSendNow(PDO $conn, string $type, string $to, string $subject, string $html, array $o = []): array
    {
        $id = mailQueue($conn, $type, $to, $subject, $html, $o + ['send_after' => date('Y-m-d H:i:s', time() + 3600)]);   // not picked up by a kick meanwhile
        if (!$id) return ['success' => false, 'error' => 'Not sent (switched off or invalid address)'];
        $st = $conn->prepare('SELECT * FROM email_outbox WHERE id = ?');
        $st->execute([$id]);
        $m = $st->fetch(PDO::FETCH_ASSOC);
        $conn->prepare("UPDATE email_outbox SET status = 'sending', attempts = 1 WHERE id = ?")->execute([$id]);
        [$ok, $err] = mailDeliver($m);
        // a reset link is not kept in the log (and a failed one is not retried: the person just asks again)
        $html = $m['html'];
        foreach ($o['redact'] ?? [] as $secret) if ($secret !== '') $html = str_replace($secret, '••••••', $html);
        $conn->prepare("UPDATE email_outbox SET status = ?, sent_at = IF(? = 'sent', NOW(), NULL), last_error = ?, html = ?, send_after = NOW() + INTERVAL 10 MINUTE WHERE id = ?")
            ->execute([$ok ? 'sent' : (!empty($o['redact']) ? 'failed' : 'queued'), $ok ? 'sent' : 'x', $ok ? null : mb_substr((string)$err, 0, 500), $html, $id]);
        return ['success' => $ok, 'error' => $err];
    }

    // ═════════════ the emails ═════════════

    function mailOwnerOf(PDO $conn, string $rid): array
    {
        if (($pv = mailPreviewing()) !== null) $u = $pv['restaurant'];
        else {
            $st = $conn->prepare('SELECT * FROM users WHERE restaurant_id = ? LIMIT 1');
            $st->execute([$rid]);
            $u = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $u['first'] = trim(explode(' ', (string)($u['owner_name'] ?? ''))[0]) ?: 'there';
        if (preg_match('/\b(group|pvt|ltd|restaurant)\b/i', (string)($u['owner_name'] ?? ''))) $u['first'] = 'there';
        return $u;
    }

    function mailOwnerWelcome(PDO $conn, string $rid): void
    {
        $u = mailOwnerOf($conn, $rid);
        if (empty($u['email'])) return;
        $b = mailPlatformBrand();
        $dash = mailAppUrl() . '/main/admin/login.php';
        $steps = '<ol style="margin:14px 0 0;padding-left:20px;font-size:14.5px;line-height:1.9;color:#4b4036"><li><b>Add your menu</b> (or snap a photo of your menu card)</li><li><b>Add your tables</b> and print the table QR codes</li>'
            . '<li><b>Connect a printer</b> for bills and KOTs</li><li><b>Share your ordering website</b> on WhatsApp and Instagram</li><li><b>Install the Partner app</b> to take orders on your phone</li></ol>';
        mailQueue($conn, 'owner_welcome', $u['email'], 'Welcome to RestroGrow, ' . $u['first'] . '! 🎉',
            mailLayout($b, ['title' => 'Welcome to RestroGrow 🎉', 'preheader' => 'Your restaurant is set up. 5 quick steps to start taking orders.',
                'intro' => '<p style="margin:0">Hi ' . mailE($u['first']) . ', <b>' . mailE((string)$u['restaurant_name']) . '</b> is ready. Here is how to be taking orders in the next 15 minutes:</p>' . $steps,
                'button' => ['Open my dashboard', $dash], 'small' => 'Questions? Just reply to this email or WhatsApp us on +91 63775 68749.']),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'welcome:' . $rid]);
        $t = mailTeamAddress();
        mailQueue($conn, 'team_signup', $t, 'New signup: ' . $u['restaurant_name'],
            mailLayout($b, ['title' => 'New restaurant signed up', 'intro' => '<p style="margin:0">A new restaurant just created an account.</p>',
                'rows' => [['Restaurant', (string)$u['restaurant_name']], ['Owner', (string)($u['owner_name'] ?? '')], ['Phone', (string)($u['phone'] ?? '')], ['Email', (string)$u['email']], ['ID', $rid]],
                'small' => 'Call them today: new restaurants who get a call in the first day are far more likely to stay.']),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'signup:' . $rid]);
    }

    /** A new lead from the website (contact form / chatbot) to the team, and a "we got it" reply to the person. */
    function mailNewLead(PDO $conn, string $source, array $lead): void
    {
        $b = mailPlatformBrand();
        $rows = [];
        foreach (['name' => 'Name', 'restaurant' => 'Restaurant', 'phone' => 'Phone', 'email' => 'Email'] as $k => $l) if (!empty($lead[$k])) $rows[] = [$l, (string)$lead[$k]];
        $msg = trim((string)($lead['message'] ?? ''));
        mailQueue($conn, 'team_lead', mailTeamAddress(), 'New lead (' . $source . '): ' . ($lead['name'] ?? 'someone'),
            mailLayout($b, ['title' => 'New lead from the ' . $source, 'intro' => '<p style="margin:0">Someone wants to hear from you. Reply quickly, leads cool down within hours.</p>',
                'rows' => $rows, 'after' => $msg !== '' ? '<div style="margin-top:14px;padding:12px 14px;border-radius:12px;background:#faf5ec;font-size:14px;color:#4b4036;white-space:pre-wrap">' . mailE($msg) . '</div>' : '',
                'button' => ['Open leads', mailAppUrl() . '/main/superadmin/dashboard.php']]),
            ['brand' => $b, 'dedupe' => $source . ':' . md5(json_encode($lead) . date('Y-m-d H'))]);
        if (!empty($lead['email']) && $source === 'contact form') {
            mailQueue($conn, 'lead_autoreply', (string)$lead['email'], 'We got your message 👋',
                mailLayout($b, ['title' => 'Thanks, ' . (trim(explode(' ', (string)($lead['name'] ?? ''))[0]) ?: 'there') . '!', 'preheader' => 'Our team will get back to you shortly.',
                    'intro' => '<p style="margin:0">We received your message and someone from the RestroGrow team will get back to you shortly, usually within a few hours.</p><p>Meanwhile you can start a <b>free trial</b>, no card needed, and have your POS and ordering website running in 5 minutes.</p>',
                    'button' => ['Start my free trial', mailAppUrl() . '/main/admin/login.php'], 'small' => 'Need us faster? Call or WhatsApp +91 63775 68749.']),
                ['brand' => $b, 'dedupe' => 'autoreply:' . strtolower((string)$lead['email']) . ':' . date('Y-m-d')]);
        }
    }

    function mailNewDevice(PDO $conn, string $rid, string $who, string $ip, string $agent): void
    {
        $u = mailOwnerOf($conn, $rid);
        if (empty($u['email'])) return;
        // not for a brand-new account (its first logins are all "new devices")
        if (mailPreviewing() === null) try {
            if (!empty($u['created_at']) && strtotime((string)$u['created_at']) > time() - 86400) return;
            $n = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE restaurant_id = ? AND outcome = 'success'");
            $n->execute([$rid]);
            if ((int)$n->fetchColumn() <= 1) return;
        } catch (Throwable $e) { return; }
        $b = mailPlatformBrand();
        $device = preg_match('/Android/i', $agent) ? 'Android phone' : (preg_match('/iPhone|iPad/i', $agent) ? 'iPhone / iPad' : (preg_match('/Windows/i', $agent) ? 'Windows computer' : (preg_match('/Mac/i', $agent) ? 'Mac' : 'a device')));
        mailQueue($conn, 'new_device', $u['email'], 'New login to ' . $u['restaurant_name'],
            mailLayout($b, ['title' => 'New login to your account', 'preheader' => $who . ' logged in from a new device.',
                'intro' => '<p style="margin:0">Hi ' . mailE($u['first']) . ', <b>' . mailE($who) . '</b> just logged in to <b>' . mailE((string)$u['restaurant_name']) . '</b> from a device we haven\'t seen before.</p>',
                'rows' => [['When', date('d M Y, h:i A')], ['Device', $device], ['Network (IP)', $ip]],
                'small' => 'If this was you or your staff, nothing to do. If not, change your password now (Settings › Account) and check Security in the dashboard.',
                'button' => ['Check my security', mailAppUrl() . '/main/views/dashboard.php?page=loginLogsPage']]),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'device:' . $rid . ':' . md5($who . $ip . $agent) . ':' . date('Y-m-d')]);
    }

    function mailSupportReply(PDO $conn, string $rid, string $subject, string $reply): void
    {
        $u = mailOwnerOf($conn, $rid);
        if (empty($u['email'])) return;
        $b = mailPlatformBrand();
        mailQueue($conn, 'support_reply', $u['email'], 'Re: ' . $subject,
            mailLayout($b, ['title' => 'We replied to your support request', 'preheader' => mb_substr($reply, 0, 90),
                'intro' => '<p style="margin:0">Hi ' . mailE($u['first']) . ', about <b>' . mailE($subject) . '</b>:</p><div style="margin-top:12px;padding:14px;border-radius:12px;background:#faf5ec;white-space:pre-wrap">' . mailE($reply) . '</div>',
                'button' => ['Open support', mailAppUrl() . '/main/views/dashboard.php?page=supportPage'], 'small' => 'You can reply in the dashboard (Help › Support).']),
            ['restaurant_id' => $rid, 'brand' => $b]);
    }

    /** The AI manager's morning report by email (when the owner switched email on). */
    function mailDailySummary(PDO $conn, string $rid, string $text, string $day): void
    {
        $u = mailOwnerOf($conn, $rid);
        if (empty($u['email'])) return;
        $b = mailPlatformBrand();
        $html = nl2br(mailE(preg_replace('/\*([^*\n]+)\*/', '$1', $text)));
        mailQueue($conn, 'daily_summary', $u['email'], 'Your morning report: ' . $u['restaurant_name'],
            mailLayout($b, ['title' => 'Your morning report', 'preheader' => mb_substr(strtok($text, "\n") ?: '', 0, 100), 'intro' => '<div style="font-size:15px;line-height:1.7">' . $html . '</div>',
                'button' => ['Open the AI manager', mailAppUrl() . '/main/views/dashboard.php?page=ownerManagerPage'], 'small' => 'Reply 1 or 2 on WhatsApp to run a suggestion. Turn these emails off in Growth › AI manager.']),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'summary:' . $rid . ':' . $day]);
    }

    /** A Super Admin invoice (config/sa_invoices.php row) to the restaurant. Returns the outbox id or null. */
    function mailInvoice(PDO $conn, array $inv): ?int
    {
        $rid = (string)$inv['restaurant_id'];
        $u = mailOwnerOf($conn, $rid);
        $to = (string)(($inv['billed_to']['email'] ?? '') ?: ($u['email'] ?? ''));
        if ($to === '') return null;
        $b = mailPlatformBrand();
        $due = $inv['status'] === 'due';
        $lines = array_map(fn($l) => [$l['desc'], rtrim(rtrim(number_format((float)$l['qty'], 2), '0'), '.') . ($l['unit'] ? ' ' . $l['unit'] : ''), (float)$l['amount']], $inv['items']);
        $tot = [['Taxable value', '₹' . number_format((float)$inv['taxable'], 2)]];
        if ((float)$inv['discount'] > 0) array_unshift($tot, ['Discount', '-₹' . number_format((float)$inv['discount'], 2)]);
        if ((float)$inv['igst'] > 0) $tot[] = ['IGST', '₹' . number_format((float)$inv['igst'], 2)];
        elseif ((float)$inv['cgst'] > 0) { $tot[] = ['CGST', '₹' . number_format((float)$inv['cgst'], 2)]; $tot[] = ['SGST', '₹' . number_format((float)$inv['sgst'], 2)]; }
        $tot[] = [$due ? 'Amount due' : 'Total paid', '₹' . number_format((float)$inv['total'], 2), true];
        return mailQueue($conn, 'invoice', $to, ($due ? 'Invoice ' : 'Receipt ') . $inv['invoice_no'] . ' from RestroGrow',
            mailLayout($b, ['title' => ($due ? 'Your invoice ' : 'Payment received · ') . $inv['invoice_no'],
                'preheader' => ($due ? 'Amount due ₹' : 'Paid ₹') . number_format((float)$inv['total'], 2),
                'intro' => '<p style="margin:0">Hi ' . mailE($u['first']) . ', ' . ($due ? 'here is your invoice for ' : 'thank you! Here is your receipt for ') . '<b>' . mailE((string)$inv['restaurant_name']) . '</b>.</p>',
                'rows' => array_values(array_filter([['Invoice', (string)$inv['invoice_no']], ['Date', date('d M Y', strtotime((string)$inv['invoice_date']))],
                    $inv['period_from'] ? ['Service period', date('d M Y', strtotime((string)$inv['period_from'])) . ' – ' . date('d M Y', strtotime((string)$inv['period_to']))] : null,
                    $due && $inv['due_date'] ? ['Pay by', date('d M Y', strtotime((string)$inv['due_date']))] : null,
                    !$due && $inv['method'] ? ['Paid by', (string)$inv['method']] : null])),
                'table' => mailItemsTable($lines, '₹', $tot),
                'small' => $due ? 'Pay by UPI or bank transfer and mention the invoice number. Need the PDF or a GST copy? Just reply to this email.' : 'Need the PDF or a GST copy? Just reply to this email.']),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'invoice:' . $inv['invoice_no'] . ':' . $inv['status'] . ':' . date('YmdHi')]);
    }

    // ── customers ──
    function mailOrderFacts(PDO $conn, int $orderId): ?array
    {
        if (($pv = mailPreviewing()) !== null) return $pv['order'];
        $st = $conn->prepare('SELECT o.*, t.table_number FROM orders o LEFT JOIN tables t ON t.id = o.table_id WHERE o.id = ?');
        $st->execute([$orderId]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        if (!$o) return null;
        $it = $conn->prepare('SELECT item_name, variation_name, quantity, total_price FROM order_items WHERE order_id = ? ORDER BY id');
        $it->execute([$orderId]);
        $o['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
        $email = trim((string)($o['customer_email'] ?? ''));
        if ($email === '' && !empty($o['customer_phone'])) {
            $c = $conn->prepare("SELECT email FROM customers WHERE restaurant_id = ? AND phone = ? AND email IS NOT NULL AND email <> '' LIMIT 1");
            $c->execute([$o['restaurant_id'], $o['customer_phone']]);
            $email = (string)$c->fetchColumn();
        }
        $o['to'] = $email;
        return $o;
    }

    function mailOrderTable(array $o): string
    {
        $lines = array_map(fn($i) => [$i['item_name'] . ($i['variation_name'] && !in_array($i['variation_name'], ['Add-on', 'Combo'], true) ? ' (' . $i['variation_name'] . ')' : ''), (int)$i['quantity'], (float)$i['total_price']],
            array_values(array_filter($o['items'], fn($i) => (float)$i['total_price'] > 0)));
        $disc = (float)($o['discount_amount'] ?? 0) + (float)($o['loyalty_discount_amount'] ?? 0) + (float)($o['vip_discount'] ?? 0);
        $tot = [['Subtotal', '₹' . number_format((float)$o['subtotal'], 2)]];
        if ($disc > 0) $tot[] = ['Discount', '-₹' . number_format($disc, 2)];
        if ((float)$o['tax'] > 0) $tot[] = ['Tax', '₹' . number_format((float)$o['tax'], 2)];
        if ((float)($o['delivery_charge'] ?? 0) > 0) $tot[] = ['Delivery', '₹' . number_format((float)$o['delivery_charge'], 2)];
        // packing and other charges are in the total but have no column of their own
        $other = round((float)$o['total'] - ((float)$o['subtotal'] - $disc + (float)$o['tax'] + (float)($o['delivery_charge'] ?? 0)), 2);
        if ($other >= 0.5) $tot[] = ['Packing & other charges', '₹' . number_format($other, 2)];
        $tot[] = ['Total', '₹' . number_format((float)$o['total'], 2), true];
        return mailItemsTable($lines, '₹', $tot);
    }

    /** The customer's "order confirmed" and the restaurant's "new order" (replaces the old plain emails). */
    function mailOrderConfirmed(PDO $conn, int $orderId): void
    {
        $o = mailOrderFacts($conn, $orderId);
        if (!$o) return;
        $rid = (string)$o['restaurant_id'];
        $b = mailRestaurantBrand($conn, $rid);
        $where = $o['table_number'] ? 'Table ' . $o['table_number'] : (string)$o['order_type'];
        if ($o['to'] !== '') {
            mailQueue($conn, 'order_confirmed', $o['to'], 'Order confirmed · ' . $o['order_number'] . ' · ' . $b['name'],
                mailLayout($b, ['title' => 'Thank you, your order is confirmed!', 'preheader' => 'Order ' . $o['order_number'] . ' · ₹' . number_format((float)$o['total'], 2),
                    'intro' => '<p style="margin:0">Hi ' . mailE(trim(explode(' ', (string)$o['customer_name'])[0]) ?: 'there') . ', <b>' . mailE($b['name']) . '</b> has your order. We\'ll email you when it\'s ready.</p>',
                    'rows' => [['Order', (string)$o['order_number']], ['Type', $where], ['Payment', (string)$o['payment_method'] . ' · ' . (string)$o['payment_status']]],
                    'table' => mailOrderTable($o), 'button' => $b['site'] ? ['Order again', $b['site']] : null,
                    'small' => 'Questions about your order? Call ' . $b['name'] . ($b['phone'] ? ' on ' . $b['phone'] : '') . '.']),
                ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'confirmed:' . $orderId, 'to_name' => (string)$o['customer_name']]);
        }
        if ($b['owner_email'] !== '') {
            mailQueue($conn, 'order_alert', $b['owner_email'], '🔔 New order ' . $o['order_number'] . ' · ₹' . number_format((float)$o['total'], 2),
                mailLayout(mailPlatformBrand(), ['title' => 'New order received', 'preheader' => $where . ' · ₹' . number_format((float)$o['total'], 2),
                    'intro' => '<p style="margin:0"><b>' . mailE((string)$o['customer_name']) . '</b>' . ($o['customer_phone'] ? ' (' . mailE((string)$o['customer_phone']) . ')' : '') . ' placed an order.</p>',
                    'rows' => [['Order', (string)$o['order_number']], ['Type', $where], ['Payment', (string)$o['payment_method'] . ' · ' . (string)$o['payment_status']]],
                    'table' => mailOrderTable($o), 'button' => ['Open orders', mailAppUrl() . '/main/views/dashboard.php?page=ordersPage']]),
                ['restaurant_id' => $rid, 'brand' => mailPlatformBrand(), 'dedupe' => 'alert:' . $orderId]);
        }
    }

    /** After an order's status changes (config/order_state_machine.php): a short update, or the e-bill when completed. */
    function mailOrderStatus(PDO $conn, int $orderId): void
    {
        $o = mailOrderFacts($conn, $orderId);
        if (!$o || $o['to'] === '' || ($o['source'] ?? '') === 'pos' && $o['order_status'] !== 'Completed') return;
        $b = mailRestaurantBrand($conn, (string)$o['restaurant_id']);
        $s = (string)$o['order_status'];
        $msg = ['Accepted' => ['Your order is accepted ✅', 'The kitchen has your order and will start on it shortly.'],
            'Ready' => $o['order_type'] === 'Delivery' ? ['Your order is packed and on its way soon 🛵', 'Freshly made, it leaves the restaurant shortly.']
                : [$o['order_type'] === 'Takeaway' ? 'Your order is ready for pickup 🎉' : 'Your order is ready 🎉', 'Freshly made and ready.'],
            'Cancelled' => ['Your order was cancelled', 'Sorry about that. If you paid online, the refund is on its way.'],
            'Rejected' => ['Your order couldn\'t be taken', 'Sorry, the restaurant couldn\'t take this order right now.']][$s] ?? null;
        if ($s === 'Completed') {
            mailQueue($conn, 'order_receipt', $o['to'], 'Your bill from ' . $b['name'] . ' · ' . $o['order_number'],
                mailLayout($b, ['title' => 'Thank you for visiting! 🙏', 'preheader' => 'Your e-bill: ₹' . number_format((float)$o['total'], 2),
                    'intro' => '<p style="margin:0">Here is your bill from <b>' . mailE($b['name']) . '</b>. Hope to see you again soon.</p>',
                    'rows' => [['Order', (string)$o['order_number']], ['Date', date('d M Y, h:i A', strtotime((string)$o['created_at']))], ['Paid by', (string)$o['payment_method']]],
                    'table' => mailOrderTable($o), 'button' => $b['site'] ? ['Order online next time', $b['site']] : null]),
                ['restaurant_id' => (string)$o['restaurant_id'], 'brand' => $b, 'dedupe' => 'receipt:' . $orderId]);
            return;
        }
        // a dine-in guest is at the table: only the e-bill (and a cancellation) by email
        if (!$msg || ($o['order_type'] === 'Dine-in' && !in_array($s, ['Cancelled', 'Rejected'], true))) return;
        mailQueue($conn, 'order_status', $o['to'], $msg[0] . ' · ' . $o['order_number'],
            mailLayout($b, ['title' => $msg[0], 'preheader' => $msg[1], 'intro' => '<p style="margin:0">' . mailE($msg[1]) . '</p>',
                'rows' => [['Order', (string)$o['order_number']], ['Total', '₹' . number_format((float)$o['total'], 2)]],
                'small' => 'Questions? Call ' . $b['name'] . ($b['phone'] ? ' on ' . $b['phone'] : '') . '.']),
            ['restaurant_id' => (string)$o['restaurant_id'], 'brand' => $b, 'dedupe' => 'status:' . $orderId . ':' . $s]);
    }

    /** The rider picked the order up (config/rider_engine.php): "on the way" with the rider's name and a track link. */
    function mailOrderOnTheWay(PDO $conn, int $orderId): void
    {
        $o = mailOrderFacts($conn, $orderId);
        if (!$o || $o['to'] === '') return;
        if (($pv = mailPreviewing()) !== null) $t = $pv['rider'];
        else {
            $st = $conn->prepare('SELECT rider_name, rider_phone FROM delivery_tracking WHERE order_id = ? LIMIT 1');
            $st->execute([$orderId]);
            $t = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        }
        $b = mailRestaurantBrand($conn, (string)$o['restaurant_id']);
        $rider = trim((string)($t['rider_name'] ?? ''));
        $track = $b['site'] ? $b['site'] . '/track?order_number=' . rawurlencode((string)$o['order_number']) . '&customer_phone=' . rawurlencode((string)$o['customer_phone']) : null;
        mailQueue($conn, 'order_status', $o['to'], 'Your order is on the way 🛵 · ' . $o['order_number'],
            mailLayout($b, ['title' => 'Your order is on the way 🛵', 'preheader' => ($rider ? $rider . ' is bringing it to you.' : 'It has left the restaurant.'),
                'intro' => '<p style="margin:0">' . ($rider ? '<b>' . mailE($rider) . '</b> picked up your order from <b>' . mailE($b['name']) . '</b> and is on the way.' : 'Your order has left <b>' . mailE($b['name']) . '</b> and is on the way.') . ' Watch it come to you live.</p>',
                'rows' => array_values(array_filter([['Order', (string)$o['order_number']], $rider ? ['Delivery partner', $rider . (!empty($t['rider_phone']) ? ' · ' . $t['rider_phone'] : '')] : null,
                    ['To pay', (stripos((string)$o['payment_method'], 'cash') !== false && $o['payment_status'] !== 'Paid') ? '₹' . number_format((float)$o['total'], 2) . ' in cash' : 'Paid']])),
                'button' => $track ? ['Track my order', $track] : null,
                'small' => 'Questions? Call ' . $b['name'] . ($b['phone'] ? ' on ' . $b['phone'] : '') . '.']),
            ['restaurant_id' => (string)$o['restaurant_id'], 'brand' => $b, 'dedupe' => 'onway:' . $orderId]);
    }

    /** Table bookings: received (on create), confirmed / cancelled (status change), reminder (the sweep). */
    function mailBooking(PDO $conn, int $reservationId, string $what): void
    {
        if (($pv = mailPreviewing()) !== null) $r = $pv['booking'];
        else {
            $st = $conn->prepare('SELECT * FROM reservations WHERE id = ?');
            $st->execute([$reservationId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
        }
        if (!$r || empty($r['email'])) return;
        $b = mailRestaurantBrand($conn, (string)$r['restaurant_id']);
        $titles = ['received' => 'We got your table booking', 'Confirmed' => 'Your table is confirmed ✅', 'Cancelled' => 'Your booking was cancelled', 'reminder' => 'See you soon! Your table is booked'];
        if (!isset($titles[$what])) return;
        $when = date('D, d M Y', strtotime((string)$r['reservation_date'])) . ' · ' . $r['time_slot'];
        mailQueue($conn, 'booking', (string)$r['email'], $titles[$what] . ' · ' . $b['name'],
            mailLayout($b, ['title' => $titles[$what], 'preheader' => $when . ' · ' . $r['no_of_guests'] . ' guests',
                'intro' => '<p style="margin:0">Hi ' . mailE(trim(explode(' ', (string)$r['customer_name'])[0]) ?: 'there') . ', '
                    . ['received' => 'thanks for booking at <b>' . mailE($b['name']) . '</b>. The restaurant will confirm it shortly.', 'Confirmed' => '<b>' . mailE($b['name']) . '</b> is expecting you.',
                        'Cancelled' => 'your booking at <b>' . mailE($b['name']) . '</b> was cancelled. Please call the restaurant if this is a surprise.', 'reminder' => 'a reminder of your table at <b>' . mailE($b['name']) . '</b>.'][$what] . '</p>',
                'rows' => [['When', $when], ['Guests', (string)$r['no_of_guests']], ['Name', (string)$r['customer_name']]],
                'small' => 'Need to change it? Call ' . $b['name'] . ($b['phone'] ? ' on ' . $b['phone'] : '') . '.']),
            ['restaurant_id' => (string)$r['restaurant_id'], 'brand' => $b, 'dedupe' => 'booking:' . $reservationId . ':' . $what]);
    }

    function mailCustomerWelcome(PDO $conn, string $rid, string $email, string $name): void
    {
        $b = mailRestaurantBrand($conn, $rid);
        mailQueue($conn, 'customer_welcome', $email, 'Welcome to ' . $b['name'] . '!',
            mailLayout($b, ['title' => 'Welcome, ' . (trim(explode(' ', $name)[0]) ?: 'friend') . '! 👋', 'preheader' => 'Your account at ' . $b['name'] . ' is ready.',
                'intro' => '<p style="margin:0">Your account at <b>' . mailE($b['name']) . '</b> is ready. Order faster next time, track your orders, and collect rewards on every order.</p>',
                'button' => $b['site'] ? ['Order now', $b['site']] : null]),
            ['restaurant_id' => $rid, 'brand' => $b, 'dedupe' => 'cwelcome:' . $rid . ':' . strtolower($email)]);
    }

    // ── date-based emails (made by mailSweep, hourly) ──

    /** Trial ends in 3 days / 1 day / today ($u['d'] = days left), or ended yesterday (-1). */
    function mailTrialEmail(PDO $conn, array $u): ?int
    {
        $b = mailPlatformBrand();
        $login = mailAppUrl() . '/main/admin/login.php';
        $d = (int)$u['d'];
        $first = trim(explode(' ', (string)$u['owner_name'])[0]) ?: 'there';
        if ($d >= 0) {
            $when = $d === 0 ? 'today' : ($d === 1 ? 'tomorrow' : 'in 3 days');
            return mailQueue($conn, 'trial_ending', $u['email'], 'Your RestroGrow trial ends ' . $when,
                mailLayout($b, ['title' => 'Your free trial ends ' . $when, 'preheader' => 'Keep your POS, website and orders running.',
                    'intro' => '<p style="margin:0">Hi ' . mailE($first) . ', the free trial for <b>' . mailE((string)$u['restaurant_name']) . '</b> ends ' . $when . '. Pick a plan to keep your POS, ordering website, loyalty and reports running without a break. Everything you set up stays as it is.</p>',
                    'button' => ['Choose my plan', $login], 'small' => 'Want a longer trial or a custom price? Just reply to this email.']),
                ['restaurant_id' => $u['restaurant_id'], 'brand' => $b, 'dedupe' => 'trial:' . $u['restaurant_id'] . ':' . $d . ':' . date('Y-m-d')]);
        }
        return mailQueue($conn, 'trial_ended', $u['email'], 'Your RestroGrow trial has ended',
            mailLayout($b, ['title' => 'Your free trial has ended', 'intro' => '<p style="margin:0">Hi ' . mailE($first) . ', the trial for <b>' . mailE((string)$u['restaurant_name']) . '</b> ended yesterday. Your menu, customers and settings are safe: choose a plan and you\'re back in a minute.</p>',
                'button' => ['Reactivate my account', $login], 'small' => 'Questions? Reply here or WhatsApp +91 63775 68749.']),
            ['restaurant_id' => $u['restaurant_id'], 'brand' => $b, 'dedupe' => 'trialend:' . $u['restaurant_id']]);
    }

    /** Plan renews in 7 days / 1 day ($u['d']), or ended yesterday (-1). */
    function mailRenewalEmail(PDO $conn, array $u): ?int
    {
        $b = mailPlatformBrand();
        $d = (int)$u['d'];
        $first = trim(explode(' ', (string)$u['owner_name'])[0]) ?: 'there';
        $title = $d < 0 ? 'Your plan has ended' : ($d === 1 ? 'Your plan renews tomorrow' : 'Your plan renews in 7 days');
        return mailQueue($conn, $d < 0 ? 'plan_expired' : 'renewal_due', $u['email'], $title . ' · ' . $u['restaurant_name'],
            mailLayout($b, ['title' => $title, 'intro' => '<p style="margin:0">Hi ' . mailE($first) . ', the RestroGrow plan for <b>' . mailE((string)$u['restaurant_name']) . '</b> ' . ($d < 0 ? 'ended on ' : 'is valid until ') . date('d M Y', strtotime((string)$u['renewal_date'])) . '.' . ($d < 0 ? ' Renew now to keep taking orders.' : ' Renew in time and nothing stops.') . '</p>',
                'button' => [$d < 0 ? 'Renew now' : 'Renew my plan', mailAppUrl() . '/main/admin/login.php'], 'small' => 'Paid already? Ignore this email. Need a GST invoice? Reply here.']),
            ['restaurant_id' => $u['restaurant_id'], 'brand' => $b, 'dedupe' => 'renew:' . $u['restaurant_id'] . ':' . $d . ':' . $u['renewal_date']]);
    }

    /** "Payment received" for a successful subscription_payments row (joined with the owner). */
    function mailPaymentEmail(PDO $conn, array $p): ?int
    {
        $b = mailPlatformBrand();
        $first = trim(explode(' ', (string)$p['owner_name'])[0]) ?: 'there';
        $months = (int)$p['duration_months'];
        return mailQueue($conn, 'payment_receipt', $p['email'], 'Payment received: ₹' . number_format((float)$p['amount'], 2) . ' · RestroGrow',
            mailLayout($b, ['title' => 'Payment received, thank you! 🙏', 'preheader' => '₹' . number_format((float)$p['amount'], 2) . ' for ' . $p['restaurant_name'],
                'intro' => '<p style="margin:0">Hi ' . mailE($first) . ', we received your payment for <b>' . mailE((string)$p['restaurant_name']) . '</b>.</p>',
                'rows' => array_values(array_filter([['Amount', '₹' . number_format((float)$p['amount'], 2)], ['Plan', ucfirst((string)$p['subscription_type']) . ($months ? ' · ' . $months . ' month' . ($months > 1 ? 's' : '') : '')],
                    ['Paid by', ucfirst((string)($p['payment_method'] ?: 'online'))], $p['transaction_id'] ? ['Reference', (string)$p['transaction_id']] : null, ['Date', date('d M Y', strtotime((string)$p['created_at']))]])),
                'small' => 'Need a GST invoice? Reply to this email and we\'ll send it.']),
            ['restaurant_id' => $p['restaurant_id'], 'brand' => $b, 'dedupe' => 'payment:' . $p['id']]);
    }

    /** A loyalty gift (loyalty_rewards_issued row + the customer's name and email) by email. */
    function mailGiftEmail(PDO $conn, array $g): ?int
    {
        $br = mailRestaurantBrand($conn, (string)$g['restaurant_id']);
        $why = ['birthday' => 'Happy birthday! 🎂', 'anniversary' => 'Happy anniversary! 💐', 'winback' => 'We miss you!', 'welcome' => 'A welcome gift for you', 'milestone' => 'Thank you for being a regular!', 'tier_up' => 'You reached a new level!', 'referral' => 'Thanks for spreading the word!'][$g['source']] ?? 'A gift for you 🎁';
        return mailQueue($conn, 'loyalty_gift', $g['email'], $why . ' ' . $g['label'] . ' at ' . $br['name'],
            mailLayout($br, ['title' => $why, 'preheader' => $g['label'] . ' with code ' . $g['coupon_code'],
                'intro' => '<p style="margin:0">Hi ' . mailE(trim(explode(' ', (string)$g['customer_name'])[0]) ?: 'there') . ', here is <b>' . mailE((string)$g['label']) . '</b> from <b>' . mailE($br['name']) . '</b>.</p>'
                    . '<div style="margin:18px 0 0;padding:16px;border:2px dashed #c1440e;border-radius:14px;text-align:center"><div style="font-size:12px;color:#7a6e61;letter-spacing:1px">YOUR CODE</div><div style="font:800 26px Arial,sans-serif;color:#241d16;letter-spacing:2px">' . mailE((string)$g['coupon_code']) . '</div>'
                    . '<div style="font-size:13px;color:#7a6e61">' . ($g['expires_on'] ? 'Valid till ' . date('d M Y', strtotime((string)$g['expires_on'])) : '') . ((float)$g['min_order'] > 0 ? ' · on orders above ₹' . number_format((float)$g['min_order']) : '') . '</div></div>',
                'button' => $br['site'] ? ['Use it now', $br['site']] : null]),
            ['restaurant_id' => $g['restaurant_id'], 'brand' => $br, 'dedupe' => 'gift:' . $g['id']]);
    }

    /** The password-reset email (owner, customer and Super Admin all use this). */
    function mailResetHtml(array $brand, string $intro, string $link, int $minutes, string $title = 'Reset your password'): string
    {
        return mailLayout($brand, ['title' => $title, 'preheader' => 'This link works for ' . $minutes . ' minutes.',
            'intro' => '<p style="margin:0">' . $intro . '</p>', 'button' => ['Choose a new password', $link],
            'after' => '<p style="margin:10px 0 0;font-size:12.5px;color:#9a8e80;word-break:break-all">Or open this link: ' . mailE($link) . '</p>',
            'small' => 'The link works for ' . $minutes . ' minutes. Didn\'t ask for this? Ignore this email, your password stays the same.']);
    }

    // ── the hourly sweep ──
    function mailSweep(PDO $conn, bool $force = false): array
    {
        mailEnsure($conn);
        $done = ['trial' => 0, 'renewal' => 0, 'receipts' => 0, 'gifts' => 0, 'bookings' => 0, 'onboarding' => 0];
        try {
            $last = $conn->query("SELECT v FROM sa_settings WHERE k = 'mail_sweep_at'")->fetchColumn();
            if (!$force && $last && strtotime((string)$last) > time() - 3300) return $done;
            $conn->prepare("REPLACE INTO sa_settings (k, v) VALUES ('mail_sweep_at', ?)")->execute([date('Y-m-d H:i:s')]);
        } catch (Throwable $e) { error_log('mailSweep lock: ' . $e->getMessage()); }
        $run = function (string $k, callable $fn) use (&$done) { try { $done[$k] = $fn(); } catch (Throwable $e) { error_log("mailSweep $k: " . $e->getMessage()); } };

        $run('trial', function () use ($conn) {
            $n = 0;
            $st = $conn->query("SELECT restaurant_id, restaurant_name, owner_name, email, DATEDIFF(trial_end_date, CURDATE()) d FROM users
                WHERE subscription_status = 'trial' AND trial_end_date IS NOT NULL AND DATEDIFF(trial_end_date, CURDATE()) IN (3, 1, 0, -1) AND email <> '' AND is_active = 1");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) if (mailTrialEmail($conn, $u)) $n++;
            return $n;
        });

        $run('renewal', function () use ($conn) {
            $n = 0;
            $st = $conn->query("SELECT restaurant_id, restaurant_name, owner_name, email, renewal_date, DATEDIFF(renewal_date, CURDATE()) d FROM users
                WHERE subscription_status = 'active' AND renewal_date IS NOT NULL AND DATEDIFF(renewal_date, CURDATE()) IN (7, 1, -1) AND email <> '' AND is_active = 1");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) if (mailRenewalEmail($conn, $u)) $n++;
            return $n;
        });

        $run('receipts', function () use ($conn) {
            $n = 0;
            $st = $conn->query("SELECT p.id, p.restaurant_id, p.amount, p.subscription_type, p.duration_months, p.payment_method, p.transaction_id, p.created_at, u.restaurant_name, u.owner_name, u.email
                FROM subscription_payments p JOIN users u ON u.restaurant_id = p.restaurant_id
                WHERE p.payment_status = 'success' AND p.amount > 0 AND p.created_at > NOW() - INTERVAL 2 DAY AND u.email <> ''");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) if (mailPaymentEmail($conn, $p)) $n++;
            return $n;
        });

        // loyalty gifts for customers who have an email but don't get WhatsApp messages
        $run('gifts', function () use ($conn) {
            $n = 0;
            $st = $conn->query("SELECT r.id, r.restaurant_id, r.label, r.coupon_code, r.expires_on, r.min_order, r.source, c.customer_name, c.email
                FROM loyalty_rewards_issued r JOIN customers c ON c.id = r.customer_id
                WHERE r.created_at > NOW() - INTERVAL 2 DAY AND c.email IS NOT NULL AND c.email <> '' AND COALESCE(c.whatsapp_optin, 0) = 0 AND COALESCE(r.notify_status, '') <> 'sent'");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) if (mailGiftEmail($conn, $g)) $n++;
            return $n;
        });

        // unfinished sign-ups become leads; setup reminders on day 1, 3, 7 (config/onboarding.php)
        $run('onboarding', function () use ($conn) {
            require_once __DIR__ . '/onboarding.php';
            $o = obSweep($conn);
            return $o['leads'] + $o['nudges'];
        });

        // booking reminders: confirmed bookings for today, sent in the morning
        $run('bookings', function () use ($conn) {
            if ((int)date('G') < 8) return 0;
            $n = 0;
            $st = $conn->query("SELECT id FROM reservations WHERE reservation_date = CURDATE() AND status IN ('Confirmed', 'confirmed') AND email IS NOT NULL AND email <> ''");
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { mailBooking($conn, (int)$id, 'reminder'); $n++; }
            return $n;
        });
        return $done;
    }
}
