<?php
/**
 * Where a visitor is, from their IP: country, state and city (restrogrow.com visitors and leads).
 *
 *  - The free DB-IP "IP to City Lite" database (CC BY 4.0: credit "IP Geolocation by DB-IP", shown in Super Admin
 *    › Website analytics), a MaxMind-DB (.mmdb) file read here directly: no outside service per visitor, nothing
 *    to pay, and it works for every visitor. City is a good guess, not exact (mobile networks often show the
 *    operator's nearest big city).
 *  - The file (~130 MB) lives in main/uploads/cache/geo/ (not in git, denied to the web). geoUpdate() downloads
 *    this month's copy: Super Admin › Website analytics › Settings, and by itself once a month from the email cron.
 *  - Answers are cached per IP in ip_geo, so each address is looked up once.
 */

if (!function_exists('geoLookup')) {

    define('GEO_DIR', __DIR__ . '/../uploads/cache/geo');
    define('GEO_FILE', GEO_DIR . '/dbip-city-lite.mmdb');

    /**
     * The visitor's real IP. Behind Hostinger's CDN (and Cloudflare) the connection comes from the CDN, which
     * adds the visitor's address to X-Forwarded-For / its own header. For analytics only, never for security.
     */
    function geoClientIp(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP'] as $h) {
            $v = trim((string)($_SERVER[$h] ?? ''));
            if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $v;
        }
        // the proxy appends the address it saw: read from the right, first public one
        $xff = array_reverse(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($xff as $v) if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $v;
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    function geoEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS ip_geo (
            ip VARCHAR(45) PRIMARY KEY,
            country_code CHAR(2) NULL,
            country VARCHAR(80) NULL,
            region VARCHAR(100) NULL,
            city VARCHAR(100) NULL,
            lat DECIMAL(8,4) NULL,
            lon DECIMAL(8,4) NULL,
            looked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** ['country_code', 'country', 'region', 'city', 'lat', 'lon'] for an IP (empty strings when unknown). Cached. */
    function geoLookup(PDO $conn, string $ip): array
    {
        $none = ['country_code' => '', 'country' => '', 'region' => '', 'city' => '', 'lat' => null, 'lon' => null];
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return $none;
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return ['country' => 'Local network'] + $none;
        geoEnsure($conn);
        $st = $conn->prepare('SELECT country_code, country, region, city, lat, lon FROM ip_geo WHERE ip = ? AND looked_at > NOW() - INTERVAL 60 DAY');
        $st->execute([$ip]);
        if ($r = $st->fetch(PDO::FETCH_ASSOC)) return array_map(fn($v) => $v ?? '', $r);
        $rec = geoMmdbLookup(GEO_FILE, $ip);
        if ($rec === null) return $none;   // no database yet: tried again next time
        $sub = $rec['subdivisions'][0] ?? [];
        $out = [
            'country_code' => strtoupper(substr((string)($rec['country']['iso_code'] ?? ''), 0, 2)),
            'country' => mb_substr((string)($rec['country']['names']['en'] ?? ''), 0, 80),
            'region' => mb_substr((string)($sub['names']['en'] ?? ''), 0, 100),
            'city' => mb_substr((string)($rec['city']['names']['en'] ?? ''), 0, 100),
            'lat' => isset($rec['location']['latitude']) ? round((float)$rec['location']['latitude'], 4) : null,
            'lon' => isset($rec['location']['longitude']) ? round((float)$rec['location']['longitude'], 4) : null,
        ];
        $conn->prepare('REPLACE INTO ip_geo (ip, country_code, country, region, city, lat, lon, looked_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())')
            ->execute([$ip, $out['country_code'] ?: null, $out['country'] ?: null, $out['region'] ?: null, $out['city'] ?: null, $out['lat'], $out['lon']]);
        return $out;
    }

    /** "Bengaluru, Karnataka, India" (the parts known). */
    function geoLabel(array $g): string
    {
        return implode(', ', array_values(array_unique(array_filter([$g['city'] ?? '', $g['region'] ?? '', $g['country'] ?? '']))));
    }

    /** The database file's age and size, for Super Admin. */
    function geoStatus(): array
    {
        $ok = is_file(GEO_FILE);
        return ['ready' => $ok, 'updated' => $ok ? date('Y-m-d', (int)filemtime(GEO_FILE)) : null, 'mb' => $ok ? round(filesize(GEO_FILE) / 1048576) : 0];
    }

    /**
     * Download this month's DB-IP City Lite (else last month's) and swap it in. Returns [ok, message].
     * About 60 MB to download; runs from Super Admin or the monthly cron, never on a visitor's page view.
     */
    function geoUpdate(): array
    {
        @set_time_limit(600);
        if (!is_dir(GEO_DIR) && !@mkdir(GEO_DIR, 0755, true)) return [false, 'Could not create ' . GEO_DIR];
        // the file is public data, but it has no business being downloaded from our site
        if (!is_file(GEO_DIR . '/.htaccess')) @file_put_contents(GEO_DIR . '/.htaccess', "Require all denied\n");
        $last = '';
        foreach ([date('Y-m'), date('Y-m', strtotime('first day of last month'))] as $month) {
            $gz = GEO_DIR . '/download.mmdb.gz';
            $fh = fopen($gz, 'wb');
            $ch = curl_init('https://download.db-ip.com/free/dbip-city-lite-' . $month . '.mmdb.gz');
            $opts = [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 500, CURLOPT_FAILONERROR => true];
            $ca = function_exists('env') ? (string)env('AI_CAINFO', '') : '';
            if ($ca !== '' && is_readable($ca)) $opts[CURLOPT_CAINFO] = $ca;   // local XAMPP has no CA bundle
            curl_setopt_array($ch, $opts);
            $ok = curl_exec($ch);
            $last = curl_error($ch) ?: ('HTTP ' . curl_getinfo($ch, CURLINFO_HTTP_CODE));
            curl_close($ch);
            fclose($fh);
            if (!$ok || filesize($gz) < 1000000) { @unlink($gz); continue; }
            // unpack to a temporary file, check it reads, then swap
            $tmp = GEO_FILE . '.new';
            $in = gzopen($gz, 'rb');
            $out = fopen($tmp, 'wb');
            while (!gzeof($in)) fwrite($out, gzread($in, 1048576));
            gzclose($in);
            fclose($out);
            @unlink($gz);
            if (geoMmdbLookup($tmp, '8.8.8.8') === null) { @unlink($tmp); return [false, 'The downloaded file could not be read.']; }
            if (!@rename($tmp, GEO_FILE)) { @unlink(GEO_FILE); @rename($tmp, GEO_FILE); }
            return [true, 'Location database updated (' . $month . ', ' . round(filesize(GEO_FILE) / 1048576) . ' MB).'];
        }
        return [false, 'Could not download the location database: ' . $last];
    }

    /** Download a new copy when it is missing or over 35 days old (the email cron calls this; cheap when fresh). */
    function geoUpdateIfOld(): ?array
    {
        $s = geoStatus();
        if ($s['ready'] && strtotime((string)$s['updated']) > time() - 35 * 86400) return null;
        return geoUpdate();
    }

    // ── a small reader for the MaxMind DB format (https://maxmind.github.io/MaxMind-DB/) ──

    /** The record for an IP from an .mmdb file, or null (no file, not found, unreadable). */
    function geoMmdbLookup(string $file, string $ip): ?array
    {
        static $open = [];
        try {
            if (!isset($open[$file])) {
                if (!is_file($file)) return null;
                $fh = fopen($file, 'rb');
                $size = filesize($file);
                $tailLen = min(131072, $size);
                fseek($fh, $size - $tailLen);
                $tail = fread($fh, $tailLen);
                $pos = strrpos($tail, "\xAB\xCD\xEFMaxMind.com");
                if ($pos === false) return null;
                $metaAt = $size - $tailLen + $pos + 14;
                $db = ['fh' => $fh, 'data' => 0];
                [$meta] = geoMmdbDecode($db, $metaAt, $metaAt);
                $nodes = (int)$meta['node_count'];
                $rec = (int)$meta['record_size'];
                $tree = (int)($rec * 2 / 8 * $nodes);
                $db += ['nodes' => $nodes, 'rec' => $rec, 'tree' => $tree, 'v' => (int)$meta['ip_version'], 'ipv4' => null];
                $db['data'] = $tree + 16;
                $open[$file] = $db;
            }
            $db = &$open[$file];
            $bin = inet_pton($ip);
            if ($bin === false) return null;
            $bits = strlen($bin) * 8;
            $node = 0;
            if ($db['v'] === 6 && $bits === 32) {
                // IPv4 addresses sit under 96 zero bits in an IPv6 tree; found once and remembered
                if ($db['ipv4'] === null) {
                    $n = 0;
                    for ($i = 0; $i < 96 && $n < $db['nodes']; $i++) $n = geoMmdbRecord($db, $n, 0);
                    $db['ipv4'] = $n;
                }
                $node = $db['ipv4'];
            }
            for ($i = 0; $i < $bits && $node < $db['nodes']; $i++) {
                $bit = (ord($bin[$i >> 3]) >> (7 - ($i & 7))) & 1;
                $node = geoMmdbRecord($db, $node, $bit);
            }
            if ($node <= $db['nodes']) return null;   // == nodes: no data for this address
            $at = $db['tree'] + ($node - $db['nodes']);
            [$val] = geoMmdbDecode($db, $at, $db['data']);
            return is_array($val) ? $val : null;
        } catch (Throwable $e) {
            error_log('geoMmdbLookup: ' . $e->getMessage());
            return null;
        }
    }

    function geoMmdbRecord(array $db, int $node, int $bit): int
    {
        $bytes = $db['rec'] / 4;   // a node holds two records
        fseek($db['fh'], $node * $bytes);
        $b = fread($db['fh'], (int)$bytes);
        switch ($db['rec']) {
            case 24:
                $s = $bit ? substr($b, 3, 3) : substr($b, 0, 3);
                return unpack('N', "\0" . $s)[1];
            case 28:
                if ($bit) return ((ord($b[3]) & 0x0F) << 24) | unpack('N', "\0" . substr($b, 4, 3))[1];
                return ((ord($b[3]) & 0xF0) << 20) | unpack('N', "\0" . substr($b, 0, 3))[1];
            case 32:
                return unpack('N', $bit ? substr($b, 4, 4) : substr($b, 0, 4))[1];
        }
        throw new RuntimeException('Unknown record size ' . $db['rec']);
    }

    /** Decode the value at $at (a file offset); pointers count from $base. Returns [value, next offset]. */
    function geoMmdbDecode(array $db, int $at, int $base): array
    {
        $read = function (int $n) use ($db, &$at): string { if ($n <= 0) return ''; fseek($db['fh'], $at); $at += $n; return (string)fread($db['fh'], $n); };
        $ctrl = ord($read(1));
        $type = $ctrl >> 5;
        if ($type === 1) {   // pointer
            $ss = ($ctrl >> 3) & 3;
            $v = $ctrl & 7;
            if ($ss === 0) $p = ($v << 8) | ord($read(1));
            elseif ($ss === 1) $p = (($v << 16) | unpack('n', $read(2))[1]) + 2048;
            elseif ($ss === 2) $p = (($v << 24) | unpack('N', "\0" . $read(3))[1]) + 526336;
            else $p = unpack('N', $read(4))[1];
            [$val] = geoMmdbDecode($db, $base + $p, $base);
            return [$val, $at];
        }
        if ($type === 0) $type = 7 + ord($read(1));
        $size = $ctrl & 0x1F;
        if ($size === 29) $size = 29 + ord($read(1));
        elseif ($size === 30) $size = 285 + unpack('n', $read(2))[1];
        elseif ($size === 31) $size = 65821 + unpack('N', "\0" . $read(3))[1];
        $uint = function (string $s): int { $n = 0; foreach (str_split($s) as $c) $n = ($n << 8) | ord($c); return $n; };
        switch ($type) {
            case 2: return [$read($size), $at];                                   // UTF-8 string
            case 3: return [unpack('E', $read(8))[1], $at];                        // double
            case 4: return [$read($size), $at];                                   // bytes
            case 5: case 6: case 9: case 10: return [$uint($read($size)), $at];   // unsigned ints (big ones may lose precision: not used here)
            case 8: $n = $uint($read($size)); if ($size === 4 && $n >= 0x80000000) $n -= 0x100000000; return [$n, $at];   // int32
            case 7:                                                               // map
                $m = [];
                for ($i = 0; $i < $size; $i++) {
                    [$k, $at] = geoMmdbDecode($db, $at, $base);
                    [$v, $at] = geoMmdbDecode($db, $at, $base);
                    $m[(string)$k] = $v;
                }
                return [$m, $at];
            case 11:                                                              // array
                $a = [];
                for ($i = 0; $i < $size; $i++) { [$v, $at] = geoMmdbDecode($db, $at, $base); $a[] = $v; }
                return [$a, $at];
            case 14: return [$size !== 0, $at];                                   // boolean
            case 15: return [unpack('G', $read(4))[1], $at];                       // float
            case 12: case 13: return [null, $at];                                 // cache container / end marker
        }
        throw new RuntimeException('Unknown data type ' . $type);
    }
}
