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
      <a class="btn btn-primary" href="<?php echo bh($base); ?>/main/admin/login.php?tab=signup">Start free trial</a>
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
                    <!-- our profiles (also in the Organization sameAs for Google) -->
                    <div class="footer-social" aria-label="Follow RestroGrow"><a href="https://www.linkedin.com/company/restro-grow/" target="_blank" rel="noopener me" aria-label="RestroGrow on LinkedIn" title="LinkedIn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.98 3.5a2.5 2.5 0 1 1 0 5 2.5 2.5 0 0 1 0-5zM3 9h4v12H3zM9 9h3.8v1.7h.05c.53-1 1.84-2.05 3.79-2.05C20.6 8.65 21 11.2 21 14.5V21h-4v-5.8c0-1.4-.03-3.2-1.95-3.2-1.95 0-2.25 1.52-2.25 3.1V21H9z"/></svg></a><a href="https://www.instagram.com/restrogrow/" target="_blank" rel="noopener me" aria-label="RestroGrow on Instagram" title="Instagram"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.2c3.2 0 3.58.01 4.85.07 3.25.15 4.77 1.69 4.92 4.92.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.15 3.23-1.66 4.77-4.92 4.92-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-3.26-.15-4.77-1.7-4.92-4.92C2.21 15.58 2.2 15.2 2.2 12s.01-3.58.07-4.85C2.42 3.92 3.94 2.37 7.15 2.27 8.42 2.21 8.8 2.2 12 2.2zm0 4.86a4.94 4.94 0 1 0 0 9.88 4.94 4.94 0 0 0 0-9.88zm0 8.15a3.21 3.21 0 1 1 0-6.42 3.21 3.21 0 0 1 0 6.42zm5.14-9.5a1.15 1.15 0 1 0 0 2.3 1.15 1.15 0 0 0 0-2.3z"/></svg></a><a href="https://x.com/restrogrow" target="_blank" rel="noopener me" aria-label="RestroGrow on X (Twitter)" title="X (Twitter)"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.75 3h3.07l-6.7 7.66L22 21h-6.17l-4.83-6.32L5.47 21H2.4l7.17-8.2L2 3h6.33l4.37 5.78zm-1.08 16.18h1.7L7.4 4.74H5.58z"/></svg></a><a href="https://www.youtube.com/@RestroGrow" target="_blank" rel="noopener me" aria-label="RestroGrow on YouTube" title="YouTube"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 7.2a3 3 0 0 0-2.1-2.12C19.04 4.6 12 4.6 12 4.6s-7.04 0-8.9.48A3 3 0 0 0 1 7.2 31 31 0 0 0 .5 12a31 31 0 0 0 .5 4.8 3 3 0 0 0 2.1 2.12c1.86.48 8.9.48 8.9.48s7.04 0 8.9-.48A3 3 0 0 0 23 16.8a31 31 0 0 0 .5-4.8 31 31 0 0 0-.5-4.8zM9.75 15.02V8.98L15.5 12z"/></svg></a></div>
                    <style>.footer-social{display:flex;gap:10px;margin-top:14px;flex-wrap:wrap}.footer-social a{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:rgba(255,255,255,.08);color:inherit;opacity:.85;transition:background .2s,opacity .2s,transform .2s}.footer-social a:hover{background:#ff6b35;color:#fff;opacity:1;transform:translateY(-2px)}.footer-social svg{width:18px;height:18px;fill:currentColor}@media(max-width:768px){.footer-social{justify-content:center}}</style>
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
