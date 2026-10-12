<?php
/**
 * Loyalty card in the phone's wallet (Loyalty Studio › Phone card).
 *
 * Google Wallet: a "Save to Google Wallet" link carrying a signed JWT with the
 *   restaurant's loyalty class and the member's object. Balance changes are
 *   pushed with the Wallet REST API (PATCH loyaltyObject).
 * Apple Wallet: a signed .pkpass (store card). Wallet registers the device with
 *   website/wallet_card_service.php; on a balance change we send an empty APNs
 *   push and Wallet fetches the fresh pass.
 *
 * RestroGrow-wide credentials in .env (set once by the RestroGrow team):
 *   GOOGLE_WALLET_ISSUER_ID, GOOGLE_WALLET_SERVICE_ACCOUNT (path to the JSON key)
 *   APPLE_PASS_TYPE_ID, APPLE_TEAM_ID, APPLE_PASS_CERT (path to .p12),
 *   APPLE_PASS_CERT_PASSWORD, APPLE_WWDR_CERT (path to Apple WWDR .pem)
 * Each restaurant switches its card on and picks its colour in the Studio.
 */
require_once __DIR__ . '/env_loader.php';
require_once __DIR__ . '/loyalty_engine.php';
require_once __DIR__ . '/growth_helpers.php';

