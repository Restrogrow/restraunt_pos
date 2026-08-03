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

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => true, 'publicKey' => env('VAPID_PUBLIC_KEY', '')]);
