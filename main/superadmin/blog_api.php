<?php
/**
 * Super Admin › Blog (superadmin/blog.php). Engine: config/blog_engine.php.
 *
 * GET  ?action=list[&q=&status=&category=]     posts + counts
 * GET  ?action=post&id=                        one post (for the editor)
 * GET  ?action=meta                            categories, keyword plan with coverage, AI available
 * POST {action:'save', …post fields}           create / update (cleans the HTML, unique slug, reading time, SEO score)
 * POST {action:'delete'|'duplicate', id}
 * POST {action:'category_save', id?, name, description} · {action:'category_delete', id}
 * POST {action:'keyword_save', id?, keyword, intent, priority, status, volume, notes} · {action:'keyword_delete', id}
 * POST {action:'keyword_bulk', text}           one keyword per line, or "keyword | intent | priority | volume | notes"
 * POST {action:'ai_seo', …post} · {action:'ai_draft', topic, keyword, notes} · {action:'ai_keywords', seed}
 * POST multipart action=upload, file=image     → {url, path}
 */
require_once __DIR__ . '/auth.php';
require_superadmin_api();
require_once __DIR__ . '/../db_connection.php';
require_once __DIR__ . '/../config/blog_engine.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function blogOut(array $d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$me = (string)($_SESSION['superadmin_username'] ?? 'RestroGrow team');
session_write_close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // only this site's own pages may post here (the admin's cookie is not enough)
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) blogOut(['success' => false, 'message' => 'Not allowed'], 403);
}

