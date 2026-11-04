<?php
/**
 * restrogrow.com/sitemap.xml — RestroGrow's own pages and every published blog post.
 * The list is built in config/site_sitemap.php; restaurant websites have their own (website/sitemap.php).
 */
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/site_sitemap.php';

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');

try {
    $urls = siteSitemapUrls(getConnection());
} catch (Throwable $e) {
    error_log('site_sitemap: ' . $e->getMessage());
    http_response_code(503);
    exit;   // better a retry than an empty sitemap, which Google would read as "these pages are gone"
}

$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<!-- RestroGrow\'s own pages and blog. Restaurant websites: /sitemap-restaurants.xml -->' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $mod, $freq, $prio]) {
    echo "    <url>\n        <loc>" . $x($loc) . "</loc>\n";
    if ($mod !== '') echo '        <lastmod>' . $x($mod) . "</lastmod>\n";
    echo '        <changefreq>' . $x($freq) . "</changefreq>\n";
    echo '        <priority>' . $x($prio) . "</priority>\n    </url>\n";
}
echo "</urlset>\n";
