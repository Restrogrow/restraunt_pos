<?php
/**
 * Post / Redirect / Get for pages with a <form method="post"> that show the result themselves.
 * After the POST is handled the browser is sent back to the same address with a GET (303), so a
 * refresh never sends the form again (no "Confirm Form Resubmission", no second demo reset, no
 * second toggle). The page's message travels once in the session.
 *
 *   if ($_SERVER['REQUEST_METHOD'] === 'POST') { …; prgRedirect('demo', ['message' => $m, 'error' => $e]); }
 *   ['message' => $message, 'error' => $error] = prgTake('demo', ['message' => '', 'error' => '']);
 *
 * The session must already be started (the page's auth / session code does that).
 */

/** Saves $flash for the next page view and redirects to $url (default: this same address). Ends the request. */
function prgRedirect(string $key, array $flash = [], ?string $url = null): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['prg_' . $key] = $flash;
    $to = $url ?? (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($to === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $to) && !preg_match('#^https?://#i', $to)) $to = './';
    header('Location: ' . $to, true, 303);
    exit;
}

/** What prgRedirect() saved for this page (once), merged over $defaults. */
function prgTake(string $key, array $defaults = []): array
{
    $flash = $_SESSION['prg_' . $key] ?? null;
    if ($flash !== null) unset($_SESSION['prg_' . $key]);
    return array_merge($defaults, is_array($flash) ? $flash : []);
}