try {
    $conn = getConnection();
    blogEnsure($conn);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = (string)($_GET['action'] ?? 'list');
        if ($action === 'list') {
            $w = ['1'];
            $p = [];
            if (($q = trim((string)($_GET['q'] ?? ''))) !== '') { $w[] = '(p.title LIKE ? OR p.focus_keyword LIKE ? OR p.tags LIKE ?)'; array_push($p, "%$q%", "%$q%", "%$q%"); }
            $status = (string)($_GET['status'] ?? '');
            if ($status === 'draft') $w[] = "p.status = 'draft'";
            if ($status === 'published') $w[] = "p.status = 'published' AND p.published_at <= NOW()";
            if ($status === 'scheduled') $w[] = "p.status = 'published' AND p.published_at > NOW()";
            if (($cat = (int)($_GET['category'] ?? 0)) > 0) { $w[] = 'p.category_id = ?'; $p[] = $cat; }
            $st = $conn->prepare("SELECT p.id, p.slug, p.title, p.status, p.published_at, p.updated_at, p.focus_keyword, p.seo_score, p.views, p.reading_minutes, p.featured,
                    p.cover_image, c.name AS category,
                    CASE WHEN p.status = 'draft' THEN 'draft' WHEN p.published_at > NOW() THEN 'scheduled' ELSE 'published' END AS state
                FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE " . implode(' AND ', $w) . ' ORDER BY p.updated_at DESC LIMIT 500');
            $st->execute($p);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['cover_url'] = blogImageUrl($r['cover_image']);
            unset($r);
            $counts = $conn->query("SELECT SUM(status = 'draft') drafts, SUM(status = 'published' AND published_at <= NOW()) published,
                SUM(status = 'published' AND published_at > NOW()) scheduled, COALESCE(SUM(views), 0) views FROM blog_posts")->fetch(PDO::FETCH_ASSOC);
            blogOut(['success' => true, 'posts' => $rows, 'counts' => array_map('intval', $counts), 'site' => blogWebBase()]);
        }
        if ($action === 'post') {
            $st = $conn->prepare('SELECT * FROM blog_posts WHERE id = ?');
            $st->execute([(int)($_GET['id'] ?? 0)]);
            $post = $st->fetch(PDO::FETCH_ASSOC);
            if (!$post) blogOut(['success' => false, 'message' => 'Post not found'], 404);
            $post['cover_url'] = blogImageUrl($post['cover_image']);
            blogOut(['success' => true, 'post' => $post, 'seo' => blogSeoReport($post)]);
        }
        if ($action === 'meta') {
            require_once __DIR__ . '/../config/ai_gemini.php';
            blogOut(['success' => true, 'categories' => blogCategories($conn, true), 'keywords' => blogKeywordCoverage($conn),
                'ai' => aiGeminiAvailable(), 'site' => blogWebBase(), 'author' => $me]);
        }
        blogOut(['success' => false, 'message' => 'Unknown action'], 400);
    }

    // ── uploads (multipart) ──
    if (($_POST['action'] ?? '') === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) blogOut(['success' => false, 'message' => 'The upload did not arrive. Try a smaller image.'], 400);
        if ($f['size'] > 8 * 1024 * 1024) blogOut(['success' => false, 'message' => 'Images up to 8 MB, please.'], 400);
        $info = @getimagesize($f['tmp_name']);
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
        if (!$info || !isset($types[$info[2]])) blogOut(['success' => false, 'message' => 'Use a JPG, PNG, WEBP or GIF image.'], 400);
        $dir = 'uploads/blog/' . date('Y-m');
        $abs = __DIR__ . '/../' . $dir;
        if (!is_dir($abs) && !@mkdir($abs, 0755, true)) blogOut(['success' => false, 'message' => 'Could not create the image folder on the server.'], 500);
        $base = blogSlug(pathinfo((string)$f['name'], PATHINFO_FILENAME)) . '-' . substr(bin2hex(random_bytes(4)), 0, 6);
        $ext = $types[$info[2]];
        // photos are resized to 1600px wide and saved as WEBP (smaller pages); GIFs stay as they are
        if ($ext !== 'gif' && function_exists('imagewebp')) {
            $src = ['jpg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'][$ext];
            $im = @$src($f['tmp_name']);
            if ($im) {
                $w = imagesx($im); $h = imagesy($im);
                if ($w > 1600) { $nh = (int)round($h * 1600 / $w); $res = imagecreatetruecolor(1600, $nh); imagealphablending($res, false); imagesavealpha($res, true); imagecopyresampled($res, $im, 0, 0, 0, 0, 1600, $nh, $w, $h); imagedestroy($im); $im = $res; }
                imagepalettetotruecolor($im);
                imagesavealpha($im, true);
                $file = "$dir/$base.webp";
                imagewebp($im, __DIR__ . '/../' . $file, 82);
                imagedestroy($im);
                blogOut(['success' => true, 'path' => $file, 'url' => blogImageUrl($file)]);
            }
        }
        $file = "$dir/$base.$ext";
        if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $file)) blogOut(['success' => false, 'message' => 'Could not save the image.'], 500);
        blogOut(['success' => true, 'path' => $file, 'url' => blogImageUrl($file)]);
    }

    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($in['id'] ?? 0);
        $title = trim(mb_substr((string)($in['title'] ?? ''), 0, 200));
        if ($title === '') blogOut(['success' => false, 'message' => 'Give the post a title.'], 400);
        $content = blogSanitizeHtml((string)($in['content'] ?? ''));
        $status = ($in['status'] ?? '') === 'published' ? 'published' : 'draft';
        $pub = trim((string)($in['published_at'] ?? ''));
        $pubAt = $pub !== '' && strtotime($pub) ? date('Y-m-d H:i:s', strtotime($pub)) : null;
        if ($status === 'published' && !$pubAt) $pubAt = date('Y-m-d H:i:s');
        if ($status === 'published' && blogWordCount($content) < 30) blogOut(['success' => false, 'message' => 'The post is almost empty. Write it before publishing (or save it as a draft).'], 400);
        $catId = (int)($in['category_id'] ?? 0) ?: null;
        $clean = fn($k, $n) => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($in[$k] ?? '')), 0, $n));
        $list = fn($k, $n) => mb_substr(implode(', ', array_values(array_unique(array_filter(array_map(fn($x) => trim(mb_strtolower($x)), preg_split('/[,\n]/', (string)($in[$k] ?? ''))))))), 0, $n);
        $cover = trim((string)($in['cover_image'] ?? ''));
        if ($cover !== '' && !preg_match('#^(uploads/blog/[\w/.-]+|https://[^\s"<>]+)$#', $cover)) $cover = '';
        $canon = trim((string)($in['canonical_url'] ?? ''));
        if ($canon !== '' && !preg_match('#^https://[^\s"<>]+$#', $canon)) $canon = '';
        $row = [
            'title' => $title,
            'slug' => blogUniqueSlug($conn, trim((string)($in['slug'] ?? '')) ?: $title, $id),
            'excerpt' => $clean('excerpt', 400) ?: mb_substr(blogPlainText($content), 0, 200),
            'content' => $content,
            'cover_image' => $cover ?: null,
            'cover_alt' => $clean('cover_alt', 200) ?: null,
            'category_id' => $catId,
            'tags' => $list('tags', 500) ?: null,
            'focus_keyword' => mb_strtolower($clean('focus_keyword', 120)) ?: null,
            'keywords' => $list('keywords', 600) ?: null,
            'meta_title' => $clean('meta_title', 160) ?: null,
            'meta_description' => $clean('meta_description', 320) ?: null,
            'canonical_url' => $canon ?: null,
            'status' => $status,
            'published_at' => $pubAt,
            'author_name' => $clean('author_name', 100) ?: 'RestroGrow team',
            'featured' => empty($in['featured']) ? 0 : 1,
            'noindex' => empty($in['noindex']) ? 0 : 1,
            'reading_minutes' => blogReadingMinutes($content),
        ];
        $row['seo_score'] = blogSeoReport($row)['score'];
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            $st = $conn->prepare("UPDATE blog_posts SET $sets WHERE id = ?");
            $st->execute([...array_values($row), $id]);
        } else {
            $cols = implode(', ', array_keys($row));
            $conn->prepare("INSERT INTO blog_posts ($cols) VALUES (" . implode(', ', array_fill(0, count($row), '?')) . ')')->execute(array_values($row));
            $id = (int)$conn->lastInsertId();
        }
        // a keyword in the plan that now has a post moves to "written"
        if ($row['focus_keyword']) $conn->prepare("UPDATE blog_keywords SET status = 'written' WHERE keyword = ? AND status <> 'written'")->execute([$row['focus_keyword']]);
        $st = $conn->prepare('SELECT * FROM blog_posts WHERE id = ?');
        $st->execute([$id]);
        $post = $st->fetch(PDO::FETCH_ASSOC);
        $post['cover_url'] = blogImageUrl($post['cover_image']);
        $live = $status === 'published' && strtotime((string)$pubAt) <= time();
        blogOut(['success' => true, 'post' => $post, 'seo' => blogSeoReport($post),
            'message' => $status === 'draft' ? 'Draft saved' : ($live ? 'Published' : 'Scheduled for ' . date('d M, h:i A', strtotime((string)$pubAt))),
            'url' => blogWebBase() . '/blog/' . $post['slug']]);
    }
    if ($action === 'delete') {
        $conn->prepare('DELETE FROM blog_posts WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        blogOut(['success' => true, 'message' => 'Post deleted']);
    }
    if ($action === 'duplicate') {
        $st = $conn->prepare('SELECT * FROM blog_posts WHERE id = ?');
        $st->execute([(int)($in['id'] ?? 0)]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) blogOut(['success' => false, 'message' => 'Post not found'], 404);
        unset($p['id'], $p['created_at'], $p['updated_at']);
        $p['title'] = mb_substr('Copy of ' . $p['title'], 0, 200);
        $p['slug'] = blogUniqueSlug($conn, $p['slug'] . '-copy');
        $p['status'] = 'draft'; $p['published_at'] = null; $p['views'] = 0; $p['featured'] = 0;
        $cols = implode(', ', array_keys($p));
        $conn->prepare("INSERT INTO blog_posts ($cols) VALUES (" . implode(', ', array_fill(0, count($p), '?')) . ')')->execute(array_values($p));
        blogOut(['success' => true, 'message' => 'Copied as a draft', 'id' => (int)$conn->lastInsertId()]);
    }

    if ($action === 'category_save') {
        $name = trim(mb_substr((string)($in['name'] ?? ''), 0, 80));
        if ($name === '') blogOut(['success' => false, 'message' => 'Give the category a name.'], 400);
        $slug = blogSlug(trim((string)($in['slug'] ?? '')) ?: $name);
        $desc = trim(mb_substr((string)($in['description'] ?? ''), 0, 300)) ?: null;
        $id = (int)($in['id'] ?? 0);
        try {
            if ($id) $conn->prepare('UPDATE blog_categories SET name = ?, slug = ?, description = ? WHERE id = ?')->execute([$name, $slug, $desc, $id]);
            else $conn->prepare('INSERT INTO blog_categories (name, slug, description, sort) VALUES (?, ?, ?, 99)')->execute([$name, $slug, $desc]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') blogOut(['success' => false, 'message' => 'Another category already uses that name.'], 400);
            throw $e;
        }
        blogOut(['success' => true, 'message' => 'Category saved', 'categories' => blogCategories($conn, true)]);
    }
    if ($action === 'category_delete') {
        $id = (int)($in['id'] ?? 0);
        $conn->prepare('UPDATE blog_posts SET category_id = NULL WHERE category_id = ?')->execute([$id]);
        $conn->prepare('DELETE FROM blog_categories WHERE id = ?')->execute([$id]);
        blogOut(['success' => true, 'message' => 'Category deleted (its posts are kept, without a category)', 'categories' => blogCategories($conn, true)]);
    }

    if ($action === 'keyword_save') {
        $kw = mb_strtolower(trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($in['keyword'] ?? '')), 0, 150)));
        if ($kw === '') blogOut(['success' => false, 'message' => 'Type the keyword.'], 400);
        $intent = in_array($in['intent'] ?? '', ['info', 'commercial', 'local', 'brand'], true) ? $in['intent'] : 'info';
        $prio = max(1, min(3, (int)($in['priority'] ?? 2)));
        $status = in_array($in['status'] ?? '', ['idea', 'planned', 'written'], true) ? $in['status'] : 'idea';
        $vol = trim(mb_substr((string)($in['volume'] ?? ''), 0, 30)) ?: null;
        $notes = trim(mb_substr((string)($in['notes'] ?? ''), 0, 500)) ?: null;
        $id = (int)($in['id'] ?? 0);
        try {
            if ($id) $conn->prepare('UPDATE blog_keywords SET keyword = ?, intent = ?, priority = ?, status = ?, volume = ?, notes = ? WHERE id = ?')->execute([$kw, $intent, $prio, $status, $vol, $notes, $id]);
            else $conn->prepare('INSERT INTO blog_keywords (keyword, intent, priority, status, volume, notes) VALUES (?, ?, ?, ?, ?, ?)')->execute([$kw, $intent, $prio, $status, $vol, $notes]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') blogOut(['success' => false, 'message' => 'That keyword is already in the list.'], 400);
            throw $e;
        }
        blogOut(['success' => true, 'message' => 'Keyword saved', 'keywords' => blogKeywordCoverage($conn)]);
    }
    if ($action === 'keyword_delete') {
        $conn->prepare('DELETE FROM blog_keywords WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
        blogOut(['success' => true, 'message' => 'Keyword removed', 'keywords' => blogKeywordCoverage($conn)]);
    }
    if ($action === 'keyword_bulk') {
        $items = $in['items'] ?? null;
        $n = 0;
        $ins = $conn->prepare('INSERT IGNORE INTO blog_keywords (keyword, intent, priority, volume, notes) VALUES (?, ?, ?, ?, ?)');
        if (is_array($items)) {
            foreach (array_slice($items, 0, 100) as $k) {
                $kw = mb_strtolower(trim(mb_substr((string)($k['keyword'] ?? ''), 0, 150)));
                if ($kw === '') continue;
                $ins->execute([$kw, in_array($k['intent'] ?? '', ['info', 'commercial', 'local', 'brand'], true) ? $k['intent'] : 'info', max(1, min(3, (int)($k['priority'] ?? 2))), null, trim(mb_substr((string)($k['notes'] ?? ''), 0, 500)) ?: null]);
                $n += $ins->rowCount();
            }
        } else {
            // a line is a keyword, or "keyword | intent | priority | volume | notes" (a pasted keyword plan); # lines are skipped
            foreach (array_slice(preg_split('/\r?\n/', (string)($in['text'] ?? '')), 0, 200) as $line) {
                $f = array_map('trim', explode('|', $line));
                $kw = mb_strtolower(trim(mb_substr(preg_replace('/\s+/u', ' ', $f[0]), 0, 150)));
                if ($kw === '' || $kw[0] === '#') continue;
                $intent = mb_strtolower($f[1] ?? '');
                $ins->execute([$kw, in_array($intent, ['info', 'commercial', 'local', 'brand'], true) ? $intent : 'info',
                    ($f[2] ?? '') !== '' ? max(1, min(3, (int)$f[2])) : 2, mb_substr($f[3] ?? '', 0, 30) ?: null, mb_substr($f[4] ?? '', 0, 500) ?: null]);
                $n += $ins->rowCount();
            }
        }
        blogOut(['success' => true, 'message' => $n ? "Added $n keyword" . ($n > 1 ? 's' : '') : 'Those keywords are already in the list', 'keywords' => blogKeywordCoverage($conn)]);
    }

    if (in_array($action, ['ai_seo', 'ai_draft', 'ai_keywords'], true)) {
        require_once __DIR__ . '/../config/ai_gemini.php';
        if (!aiGeminiAvailable()) blogOut(['success' => false, 'message' => 'AI is not set up (GEMINI_API_KEY in main/.env).'], 400);
        @set_time_limit(120);
        if ($action === 'ai_seo') {
            if (blogWordCount((string)($in['content'] ?? '')) < 60) blogOut(['success' => false, 'message' => 'Write at least a few paragraphs first, then AI can suggest the SEO fields.'], 400);
            $r = blogAiSeo($in);
        } elseif ($action === 'ai_draft') {
            $topic = trim(mb_substr((string)($in['topic'] ?? ''), 0, 300));
            if ($topic === '') blogOut(['success' => false, 'message' => 'Type what the post is about.'], 400);
            $r = blogAiDraft($topic, trim(mb_substr((string)($in['keyword'] ?? ''), 0, 120)), trim(mb_substr((string)($in['notes'] ?? ''), 0, 1000)));
            if ($r && isset($r['html'])) $r['html'] = blogSanitizeHtml($r['html']);
        } else {
            $seed = trim(mb_substr((string)($in['seed'] ?? ''), 0, 200));
            if ($seed === '') blogOut(['success' => false, 'message' => 'Type a topic, for example "restaurant billing".'], 400);
            $r = blogAiKeywords($seed);
        }
        if (!$r) blogOut(['success' => false, 'message' => 'The AI did not answer. Try again in a minute.'], 502);
        blogOut(['success' => true, 'result' => $r]);
    }

    blogOut(['success' => false, 'message' => 'Unknown action'], 400);
} catch (Throwable $e) {
    error_log('blog_api: ' . $e->getMessage());
    blogOut(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
