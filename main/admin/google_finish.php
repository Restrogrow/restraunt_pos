<?php
/**
 * Last step of "Continue with Google" on the dashboard login (config/google_login.php):
 * logs the owner or staff member whose email matches in, or sends a new owner to sign-up with
 * their name and email filled in.
 */
require_once __DIR__ . '/../config/session_config.php';
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/google_login.php';

function googleOwnerBack(array $query): void
{
    header('Location: login.php?' . http_build_query($query));
    exit;
}

try {
    $conn = getConnection();
    $p = googleLoginConsumeToken($conn, (string)($_GET['t'] ?? ''), 'owner');
    if (!$p) googleOwnerBack(['google_error' => 'That sign-in link expired. Please try again.']);

    $account = googleLoginFindAccount($conn, $p['email']);
    if (!$account) {
        // no RestroGrow account with this email yet: start the free-trial sign-up, filled in
        googleOwnerBack(['google_signup' => 1, 'email' => $p['email'], 'name' => $p['name']]);
    }
    [$redirect] = googleLoginStartSession($conn, $account);
    header('Location: ' . $redirect);
    exit;
} catch (Throwable $e) {
    error_log('Google owner login: ' . $e->getMessage());
    googleOwnerBack(['google_error' => $e instanceof RuntimeException ? $e->getMessage() : 'Google sign-in failed. Please try again.']);
}
