<?php
/**
 * Returns the server's VAPID public key. This is a public key by design
 * (that's the whole point of VAPID) — safe to expose with no auth check.
 *
 * Used by the service worker's 'pushsubscriptionchange' handler to
 * re-subscribe when the browser rotates a push endpoint, without needing
 * the SW to have access to any server-rendered page context.
 */
require_once __DIR__ . '/../config/env_loader.php';
require_once __DIR__ . '/../config/web_push.php';
require_once __DIR__ . '/../db_connection.php';

header('Content-Type: application/json; charset=UTF-8');
// from .env, or generated once and kept in push_vapid (config/web_push.php)
try {
    echo json_encode(['success' => true, 'publicKey' => wpVapidKeys(getConnection())['public']]);
} catch (Throwable $e) {
    error_log('get_vapid_key.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'publicKey' => '']);
}
