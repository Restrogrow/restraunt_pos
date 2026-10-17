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
// drop empty paragraphs at the top and an opening heading that only repeats the title (the page already shows it as h1)
$raw = preg_replace('#^(\s*<p>(\s|<br\s*/?>|&nbsp;)*</p>)+#i', '', (string)$post['content']);
$norm = fn($t) => preg_replace('/[^a-z0-9]+/', '', strtolower(html_entity_decode(strip_tags($t), ENT_QUOTES, 'UTF-8')));
if (preg_match('#^\s*<h[12][^>]*>(.*?)</h[12]>#is', $raw, $hm) && $norm($hm[1]) === $norm($post['title'])) $raw = substr($raw, strlen($hm[0]));
[$content, $toc] = blogToc($raw);
$toc = array_values(array_filter($toc, fn($t) => (int)$t[2] === 2));
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
$author = $post['author_name'] ?: 'RestroGrow team';
$initial = mb_strtoupper(mb_substr($author, 0, 1));
$updated = strtotime((string)$post['updated_at']) - strtotime((string)$post['published_at']) > 86400 * 2;

/**
 * Presentation only (the stored post is untouched): "1. Heading" h2s get a number badge, the FAQ section's
 * questions become cards, a closing Summary/Conclusion is boxed, and a sign-up box goes in before the 3rd section.
 */
$h2count = preg_match_all('#<h2[\s>]#i', $content);
$cta = '<aside class="post-inline-cta"><span class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3h18v4H3zM5 7v14h14V7M9 11h6M9 15h4"/></svg></span>'
    . '<div><b>Run your restaurant on RestroGrow</b><p>GST billing, KOT, QR ordering, your own ordering site and WhatsApp marketing in one app. Start free, no card needed.</p>'
    . '<div class="btns"><a href="' . bh($base) . '/main/admin/login.php">Start free trial</a><a href="' . bh($base) . '/app-demo/">See the demo</a></div></div></aside>';
