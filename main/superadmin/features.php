<?php
/**
 * Feature catalogue (Super Admin only): shows docs/FEATURES.md — every RestroGrow feature,
 * grouped by category, with where to find it. Built like handbook.php: docs/ is blocked from
 * the public website by .htaccess, so this page reads the file on the server.
 * Adds a live search box (filters table rows) and a category jump list.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();

$root = realpath(__DIR__ . '/../..');
$file = "$root/docs/FEATURES.md";
$markdown = is_file($file) ? (string)file_get_contents($file) : "# Not found\n\nThe feature list (docs/FEATURES.md) isn't on the server.";
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// Category jump list: every "## " heading outside code blocks, in order. The script below gives
// the rendered <h2> elements matching ids (sec-0, sec-1, ...) in the same order.
$sections = [];
$inCode = false;
foreach (preg_split('/\R/', $markdown) as $line) {
    if (str_starts_with(ltrim($line), '```')) { $inCode = !$inCode; continue; }
    if (!$inCode && preg_match('/^##\s+(.+?)\s*#*\s*$/', $line, $m)) {
        $sections[] = trim(str_replace(['**', '`'], '', $m[1]));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Features · Super Admin</title>
<style>
  :root { --ink:#111827; --soft:#374151; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --accent:#c2410c; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  .layout { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }
  nav { background: #111827; color: #e5e7eb; padding: 18px 10px; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
  nav .back { display: block; color: #9ca3af; text-decoration: none; font-size: 13px; padding: 6px 10px 14px; }
  nav h2 { font-size: 11px; letter-spacing: .08em; text-transform: uppercase; color: #9ca3af; margin: 14px 10px 6px; }
  nav a.doc { display: block; color: #e5e7eb; text-decoration: none; font-size: 13.5px; padding: 7px 10px; border-radius: 8px; }
  nav a.doc:hover { background: rgba(255,255,255,.08); }
  nav a.doc.on { background: rgba(255,255,255,.16); font-weight: 600; }
  nav a.doc .n { float: right; color: #9ca3af; font-size: 12px; }
  main { padding: 28px 20px 60px; }
  .search { max-width: 880px; margin: 0 auto 14px; position: sticky; top: 0; z-index: 2; padding-top: 4px; background: var(--bg); }
  .search input { width: 100%; font: inherit; font-size: 15px; padding: 11px 14px; border: 1px solid var(--line); border-radius: 10px; background: #fff; color: var(--ink); }
  .search input:focus { outline: 2px solid #fdba74; border-color: #fdba74; }
  .search .hint { font-size: 13px; color: var(--muted); margin: 6px 4px 8px; min-height: 18px; }
  article { max-width: 880px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 2px 12px rgba(0,0,0,.05); line-height: 1.65; font-size: 15px; color: var(--soft); }
  article h1 { color: var(--ink); font-size: 28px; margin-top: 0; }
  article h2 { color: var(--ink); font-size: 21px; margin-top: 34px; padding-top: 14px; border-top: 1px solid var(--line); scroll-margin-top: 90px; }
  article h3 { color: var(--ink); font-size: 17px; margin-top: 24px; }
  article a { color: var(--accent); word-break: break-word; }
  article code { background: var(--bg); padding: 2px 6px; border-radius: 5px; font-size: 13px; }
  article pre { background: #0f172a; color: #e2e8f0; padding: 14px 16px; border-radius: 10px; overflow-x: auto; }
  article pre code { background: none; color: inherit; padding: 0; }
  article table { border-collapse: collapse; width: 100%; display: block; overflow-x: auto; font-size: 14px; }
  article th, article td { border: 1px solid var(--line); padding: 7px 10px; text-align: left; vertical-align: top; }
  article th { background: #f9fafb; color: var(--ink); }
  article blockquote { margin: 0; padding: 8px 16px; border-left: 4px solid #fdba74; background: #fff7ed; border-radius: 0 8px 8px 0; }
  article mark { background: #fed7aa; color: inherit; padding: 0 1px; border-radius: 3px; }
  .hidden { display: none !important; }
  @media (max-width: 800px) { .layout { grid-template-columns: 1fr; } nav { position: static; height: auto; } article { padding: 20px 16px; } }
</style>
</head>
<body>
<div class="layout">
  <nav>
    <a class="back" href="dashboard.php">← Back to dashboard</a>
    <a class="back" href="handbook.php" style="padding-top:0">Admin handbook →</a>
    <h2>Categories</h2>
    <?php foreach ($sections as $i => $title): ?>
      <a class="doc" href="#sec-<?php echo $i; ?>" data-sec="<?php echo $i; ?>"><?php echo $h($title); ?><span class="n"></span></a>
    <?php endforeach; ?>
  </nav>
  <main>
    <div class="search">
      <input type="search" id="q" placeholder="Search features — e.g. loyalty, WhatsApp, printer, PhonePe…" autocomplete="off" aria-label="Search features">
      <div class="hint" id="hint"></div>
    </div>
    <article id="doc"><pre><?php echo $h($markdown); ?></pre></article>
  </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
(function () {
  var doc = document.getElementById('doc');
  // Our own file, rendered as written; if the library can't load, the plain text above stays.
  if (!window.marked) return;
  doc.innerHTML = marked.parse(<?php echo json_encode($markdown, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>);

  // Wrap each "## " heading and everything after it (up to the next one) in a <section>,
  // so search can hide a whole category, and give it the id the jump list points to.
  var sections = [];
  Array.prototype.slice.call(doc.children).forEach(function (el) {
    if (el.tagName === 'H2') {
      var s = document.createElement('section');
      s.id = 'sec-' + sections.length;
      doc.insertBefore(s, el);
      sections.push(s);
    }
    if (sections.length && el.tagName !== 'H1') sections[sections.length - 1].appendChild(el);
  });
  var intro = Array.prototype.filter.call(doc.children, function (el) { return el.tagName !== 'SECTION' && el.tagName !== 'H1'; });

  var links = document.querySelectorAll('nav a.doc[data-sec]');
  // Feature rows = rows of tables whose first column is "Feature" (not the summary count table).
  function rowsOf(s) {
    var out = [];
    s.querySelectorAll('table').forEach(function (t) {
      var th = t.querySelector('th');
      if (th && th.textContent.trim().toLowerCase() === 'feature') out.push.apply(out, t.querySelectorAll('tbody tr'));
    });
    return out;
  }
  function isPlanned(s) { var h = s.querySelector('h2'); return !!h && /^planned/i.test(h.textContent.trim()); }
  var total = 0, built = 0, planned = 0, cats = 0;
  sections.forEach(function (s, i) {
    var n = rowsOf(s).length;
    total += n;
    if (n) { if (isPlanned(s)) planned += n; else { built += n; cats++; } }
    if (links[i] && n) links[i].querySelector('.n').textContent = n;
  });

  var q = document.getElementById('q'), hint = document.getElementById('hint');
  function filter() {
    var term = q.value.trim().toLowerCase();
    var shown = 0;
    intro.forEach(function (el) { el.classList.toggle('hidden', !!term); });
    sections.forEach(function (s, i) {
      var rows = rowsOf(s), heading = s.querySelector('h2'), visible = 0;
      var headHit = term && heading && heading.textContent.toLowerCase().indexOf(term) !== -1;
      if (rows.length) {
        rows.forEach(function (tr) {
          var hit = !term || headHit || tr.textContent.toLowerCase().indexOf(term) !== -1;
          tr.classList.toggle('hidden', !hit);
          if (hit) visible++;
        });
        shown += visible;
        s.classList.toggle('hidden', !!term && !visible);
      } else {
        s.classList.toggle('hidden', !!term && !headHit && s.textContent.toLowerCase().indexOf(term) === -1);
      }
      if (links[i]) links[i].classList.toggle('hidden', !!term && s.classList.contains('hidden'));
    });
    hint.textContent = term
      ? (shown + ' of ' + total + ' features match “' + q.value.trim() + '”')
      : (built + ' built features in ' + cats + ' categories' + (planned ? ' · ' + planned + ' planned' : '') + ' — type to search, press / to jump here');
  }
  q.addEventListener('input', filter);
  document.addEventListener('keydown', function (e) {
    if (e.key === '/' && document.activeElement !== q) { e.preventDefault(); q.focus(); }
    if (e.key === 'Escape' && document.activeElement === q) { q.value = ''; filter(); }
  });
  filter();
  if (location.hash) { var t = document.getElementById(location.hash.slice(1)); if (t) t.scrollIntoView(); }
})();
</script>
</body>
</html>
