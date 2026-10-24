<?php
/**
 * Images sent to the API / AI tools (mcp/tools/*): a data URL ("data:image/png;base64,…") or a public https link.
 * The link is fetched here with the same guard the rest of the code expects from uploads: public address only (no
 * localhost, private or metadata ranges), no redirects, size capped while downloading, and the bytes must really be
 * a JPEG, PNG, GIF or WebP (getimagesizefromstring), whatever the server claims.
 */

if (defined('API_MEDIA_LOADED')) return;
define('API_MEDIA_LOADED', true);

const API_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

/** ['bytes' => …, 'mime' => …, 'width' => …, 'height' => …] or throws InvalidArgumentException with a message for people. */
function apiImageFrom(string $src, int $maxBytes, string $label = 'Image'): array
{
    $src = trim($src);
    if ($src === '') throw new InvalidArgumentException("$label: send image (a data:image/…;base64 URL or an https link).");
    if (preg_match('#^data:image/[a-z+.-]+;base64,#i', $src)) {
        $bytes = base64_decode(substr($src, strpos($src, ',') + 1), true);
        if ($bytes === false) throw new InvalidArgumentException("$label: the base64 data could not be read.");
    } elseif (preg_match('#^https://#i', $src)) {
        $bytes = apiFetchPublic($src, $maxBytes, $label);
    } else {
        throw new InvalidArgumentException("$label: use a data:image/…;base64 URL or an https:// link.");
    }
    if (strlen($bytes) > $maxBytes) throw new InvalidArgumentException("$label is larger than " . round($maxBytes / 1048576, 1) . ' MB.');
    $info = @getimagesizefromstring($bytes);
    if (!$info || !in_array($info['mime'], API_IMAGE_TYPES, true)) throw new InvalidArgumentException("$label must be a JPEG, PNG, GIF or WebP picture.");
    return ['bytes' => $bytes, 'mime' => $info['mime'], 'width' => (int)$info[0], 'height' => (int)$info[1]];
}

/** Download a public https URL (no redirects, public IPs only, capped). */
function apiFetchPublic(string $url, int $maxBytes, string $label): string
{
    $p = parse_url($url);
    $host = strtolower((string)($p['host'] ?? ''));
    if ($host === '' || ($p['scheme'] ?? '') !== 'https') throw new InvalidArgumentException("$label: the link must start with https://");
    $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(
        array_column(@dns_get_record($host, DNS_A) ?: [], 'ip'), array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
    if (!$ips) throw new InvalidArgumentException("$label: could not find $host.");
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new InvalidArgumentException("$label: that link points to a private address.");
    }
    $port = (int)($p['port'] ?? 443);
    $got = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ips[0]],   // connect to the address we checked
        CURLOPT_HTTPHEADER => ['Accept: image/*'], CURLOPT_USERAGENT => 'RestroGrow-API/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$got, $maxBytes) {
            $got .= $chunk;
            return strlen($got) > $maxBytes ? 0 : strlen($chunk);   // stop once over the limit
        },
    ]);
    // a local PC whose antivirus re-signs HTTPS needs its own CA bundle (same setting as config/ai_gemini.php)
    $ca = function_exists('env') ? trim((string)env('AI_CAINFO', '')) : '';
    if ($ca !== '' && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($code === 0) error_log('apiFetchPublic ' . $host . ': ' . curl_error($ch));
    curl_close($ch);
    if (strlen($got) > $maxBytes) throw new InvalidArgumentException("$label is larger than " . round($maxBytes / 1048576, 1) . ' MB.');
    if ($code !== 200 || $got === '') throw new InvalidArgumentException("$label: the link did not load (HTTP $code). It must open the picture directly, without redirects.");
    return $got;
}
