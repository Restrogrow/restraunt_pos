<?php
/**
 * restrogrow.com/blog: the blog home, /blog/category/{slug}, ?tag=, ?q= (search), ?page=.
 * Posts are written in Super Admin › Blog (config/blog_engine.php).
 */
require_once __DIR__ . '/_layout.php';

$conn = getConnection();
blogEnsure($conn);
$base = blogWebBase();
$perPage = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$catSlug = preg_match('/^[a-z0-9-]{1,100}$/', (string)($_GET['category'] ?? '')) ? $_GET['category'] : '';
$tag = trim(mb_substr((string)($_GET['tag'] ?? ''), 0, 60));
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$cats = array_values(array_filter(blogCategories($conn, true), fn($c) => (int)$c['posts'] > 0 || $c['slug'] === $catSlug));
$cat = null;
foreach ($cats as $c) if ($c['slug'] === $catSlug) $cat = $c;
if ($catSlug !== '' && !$cat) { http_response_code(404); }

$isHome = !$catSlug && !$tag && !$q && $page === 1;
$featured = null;
if ($isHome) {
    $f = blogPublished($conn, ['featured' => 1, 'limit' => 1]);
    $featured = $f['posts'][0] ?? (blogPublished($conn, ['limit' => 1])['posts'][0] ?? null);
}
$list = blogPublished($conn, ['category' => $catSlug ?: null, 'tag' => $tag ?: null, 'q' => $q ?: null, 'exclude' => $featured['id'] ?? null,
    'limit' => $perPage, 'offset' => ($page - 1) * $perPage]);
$pages = max(1, (int)ceil($list['total'] / $perPage));

$title = $cat ? $cat['name'] . ' | RestroGrow Blog' : ($tag ? '“' . $tag . '” posts | RestroGrow Blog' : ($q ? 'Search: ' . $q . ' | RestroGrow Blog' : 'RestroGrow Blog: restaurant growth tips for India'));
if ($page > 1) $title .= ' (page ' . $page . ')';
$desc = $cat && $cat['description'] ? $cat['description'] : 'Practical guides for restaurant, cafe and cloud-kitchen owners in India: more orders, more regulars, billing, staff, marketing and WhatsApp.';
$canon = $base . '/blog/' . ($cat ? 'category/' . $cat['slug'] : '') . ($page > 1 ? '?page=' . $page : '');
$jsonld = [[
    '@context' => 'https://schema.org', '@type' => 'Blog', 'name' => 'RestroGrow Blog', 'url' => $base . '/blog/', 'description' => $desc,
    'publisher' => ['@type' => 'Organization', 'name' => 'RestroGrow', 'logo' => ['@type' => 'ImageObject', 'url' => $base . '/assets/images/logo-transparent.png']],
]];
blogHeader(['title' => $title, 'description' => $desc, 'canonical' => $canon, 'noindex' => $q !== '' || $tag !== '' || ($catSlug !== '' && !$cat), 'jsonld' => $jsonld]);

$qs = function (array $add) use ($catSlug, $tag, $q) {
    $p = array_filter(['tag' => $tag, 'q' => $q] + $add, fn($v) => $v !== '' && $v !== null && $v !== 1);
    return ($catSlug ? 'category/' . $catSlug : '') . ($p ? '?' . http_build_query($p) : '');
};
?>
<main>
  <header class="blog-hero">
    <div class="blog-wrap">
      <div class="blog-kicker"><?php echo $cat ? 'Category' : ($tag ? 'Tag' : ($q ? 'Search' : 'The RestroGrow blog')); ?></div>
      <h1><?php echo bh($cat ? $cat['name'] : ($tag ? '#' . $tag : ($q ? 'Results for “' . $q . '”' : 'Grow your restaurant, one good idea at a time'))); ?></h1>
      <p><?php echo bh($cat && $cat['description'] ? $cat['description'] : ($q || $tag ? $list['total'] . ' post' . ($list['total'] === 1 ? '' : 's') . ' found.' : 'Practical guides for restaurant, cafe and cloud-kitchen owners in India. More orders, more regulars, less chaos.')); ?></p>
      <div class="blog-tools">
        <nav class="blog-cats" aria-label="Categories">
          <a href="<?php echo bh($base); ?>/blog/"<?php echo !$cat && !$tag && !$q ? ' aria-current="page"' : ''; ?>>All posts</a>
          <?php foreach ($cats as $c): ?><a href="<?php echo bh($base . '/blog/category/' . $c['slug']); ?>"<?php echo $cat && $cat['id'] === $c['id'] ? ' aria-current="page"' : ''; ?>><?php echo bh($c['name']); ?></a><?php endforeach; ?>
        </nav>
        <form class="blog-search" role="search" action="<?php echo bh($base); ?>/blog/" method="get">
          <input type="search" name="q" value="<?php echo bh($q); ?>" placeholder="Search the blog" aria-label="Search the blog">
          <button type="submit">Search</button>
        </form>
      </div>
    </div>
  </header>

  <div class="blog-wrap">
    <?php if ($featured): ?><?php echo blogCard($featured, true); ?><?php endif; ?>
    <?php if ($list['posts']): ?>
      <div class="blog-grid"><?php foreach ($list['posts'] as $p) echo blogCard($p); ?></div>
    <?php elseif (!$featured): ?>
      <div class="blog-empty">
        <h2><?php echo $q || $tag || $cat ? 'Nothing here yet' : 'The first posts are on their way'; ?></h2>
        <p><?php echo $q ? 'Try a shorter search, or browse all posts.' : 'We\'re writing practical guides for restaurant owners. Meanwhile, see what RestroGrow can do for your restaurant.'; ?></p>
        <p style="margin-top:16px"><a class="btn btn-primary" href="<?php echo bh($base); ?>/<?php echo $q || $tag || $cat ? 'blog/' : ''; ?>"><?php echo $q || $tag || $cat ? 'All posts' : 'See RestroGrow'; ?></a></p>
      </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
      <nav class="blog-pager" aria-label="Pages">
        <?php if ($page > 1): ?><a href="<?php echo bh($base . '/blog/' . $qs(['page' => $page - 1])); ?>" rel="prev">← Newer</a><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <?php if ($i === $page): ?><span aria-current="page"><?php echo $i; ?></span><?php else: ?><a href="<?php echo bh($base . '/blog/' . $qs(['page' => $i])); ?>"><?php echo $i; ?></a><?php endif; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="<?php echo bh($base . '/blog/' . $qs(['page' => $page + 1])); ?>" rel="next">Older →</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  </div>
</main>
<?php blogFooter(); ?>
