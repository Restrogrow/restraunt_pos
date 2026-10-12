<?php
/**
 * Live preview on a phone — the phone's check (Website Appearance > "On your phone").
 * GET ?t=<token>  →  { v: version, alive: bool }   (cheap, polled every ~1.2 s)
 */
require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/../config/theme_live_preview.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$conn = isset($pdo) ? $pdo : getConnection();
$row = themeLivePreviewGet($conn, (string)($_GET['t'] ?? ''));
echo json_encode($row ? ['alive' => true, 'v' => (int)$row['version']] : ['alive' => false]);