$out = ''; $n = 0;
foreach (preg_split('#(?=<h2[\s>])#i', $content) as $part) {
    if (!preg_match('#^<h2([^>]*)>(.*?)</h2>(.*)$#is', $part, $m)) { $out .= $part; continue; }
    $n++;
    [, $attrs, $inner, $body] = $m;
    $plain = trim(html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^(\d{1,2})[.)]\s+/', $plain, $nm)) {
        $inner = preg_replace('/^((?:\s|<[^>]+>)*)\d{1,2}[.)]\s+/', '$1', $inner, 1);
        // keep an existing class (e.g. ql-align-center from the editor) next to h-numbered: one class attribute only
        $cls = preg_match('/\sclass="([^"]*)"/i', $attrs, $cm) ? trim($cm[1]) . ' h-numbered' : 'h-numbered';
        $attrs = preg_replace('/\sclass="[^"]*"/i', '', $attrs);
        $head = '<h2' . $attrs . ' class="' . $cls . '"><span class="h-num">' . str_pad($nm[1], 2, '0', STR_PAD_LEFT) . '</span><span>' . $inner . '</span></h2>';
    } else {
        $head = '<h2' . $attrs . '>' . $inner . '</h2>';
    }
    if ($n === 3 && $h2count >= 4) $out .= $cta;
    if (preg_match('/^(FAQs?|Frequently asked)/i', $plain)) {
        $body = preg_replace('#(<h3[^>]*>.*?</h3>)(.*?)(?=<h3[\s>]|$)#is', '<div class="faq-item">$1$2</div>', $body);
        $out .= '<section class="post-faq">' . $head . $body . '</section>';
    } elseif ($n === $h2count && preg_match('/^(Summary|Conclusion|In short|Final thoughts|Key takeaways|The bottom line|Wrapping up)/i', $plain)) {
        $out .= '<section class="post-wrapup">' . $head . $body . '</section>';
    } else {
        $out .= $head . $body;
    }
}
// a paragraph that opens with a bold label ending in a colon ("Hardware locks: ...") is shown as a small card;
// tables get a scroll wrapper so wide ones scroll on phones while normal ones fill the column
$content = preg_replace('#<p>(\s*<strong>[^<]{2,80}(?::</strong>|</strong>\s*:))#iu', '<p class="def">$1', $out);
$content = preg_replace(['#<table>#i', '#</table>#i'], ['<div class="table-wrap"><table>', '</table></div>'], $content);
?>
<div class="read-progress" id="readProgress" aria-hidden="true"></div>
<?php if ($preview): ?><div class="preview-bar">Preview: this post is <?php echo $post['status'] === 'draft' ? 'a draft' : 'scheduled for ' . bh(date('j M Y, g:i A', strtotime((string)$post['published_at']))); ?>. Only you can see it.</div><?php endif; ?>
<main>
  <article>
    <header class="post-hero">
      <div class="blog-wrap post-hero-in<?php echo $img ? '' : ' no-cover'; ?>">
        <div>
          <nav class="post-crumbs" aria-label="Breadcrumb"><a href="<?php echo bh($base); ?>/blog/">Blog</a><?php if ($post['category']): ?><span aria-hidden="true">/</span><a class="post-cat" href="<?php echo bh($base . '/blog/category/' . $post['category_slug']); ?>"><?php echo bh($post['category']); ?></a><?php endif; ?></nav>
          <h1><?php echo bh($post['title']); ?></h1>
          <?php if ($post['excerpt']): ?><p class="post-lede"><?php echo bh($post['excerpt']); ?></p><?php endif; ?>
          <div class="post-meta">
            <span class="who"><span class="av-sm" aria-hidden="true"><?php echo bh($initial); ?></span>
              <span><b><?php echo bh($author); ?></b><small><?php echo bh(blogDate($post['published_at'] ?: $post['created_at'])); ?><?php echo $updated ? ' · Updated ' . bh(blogDate($post['updated_at'])) : ''; ?></small></span></span>
            <span class="stat"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><?php echo (int)$post['reading_minutes']; ?> min read</span>
          </div>
        </div>
        <?php if ($img): ?><figure class="post-cover"><img src="<?php echo bh($img); ?>" alt="<?php echo bh($post['cover_alt'] ?: $post['title']); ?>" fetchpriority="high"></figure><?php endif; ?>
      </div>
    </header>

    <div class="post-body-band">
      <div class="blog-wrap">
        <div class="post-layout<?php echo count($toc) >= 3 ? '' : ' no-toc'; ?>">
          <div class="post-main">
            <div class="prose"><?php echo $content; ?></div>
            <?php if ($tags): ?><div class="post-tags"><?php foreach ($tags as $t): ?><a href="<?php echo bh($base . '/blog/?tag=' . rawurlencode($t)); ?>">#<?php echo bh($t); ?></a><?php endforeach; ?></div><?php endif; ?>
            <div class="post-share">
              <span>Share this</span>
              <a href="https://wa.me/?text=<?php echo $shareText . '%20' . $shareUrl; ?>" target="_blank" rel="noopener">WhatsApp</a>
              <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">LinkedIn</a>
              <a href="https://twitter.com/intent/tweet?text=<?php echo $shareText; ?>&amp;url=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">X</a>
              <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo $shareUrl; ?>" target="_blank" rel="noopener">Facebook</a>
              <button type="button" onclick="var b=this;navigator.clipboard&&navigator.clipboard.writeText(<?php echo bh(json_encode($url)); ?>).then(function(){b.lastChild.textContent='Link copied'})"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/></svg><span>Copy link</span></button>
            </div>
            <div class="post-author"><div class="av"><?php echo bh($initial); ?></div>
              <div><small>Written by</small><b><?php echo bh($author); ?></b><p>We build RestroGrow, restaurant software made in India, and write about what works for restaurant owners.</p></div></div>
          </div>
          <?php if (count($toc) >= 3): ?>
            <details class="post-toc" id="postToc" open aria-label="On this page"><summary>On this page</summary><ol>
              <?php foreach ($toc as $i => [$id, $text]): ?><li><a href="#<?php echo bh($id); ?>"><span class="n"><?php echo $i + 1; ?></span><span><?php echo bh(preg_replace('/^\d{1,2}[.)]\s+/', '', $text)); ?></span></a></li><?php endforeach; ?>
            </ol>
              <div class="post-toc-cta"><b>Try RestroGrow free</b><p>Billing, KOT, QR menu and WhatsApp marketing for your restaurant.</p><a href="<?php echo bh($base); ?>/main/admin/login.php">Start free trial</a></div>
            </details>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($related['posts']): ?>
      <div class="blog-wrap"><section class="post-related"><h2>Keep reading</h2><div class="blog-grid"><?php foreach ($related['posts'] as $p) echo blogCard($p); ?></div></section></div>
    <?php endif; ?>
  </article>
</main>
<script>
(function () {
  var bar = document.getElementById('readProgress'), art = document.querySelector('.prose'), toc = document.getElementById('postToc');
  var wide = window.matchMedia('(min-width: 961px)');
  // the contents list is always open on wide screens and folded on phones
  function fold() { if (toc) { if (wide.matches) toc.open = true; else toc.open = false; } }
  fold(); wide.addEventListener && wide.addEventListener('change', fold);
  if (toc) toc.addEventListener('click', function (e) { if (wide.matches && e.target.tagName === 'SUMMARY') e.preventDefault(); if (!wide.matches && e.target.closest('a')) toc.open = false; });
  var links = toc ? [].slice.call(toc.querySelectorAll('a')) : [];
  var heads = links.map(function (a) { return document.getElementById(decodeURIComponent(a.hash.slice(1))); });
  function tick() {
    if (art && bar) {
      var r = art.getBoundingClientRect(), total = r.height - window.innerHeight * 0.6;
      bar.style.transform = 'scaleX(' + Math.max(0, Math.min(1, total > 0 ? -r.top / total : 1)) + ')';
    }
    var cur = -1, line = window.innerHeight * 0.3;
    heads.forEach(function (h, i) { if (h && h.getBoundingClientRect().top < line) cur = i; });
    links.forEach(function (a, i) { a.classList.toggle('on', i === cur); a.classList.toggle('done', i < cur); });
  }
  var queued = false;
  window.addEventListener('scroll', function () { if (!queued) { queued = true; requestAnimationFrame(function () { queued = false; tick(); }); } }, { passive: true });
  tick();
})();
</script>
<?php blogFooter(); ?>
