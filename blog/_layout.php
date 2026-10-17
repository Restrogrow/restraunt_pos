<?php
/**
 * Shared parts of the public blog (blog/index.php, blog/post.php): the site's header and footer
 * (same look as the homepage, style.css), SEO head tags and the blog's own styles (blog.css).
 */
require_once __DIR__ . '/../main/db_connection.php';
require_once __DIR__ . '/../main/config/blog_engine.php';

function bh(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function blogDate(?string $d): string { return $d ? date('j M Y', strtotime($d)) : ''; }

/** <head> and the site header. $m: title, description, canonical, image, type (website|article), noindex, jsonld (array of arrays), extra (raw head HTML) */
function blogHeader(array $m): void
{
    $base = blogWebBase();
    $title = $m['title'] ?? 'RestroGrow Blog';
    $desc = $m['description'] ?? 'Tips, guides and ideas for restaurant owners in India: more orders, more regulars, smoother service.';
    $img = $m['image'] ?? ($base . '/assets/images/logo-transparent.png');
    $canon = $m['canonical'] ?? '';
    $v = @filemtime(__DIR__ . '/blog.css');
    ?><!DOCTYPE html>
<html lang="en-IN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo bh($title); ?></title>
<meta name="description" content="<?php echo bh($desc); ?>">
<?php if (!empty($m['noindex'])): ?><meta name="robots" content="noindex, follow">
<?php else: ?><meta name="robots" content="index, follow, max-image-preview:large">
<?php endif; ?>
<?php if ($canon): ?><link rel="canonical" href="<?php echo bh($canon); ?>">
<?php endif; ?>
<meta property="og:site_name" content="RestroGrow">
<meta property="og:type" content="<?php echo bh($m['type'] ?? 'website'); ?>">
<meta property="og:title" content="<?php echo bh($m['og_title'] ?? $title); ?>">
<meta property="og:description" content="<?php echo bh($desc); ?>">
<?php if ($canon): ?><meta property="og:url" content="<?php echo bh($canon); ?>">
<?php endif; ?>
<meta property="og:image" content="<?php echo bh($img); ?>">
<meta property="og:locale" content="en_IN">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo bh($m['og_title'] ?? $title); ?>">
<meta name="twitter:description" content="<?php echo bh($desc); ?>">
<meta name="twitter:image" content="<?php echo bh($img); ?>">
<link rel="alternate" type="application/rss+xml" title="RestroGrow Blog" href="<?php echo bh($base); ?>/blog/feed.xml">
<link rel="icon" type="image/png" href="<?php echo bh($base); ?>/favicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800;900&family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap">
<link rel="stylesheet" href="<?php echo bh($base); ?>/style.css?v=3.1">
<link rel="stylesheet" href="<?php echo bh($base); ?>/blog/blog.css?v=<?php echo (int)$v; ?>">
<?php foreach ($m['jsonld'] ?? [] as $ld): ?><script type="application/ld+json"><?php echo json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?></script>
<?php endforeach; ?>
<?php echo $m['extra'] ?? ''; ?>
</head>
<body class="blog-body">
<nav class="navbar">
  <div class="container">
    <a href="<?php echo bh($base); ?>/" class="nav-brand">
      <img src="<?php echo bh($base); ?>/assets/images/logo-transparent.png" alt="Restro Grow Logo" class="brand-logo">
      <span class="brand-text">Restro Grow</span>
    </a>
    <ul class="nav-menu" id="blogNav">
      <li><a href="<?php echo bh($base); ?>/#features">Features</a></li>
      <li><a href="<?php echo bh($base); ?>/#pricing">Pricing</a></li>
      <li><a href="<?php echo bh($base); ?>/about/">About</a></li>
      <li><a href="<?php echo bh($base); ?>/blog/" aria-current="page">Blog</a></li>
      <li><a href="<?php echo bh($base); ?>/faq/">FAQ</a></li>
      <li><a href="<?php echo bh($base); ?>/app-demo/">App demo</a></li>
      <li><a href="<?php echo bh($base); ?>/main/admin/login.php" class="btn-nav">Login</a></li>
    </ul>
    <button class="nav-toggle" aria-label="Open the menu" aria-controls="blogNav" aria-expanded="false" onclick="var m=document.getElementById('blogNav');m.classList.toggle('active');this.setAttribute('aria-expanded',m.classList.contains('active'))">
      <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
    </button>
  </div>
</nav>
<?php
}

function blogFooter(): void
{
    $base = blogWebBase();
    ?>
<section class="blog-cta-band">
  <div class="blog-wrap">
    <div>
      <h2>Run your restaurant on RestroGrow</h2>
      <p>POS billing, QR ordering, your own ordering website and app, WhatsApp marketing and loyalty. Start free, no card needed.</p>
    </div>
    <div class="blog-cta-btns">
      <a class="btn btn-primary" href="<?php echo bh($base); ?>/main/admin/login.php">Start free trial</a>
      <a class="btn btn-secondary" href="<?php echo bh($base); ?>/app-demo/">Try the app demo</a>
    </div>
  </div>
</section>
<footer class="footer">
  <div class="container">
    <div class="footer-content">
      <div class="footer-section">
        <div class="footer-brand"><img src="<?php echo bh($base); ?>/assets/images/logo-transparent.png" alt="Restro Grow Logo" class="footer-logo"><span>Restro Grow</span></div>
        <p>Complete restaurant management solution for modern restaurants.</p>
      </div>
      <div class="footer-section"><h4>Product</h4><ul>
        <li><a href="<?php echo bh($base); ?>/#features">Features</a></li><li><a href="<?php echo bh($base); ?>/#pricing">Pricing</a></li><li><a href="<?php echo bh($base); ?>/main/admin/login.php">Login</a></li>
      </ul></div>
      <div class="footer-section"><h4>Company</h4><ul>
        <li><a href="<?php echo bh($base); ?>/about/">About Us</a></li><li><a href="<?php echo bh($base); ?>/blog/">Blog</a></li><li><a href="<?php echo bh($base); ?>/faq/">FAQ</a></li><li><a href="<?php echo bh($base); ?>/blog/feed.xml">RSS feed</a></li>
      </ul></div>
      <div class="footer-section"><h4>Legal</h4><ul>
        <li><a href="<?php echo bh($base); ?>/privacy-policy/">Privacy Policy</a></li><li><a href="<?php echo bh($base); ?>/terms-of-service/">Terms of Service</a></li>
        <li><a href="<?php echo bh($base); ?>/refund-policy/">Refund &amp; Return Policy</a></li><li><a href="<?php echo bh($base); ?>/cookie-policy/">Cookie Policy</a></li>
      </ul></div>
    </div>
    <div class="footer-bottom">
      <p>&copy; <?php echo date('Y'); ?> Restro Grow. All rights reserved.</p>
      <p class="footer-contact-line"><a href="mailto:restrogrow@gmail.com">restrogrow@gmail.com</a> <a href="tel:+916377568749">+91 63775 68749</a></p>
    </div>
  </div>
</footer>
</body>
</html>
<?php
}

/** One post card for the lists. */
function blogCard(array $p, bool $big = false): string
{
    $base = blogWebBase();
    $url = $base . '/blog/' . rawurlencode($p['slug']);
    $img = blogImageUrl($p['cover_image'] ?? '');
    return '<article class="bcard' . ($big ? ' bcard-big' : '') . '">' . ($big ? '<span class="bcard-flag">Featured</span>' : '')
        . '<a class="bcard-img" href="' . bh($url) . '" tabindex="-1" aria-hidden="true">'
        . ($img ? '<img src="' . bh($img) . '" alt="' . bh($p['cover_alt'] ?: $p['title']) . '" loading="lazy">' : '<span class="bcard-ph">' . bh(mb_substr($p['title'], 0, 1)) . '</span>') . '</a>'
        . '<div class="bcard-body">'
        . (!empty($p['category']) ? '<a class="bcard-cat" href="' . bh($base . '/blog/category/' . $p['category_slug']) . '">' . bh($p['category']) . '</a>' : '')
        . '<h' . ($big ? '2' : '3') . ' class="bcard-title"><a href="' . bh($url) . '">' . bh($p['title']) . '</a></h' . ($big ? '2' : '3') . '>'
        . ($p['excerpt'] ? '<p class="bcard-ex">' . bh($p['excerpt']) . '</p>' : '')
        . '<p class="bcard-meta"><span>' . bh(blogDate($p['published_at'])) . ' · ' . (int)$p['reading_minutes'] . ' min read</span><b>Read →</b></p>'
        . '</div></article>';
}
