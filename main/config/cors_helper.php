<?php
// Origins allowed to make credentialed cross-origin requests, for endpoints
// the companion mobile/web app (app/) needs to call. Kept to known dev
// origins only — never a wildcard, since responses include session cookies.
// $fallbackWildcard: for endpoints that also serve anonymous, unauthenticated
// public requests (e.g. a restaurant's public menu widget on any domain),
// pass true so non-app origins still get "*" — matching prior behavior.
// Never combine "*" with credentials; only the allow-listed app origins get
// the credentialed, origin-reflecting response.
function allowAppOrigin($fallbackWildcard = false) {
    $allowedOrigins = [
        'http://localhost:8081',
        'http://127.0.0.1:8081',
        'http://localhost:19006',
        'http://127.0.0.1:19006',
    ];

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    } elseif ($fallbackWildcard) {
        header('Access-Control-Allow-Origin: *');
    }
}
