<?php
/**
 * RestroGrow blog (restrogrow.com/blog), managed in Super Admin › Blog.
 *
 *  - blog_posts       title, slug, content (clean HTML), cover, category, tags, SEO fields, status, publish time
 *  - blog_categories  name, slug, description
 *  - blog_keywords    the keyword plan: keyword, intent, priority, status, notes (and which posts cover it)
 *
 * A post is live when status = 'published' and published_at <= now (a future time = scheduled).
 * Content is cleaned with blogSanitizeHtml() on save, so the public page can print it as is.
 * blogSeoReport() is the same checklist the editor shows live.
 */

if (!function_exists('blogEnsure')) {

    function blogEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        // starter posts already added once (so one deleted or renamed later isn't offered again)
        $conn->exec("CREATE TABLE IF NOT EXISTS blog_starter_imports (
            slug VARCHAR(160) NOT NULL PRIMARY KEY,
            imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS blog_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            slug VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(300) NULL,
            sort INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS blog_posts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            slug VARCHAR(160) NOT NULL UNIQUE,
            title VARCHAR(200) NOT NULL,
            excerpt VARCHAR(400) NULL,
            content MEDIUMTEXT NULL,
            cover_image VARCHAR(255) NULL,
            cover_alt VARCHAR(200) NULL,
            category_id INT NULL,
            tags VARCHAR(500) NULL,
            focus_keyword VARCHAR(120) NULL,
            keywords VARCHAR(600) NULL,
            meta_title VARCHAR(160) NULL,
            meta_description VARCHAR(320) NULL,
            canonical_url VARCHAR(255) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'draft',
            published_at DATETIME NULL,
            author_name VARCHAR(100) NULL,
            featured TINYINT NOT NULL DEFAULT 0,
            noindex TINYINT NOT NULL DEFAULT 0,
            reading_minutes INT NOT NULL DEFAULT 1,
            seo_score INT NOT NULL DEFAULT 0,
            views INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX (status, published_at), INDEX (category_id), INDEX (featured)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $conn->exec("CREATE TABLE IF NOT EXISTS blog_keywords (
            id INT AUTO_INCREMENT PRIMARY KEY,
            keyword VARCHAR(150) NOT NULL UNIQUE,
            intent VARCHAR(20) NOT NULL DEFAULT 'info',
            priority TINYINT NOT NULL DEFAULT 2,
            status VARCHAR(12) NOT NULL DEFAULT 'idea',
            volume VARCHAR(30) NULL,
            notes VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // first use: a few categories to start with
        if ((int)$conn->query('SELECT COUNT(*) FROM blog_categories')->fetchColumn() === 0) {
            $ins = $conn->prepare('INSERT INTO blog_categories (name, slug, description, sort) VALUES (?, ?, ?, ?)');
            foreach ([['Restaurant growth', 'restaurant-growth', 'More orders, more regulars, more profit.'],
                      ['Running a restaurant', 'running-a-restaurant', 'Kitchen, staff, billing and the daily work.'],
                      ['Marketing', 'marketing', 'WhatsApp, Google, Instagram and offers that bring guests back.'],
                      ['RestroGrow updates', 'product-updates', 'New features and how to use them.']] as $i => $c) {
                $ins->execute([$c[0], $c[1], $c[2], $i]);
            }
        }
    }

    function blogSiteUrl(): string
    {
        return rtrim((string)(env('APP_URL', '') ?: 'https://restrogrow.com'), '/');
    }

    /** This site's address as the visitor sees it (http://localhost/menuwebsite on a PC, https://restrogrow.com live). */
    function blogWebBase(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($host === '' || !preg_match('/^[a-z0-9.:-]+$/i', $host)) return blogSiteUrl();
        $path = (string)($_SERVER['SCRIPT_NAME'] ?? '');
        foreach (['/main/', '/blog/'] as $cut) {
            $i = strpos($path, $cut);
            if ($i !== false) return ($https ? 'https' : 'http') . '://' . $host . substr($path, 0, $i);
        }
        return ($https ? 'https' : 'http') . '://' . $host;
    }

    /** A cover / image path saved as "uploads/blog/…" becomes a full address; full addresses stay. */
    function blogImageUrl(?string $path): string
    {
        $path = trim((string)$path);
        if ($path === '') return '';
        if (preg_match('#^https?://#i', $path)) return $path;
        return blogWebBase() . '/main/' . ltrim($path, '/');
    }

    function blogSlug(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^\p{L}\p{N}]+/u', '-', $s);
        $s = trim(preg_replace('/-+/', '-', $s), '-');
        // keep URLs plain ASCII where the words are English; transliterate the rest if possible
        if (function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $s);
            if ($t) $s = trim(preg_replace('/[^a-z0-9]+/', '-', $t), '-');
        } else {
            $s = trim(preg_replace('/[^a-z0-9-]+/', '', $s), '-');
        }
        return mb_substr($s !== '' ? $s : 'post', 0, 150);
    }

    /** A slug no other post uses ($exceptId = the post being saved). */
    function blogUniqueSlug(PDO $conn, string $slug, int $exceptId = 0): string
    {
        $base = blogSlug($slug);
        $try = $base;
        for ($i = 2; $i < 200; $i++) {
            $st = $conn->prepare('SELECT id FROM blog_posts WHERE slug = ? AND id <> ?');
            $st->execute([$try, $exceptId]);
            if (!$st->fetchColumn()) return $try;
            $try = $base . '-' . $i;
        }
        return $base . '-' . time();
    }

    /**
     * Clean HTML from the editor: only safe tags and attributes, links get rel, images keep src/alt,
     * the editor's alignment classes stay. Scripts, styles, event handlers and javascript: links go.
     */
    function blogSanitizeHtml(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        $allowed = [
            'p' => ['class'], 'br' => [], 'hr' => [], 'h2' => ['id', 'class'], 'h3' => ['id', 'class'], 'h4' => ['id', 'class'],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'mark' => [], 'sub' => [], 'sup' => [],
            'a' => ['href', 'target', 'rel', 'title'], 'ul' => ['class'], 'ol' => ['class'], 'li' => ['class', 'data-list'],
            'blockquote' => ['class'], 'pre' => ['class', 'spellcheck'], 'code' => [], 'img' => ['src', 'alt', 'title', 'width', 'height', 'loading'],
            'figure' => [], 'figcaption' => [], 'table' => [], 'thead' => [], 'tbody' => [], 'tr' => [], 'th' => [], 'td' => [],
            'span' => ['class'], 'div' => ['class'], 'iframe' => ['src', 'width', 'height', 'allowfullscreen', 'frameborder', 'class'],
        ];
        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="blog-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        $root = $doc->getElementById('blog-root');
        if (!$root) return '';
        $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
            for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
                $c = $node->childNodes->item($i);
                if ($c instanceof DOMElement) {
                    $tag = strtolower($c->tagName);
                    if ($tag === 'h1') { // one h1 per page (the title): demote
                        $h2 = $doc->createElement('h2');
                        while ($c->firstChild) $h2->appendChild($c->firstChild);
                        $node->replaceChild($h2, $c);
                        $c = $h2; $tag = 'h2';
                    }
                    if (!isset($allowed[$tag])) {
                        if (in_array($tag, ['script', 'style', 'object', 'embed', 'form', 'input', 'button', 'textarea', 'select', 'link', 'meta', 'svg', 'math'], true)) {
                            $node->removeChild($c);
                        } else { // unknown wrapper: keep its text and children
                            $walk($c);
                            while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                            $node->removeChild($c);
                        }
                        continue;
                    }
                    foreach (iterator_to_array($c->attributes) as $a) {
                        $n = strtolower($a->name);
                        $v = trim($a->value);
                        $ok = in_array($n, $allowed[$tag], true);
                        if ($ok && in_array($n, ['href', 'src'], true)) {
                            $ok = (bool)preg_match('#^(https?://|/|\.\./|\#|mailto:|tel:)#i', $v) && !preg_match('#^\s*(javascript|data|vbscript):#i', $v);
                            if ($tag === 'iframe') $ok = (bool)preg_match('#^https://(www\.)?(youtube\.com|youtube-nocookie\.com|player\.vimeo\.com)/#i', $v);
                        }
                        if ($ok && $n === 'class') $ok = (bool)preg_match('/^[\w\s-]{0,80}$/', $v) && preg_match('/^(ql-|blog-)/', $v);
                        if (!$ok) $c->removeAttribute($a->name);
                    }
                    if ($tag === 'a') {
                        $href = $c->getAttribute('href');
                        $external = preg_match('#^https?://#i', $href) && stripos($href, parse_url(blogSiteUrl(), PHP_URL_HOST) ?: 'restrogrow.com') === false;
                        if ($external) { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener'); }
                        else { $c->removeAttribute('target'); $c->removeAttribute('rel'); }
                    }
                    if ($tag === 'img') $c->setAttribute('loading', 'lazy');
                    if ($tag === 'iframe') { $c->setAttribute('loading', 'lazy'); $c->setAttribute('allowfullscreen', 'allowfullscreen'); }
                    $walk($c);
                } elseif ($c instanceof DOMComment) {
                    $node->removeChild($c);
                }
            }
        };
        $walk($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
        // empty paragraphs the editor leaves behind
        $out = preg_replace('#<p>(\s|&nbsp;|<br\s*/?>)*</p>#i', '', $out);
        return trim($out);
    }

    function blogPlainText(string $html): string
    {
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h[2-4]|/li)[^>]*>#i', ' ', $html)), ENT_QUOTES, 'UTF-8')));
    }

    function blogWordCount(string $html): int
    {
        $t = blogPlainText($html);
        return $t === '' ? 0 : count(preg_split('/\s+/u', $t));
    }

    function blogReadingMinutes(string $html): int
    {
        return max(1, (int)ceil(blogWordCount($html) / 220));
    }

    /** Headings get ids (for the table of contents). Returns [html, [[id, text, level], …]]. */
    function blogToc(string $html): array
    {
        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<h([23])([^>]*)>(.*?)</h\1>#is', function ($m) use (&$toc, &$used) {
            $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            if ($text === '') return $m[0];
            $id = blogSlug($text);
            $base = $id; $n = 2;
            while (isset($used[$id])) $id = $base . '-' . $n++;
            $used[$id] = true;
            $toc[] = [$id, $text, (int)$m[1]];
            $attrs = preg_replace('/\sid="[^"]*"/i', '', $m[2]);
            return '<h' . $m[1] . ' id="' . $id . '"' . $attrs . '>' . $m[3] . '</h' . $m[1] . '>';
        }, $html);
        return [$html, $toc];
    }

    /**
     * The SEO checklist (the editor runs the same rules live). Returns ['score' => 0-100, 'checks' => [[ok, text], …]].
     */
    function blogSeoReport(array $p): array
    {
        $kw = mb_strtolower(trim((string)($p['focus_keyword'] ?? '')));
        $title = (string)($p['meta_title'] ?? '') ?: (string)($p['title'] ?? '');
        $desc = (string)($p['meta_description'] ?? '') ?: (string)($p['excerpt'] ?? '');
        $html = (string)($p['content'] ?? '');
        $text = mb_strtolower(blogPlainText($html));
        $words = blogWordCount($html);
        $first = mb_strtolower(mb_substr(blogPlainText(preg_match('#<p[^>]*>(.*?)</p>#is', $html, $m) ? $m[1] : $html), 0, 400));
        $slug = (string)($p['slug'] ?? '');
        $kwCount = $kw !== '' ? substr_count($text, $kw) : 0;
        $density = $words ? $kwCount * max(1, count(explode(' ', $kw))) / $words * 100 : 0;
        $checks = [
            [$kw !== '', 'A focus keyword is set'],
            [$kw !== '' && mb_stripos($title, $kw) !== false, 'The focus keyword is in the SEO title'],
            [$kw !== '' && mb_stripos($desc, $kw) !== false, 'The focus keyword is in the meta description'],
            [$kw !== '' && strpos($slug, blogSlug($kw)) !== false, 'The focus keyword is in the URL'],
            [$kw !== '' && mb_strpos($first, $kw) !== false, 'The focus keyword is in the first paragraph'],
            [$kw !== '' && preg_match('#<h[23][^>]*>[^<]*' . preg_quote($kw, '#') . '#iu', $html), 'The focus keyword is in a subheading'],
            [$kw !== '' && $density >= 0.5 && $density <= 2.5, 'Keyword used naturally (0.5–2.5% of the words)' . ($kw !== '' ? ', now ' . round($density, 1) . '%' : '')],
            [mb_strlen($title) >= 30 && mb_strlen($title) <= 60, 'SEO title is 30–60 characters (now ' . mb_strlen($title) . ')'],
            [mb_strlen($desc) >= 120 && mb_strlen($desc) <= 160, 'Meta description is 120–160 characters (now ' . mb_strlen($desc) . ')'],
            [$words >= 600, 'At least 600 words (now ' . $words . ')'],
            [preg_match_all('#<h[23][^>]*>#i', $html) >= 2, 'At least 2 subheadings'],
            [!empty($p['cover_image']), 'A cover image is set'],
            [!preg_match('#<img(?![^>]*\balt="[^"]+")[^>]*>#i', $html) && (empty($p['cover_image']) || trim((string)($p['cover_alt'] ?? '')) !== ''), 'Every image has alt text'],
            [preg_match('#<a[^>]+href="(/|https?://(www\.)?restrogrow\.com)#i', $html) === 1, 'Links to another RestroGrow page'],
            [preg_match('#<a[^>]+href="https?://(?!(www\.)?restrogrow\.com)#i', $html) === 1, 'Links to a useful outside source'],
        ];
        $ok = count(array_filter($checks, fn($c) => $c[0]));
        return ['score' => (int)round($ok / count($checks) * 100), 'checks' => array_map(fn($c) => [(bool)$c[0], $c[1]], $checks), 'words' => $words];
    }

    /** Published posts for the public blog. $o: category (slug), q (search), limit, offset, tag, exclude, featured */
    function blogPublished(PDO $conn, array $o = []): array
    {
        $w = ["p.status = 'published'", 'p.published_at <= NOW()'];
        $par = [];
        if (!empty($o['category'])) { $w[] = 'c.slug = ?'; $par[] = $o['category']; }
        if (!empty($o['tag'])) { $w[] = 'FIND_IN_SET(?, REPLACE(p.tags, \', \', \',\'))'; $par[] = $o['tag']; }
        if (!empty($o['q'])) { $w[] = '(p.title LIKE ? OR p.excerpt LIKE ? OR p.tags LIKE ? OR p.focus_keyword LIKE ?)'; for ($i = 0; $i < 4; $i++) $par[] = '%' . $o['q'] . '%'; }
        if (!empty($o['exclude'])) { $w[] = 'p.id <> ?'; $par[] = (int)$o['exclude']; }
        if (isset($o['featured'])) { $w[] = 'p.featured = ?'; $par[] = (int)$o['featured']; }
        $sql = 'FROM blog_posts p LEFT JOIN blog_categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $w);
        $cnt = $conn->prepare('SELECT COUNT(*) ' . $sql);
        $cnt->execute($par);
        $limit = max(1, min(50, (int)($o['limit'] ?? 12)));
        $offset = max(0, (int)($o['offset'] ?? 0));
        $st = $conn->prepare('SELECT p.id, p.slug, p.title, p.excerpt, p.cover_image, p.cover_alt, p.tags, p.published_at, p.updated_at, p.reading_minutes, p.author_name, p.featured, p.views,
                c.name AS category, c.slug AS category_slug ' . $sql . ' ORDER BY ' . (!empty($o['order']) && $o['order'] === 'views' ? 'p.views DESC' : 'p.published_at DESC') . " LIMIT $limit OFFSET $offset");
        $st->execute($par);
        return ['total' => (int)$cnt->fetchColumn(), 'posts' => $st->fetchAll(PDO::FETCH_ASSOC)];
    }

    function blogCategories(PDO $conn, bool $withCounts = false): array
    {
        if (!$withCounts) return $conn->query('SELECT * FROM blog_categories ORDER BY sort, name')->fetchAll(PDO::FETCH_ASSOC);
        return $conn->query("SELECT c.*, (SELECT COUNT(*) FROM blog_posts p WHERE p.category_id = c.id AND p.status = 'published' AND p.published_at <= NOW()) AS posts
            FROM blog_categories c ORDER BY c.sort, c.name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Live keywords and which posts aim at them (focus keyword or keyword list). */
    function blogKeywordCoverage(PDO $conn): array
    {
        $kws = $conn->query('SELECT * FROM blog_keywords ORDER BY priority, keyword')->fetchAll(PDO::FETCH_ASSOC);
        $posts = $conn->query('SELECT id, title, slug, status, published_at, focus_keyword, keywords, views FROM blog_posts')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($kws as &$k) {
            $kl = mb_strtolower($k['keyword']);
            $k['posts'] = array_values(array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title'], 'status' => $p['status'], 'focus' => mb_strtolower((string)$p['focus_keyword']) === $kl],
                array_filter($posts, function ($p) use ($kl) {
                    if (mb_strtolower((string)$p['focus_keyword']) === $kl) return true;
                    return in_array($kl, array_map('trim', explode(',', mb_strtolower((string)$p['keywords']))), true);
                })));
        }
        unset($k);
        return $kws;
    }

    /** Ask Gemini for JSON with a bigger answer than aiGeminiJson allows. */
    function blogAiJson(string $system, string $user, array $schema, int $maxTokens = 2000): ?array
    {
        require_once __DIR__ . '/ai_gemini.php';
        if (!aiGeminiAvailable()) return null;
        $r = aiGeminiCall([
            'systemInstruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => mb_substr($user, 0, 12000)]]]],
            'generationConfig' => ['temperature' => 0.5, 'maxOutputTokens' => $maxTokens, 'responseMimeType' => 'application/json',
                'responseSchema' => $schema, 'thinkingConfig' => ['thinkingBudget' => 0]],
        ], 60);
        if (!$r || $r['text'] === '') return null;
        $j = json_decode(preg_replace('/^```(?:json)?|```$/m', '', $r['text']), true);
        return is_array($j) ? $j : null;
    }

    define('BLOG_AI_BRAND', 'RestroGrow is Indian restaurant software: POS billing, KOT, QR table ordering, the restaurant\'s own ordering website and app (no commission), WhatsApp ordering and marketing, loyalty, delivery riders, inventory, reports and an AI manager. Readers are Indian restaurant, cafe and cloud-kitchen owners. Write in clear, simple Indian English, practical, no hype, no made-up statistics or fake quotes.');

    /** SEO fields for a post: meta title, description, slug, focus keyword, keywords, excerpt, tags. */
    function blogAiSeo(array $p): ?array
    {
        $schema = ['type' => 'OBJECT', 'properties' => [
            'meta_title' => ['type' => 'STRING'], 'meta_description' => ['type' => 'STRING'], 'slug' => ['type' => 'STRING'],
            'focus_keyword' => ['type' => 'STRING'], 'keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
            'excerpt' => ['type' => 'STRING'], 'tags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ], 'required' => ['meta_title', 'meta_description', 'slug', 'focus_keyword', 'keywords', 'excerpt', 'tags']];
        $text = mb_substr(blogPlainText((string)($p['content'] ?? '')), 0, 6000);
        return blogAiJson(BLOG_AI_BRAND . ' You are an SEO editor for the RestroGrow blog (Google India). Rules: meta_title 45-60 characters with the focus keyword near the start; meta_description 130-155 characters, includes the focus keyword and a reason to click; slug short, lowercase, hyphens, with the keyword; focus_keyword = the one search phrase this post can rank for (2-5 words, what people in India type); keywords = 6-10 related phrases people search; excerpt = 1-2 sentences for the blog list (max 200 characters); tags = 3-6 short topics.',
            "Title: " . ($p['title'] ?? '') . "\nCurrent focus keyword: " . ($p['focus_keyword'] ?? '') . "\nArticle:\n" . $text, $schema, 1200);
    }

    /** A first draft: title, outline and HTML body (h2/h3/p/ul) for a topic and keyword. */
    function blogAiDraft(string $topic, string $keyword, string $notes = ''): ?array
    {
        $schema = ['type' => 'OBJECT', 'properties' => [
            'title' => ['type' => 'STRING'], 'html' => ['type' => 'STRING'], 'excerpt' => ['type' => 'STRING'],
            'meta_title' => ['type' => 'STRING'], 'meta_description' => ['type' => 'STRING'], 'keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
        ], 'required' => ['title', 'html', 'excerpt', 'meta_title', 'meta_description', 'keywords']];
        return blogAiJson(BLOG_AI_BRAND . ' Write a blog post draft for the RestroGrow blog. Return html using only <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <blockquote> (no <h1>, no inline styles). Start with the intro paragraph: do not repeat the title as a heading. 900-1300 words, an intro that answers the reader\'s question fast, 4-7 sections with h2 headings (put the focus keyword naturally in the intro and one h2), practical steps and examples from Indian restaurants, a short FAQ section (h2 "FAQ" with h3 questions), and a closing paragraph that mentions how RestroGrow helps (one or two sentences, not salesy). meta_title 45-60 characters, meta_description 130-155 characters.',
            "Topic: $topic\nFocus keyword: $keyword\nNotes from the editor: $notes", $schema, 6000);
    }

    /** Keyword ideas for a seed topic. */
    function blogAiKeywords(string $seed): ?array
    {
        $schema = ['type' => 'OBJECT', 'properties' => ['keywords' => ['type' => 'ARRAY', 'items' => ['type' => 'OBJECT', 'properties' => [
            'keyword' => ['type' => 'STRING'], 'intent' => ['type' => 'STRING', 'enum' => ['info', 'commercial', 'local', 'brand']],
            'priority' => ['type' => 'INTEGER'], 'notes' => ['type' => 'STRING'],
        ], 'required' => ['keyword', 'intent', 'priority', 'notes']]]], 'required' => ['keywords']];
        return blogAiJson(BLOG_AI_BRAND . ' Suggest 12 search keywords (what Indian restaurant owners actually type into Google) for blog posts around the seed topic. intent: info (how-to), commercial (comparing software, buying), local (city based), brand. priority 1 = best chance to bring RestroGrow customers, 3 = nice to have. notes = the angle for a post (one line). No made-up search volumes.',
            "Seed topic: $seed", $schema, 1500);
    }

    /**
     * Ready-written posts from the keyword plan (docs/BLOG_KEYWORD_PLAN.md), kept in config/blog_starters/*.html:
     * a "<!-- key: value -->" header (title, slug, focus_keyword, keywords, excerpt, meta_title, meta_description,
     * tags, category = category slug) and the post HTML. Super Admin › Blog imports them as drafts.
     */
    function blogStarters(): array
    {
        $out = [];
        foreach (glob(__DIR__ . '/blog_starters/*.html') ?: [] as $file) {
            $raw = (string)file_get_contents($file);
            if (!preg_match('/^\s*<!--(.*?)-->(.*)$/s', $raw, $m)) continue;
            $p = [];
            foreach (preg_split('/\r?\n/', $m[1]) as $line) {
                if (preg_match('/^\s*([a-z_]+):\s*(.*)$/', $line, $kv)) $p[$kv[1]] = trim($kv[2]);
            }
            if (empty($p['title']) || empty($p['slug'])) continue;
            $p['content'] = trim($m[2]);
            $out[] = $p;
        }
        return $out;
    }

    /** Starter posts never added before (not on the blog, and not added once then deleted or renamed). */
    function blogStartersMissing(PDO $conn): array
    {
        $have = array_flip(array_merge($conn->query('SELECT slug FROM blog_posts')->fetchAll(PDO::FETCH_COLUMN),
            $conn->query('SELECT slug FROM blog_starter_imports')->fetchAll(PDO::FETCH_COLUMN)));
        return array_values(array_filter(blogStarters(), fn($p) => !isset($have[$p['slug']])));
    }
}