if (!function_exists('ensureWalletCardSchema')) {

    function ensureWalletCardSchema(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS wallet_cards (
            id INT AUTO_INCREMENT PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            customer_id INT NOT NULL,
            platform VARCHAR(10) NOT NULL,
            serial VARCHAR(80) NOT NULL,
            auth_token VARCHAR(64) NOT NULL,
            state_hash VARCHAR(40) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY uq_wc (restaurant_id, customer_id, platform),
            UNIQUE KEY uq_wc_serial (serial)
        )");
        $conn->exec("CREATE TABLE IF NOT EXISTS wallet_card_devices (
            device_id VARCHAR(100) NOT NULL,
            serial VARCHAR(80) NOT NULL,
            push_token VARCHAR(200) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (device_id, serial)
        )");
        try {
            $q = $conn->query("SHOW COLUMNS FROM growth_settings LIKE 'card_enabled'");
            if (!($q && $q->fetch())) $conn->exec("ALTER TABLE growth_settings ADD COLUMN card_enabled TINYINT NOT NULL DEFAULT 0, ADD COLUMN card_color VARCHAR(7) NULL, ADD COLUMN card_text_color VARCHAR(7) NULL");
        } catch (PDOException $e) { error_log('ensureWalletCardSchema: ' . $e->getMessage()); }
    }

    /** What the RestroGrow team has set up. */
    function walletCardPlatforms(): array {
        $g = (string)env('GOOGLE_WALLET_SERVICE_ACCOUNT', '');
        $a = (string)env('APPLE_PASS_CERT', '');
        return [
            'google' => env('GOOGLE_WALLET_ISSUER_ID') && $g !== '' && is_readable($g),
            'apple' => env('APPLE_PASS_TYPE_ID') && env('APPLE_TEAM_ID') && $a !== '' && is_readable($a) && is_readable((string)env('APPLE_WWDR_CERT', '')),
        ];
    }

    function walletCardSettings(PDO $conn, string $rid): array {
        $s = getGrowthSettings($conn, $rid);
        $c = preg_match('/^#[0-9a-f]{6}$/i', (string)($s['card_color'] ?? '')) ? $s['card_color'] : '#1c1230';
        $t = preg_match('/^#[0-9a-f]{6}$/i', (string)($s['card_text_color'] ?? '')) ? $s['card_text_color'] : '#ffffff';
        return ['enabled' => (int)($s['card_enabled'] ?? 0), 'color' => $c, 'text_color' => $t, 'loyalty_enabled' => (int)$s['loyalty_enabled'], 'settings' => $s];
    }

    /** Everything a card shows, for one member. */
    function walletCardContent(PDO $conn, string $rid, array $customer): array {
        $cs = walletCardSettings($conn, $rid);
        $s = $cs['settings'];
        $u = $conn->prepare('SELECT id, restaurant_name, currency_symbol, restaurant_logo, (logo_data IS NOT NULL) has_blob FROM users WHERE restaurant_id = ? LIMIT 1');
        $u->execute([$rid]);
        $r = $u->fetch(PDO::FETCH_ASSOC) ?: [];
        $cur = (string)($r['currency_symbol'] ?? '') ?: '₹';
        $type = loyaltyProgramType($s);
        $phone = (string)$customer['phone'];
        $done = $conn->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE restaurant_id = ? AND customer_phone = ? AND order_status = 'Completed' AND deleted_at IS NULL");
        $done->execute([$rid, $phone]);
        $spent = max((float)($customer['total_spent'] ?? 0), (float)$done->fetchColumn());
        $tier = getCustomerTier($conn, $rid, $spent);
        if ($type === 'cashback') { $label = 'Wallet'; $value = $cur . number_format(loyaltyWalletBalance($conn, $rid, $phone), 0); }
        elseif ($type === 'stamps') { $label = 'Stamps'; $value = (int)($customer['stamps'] ?? 0) . ' / ' . (int)$s['stamp_goal']; }
        else { $label = 'Points'; $value = number_format((int)($customer['loyalty_points_balance'] ?? 0)); }
        $gifts = 0;
        try {
            $g = $conn->prepare("SELECT COUNT(*) FROM coupons WHERE restaurant_id = ? AND owner_phone = ? AND is_active = 1 AND current_uses < max_uses AND (valid_until IS NULL OR valid_until >= CURDATE())");
            $g->execute([$rid, $phone]);
            $gifts = (int)$g->fetchColumn();
        } catch (PDOException $e) {}
        $vip = null;
        if (function_exists('vipActiveMembership')) { $m = vipActiveMembership($conn, $rid, $phone); if ($m) $vip = $m['plan_name']; }
        $base = rtrim((string)env('APP_URL', ''), '/');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if ($base === '' && !empty($_SERVER['HTTP_HOST'])) $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . (strpos($_SERVER['REQUEST_URI'] ?? '', '/menuwebsite/') === 0 ? '/menuwebsite' : '');
        if ($base === '') $base = 'https://restrogrow.com';   // cron runs have no request to read the domain from
        // same rules as the website header (header.php)
        $logo = (string)($r['restaurant_logo'] ?? '');
        $logoUrl = null;
        if ($logo !== '' && strpos($logo, 'http') === 0) $logoUrl = $logo;
        elseif ($logo !== '' && strpos($logo, 'db:') !== 0) $logoUrl = $base . '/main/website/image.php?type=file&path=' . rawurlencode('../' . $logo);
        elseif (!empty($r['has_blob']) || strpos($logo, 'db:') === 0) $logoUrl = $base . '/main/website/image.php?type=logo&id=' . rawurlencode((string)$r['id']);
        return [
            'restaurant' => (string)($r['restaurant_name'] ?? 'Rewards'), 'logo_url' => $logoUrl, 'color' => $cs['color'], 'text_color' => $cs['text_color'],
            'name' => trim((string)($customer['customer_name'] ?? '')) ?: 'Member', 'phone' => $phone,
            'program' => $type, 'balance_label' => $label, 'balance' => $value,
            'tier' => $tier['tier_name'] ?? null, 'vip' => $vip, 'gifts' => $gifts,
            'link' => ($slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($r['restaurant_name'] ?? ''))), '-')) !== ''
                ? $base . '/' . $slug . '/loyalty' : $base . '/main/website/loyalty.php?restaurant_id=' . rawurlencode($rid),
            'base' => $base,
            'barcode' => $phone, 'barcode_alt' => 'Member ' . substr($phone, 0, 2) . '••••' . substr($phone, -4),
        ];
    }

    function walletCardRecord(PDO $conn, string $rid, int $cid, string $platform): array {
        $st = $conn->prepare('SELECT * FROM wallet_cards WHERE restaurant_id = ? AND customer_id = ? AND platform = ?');
        $st->execute([$rid, $cid, $platform]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
        $serial = $platform[0] . substr(hash('sha256', $rid . '|' . $cid . '|' . random_bytes(8)), 0, 24);
        $conn->prepare('INSERT IGNORE INTO wallet_cards (restaurant_id, customer_id, platform, serial, auth_token) VALUES (?, ?, ?, ?, ?)')
             ->execute([$rid, $cid, $platform, $serial, bin2hex(random_bytes(16))]);
        $st->execute([$rid, $cid, $platform]);
        return $st->fetch(PDO::FETCH_ASSOC);
    }

    function walletCardHash(array $c): string { return sha1(json_encode([$c['name'], $c['balance'], $c['tier'], $c['vip'], $c['gifts'], $c['color'], $c['restaurant']])); }

    /* ───────────── Google Wallet ───────────── */

    function gwB64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    function gwServiceAccount(): ?array {
        $f = (string)env('GOOGLE_WALLET_SERVICE_ACCOUNT', '');
        $j = $f !== '' && is_readable($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($j) && !empty($j['client_email']) && !empty($j['private_key']) ? $j : null;
    }
    function gwSign(array $claims, array $sa): string {
        $h = gwB64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $p = gwB64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        openssl_sign($h . '.' . $p, $sig, $sa['private_key'], OPENSSL_ALGO_SHA256);
        return $h . '.' . $p . '.' . gwB64($sig);
    }
    function gwIds(string $rid, string $serial): array {
        $iss = (string)env('GOOGLE_WALLET_ISSUER_ID', '');
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $rid);
        return [$iss . '.rg_' . $safe, $iss . '.' . $serial];
    }
    function gwClass(string $classId, array $c): array {
        $cls = ['id' => $classId, 'issuerName' => mb_substr($c['restaurant'], 0, 60), 'programName' => mb_substr($c['restaurant'] . ' Rewards', 0, 60),
                'reviewStatus' => 'UNDER_REVIEW', 'hexBackgroundColor' => $c['color'], 'multipleDevicesAndHoldersAllowedStatus' => 'ONE_USER_ALL_DEVICES'];
        if ($c['logo_url']) $cls['programLogo'] = ['sourceUri' => ['uri' => $c['logo_url']], 'contentDescription' => ['defaultValue' => ['language' => 'en-US', 'value' => $c['restaurant']]]];
        return $cls;
    }
    function gwObject(string $objectId, string $classId, array $c): array {
        $o = ['id' => $objectId, 'classId' => $classId, 'state' => 'ACTIVE', 'accountId' => $c['phone'], 'accountName' => $c['name'],
              'loyaltyPoints' => ['label' => $c['balance_label'], 'balance' => ['string' => $c['balance']]],
              'barcode' => ['type' => 'QR_CODE', 'value' => $c['barcode'], 'alternateText' => $c['barcode_alt']],
              'linksModuleData' => ['uris' => [['uri' => $c['link'], 'description' => 'Your rewards, gifts and offers', 'id' => 'rewards']]],
              'textModulesData' => array_values(array_filter([
                  $c['vip'] ? ['id' => 'vip', 'header' => 'Membership', 'body' => $c['vip']] : null,
                  ['id' => 'gifts', 'header' => 'Gifts ready', 'body' => $c['gifts'] ? $c['gifts'] . ' gift' . ($c['gifts'] > 1 ? 's' : '') . ' to use' : 'None right now'],
              ]))];
        if ($c['tier']) $o['secondaryLoyaltyPoints'] = ['label' => 'Level', 'balance' => ['string' => $c['tier']]];
        return $o;
    }
    /** "Save to Google Wallet" link for a member. */
    function walletCardGoogleUrl(PDO $conn, string $rid, array $customer): ?string {
        $sa = gwServiceAccount();
        if (!$sa || !env('GOOGLE_WALLET_ISSUER_ID')) return null;
        $rec = walletCardRecord($conn, $rid, (int)$customer['id'], 'google');
        $c = walletCardContent($conn, $rid, $customer);
        [$classId, $objectId] = gwIds($rid, $rec['serial']);
        $host = parse_url($c['link'], PHP_URL_SCHEME) . '://' . parse_url($c['link'], PHP_URL_HOST);
        $jwt = gwSign(['iss' => $sa['client_email'], 'aud' => 'google', 'typ' => 'savetowallet', 'iat' => time(), 'origins' => [$host],
                       'payload' => ['loyaltyClasses' => [gwClass($classId, $c)], 'loyaltyObjects' => [gwObject($objectId, $classId, $c)]]], $sa);
        $conn->prepare('UPDATE wallet_cards SET state_hash = ?, updated_at = NOW() WHERE id = ?')->execute([walletCardHash($c), $rec['id']]);
        return 'https://pay.google.com/gp/v/save/' . $jwt;
    }
    function gwAccessToken(array $sa): ?string {
        static $tok = null, $exp = 0;
        if ($tok && $exp > time() + 60) return $tok;
        $jwt = gwSign(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600], $sa);
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
            CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt])]);
        $d = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        if (empty($d['access_token'])) return null;
        $tok = $d['access_token']; $exp = time() + (int)($d['expires_in'] ?? 3000);
        return $tok;
    }
    function gwPatch(string $objectId, array $obj): bool {
        $sa = gwServiceAccount(); if (!$sa) return false;
        $t = gwAccessToken($sa); if (!$t) return false;
        unset($obj['id'], $obj['classId']);
        $ch = curl_init('https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/' . rawurlencode($objectId));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'PATCH', CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($obj, JSON_UNESCAPED_UNICODE)]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code >= 200 && $code < 300 || $code === 404;   // 404: the member never saved it, nothing to update
    }

    /* ───────────── Apple Wallet ───────────── */

    /** Minimal zip writer (stored entries), so .pkpass works without the zip extension. */
    function apZip(array $files): string {
        $data = ''; $central = ''; $n = 0;
        $t = getdate(); $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2); $dosDate = (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
        foreach ($files as $name => $body) {
            $crc = crc32($body); $len = strlen($body); $off = strlen($data);
            $data .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0) . $name . $body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $len, $len, strlen($name), 0, 0, 0, 0, 0, $off) . $name;
            $n++;
        }
        return $data . $central . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($central), strlen($data), 0);
    }
    function apHexRgb(string $hex): string { $h = ltrim($hex, '#'); return sprintf('rgb(%d, %d, %d)', hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))); }
    /** Icon / logo PNGs: the restaurant logo if we can load it, else its initial on the card colour. */
    function apImages(array $c): array {
        $src = null;
        if ($c['logo_url'] && function_exists('imagecreatefromstring')) {
            $ctx = stream_context_create(['http' => ['timeout' => 4], 'https' => ['timeout' => 4]]);
            $raw = @file_get_contents($c['logo_url'], false, $ctx);
            if ($raw) $src = @imagecreatefromstring($raw);
        }
        $make = function ($w, $h, $round) use ($src, $c) {
            $im = imagecreatetruecolor($w, $h);
            imagesavealpha($im, true);
            imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
            if ($src) {
                $sw = imagesx($src); $sh = imagesy($src); $sc = min($w / $sw, $h / $sh);
                imagecopyresampled($im, $src, (int)(($w - $sw * $sc) / 2), (int)(($h - $sh * $sc) / 2), 0, 0, (int)($sw * $sc), (int)($sh * $sc), $sw, $sh);
            } else {
                $h6 = ltrim($c['color'], '#'); $bg = imagecolorallocate($im, hexdec(substr($h6, 0, 2)), hexdec(substr($h6, 2, 2)), hexdec(substr($h6, 4, 2)));
                $d = min($w, $h);
                imagefilledellipse($im, (int)($d / 2), (int)($h / 2), $d, $d, $bg);
                $fg = imagecolorallocate($im, 255, 224, 138);
                $ch = strtoupper(mb_substr($c['restaurant'], 0, 1)) ?: 'R';
                imagestring($im, 5, (int)($d / 2 - 4), (int)($h / 2 - 7), $ch, $fg);
            }
            ob_start(); imagepng($im); imagedestroy($im); return ob_get_clean();
        };
        return ['icon.png' => $make(29, 29, true), 'icon@2x.png' => $make(58, 58, true), 'logo.png' => $make(50, 50, true), 'logo@2x.png' => $make(100, 100, true)];
    }
    /** The signed .pkpass for a member (string), or null when Apple is not set up. */
    function walletCardApplePass(PDO $conn, string $rid, array $customer, ?array $rec = null): ?string {
        if (!walletCardPlatforms()['apple']) return null;
        $rec = $rec ?: walletCardRecord($conn, $rid, (int)$customer['id'], 'apple');
        $c = walletCardContent($conn, $rid, $customer);
        $base = $c['base'];
        $pass = [
            'formatVersion' => 1, 'passTypeIdentifier' => env('APPLE_PASS_TYPE_ID'), 'serialNumber' => $rec['serial'], 'teamIdentifier' => env('APPLE_TEAM_ID'),
            'organizationName' => $c['restaurant'], 'description' => $c['restaurant'] . ' rewards card', 'logoText' => $c['restaurant'],
            'backgroundColor' => apHexRgb($c['color']), 'foregroundColor' => apHexRgb($c['text_color']), 'labelColor' => 'rgb(245, 196, 81)',
            'webServiceURL' => $base . '/main/website/wallet_card_service.php', 'authenticationToken' => $rec['auth_token'],
            'storeCard' => [
                'headerFields' => [['key' => 'balance', 'label' => strtoupper($c['balance_label']), 'value' => $c['balance'], 'changeMessage' => 'Your ' . strtolower($c['balance_label']) . ': %@']],
                'primaryFields' => [['key' => 'name', 'label' => 'MEMBER', 'value' => $c['name']]],
                'secondaryFields' => array_values(array_filter([
                    $c['tier'] ? ['key' => 'tier', 'label' => 'LEVEL', 'value' => $c['tier'], 'changeMessage' => 'You reached %@'] : null,
                    $c['vip'] ? ['key' => 'vip', 'label' => 'MEMBERSHIP', 'value' => $c['vip']] : null,
                    ['key' => 'gifts', 'label' => 'GIFTS READY', 'value' => (string)$c['gifts'], 'changeMessage' => 'You have %@ gift(s) ready to use'],
                ])),
                'backFields' => [
                    ['key' => 'how', 'label' => 'How it works', 'value' => 'Show this card when you order at the counter, or order online with the same phone number. Your balance updates here by itself.'],
                    ['key' => 'link', 'label' => 'Your rewards', 'value' => $c['link']],
                ],
            ],
            'barcodes' => [['format' => 'PKBarcodeFormatQR', 'message' => $c['barcode'], 'messageEncoding' => 'iso-8859-1', 'altText' => $c['barcode_alt']]],
        ];
        $files = ['pass.json' => json_encode($pass, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] + apImages($c);
        $manifest = [];
        foreach ($files as $n => $b) $manifest[$n] = sha1($b);
        $files['manifest.json'] = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        $sig = apSign($files['manifest.json']);
        if ($sig === null) return null;
        $files['signature'] = $sig;
        $conn->prepare('UPDATE wallet_cards SET state_hash = ?, updated_at = NOW() WHERE id = ?')->execute([walletCardHash($c), $rec['id']]);
        return apZip($files);
    }
    /** Detached PKCS#7 (DER) signature of the manifest with the pass certificate. */
    function apSign(string $manifest): ?string {
        $p12 = @file_get_contents((string)env('APPLE_PASS_CERT', ''));
        if (!$p12 || !openssl_pkcs12_read($p12, $certs, (string)env('APPLE_PASS_CERT_PASSWORD', ''))) { error_log('Apple pass: cannot read the .p12'); return null; }
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'pm'); $out = tempnam($dir, 'ps');
        file_put_contents($in, $manifest);
        $ok = openssl_pkcs7_sign($in, $out, $certs['cert'], $certs['pkey'], [], PKCS7_BINARY | PKCS7_DETACHED, (string)env('APPLE_WWDR_CERT', ''));
        $smime = $ok ? (string)file_get_contents($out) : '';
        @unlink($in); @unlink($out);
        if (!$ok) { error_log('Apple pass: signing failed ' . openssl_error_string()); return null; }
        // S/MIME → the base64 signature part → DER
        if (!preg_match('/Content-Disposition: attachment; filename="smime\.p7s"\s+(.+?)\s+------/s', $smime, $m)) return null;
        return base64_decode($m[1]);
    }
    /** Tell Apple a pass changed (Wallet then asks wallet_card_service.php for it). */
    function apPush(string $pushToken): bool {
        $p12 = @file_get_contents((string)env('APPLE_PASS_CERT', ''));
        if (!$p12 || !openssl_pkcs12_read($p12, $certs, (string)env('APPLE_PASS_CERT_PASSWORD', ''))) return false;
        $pem = tempnam(sys_get_temp_dir(), 'ap');
        openssl_pkey_export($certs['pkey'], $key);
        file_put_contents($pem, $certs['cert'] . $key);
        $ch = curl_init('https://api.push.apple.com/3/device/' . $pushToken);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => '{}', CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0, CURLOPT_SSLCERT => $pem, CURLOPT_HTTPHEADER => ['apns-topic: ' . env('APPLE_PASS_TYPE_ID')]]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        @unlink($pem);
        return $code === 200;
    }

    /**
     * Push balance changes to cards already in phones. Cheap when nothing changed;
     * runs with the WhatsApp heartbeat and its cron. Returns how many cards updated.
     */
    function walletCardsSync(PDO $conn, string $rid, int $limit = 25): int {
        ensureWalletCardSchema($conn);
        $cs = walletCardSettings($conn, $rid);
        if (!$cs['enabled']) return 0;
        $p = walletCardPlatforms();
        if (!$p['google'] && !$p['apple']) return 0;
        $st = $conn->prepare('SELECT w.*, c.* , w.id card_id FROM wallet_cards w JOIN customers c ON c.id = w.customer_id WHERE w.restaurant_id = ? ORDER BY w.updated_at LIMIT 300');
        $st->execute([$rid]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($n >= $limit) break;
            $c = walletCardContent($conn, $rid, $row);
            $hash = walletCardHash($c);
            if ($hash === $row['state_hash']) continue;
            $ok = false;
            if ($row['platform'] === 'google' && $p['google']) {
                [$classId, $objectId] = gwIds($rid, $row['serial']);
                $ok = gwPatch($objectId, gwObject($objectId, $classId, $c));
            } elseif ($row['platform'] === 'apple' && $p['apple']) {
                $d = $conn->prepare('SELECT push_token FROM wallet_card_devices WHERE serial = ?');
                $d->execute([$row['serial']]);
                $tokens = $d->fetchAll(PDO::FETCH_COLUMN);
                $ok = true;
                foreach ($tokens as $t) apPush($t);
            }
            if ($ok) {
                $conn->prepare('UPDATE wallet_cards SET state_hash = ?, updated_at = NOW() WHERE id = ?')->execute([$hash, $row['card_id']]);
                $n++;
            }
        }
        return $n;
    }
}
