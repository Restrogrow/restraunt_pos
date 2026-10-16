<?php
/**
 * Admin handbook (Super Admin only): shows docs/ADMIN_HANDBOOK.md and the other team docs
 * (README, docs/*.md, main/docs/*.md). These files are blocked from the public website by
 * .htaccess, so this page is the way to read them on the live server.
 */
require_once __DIR__ . '/auth.php';
require_superadmin();

$root = realpath(__DIR__ . '/../..');
$docs = ['handbook' => ['Admin handbook', "$root/docs/ADMIN_HANDBOOK.md"], 'readme' => ['README', "$root/README.md"]];
foreach (["$root/docs" => 'docs', "$root/main/docs" => 'main/docs'] as $dir => $prefix) {
    foreach (glob("$dir/*.md") ?: [] as $file) {
        $base = basename($file, '.md');
        if ($base === 'ADMIN_HANDBOOK') continue;
        $docs["$prefix/$base"] = [ucfirst(strtolower(str_replace('_', ' ', $base))), $file];
    }
}
$key = isset($_GET['doc'], $docs[$_GET['doc']]) ? $_GET['doc'] : 'handbook';
$markdown = is_file($docs[$key][1]) ? (string)file_get_contents($docs[$key][1]) : "# Not found\n\nThis document isn't on the server.";
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title><?php echo $h($docs[$key][0]); ?> · Super Admin</title>
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
  main { padding: 28px 20px 60px; }
  article { max-width: 880px; margin: 0 auto; background: #fff; border-radius: 14px; padding: 28px 32px; box-shadow: 0 2px 12px rgba(0,0,0,.05); line-height: 1.65; font-size: 15px; color: var(--soft); }
  article h1 { color: var(--ink); font-size: 28px; margin-top: 0; }
  article h2 { color: var(--ink); font-size: 21px; margin-top: 34px; padding-top: 14px; border-top: 1px solid var(--line); }
  article h3 { color: var(--ink); font-size: 17px; margin-top: 24px; }
  article a { color: var(--accent); word-break: break-word; }
  article code { background: var(--bg); padding: 2px 6px; border-radius: 5px; font-size: 13px; }
  article pre { background: #0f172a; color: #e2e8f0; padding: 14px 16px; border-radius: 10px; overflow-x: auto; }
  article pre code { background: none; color: inherit; padding: 0; }
  article table { border-collapse: collapse; width: 100%; display: block; overflow-x: auto; font-size: 14px; }
  article th, article td { border: 1px solid var(--line); padding: 7px 10px; text-align: left; vertical-align: top; }
  article th { background: #f9fafb; color: var(--ink); }
  article blockquote { margin: 0; padding: 8px 16px; border-left: 4px solid #fdba74; background: #fff7ed; border-radius: 0 8px 8px 0; }
  @media (max-width: 800px) { .layout { grid-template-columns: 1fr; } nav { position: static; height: auto; } article { padding: 20px 16px; } }
</style>
</head>
<body>
<div class="layout">
  <nav>
    <a class="back" href="dashboard.php">← Back to dashboard</a>
    <h2>Handbook</h2>
    <?php foreach ($docs as $k => [$title]): ?>
      <?php if (str_starts_with($k, 'docs/') && !isset($shownDocs)): $shownDocs = true; ?><h2>Product docs</h2><?php endif; ?>
      <?php if (str_starts_with($k, 'main/docs/') && !isset($shownMain)): $shownMain = true; ?><h2>Technical notes</h2><?php endif; ?>
      <a class="doc<?php echo $k === $key ? ' on' : ''; ?>" href="?doc=<?php echo $h(urlencode($k)); ?>"><?php echo $h($title); ?></a>
    <?php endforeach; ?>
  </nav>
  <main><article id="doc"><pre><?php echo $h($markdown); ?></pre></article></main>
</div>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js"></script>
<script>
  // Our own files, rendered as written; if the library can't load, the plain text above stays.
  if (window.marked) document.getElementById('doc').innerHTML = marked.parse(<?php echo json_encode($markdown, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>);
</script>
</body>
</html>
