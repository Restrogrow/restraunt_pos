<?php
/**
 * Embed Proxy — serves as a backward-compatible redirect to the main customer website.
 *
 * Previously this file had its own separate UI that quickly went out of sync with
 * the main customer website. Now it simply redirects to website/index.php so the
 * embed always shows the EXACT same content — any updates (menu, prices, features,
 * design) reflect automatically with zero maintenance.
 *
 * For new embeds, embed.js iframes website/index.php directly.
 * This file is kept so old embed codes and bookmarks still work.
 */

// No-cache headers
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$restaurant_id = $_GET['restaurant_id'] ?? '';
if (!$restaurant_id) {
    http_response_code(400);
    echo '<h2>No restaurant specified</h2>';
    exit;
}

// Build the URL to the main customer website
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
$redirectUrl = $basePath . '/website/index.php?restaurant_id=' . urlencode($restaurant_id);

// Preserve any query parameters that might be needed (e.g., payment return params)
$allowedParams = array('order_id', 'code', 'transaction_id', 'phonepe_demo');
foreach ($allowedParams as $param) {
    if (isset($_GET[$param]) && $_GET[$param] !== '') {
        $redirectUrl .= '&' . urlencode($param) . '=' . urlencode($_GET[$param]);
    }
}

header('Location: ' . $redirectUrl);
exit;
