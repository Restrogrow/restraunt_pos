<?php
/**
 * restrogrow.com/blog/{slug}: one blog post. Drafts and scheduled posts open only for a signed-in
 * Super Admin with ?preview=1 (never indexed). Counts a view once per visitor per post per 6 hours.
 */
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../main/config/session_config.php';

$conn = getConnection();
blogEnsure($conn);
$base = blogWebBase();
$slug = preg_match('/^[a-z0-9-]{1,160}$/', (string)($_GET['slug'] ?? '')) ? $_GET['slug'] : '';

$st = $conn->prepare('SELECT p.*, c.name AS category, c.slug AS category_slug FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE p.slug = ?');
$st->execute([$slug]);
$post = $slug !== '' ? $st->fetch(PDO::FETCH_ASSOC) : false;
$live = $post && $post['status'] === 'published' && strtotime((string)$post['published_at']) <= time();
$preview = false;
if ($post && !$live && !empty($_GET['preview'])) {
    startSecureSession();
    $preview = !empty($_SESSION['superadmin_id']);
    session_write_close();
}

if (!$post || (!$live && !$preview)) {
    http_response_code(404);
    blogHeader(['title' => 'Post not found | RestroGrow Blog', 'noindex' => true]);
    $recent = blogPublished($conn, ['limit' => 3]);
    echo '<main><header class="post-head"><div class="blog-wrap blog-narrow"><div class="blog-kicker">404</div><h1>We couldn\'t find that post</h1><p class="post-lede">It may have moved. Here are the latest ones instead.</p>'
        . '<p><a class="btn btn-primary" href="' . bh($base) . '/blog/">All posts</a></p></div></header><div class="blog-wrap"><div class="blog-grid">';
    foreach ($recent['posts'] as $p) echo blogCard($p);
    echo '</div></div></main>';
    blogFooter();
    exit;
}

// a view (real visitors only)
if ($live) {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ck = 'bv' . (int)$post['id'];
    if ($ua !== '' && !preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|curl|wget|python|headless/i', $ua) && empty($_COOKIE[$ck])) {
        try { $conn->prepare('UPDATE blog_posts SET views = views + 1 WHERE id = ?')->execute([$post['id']]); } catch (Throwable $e) { /* not important */ }
        setcookie($ck, '1', ['expires' => time() + 6 * 3600, 'path' => '/', 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true]);
    }
}

$url = $base . '/blog/' . $post['slug'];
[$content, $toc] = blogToc((string)$post['content']);
$img = blogImageUrl($post['cover_image']);
$title = $post['meta_title'] ?: $post['title'] . ' | RestroGrow Blog';
$desc = $post['meta_description'] ?: ($post['excerpt'] ?: mb_substr(blogPlainText($content), 0, 160));
$tags = array_values(array_filter(array_map('trim', explode(',', (string)$post['tags']))));

// structured data: the article, the breadcrumbs, and the FAQ if the post has one (an "FAQ" h2 with h3 questions)
$article = [
    '@context' => 'https://schema.org', '@type' => 'BlogPosting', 'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
    'headline' => mb_substr($post['title'], 0, 110), 'description' => $desc, 'image' => $img ? [$img] : [$base . '/assets/images/logo-transparent.png'],
    'datePublished' => date('c', strtotime((string)$post['published_at'] ?: (string)$post['created_at'])), 'dateModified' => date('c', strtotime((string)$post['updated_at'])),
    'author' => ['@type' => 'Organization', 'name' => $post['author_name'] ?: 'RestroGrow team', 'url' => $base . '/about/'],
    'publisher' => ['@type' => 'Organization', 'name' => 'RestroGrow', 'logo' => ['@type' => 'ImageObject', 'url' => $base . '/assets/images/logo-transparent.png']],
    'wordCount' => blogWordCount($content), 'inLanguage' => 'en-IN',
];
if ($post['focus_keyword'] || $post['keywords']) $article['keywords'] = trim($post['focus_keyword'] . ($post['keywords'] ? ', ' . $post['keywords'] : ''), ', ');
if ($post['category']) $article['articleSection'] = $post['category'];
$crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => array_values(array_filter([
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Blog', 'item' => $base . '/blog/'],
    $post['category'] ? ['@type' => 'ListItem', 'position' => 2, 'name' => $post['category'], 'item' => $base . '/blog/category/' . $post['category_slug']] : null,
    ['@type' => 'ListItem', 'position' => $post['category'] ? 3 : 2, 'name' => $post['title'], 'item' => $url],
]))];
$jsonld = [$article, $crumbs];
if (preg_match('#<h2[^>]*>[^<]*(FAQ|Frequently asked)[^<]*</h2>(.*?)(?=<h2|$)#is', $content, $fm)) {
    $faqs = [];
    if (preg_match_all('#<h3[^>]*>(.*?)</h3>(.*?)(?=<h3|$)#is', $fm[2], $qm, PREG_SET_ORDER)) {
        foreach ($qm as $x) {
            $qq = trim(blogPlainText($x[1])); $aa = trim(blogPlainText($x[2]));
            if ($qq !== '' && $aa !== '') $faqs[] = ['@type' => 'Question', 'name' => $qq, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $aa]];
        }
    }
    if (count($faqs) >= 2) $jsonld[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $faqs];
}

