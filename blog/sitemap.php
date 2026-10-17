<?php
/** restrogrow.com/sitemap-blog.xml: the blog home, categories with posts and every published, indexable post. */
require_once __DIR__ . '/../main/db_connection.php';
require_once __DIR__ . '/../main/config/blog_engine.php';
header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=3600');
$conn = getConnection();
blogEnsure($conn);
$base = blogWebBase();
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$posts = $conn->query("SELECT slug, updated_at, cover_image, title FROM blog_posts WHERE status = 'published' AND published_at <= NOW() AND noindex = 0 AND (canonical_url IS NULL OR canonical_url = '') ORDER BY published_at DESC")->fetchAll(PDO::FETCH_ASSOC);
$cats = array_filter(blogCategories($conn, true), fn($c) => (int)$c['posts'] > 0);
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">
  <url><loc><?php echo $x($base); ?>/blog/</loc><?php if ($posts): ?><lastmod><?php echo date('Y-m-d', strtotime($posts[0]['updated_at'])); ?></lastmod><?php endif; ?></url>
<?php foreach ($cats as $c): ?>
  <url><loc><?php echo $x($base . '/blog/category/' . $c['slug']); ?></loc></url>
<?php endforeach; ?>
<?php foreach ($posts as $p): ?>
  <url>
    <loc><?php echo $x($base . '/blog/' . $p['slug']); ?></loc>
    <lastmod><?php echo date('Y-m-d', strtotime($p['updated_at'])); ?></lastmod>
    <?php if ($p['cover_image']): ?><image:image><image:loc><?php echo $x(blogImageUrl($p['cover_image'])); ?></image:loc><image:title><?php echo $x($p['title']); ?></image:title></image:image><?php endif; ?>
  </url>
<?php endforeach; ?>
</urlset>
