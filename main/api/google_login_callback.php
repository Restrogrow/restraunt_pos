<?php
/**
 * Google sends people back here after "Continue with Google" (the one redirect address on the
 * OAuth client). Checks the answer, then forwards to the finish step for the dashboard, the
 * restaurant's website (on its own host) or the Partner app, carrying a single-use pass.
 */
require_once __DIR__ . '/../config/google_login.php';
require_once __DIR__ . '/../db_connection.php';

$state = googleLoginReadState((string)($_GET['state'] ?? ''));

/** Where this sign-in started, so errors and passes go back there. */
function googleBackTo(?array $state, string $page, array $query): string
{
    $q = http_build_query($query);
    $for = $state['f'] ?? 'owner';
    if ($for === 'app' && !empty($state['a'])) {
        return $state['a'] . (str_contains($state['a'], '?') ? '&' : '?') . $q;
    }
    if ($for === 'customer' && !empty($state['h'])) {
        $local = googleLoginIsLocal();
        $root = $local && $state['h'] === strtolower($_SERVER['HTTP_HOST'] ?? '') ? (string)parse_url(googleLoginMainBase(), PHP_URL_PATH) : '';
        $scheme = $local ? 'http' : 'https';
        if ($page === 'finish') return "$scheme://{$state['h']}$root/main/website/google_finish.php?$q";
        $ret = $state['b'] ?? $state['r'] ?? '/';
        // the login page they started from (its path already includes this site's folder)
        return "$scheme://{$state['h']}" . $ret . (str_contains($ret, '?') ? '&' : '?') . $q;
    }
    return googleLoginMainBase() . ($page === 'finish' ? '/main/admin/google_finish.php?' : '/main/admin/login.php?') . $q;
}

if (!$state) {
    header('Location: ' . googleLoginMainBase() . '/main/admin/login.php?google_error=' . urlencode('That sign-in link expired. Please try again.'));
    exit;
}
if (!empty($_GET['error'])) {
    // the person pressed Cancel on Google's screen: just go back, no message needed
    $msg = $_GET['error'] === 'access_denied' ? '' : 'Google sign-in did not finish. Please try again.';
    header('Location: ' . googleBackTo($state, 'back', $msg !== '' ? ['google_error' => $msg] : ['google' => 'cancelled']));
    exit;
}

try {
    $profile = googleLoginProfile((string)($_GET['code'] ?? ''), (string)($state['n'] ?? ''));
    $conn = getConnection();
    $for = $state['f'];
    if ($for === 'customer') {
        $token = googleLoginIssueToken($conn, 'customer', $state['rid'], $profile + ['ret' => $state['r'] ?? '/']);
    } else {
        $token = googleLoginIssueToken($conn, $for === 'app' ? 'app' : 'owner', null, $profile);
    }
    header('Location: ' . googleBackTo($state, 'finish', ['t' => $token]));
} catch (Throwable $e) {
    error_log('Google login callback: ' . $e->getMessage());
    header('Location: ' . googleBackTo($state, 'back', ['google_error' => $e instanceof RuntimeException ? $e->getMessage() : 'Google sign-in failed. Please try again.']));
}
exit;
