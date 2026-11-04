<?php
/**
 * The URL list for restrogrow.com's own sitemap (main/website/site_sitemap.php).
 *
 * It used to be a hand-written sitemap.xml at the project root. That file listed /blog/ and stopped
 * there, so every article written since was missing: 23 published posts, one of them in the sitemap.
 * Search Console had no way to learn about the other 22 from it, and seoOwnUrls() (config/site_seo.php)
 * reads the same list to check each page's index status, so the SEO dashboard was blind to them too.
 *
 * Built from the database instead, so publishing a post is all it takes for the post to appear here.
 * Restaurant websites are not in this list — they have their own (main/website/sitemap.php).
 */
require_once __DIR__ . '/blog_engine.php';

/**
 * The marketing and legal pages, which are files rather than database rows: [path, lastmod, changefreq, priority].
 *
 * The date is written here rather than taken from the file's own timestamp. A deploy rewrites every
 * file, so filemtime would tell Google that all seventeen pages changed today, every single time —
 * and a sitemap that cries wolf about lastmod is one Google stops believing. Change the date by hand
 * when the page's content really changes. The blog index is the exception below: it genuinely changes
 * whenever a post is published, and the newest post's date says so truthfully.
 */
const SITE_STATIC_PAGES = [
    ['', '2026-10-22', 'weekly', '1.0'],
    ['app-demo/', '2026-10-16', 'monthly', '0.9'],
    ['about/', '2026-10-16', 'monthly', '0.8'],
    ['nepal/', '2026-10-25', 'monthly', '0.9'],
    ['loyalty/', '2026-10-23', 'monthly', '0.9'],
    ['meal-plans/', '2026-10-24', 'monthly', '0.9'],
    ['restaurant-billing-software/', '2026-10-26', 'monthly', '0.9'],
    ['restaurant-pos-software/', '2026-10-26', 'monthly', '0.9'],
    ['qr-menu-ordering/', '2026-10-26', 'monthly', '0.9'],
    ['whatsapp-ordering/', '2026-10-26', 'monthly', '0.9'],
    ['faq/', '2026-10-16', 'monthly', '0.8'],
    ['developers/', '2026-10-24', 'monthly', '0.6'],
    ['blog/', '', 'weekly', '0.7'],            // filled in from the newest published post
    ['privacy-policy/', '2026-10-16', 'yearly', '0.4'],
    ['terms-of-service/', '2026-10-16', 'yearly', '0.4'],
    ['refund-policy/', '2026-10-16', 'yearly', '0.4'],
    ['cookie-policy/', '2026-10-16', 'yearly', '0.4'],
];

/**
 * Every URL on restrogrow.com worth indexing: [loc, lastmod (Y-m-d or ''), changefreq, priority].
 */
function siteSitemapUrls(PDO $conn): array
{
    $base = rtrim(defined('SITE_BASE_URL') ? SITE_BASE_URL : 'https://restrogrow.com', '/');

    // published articles, newest first; the post page itself is at /blog/<slug>.
    // noindex posts are left out: asking Google to crawl a page that tells it not to index is noise.
    $posts = $cats = [];
    $newest = '';
    try {
        blogEnsure($conn);
        $st = $conn->query("SELECT slug, published_at, updated_at FROM blog_posts
            WHERE status = 'published' AND published_at <= NOW() AND noindex = 0 ORDER BY published_at DESC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $when = $p['updated_at'] ?: $p['published_at'];
            $day = $when ? date('Y-m-d', strtotime($when)) : '';
            if ($day > $newest) $newest = $day;
            $posts[] = [$base . '/blog/' . $p['slug'], $day, 'monthly', '0.7'];
        }
        // a category page is worth having only once something is filed under it
        foreach (blogCategories($conn, true) as $c) {
            if ((int)$c['posts'] < 1) continue;
            $cats[] = [$base . '/blog/category/' . $c['slug'], $newest, 'weekly', '0.5'];
        }
    } catch (Throwable $e) {
        error_log('siteSitemapUrls (blog): ' . $e->getMessage());   // the static pages still go out
    }

    $out = [];
    foreach (SITE_STATIC_PAGES as [$path, $mod, $freq, $prio]) {
        if ($path === 'blog/' && $newest !== '') $mod = $newest;   // the index really did change then
        $out[] = [$base . '/' . $path, $mod, $freq, $prio];
    }
    return array_merge($out, $posts, $cats);
}