$extra = '<meta property="article:published_time" content="' . bh(date('c', strtotime((string)$post['published_at'] ?: 'now'))) . '">'
    . '<meta property="article:modified_time" content="' . bh(date('c', strtotime((string)$post['updated_at']))) . '">'
    . ($post['category'] ? '<meta property="article:section" content="' . bh($post['category']) . '">' : '');
foreach ($tags as $t) $extra .= '<meta property="article:tag" content="' . bh($t) . '">';
blogHeader(['title' => $title, 'og_title' => $post['meta_title'] ?: $post['title'], 'description' => $desc, 'canonical' => $post['canonical_url'] ?: $url,
    'image' => $img ?: null, 'type' => 'article', 'noindex' => $preview || (int)$post['noindex'] === 1, 'jsonld' => $jsonld, 'extra' => $extra]);

$related = blogPublished($conn, ['category' => $post['category_slug'] ?: null, 'exclude' => $post['id'], 'limit' => 3]);
if (count($related['posts']) < 3) {
    $more = blogPublished($conn, ['exclude' => $post['id'], 'limit' => 6]);
    $ids = array_column($related['posts'], 'id');
    foreach ($more['posts'] as $m) if (count($related['posts']) < 3 && !in_array($m['id'], $ids)) $related['posts'][] = $m;
}
$shareText = rawurlencode($post['title']);
$shareUrl = rawurlencode($url);
?>
<?php if ($preview): ?><div class="preview-bar">Preview: this post is <?php echo $post['status'] === 'draft' ? 'a draft' : 'scheduled for ' . bh(date('j M Y, g:i A', strtotime((string)$post['published_at']))); ?>. Only you can see it.</div><?php endif; ?>
<main>
  <article>
    <header class="post-head">
      <div class="blog-wrap blog-narrow">
        <nav class="post-crumbs" aria-label="Breadcrumb"><a href="<?php echo bh($base); ?>/blog/">Blog</a><?php if ($post['category']): ?><span>›</span><a href="<?php echo bh($base . '/blog/category/' . $post['category_slug']); ?>"><?php echo bh($post['category']); ?></a><?php endif; ?></nav>
        <h1><?php echo bh($post['title']); ?></h1>
        <?php if ($post['excerpt']): ?><p class="post-lede"><?php echo bh($post['excerpt']); ?></p><?php endif; ?>
        <div class="post-meta">
          <span>By <b><?php echo bh($post['author_name'] ?: 'RestroGrow team'); ?></b></span>
          <span><?php echo bh(blogDate($post['published_at'] ?: $post['created_at'])); ?></span>
          <?php if (strtotime((string)$post['updated_at']) - strtotime((string)$post['published_at']) > 86400 * 2): ?><span>Updated <?php echo bh(blogDate($post['updated_at'])); ?></span><?php endif; ?>
          <span><?php echo (int)$post['reading_minutes']; ?> min read</span>
        </div>
      </div>
      <?php if ($img): ?><div class="blog-wrap"><figure class="post-cover"><img src="<?php echo bh($img); ?>" alt="<?php echo bh($post['cover_alt'] ?: $post['title']); ?>" fetchpriority="high"></figure></div><?php endif; ?>
    </header>

    <div class="blog-wrap">
      <div class="post-layout<?php echo count($toc) >= 3 ? '' : ' no-toc'; ?>">
        <div>
          <div class="prose"><?php echo $content; ?></div>
          <?php if ($tags): ?><div class="post-tags"><?php foreach ($tags as $t): ?><a href="<?php echo bh($base . '/blog/?tag=' . rawurlencode($t)); ?>">#<?php echo bh($t); ?></a><?php endforeach; ?></div><?php endif; ?>
          <div class="post-share">
            <span>Share</span>
            <a href="https://wa.me/?text=<?php echo $shareText . '%20' . $shareUrl; ?>" target="_blank" rel="noopener">WhatsApp</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">LinkedIn</a>
            <a href="https://twitter.com/intent/tweet?text=<?php echo $shareText; ?>&amp;url=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">X</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">Facebook</a>
            <button type="button" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(<?php echo bh(json_encode($url)); ?>).then(function(){b.textContent='Link copied'})">Copy link</button>
          </div>
          <div class="post-author"><div class="av"><?php echo bh(mb_strtoupper(mb_substr($post['author_name'] ?: 'R', 0, 1))); ?></div>
            <div><b><?php echo bh($post['author_name'] ?: 'RestroGrow team'); ?></b><p>We build RestroGrow, restaurant software made in India, and write about what works for restaurant owners.</p></div></div>
        </div>
        <?php if (count($toc) >= 3): ?>
          <aside class="post-toc" aria-label="On this page"><b>On this page</b><ol>
            <?php foreach ($toc as [$id, $text, $level]): ?><li class="l<?php echo $level; ?>"><a href="#<?php echo bh($id); ?>"><?php echo bh($text); ?></a></li><?php endforeach; ?>
          </ol></aside>
        <?php endif; ?>
      </div>

      <?php if ($related['posts']): ?>
        <section class="post-related"><h2>Keep reading</h2><div class="blog-grid"><?php foreach ($related['posts'] as $p) echo blogCard($p); ?></div></section>
      <?php endif; ?>
    </div>
  </article>
</main>
<?php blogFooter(); ?>
