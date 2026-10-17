<?php
/** restrogrow.com/blog/feed.xml: RSS 2.0 feed of the latest 30 published posts. */
require_once __DIR__ . '/../main/db_connection.php';
require_once __DIR__ . '/../main/config/blog_engine.php';
header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');
$conn = getConnection();
blogEnsure($conn);
$base = blogWebBase();
$x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$posts = blogPublished($conn, ['limit' => 30])['posts'];
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
  <title>RestroGrow Blog</title>
  <link><?php echo $x($base); ?>/blog/</link>
  <atom:link href="<?php echo $x($base); ?>/blog/feed.xml" rel="self" type="application/rss+xml"/>
  <description>Practical guides for restaurant, cafe and cloud-kitchen owners in India.</description>
  <language>en-in</language>
  <?php if ($posts): ?><lastBuildDate><?php echo date(DATE_RSS, strtotime($posts[0]['published_at'])); ?></lastBuildDate><?php endif; ?>
<?php foreach ($posts as $p): $u = $base . '/blog/' . $p['slug']; ?>
  <item>
    <title><?php echo $x($p['title']); ?></title>
    <link><?php echo $x($u); ?></link>
    <guid isPermaLink="true"><?php echo $x($u); ?></guid>
    <pubDate><?php echo date(DATE_RSS, strtotime($p['published_at'])); ?></pubDate>
    <?php if ($p['category']): ?><category><?php echo $x($p['category']); ?></category><?php endif; ?>
    <description><?php echo $x($p['excerpt']); ?></description>
  </item>
<?php endforeach; ?>
</channel>
</rss>
