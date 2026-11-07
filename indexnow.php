<?php
/**
 * Serves the IndexNow key file at https://restrogrow.com/<key>.txt — the engines fetch it to check
 * that whoever submitted the pages controls this site. .htaccess rewrites <key>.txt here.
 * See main/config/indexnow.php.
 */
require_once __DIR__ . '/main/config/indexnow.php';

$key = rgIndexNowKey();
$asked = preg_replace('/\.txt$/i', '', basename((string)($_GET['key'] ?? '')));

header('Content-Type: text/plain; charset=UTF-8');
if (!$key || $asked === '' || !hash_equals($key, $asked)) {
    http_response_code(404);
    echo "Not found\n";
    exit;
}
echo $key;
