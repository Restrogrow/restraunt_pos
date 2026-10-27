<?php
/**
 * Super Admin only: opens one menu file a new owner sent from a sign-up link
 * (config/signup_invites.php, private folder uploads/signup_menus).
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/signup_invites.php';

$conn = getConnection();
inviteEnsure($conn);
$st = $conn->prepare('SELECT * FROM signup_menu_files WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$f = $st->fetch(PDO::FETCH_ASSOC);
$path = $f ? inviteMenuDir() . '/' . basename($f['file']) : '';
if (!$f || !is_file($path)) { http_response_code(404); exit('File not found'); }
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . (isset($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . str_replace(['"', "\r", "\n"], '', $f['name']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
