<?php
/**
 * Super Admin › Blog: write and manage the restrogrow.com/blog posts, the keyword plan and categories.
 * Data: blog_api.php. Engine: config/blog_engine.php. Public pages: /blog (blog/index.php, blog/post.php).
 * The editor is Quill (cdnjs); the SEO checklist below runs the same rules as blogSeoReport().
 */
require_once __DIR__ . '/auth.php';
require_superadmin();
$me = (string)($_SESSION['superadmin_username'] ?? 'RestroGrow team');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Blog · Super Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.snow.min.css">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:opsz,wght,FILL@20..48,500,0..1&display=block" rel="stylesheet">
<style>
  :root { --ink:#111827; --soft:#374151; --muted:#6b7280; --line:#e5e7eb; --bg:#f3f4f6; --card:#fff; --accent:#c1440e; --accent-2:#fbe6da; --ok:#16a34a; --ok-bg:#dcfce7; --warn:#b45309; --warn-bg:#fef3c7; --bad:#dc2626; --bad-bg:#fee2e2; --blue:#2563eb; --blue-bg:#dbeafe; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: var(--bg); color: var(--ink); }
  .ms { font-family: 'Material Symbols Rounded'; font-size: 20px; line-height: 1; vertical-align: middle; font-weight: normal; }
  main { max-width: 1240px; margin: 0 auto; padding: 22px 16px 70px; }
  .back { color: var(--muted); text-decoration: none; font-size: 14px; }
  .top { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: flex-end; margin: 10px 0 16px; }
  h1 { margin: 0; font-size: 28px; } .sub { color: var(--muted); margin: 4px 0 0; font-size: 15px; }
  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 15px; border: 0; border-radius: 10px; background: var(--ink); color: #fff; font: inherit; font-weight: 700; font-size: 14px; cursor: pointer; text-decoration: none; white-space: nowrap; }
  .btn.acc { background: var(--accent); } .btn.light { background: #fff; color: var(--ink); border: 1.5px solid var(--line); } .btn.sm { padding: 6px 10px; font-size: 13px; border-radius: 8px; } .btn.danger { background: #fff; color: var(--bad); border: 1.5px solid #fecaca; }
  .btn:disabled { opacity: .5; cursor: wait; } .btn .ms { font-size: 18px; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-bottom: 14px; }
  .stat { background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 12px 14px; } .stat b { display: block; font-size: 22px; } .stat small { color: var(--muted); }
  .tabs { display: flex; gap: 4px; border-bottom: 1px solid var(--line); margin-bottom: 14px; overflow-x: auto; }
  .tabs button { border: 0; background: none; padding: 10px 14px; font: inherit; font-weight: 700; color: var(--muted); border-bottom: 3px solid transparent; cursor: pointer; white-space: nowrap; }
  .tabs button.on { color: var(--ink); border-color: var(--accent); }
  .card { background: var(--card); border: 1px solid var(--line); border-radius: 16px; padding: 16px; }
  .row { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  input[type=text], input[type=search], input[type=url], input[type=datetime-local], select, textarea { padding: 9px 11px; border: 1.5px solid var(--line); border-radius: 10px; font: inherit; font-size: 14px; background: #fff; color: var(--ink); min-width: 0; }
  textarea { resize: vertical; width: 100%; }
  input:focus, select:focus, textarea:focus { outline: 2px solid #fdba74; border-color: #fdba74; }
  .chips { display: flex; gap: 6px; flex-wrap: wrap; } .chip { border: 1.5px solid var(--line); background: #fff; border-radius: 999px; padding: 6px 12px; font: inherit; font-size: 13px; font-weight: 700; cursor: pointer; color: var(--soft); } .chip.on { background: var(--ink); color: #fff; border-color: var(--ink); }
  .pill { display: inline-block; font-size: 11.5px; font-weight: 700; padding: 2px 9px; border-radius: 999px; white-space: nowrap; }
  .p-published { background: var(--ok-bg); color: #166534; } .p-draft { background: #f3f4f6; color: var(--soft); } .p-scheduled { background: var(--blue-bg); color: #1e40af; }
  .score { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 50%; font-size: 12px; font-weight: 800; color: #fff; }
  .posts { display: grid; gap: 8px; margin-top: 12px; }
  .post { display: grid; grid-template-columns: 72px minmax(0, 1fr) auto; gap: 12px; align-items: center; background: var(--card); border: 1px solid var(--line); border-radius: 14px; padding: 10px; }
  .thumb { width: 72px; height: 52px; border-radius: 9px; background: #f3f4f6 center/cover no-repeat; display: grid; place-items: center; color: #9ca3af; }
  .post b { font-size: 15px; display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .post small { color: var(--muted); font-size: 12.5px; }
  .post .acts { display: flex; gap: 6px; align-items: center; }
  .empty { text-align: center; color: var(--muted); padding: 34px 10px; }
  /* editor */
  .ed { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 16px; align-items: start; }
  .ed > *, .side > * { min-width: 0; }
  .ql-editor p + p, .ql-editor ul, .ql-editor ol { margin-top: .7em; }
  .ed-title { width: 100%; font-size: 26px; font-weight: 800; border: 0; padding: 4px 0; background: transparent; }
  .ed-title:focus { outline: none; }
  .slug { font-size: 13px; color: var(--muted); display: flex; gap: 6px; align-items: center; flex-wrap: wrap; margin: 2px 0 12px; }
  .slug input { padding: 4px 8px; font-size: 13px; border-radius: 7px; width: 280px; }
  #editor { min-height: 460px; font-size: 16.5px; line-height: 1.7; background: #fff; }
  .ql-toolbar.ql-snow { border-radius: 12px 12px 0 0; border-color: var(--line); background: #fafafa; position: sticky; top: 0; z-index: 3; }
  .ql-container.ql-snow { border-radius: 0 0 12px 12px; border-color: var(--line); }
  .ql-editor { min-height: 460px; padding: 18px 22px; } .ql-editor h2 { font-size: 1.45em; margin-top: 1.2em; } .ql-editor h3 { font-size: 1.2em; } .ql-editor img { border-radius: 10px; }
  .meta-line { display: flex; justify-content: space-between; color: var(--muted); font-size: 13px; padding: 8px 2px; flex-wrap: wrap; gap: 6px; }
  .side { display: grid; gap: 12px; position: sticky; top: 10px; max-height: calc(100vh - 20px); overflow-y: auto; padding-right: 2px; overscroll-behavior: contain; }
  .panel h3 { margin: 0 0 10px; font-size: 14.5px; display: flex; align-items: center; gap: 6px; }
  .f { display: grid; gap: 5px; margin-bottom: 10px; } .f label { font-size: 12.5px; font-weight: 700; color: var(--soft); display: flex; justify-content: space-between; }
  .f label em { font-style: normal; font-weight: 600; color: var(--muted); } .f label em.bad { color: var(--bad); } .f label em.good { color: var(--ok); }
  .f input, .f select, .f textarea { width: 100%; }
  .cover { border: 2px dashed var(--line); border-radius: 12px; min-height: 120px; display: grid; place-items: center; text-align: center; color: var(--muted); font-size: 13px; cursor: pointer; background: #fafafa center/cover no-repeat; position: relative; }
  .cover.has { border-style: solid; min-height: 170px; } .cover.has span { display: none; }
  .gprev { border: 1px solid var(--line); border-radius: 12px; padding: 12px; font-family: arial, sans-serif; }
  .gprev .u { font-size: 12px; color: #202124; } .gprev .t { color: #1a0dab; font-size: 18px; line-height: 1.3; margin: 3px 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .gprev .d { color: #4d5156; font-size: 13.5px; line-height: 1.45; }
  .checks { list-style: none; margin: 8px 0 0; padding: 0; display: grid; gap: 5px; font-size: 13px; }
  .checks li { display: flex; gap: 6px; align-items: flex-start; } .checks .ms { font-size: 17px; } .checks .y { color: var(--ok); } .checks .n { color: #d1d5db; }
  .scorebar { height: 8px; border-radius: 99px; background: #f3f4f6; overflow: hidden; } .scorebar i { display: block; height: 100%; border-radius: 99px; }
  .tog { display: flex; align-items: center; gap: 8px; font-size: 13.5px; font-weight: 600; margin: 6px 0; cursor: pointer; } .tog input { width: 17px; height: 17px; accent-color: var(--accent); }
  .ai-btn { background: linear-gradient(135deg, #7c3aed, #c1440e); }
  /* keywords */
  .kw-table { width: 100%; border-collapse: collapse; font-size: 13.5px; } .kw-table th { text-align: left; color: var(--muted); font-size: 12px; padding: 8px 6px; border-bottom: 1px solid var(--line); } .kw-table td { padding: 9px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  .kw-table select { padding: 5px 7px; font-size: 13px; }
  .i-info { background: var(--blue-bg); color: #1e40af; } .i-commercial { background: var(--accent-2); color: #9a3412; } .i-local { background: var(--ok-bg); color: #166534; } .i-brand { background: #ede9fe; color: #5b21b6; }
  .scroll { overflow-x: auto; }
  .grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
  dialog { border: 0; border-radius: 16px; padding: 0; width: min(620px, 94vw); } dialog::backdrop { background: rgba(17,24,39,.55); }
  .dlg { padding: 18px; display: grid; gap: 10px; } .dlg h3 { margin: 0; }
  #toast { position: fixed; left: 50%; bottom: 22px; transform: translateX(-50%); background: var(--ink); color: #fff; padding: 11px 16px; border-radius: 12px; font-size: 14px; display: none; z-index: 50; max-width: 92vw; }
  .hide { display: none !important; }
  @media (max-width: 980px) { .ed { grid-template-columns: 1fr; } .side { position: static; } }
  @media (max-width: 600px) { .post { grid-template-columns: 1fr; } .thumb { display: none; } .post .acts { flex-wrap: wrap; } .slug input { width: 100%; } }
</style>
</head>
<body>
<main>
  <a class="back" href="dashboard.php">← Back to dashboard</a>

  <!-- ═══ list ═══ -->
  <div id="listView">
    <div class="top">
      <div><h1>Blog</h1><p class="sub">Write posts for restrogrow.com/blog, plan keywords, and see how each post does.</p></div>
      <div class="row"><a class="btn light" id="viewBlog" href="../../blog/" target="_blank" rel="noopener"><span class="ms">open_in_new</span>View blog</a><button class="btn acc" id="newPost" type="button"><span class="ms">edit_square</span>New post</button></div>
    </div>
    <div class="stats" id="stats"></div>
    <nav class="tabs"><button type="button" class="on" data-tab="posts">Posts</button><button type="button" data-tab="keywords">Keywords</button><button type="button" data-tab="categories">Categories</button></nav>

    <section id="tab-posts">
      <div class="row">
        <input type="search" id="q" placeholder="Search title, keyword or tag" style="flex:1;min-width:220px">
        <select id="catFilter"><option value="">All categories</option></select>
      </div>
      <div class="chips" style="margin-top:10px" id="statusChips">
        <button class="chip on" data-st="">All</button><button class="chip" data-st="published">Published</button><button class="chip" data-st="scheduled">Scheduled</button><button class="chip" data-st="draft">Drafts</button>
      </div>
      <div class="posts" id="posts"></div>
    </section>

    <section id="tab-keywords" class="hide">
      <div class="grid2">
        <div class="card">
          <h3 style="margin:0 0 10px">Add a keyword</h3>
          <div class="f"><input type="text" id="kwNew" placeholder="e.g. restaurant billing software"></div>
          <div class="row">
            <select id="kwIntent"><option value="info">How-to (info)</option><option value="commercial">Buying (commercial)</option><option value="local">Local (city)</option><option value="brand">Brand</option></select>
            <select id="kwPrio"><option value="1">Priority 1</option><option value="2" selected>Priority 2</option><option value="3">Priority 3</option></select>
            <input type="text" id="kwVol" placeholder="Monthly searches (optional)" style="flex:1">
          </div>
          <div class="f" style="margin-top:8px"><input type="text" id="kwNotes" placeholder="Angle for the post (optional)"></div>
          <button class="btn" type="button" id="kwAdd"><span class="ms">add</span>Add keyword</button>
          <details style="margin-top:12px"><summary style="cursor:pointer;font-weight:700;font-size:13.5px">Add many at once</summary>
            <textarea id="kwBulk" rows="5" placeholder="One keyword per line" style="margin-top:8px"></textarea>
            <button class="btn light sm" type="button" id="kwBulkAdd" style="margin-top:6px">Add all</button>
          </details>
        </div>
        <div class="card">
          <h3 style="margin:0 0 4px">Keyword ideas with AI</h3>
          <p class="sub" style="font-size:13px;margin:0 0 10px">Type a topic; AI suggests what restaurant owners in India search for. Tick the ones you want.</p>
          <div class="row"><input type="text" id="kwSeed" placeholder="e.g. restaurant marketing, cloud kitchen, POS" style="flex:1"><button class="btn ai-btn" type="button" id="kwIdeas"><span class="ms">auto_awesome</span>Suggest</button></div>
          <div id="kwIdeasList" style="margin-top:10px"></div>
        </div>
      </div>
      <div class="card" style="margin-top:12px">
        <div class="row" style="justify-content:space-between;margin-bottom:8px">
          <b>Keyword plan</b>
          <div class="chips" id="kwChips"><button class="chip on" data-ks="">All</button><button class="chip" data-ks="idea">Ideas</button><button class="chip" data-ks="planned">Planned</button><button class="chip" data-ks="written">Written</button><button class="chip" data-ks="gap">No post yet</button></div>
        </div>
        <div class="scroll"><table class="kw-table"><thead><tr><th>Keyword</th><th>Type</th><th>Priority</th><th>Status</th><th>Posts</th><th></th></tr></thead><tbody id="kwBody"></tbody></table></div>
      </div>
    </section>

    <section id="tab-categories" class="hide">
      <div class="grid2">
        <div class="card">
          <h3 style="margin:0 0 10px" id="catFormTitle">Add a category</h3>
          <input type="hidden" id="catId">
          <div class="f"><label>Name</label><input type="text" id="catName" placeholder="e.g. Cloud kitchens"></div>
          <div class="f"><label>Description <em>shown on the category page</em></label><textarea id="catDesc" rows="2"></textarea></div>
          <div class="row"><button class="btn" type="button" id="catSave">Save category</button><button class="btn light hide" type="button" id="catCancel">Cancel</button></div>
        </div>
        <div class="card"><b>Categories</b><div id="catList" style="margin-top:8px"></div></div>
      </div>
    </section>
  </div>

  <!-- ═══ editor ═══ -->
  <div id="edView" class="hide">
    <div class="top">
      <div class="row"><button class="btn light sm" type="button" id="edBack"><span class="ms">arrow_back</span>All posts</button><span class="pill p-draft" id="edState">New</span><span class="sub" id="edSaved" style="margin:0;font-size:13px"></span></div>
      <div class="row"><button class="btn ai-btn sm" type="button" id="aiDraftBtn"><span class="ms">auto_awesome</span>Write a draft with AI</button><a class="btn light sm hide" id="edPreview" target="_blank" rel="noopener"><span class="ms">visibility</span>Preview</a></div>
    </div>
    <div class="ed">
      <div>
        <input class="ed-title" id="title" type="text" placeholder="Post title" maxlength="200">
        <div class="slug"><span id="slugBase"></span><input type="text" id="slug" placeholder="post-url"><button class="btn light sm" type="button" id="slugFromTitle" title="Make the URL from the title">From title</button></div>
        <div id="editor"></div>
        <div class="meta-line"><span id="wc">0 words · 1 min read</span><span>Ctrl + S saves · images: paste, drag in, or use the image button</span></div>
        <div class="card" style="margin-top:8px">
          <div class="f" style="margin:0"><label>Excerpt <em id="exCount">shown on the blog list and in shares</em></label><textarea id="excerpt" rows="2" maxlength="400" placeholder="One or two sentences about the post"></textarea></div>
        </div>
      </div>
      <aside class="side">
        <div class="card panel">
          <h3><span class="ms">send</span>Publish</h3>
          <div class="f"><label>When</label>
            <select id="pubMode"><option value="draft">Keep as draft</option><option value="now">Publish now</option><option value="later">Schedule for a date</option></select></div>
          <div class="f hide" id="pubAtWrap"><label>Publish on</label><input type="datetime-local" id="pubAt"></div>
          <div class="f"><label>Author</label><input type="text" id="author" maxlength="100"></div>
          <label class="tog"><input type="checkbox" id="featured">Feature on the blog home</label>
          <label class="tog"><input type="checkbox" id="noindex">Hide from Google (noindex)</label>
          <div class="row" style="margin-top:8px"><button class="btn acc" type="button" id="saveBtn" style="flex:1;justify-content:center"><span class="ms">save</span>Save</button><button class="btn danger sm hide" type="button" id="delBtn" title="Delete post"><span class="ms">delete</span></button></div>
        </div>
        <div class="card panel">
          <h3><span class="ms">image</span>Cover image</h3>
          <div class="cover" id="cover"><span><span class="ms" style="font-size:30px">add_photo_alternate</span><br>Click to upload (1200 × 630 is best)</span></div>
          <input type="file" id="coverFile" accept="image/*" hidden>
          <div class="f" style="margin-top:8px"><label>Alt text <em>describe the image for Google</em></label><input type="text" id="coverAlt" maxlength="200"></div>
          <button class="btn light sm hide" type="button" id="coverRemove">Remove cover</button>
        </div>
        <div class="card panel">
          <h3><span class="ms">category</span>Category and tags</h3>
          <div class="f"><select id="category"><option value="">No category</option></select></div>
          <div class="f"><label>Tags <em>comma separated</em></label><input type="text" id="tags" placeholder="pos, billing, gst"></div>
        </div>
        <div class="card panel">
          <h3 style="justify-content:space-between"><span><span class="ms">search</span> SEO</span><button class="btn ai-btn sm" type="button" id="aiSeoBtn"><span class="ms">auto_awesome</span>Fill with AI</button></h3>
          <div class="f"><label>Focus keyword <em>the search you want to rank for</em></label><input type="text" id="focus" list="kwList" maxlength="120"><datalist id="kwList"></datalist></div>
          <div class="f"><label>SEO title <em id="mtCount"></em></label><input type="text" id="metaTitle" maxlength="160" placeholder="Leave empty to use the post title"></div>
          <div class="f"><label>Meta description <em id="mdCount"></em></label><textarea id="metaDesc" rows="3" maxlength="320" placeholder="Leave empty to use the excerpt"></textarea></div>
          <div class="f"><label>More keywords <em>comma separated</em></label><input type="text" id="keywords"></div>
          <div class="f"><label>Canonical URL <em>only if this post first appeared elsewhere</em></label><input type="url" id="canonical" placeholder="https://"></div>
          <div class="gprev" id="gprev"><div class="u" id="gpU"></div><div class="t" id="gpT"></div><div class="d" id="gpD"></div></div>
          <div style="margin-top:12px"><div class="row" style="justify-content:space-between;margin-bottom:6px"><b style="font-size:13.5px">SEO score</b><b id="scoreTxt">0 / 100</b></div><div class="scorebar"><i id="scoreBar"></i></div><ul class="checks" id="checks"></ul></div>
        </div>
      </aside>
    </div>
  </div>
</main>

<dialog id="aiDlg"><div class="dlg">
  <h3>Write a draft with AI</h3>
  <p class="sub" style="margin:0;font-size:13.5px">AI writes a first draft (about 1,000 words) with headings, an FAQ and the SEO fields. Read it, add your own experience and real examples, then publish.</p>
  <div class="f"><label>What is the post about?</label><input type="text" id="aiTopic" placeholder="e.g. How to get more repeat customers for a small restaurant"></div>
  <div class="f"><label>Focus keyword</label><input type="text" id="aiKeyword" list="kwList" placeholder="e.g. repeat customers restaurant"></div>
  <div class="f"><label>Notes for the AI <em>optional</em></label><textarea id="aiNotes" rows="3" placeholder="Points to cover, examples, tone…"></textarea></div>
  <p class="sub" id="aiWarn" style="margin:0;font-size:13px;color:var(--warn)"></p>
  <div class="row" style="justify-content:flex-end"><button class="btn light" type="button" onclick="aiDlg.close()">Cancel</button><button class="btn ai-btn" type="button" id="aiGo"><span class="ms">auto_awesome</span>Write the draft</button></div>
</div></dialog>
<div id="toast" role="status"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/quill/1.3.7/quill.min.js"></script>
<script>
(function () {
  'use strict';
  var API = 'blog_api.php', ME = <?php echo json_encode($me); ?>;
  var META = { categories: [], keywords: [], ai: false, site: '' }, POSTS = [], CUR = null, dirty = false, st = '', ks = '';
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function toast(t) { var e = $('toast'); e.textContent = t; e.style.display = 'block'; clearTimeout(toast.t); toast.t = setTimeout(function () { e.style.display = 'none'; }, 4000); }
  function get(q) { return fetch(API + '?' + q, { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }); }
  function post(b) { return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(function (r) { return r.json(); }); }
  function busy(btn, on) { if (btn) btn.disabled = on; }
  function fmt(d) { if (!d) return ''; var t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? d : t.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }); }
  function slugify(s) { return String(s || '').toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 150); }
  function scoreColor(s) { return s >= 80 ? '#16a34a' : s >= 50 ? '#d97706' : '#dc2626'; }

  // ── editor ──
  var quill = new Quill('#editor', {
    theme: 'snow', placeholder: 'Start writing… Use H2 / H3 for sections.',
    modules: { toolbar: { container: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline', 'strike'], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'code-block'], ['link', 'image', 'video'], [{ align: [] }], ['clean']],
      handlers: { image: function () { pickImage(function (url) { var r = quill.getSelection(true); quill.insertEmbed(r.index, 'image', url, 'user'); quill.setSelection(r.index + 1); }); } } } }
  });
  function pickImage(cb) {
    var i = document.createElement('input'); i.type = 'file'; i.accept = 'image/*';
    i.onchange = function () { if (i.files[0]) upload(i.files[0]).then(function (d) { if (d) cb(d.url, d.path); }); };
    i.click();
  }
  function upload(file) {
    var fd = new FormData(); fd.append('action', 'upload'); fd.append('file', file);
    toast('Uploading image…');
    return fetch(API, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); })
      .then(function (d) { if (!d.success) { toast(d.message || 'Upload failed'); return null; } toast('Image added'); return d; })
      .catch(function () { toast('Upload failed'); return null; });
  }
  // pasted / dropped images are uploaded instead of being stored inside the post
  quill.root.addEventListener('paste', function (e) {
    var f = Array.prototype.find.call((e.clipboardData || {}).files || [], function (x) { return /^image\//.test(x.type); });
    if (!f) return; e.preventDefault();
    upload(f).then(function (d) { if (d) { var r = quill.getSelection(true); quill.insertEmbed(r.index, 'image', d.url, 'user'); } });
  });
  quill.root.addEventListener('drop', function (e) {
    var f = Array.prototype.find.call((e.dataTransfer || {}).files || [], function (x) { return /^image\//.test(x.type); });
    if (!f) return; e.preventDefault();
    upload(f).then(function (d) { if (d) { var r = quill.getSelection(true) || { index: quill.getLength() }; quill.insertEmbed(r.index, 'image', d.url, 'user'); } });
  });
  quill.on('text-change', function () { dirty = true; live(); });

  function field(id) { return $(id).value; }
  function gather() {
    var mode = field('pubMode');
    return {
      action: 'save', id: CUR ? CUR.id : 0, title: field('title').trim(), slug: field('slug').trim(), content: quill.root.innerHTML,
      excerpt: field('excerpt'), cover_image: $('cover').dataset.path || '', cover_alt: field('coverAlt'), category_id: field('category'),
      tags: field('tags'), focus_keyword: field('focus'), keywords: field('keywords'), meta_title: field('metaTitle'), meta_description: field('metaDesc'),
      canonical_url: field('canonical'), status: mode === 'draft' ? 'draft' : 'published',
      published_at: mode === 'later' ? field('pubAt') : (mode === 'now' ? (CUR && CUR.status === 'published' && CUR.published_at && new Date(String(CUR.published_at).replace(' ', 'T')) <= new Date() ? CUR.published_at : '') : ''),
      author_name: field('author'), featured: $('featured').checked ? 1 : 0, noindex: $('noindex').checked ? 1 : 0
    };
  }

  // live SEO checklist (same rules as blogSeoReport on the server)
  function live() {
    var p = gather(), kw = p.focus_keyword.trim().toLowerCase(), title = p.meta_title || p.title, desc = p.meta_description || p.excerpt;
    var text = quill.getText().replace(/\s+/g, ' ').trim(), low = text.toLowerCase(), words = text ? text.split(' ').length : 0, html = p.content;
    var firstP = (quill.root.querySelector('p') || { textContent: '' }).textContent.toLowerCase();
    var count = kw ? low.split(kw).length - 1 : 0, density = words ? count * Math.max(1, kw.split(' ').length) / words * 100 : 0;
    var heads = quill.root.querySelectorAll('h2, h3'), kwHead = kw && Array.prototype.some.call(heads, function (h) { return h.textContent.toLowerCase().indexOf(kw) !== -1; });
    var imgs = quill.root.querySelectorAll('img'), altOk = Array.prototype.every.call(imgs, function (i) { return (i.getAttribute('alt') || '').trim(); }) && (!p.cover_image || p.cover_alt.trim());
    var links = Array.prototype.map.call(quill.root.querySelectorAll('a[href]'), function (a) { return a.getAttribute('href'); });
    var checks = [
      [!!kw, 'A focus keyword is set'],
      [kw && title.toLowerCase().indexOf(kw) !== -1, 'The focus keyword is in the SEO title'],
      [kw && desc.toLowerCase().indexOf(kw) !== -1, 'The focus keyword is in the meta description'],
      [kw && (p.slug || slugify(p.title)).indexOf(slugify(kw)) !== -1, 'The focus keyword is in the URL'],
      [kw && firstP.indexOf(kw) !== -1, 'The focus keyword is in the first paragraph'],
      [!!kwHead, 'The focus keyword is in a subheading'],
      [kw && density >= 0.5 && density <= 2.5, 'Keyword used naturally (0.5–2.5% of the words)' + (kw ? ', now ' + density.toFixed(1) + '%' : '')],
      [title.length >= 30 && title.length <= 60, 'SEO title is 30–60 characters (now ' + title.length + ')'],
      [desc.length >= 120 && desc.length <= 160, 'Meta description is 120–160 characters (now ' + desc.length + ')'],
      [words >= 600, 'At least 600 words (now ' + words + ')'],
      [heads.length >= 2, 'At least 2 subheadings'],
      [!!p.cover_image, 'A cover image is set'],
      [altOk, 'Every image has alt text'],
      [links.some(function (h) { return /^(\/|https?:\/\/(www\.)?restrogrow\.com)/i.test(h); }), 'Links to another RestroGrow page'],
      [links.some(function (h) { return /^https?:\/\//i.test(h) && !/restrogrow\.com/i.test(h); }), 'Links to a useful outside source']
    ];
    var ok = checks.filter(function (c) { return c[0]; }).length, score = Math.round(ok / checks.length * 100);
    $('scoreTxt').textContent = score + ' / 100'; $('scoreBar').style.width = score + '%'; $('scoreBar').style.background = scoreColor(score);
    $('checks').innerHTML = checks.map(function (c) { return '<li><span class="ms ' + (c[0] ? 'y' : 'n') + '">' + (c[0] ? 'check_circle' : 'radio_button_unchecked') + '</span>' + esc(c[1]) + '</li>'; }).join('');
    $('wc').textContent = words + ' words · ' + Math.max(1, Math.ceil(words / 220)) + ' min read';
    var mt = (p.meta_title || p.title).length, md = (p.meta_description || p.excerpt).length;
    $('mtCount').textContent = mt + ' / 60'; $('mtCount').className = mt > 60 || (mt && mt < 30) ? 'bad' : mt ? 'good' : '';
    $('mdCount').textContent = md + ' / 160'; $('mdCount').className = md > 160 || (md && md < 120) ? 'bad' : md ? 'good' : '';
    $('exCount').textContent = p.excerpt.length + ' / 400';
    $('gpU').textContent = META.site.replace(/^https?:\/\//, '') + ' › blog › ' + (p.slug || slugify(p.title) || 'post-url');
    $('gpT').textContent = (p.meta_title || p.title || 'Your post title') + (p.meta_title ? '' : ' | RestroGrow Blog');
    $('gpD').textContent = (p.meta_description || p.excerpt || text).slice(0, 160) || 'Your meta description shows here.';
  }
  ['title', 'slug', 'excerpt', 'coverAlt', 'category', 'tags', 'focus', 'keywords', 'metaTitle', 'metaDesc', 'canonical', 'author', 'pubAt'].forEach(function (id) {
    $(id).addEventListener('input', function () { dirty = true; live(); });
  });
  ['featured', 'noindex'].forEach(function (id) { $(id).addEventListener('change', function () { dirty = true; }); });
  $('pubMode').addEventListener('change', function () { dirty = true; $('pubAtWrap').classList.toggle('hide', this.value !== 'later'); updateSaveLabel(); });
  $('title').addEventListener('input', function () { if (!CUR || !CUR.slugTouched) $('slug').value = slugify(this.value); });
  $('slug').addEventListener('input', function () { if (CUR) CUR.slugTouched = true; else CUR = { id: 0, slugTouched: true }; });
  $('slugFromTitle').onclick = function () { $('slug').value = slugify(field('title')); dirty = true; live(); };
  function updateSaveLabel() { var m = field('pubMode'); $('saveBtn').innerHTML = '<span class="ms">' + (m === 'draft' ? 'save' : m === 'now' ? 'public' : 'schedule') + '</span>' + (m === 'draft' ? 'Save draft' : m === 'now' ? (CUR && CUR.status === 'published' ? 'Update' : 'Publish') : 'Schedule'); }

  // cover
  $('cover').onclick = function () { $('coverFile').click(); };
  $('coverFile').onchange = function () { if (this.files[0]) upload(this.files[0]).then(function (d) { if (d) setCover(d.path, d.url); }); this.value = ''; };
  $('coverRemove').onclick = function () { setCover('', ''); };
  function setCover(path, url) {
    var c = $('cover'); c.dataset.path = path || ''; c.style.backgroundImage = url ? 'url("' + url.replace(/"/g, '') + '")' : ''; c.classList.toggle('has', !!url);
    $('coverRemove').classList.toggle('hide', !url); dirty = true; live();
  }

  function openEditor(p, preset) {
    CUR = p ? Object.assign({}, p, { slugTouched: true }) : null;
    $('listView').classList.add('hide'); $('edView').classList.remove('hide'); window.scrollTo(0, 0);
    $('title').value = p ? p.title : ''; $('slug').value = p ? p.slug : '';
    quill.setContents([]); if (p && p.content) quill.clipboard.dangerouslyPasteHTML(p.content);
    $('excerpt').value = p ? (p.excerpt || '') : ''; $('coverAlt').value = p ? (p.cover_alt || '') : '';
    setCover(p ? p.cover_image : '', p ? p.cover_url : '');
    $('category').innerHTML = '<option value="">No category</option>' + META.categories.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
    $('category').value = p && p.category_id ? p.category_id : '';
    $('tags').value = p ? (p.tags || '') : ''; $('focus').value = p ? (p.focus_keyword || '') : (preset && preset.keyword || '');
    $('keywords').value = p ? (p.keywords || '') : ''; $('metaTitle').value = p ? (p.meta_title || '') : ''; $('metaDesc').value = p ? (p.meta_description || '') : '';
    $('canonical').value = p ? (p.canonical_url || '') : ''; $('author').value = p ? (p.author_name || ME) : ME;
    $('featured').checked = !!(p && +p.featured); $('noindex').checked = !!(p && +p.noindex);
    var state = !p ? 'new' : p.status === 'draft' ? 'draft' : (new Date(String(p.published_at).replace(' ', 'T')) > new Date() ? 'scheduled' : 'published');
    $('pubMode').value = state === 'scheduled' ? 'later' : state === 'published' ? 'now' : 'draft';
    $('pubAt').value = p && p.published_at ? String(p.published_at).replace(' ', 'T').slice(0, 16) : '';
    $('pubAtWrap').classList.toggle('hide', $('pubMode').value !== 'later');
    $('edState').textContent = { new: 'New post', draft: 'Draft', scheduled: 'Scheduled', published: 'Published' }[state];
    $('edState').className = 'pill p-' + (state === 'new' ? 'draft' : state);
    $('edSaved').textContent = p ? 'Last saved ' + fmt(p.updated_at) : '';
    $('delBtn').classList.toggle('hide', !p);
    var prev = $('edPreview'); prev.classList.toggle('hide', !p); if (p) prev.href = META.site + '/blog/' + encodeURIComponent(p.slug) + (state === 'published' ? '' : '?preview=1');
    $('slugBase').textContent = META.site.replace(/^https?:\/\//, '') + '/blog/';
    $('aiSeoBtn').classList.toggle('hide', !META.ai); $('aiDraftBtn').classList.toggle('hide', !META.ai);
    updateSaveLabel(); live(); dirty = false;
    if (!p && preset && preset.keyword && META.ai) { $('aiKeyword').value = preset.keyword; $('aiTopic').value = preset.notes || ''; }
    if (!p) $('title').focus();
  }
  function closeEditor() {
    if (dirty && !confirm('Leave without saving your changes?')) return;
    dirty = false; $('edView').classList.add('hide'); $('listView').classList.remove('hide'); loadPosts(); loadMeta();
  }
  $('edBack').onclick = closeEditor;
  $('newPost').onclick = function () { openEditor(null); };
  function save() {
    var p = gather();
    if (!p.title) { toast('Give the post a title first'); $('title').focus(); return; }
    if (field('pubMode') === 'later' && !field('pubAt')) { toast('Pick the date to publish on'); $('pubAt').focus(); return; }
    var b = $('saveBtn'); busy(b, true);
    post(p).then(function (d) {
      toast(d.message || (d.success ? 'Saved' : 'Could not save'));
      if (!d.success) return;
      dirty = false; var keep = window.scrollY; openEditor(d.post); window.scrollTo(0, keep);
    }).catch(function () { toast('No connection. Your text is still here; try again.'); }).finally(function () { busy(b, false); });
  }
  $('saveBtn').onclick = save;
  document.addEventListener('keydown', function (e) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && !$('edView').classList.contains('hide')) { e.preventDefault(); save(); } });
  window.addEventListener('beforeunload', function (e) { if (dirty && !$('edView').classList.contains('hide')) { e.preventDefault(); e.returnValue = ''; } });
  $('delBtn').onclick = function () {
    if (!CUR || !confirm('Delete "' + (CUR.title || 'this post') + '"? This cannot be undone.')) return;
    post({ action: 'delete', id: CUR.id }).then(function (d) { toast(d.message); dirty = false; closeEditor(); });
  };

  // AI
  $('aiSeoBtn').onclick = function () {
    var b = this; busy(b, true); toast('AI is reading the post…');
    post(Object.assign(gather(), { action: 'ai_seo' })).then(function (d) {
      if (!d.success) { toast(d.message); return; }
      var r = d.result;
      if (r.focus_keyword) $('focus').value = r.focus_keyword;
      if (r.meta_title) $('metaTitle').value = r.meta_title;
      if (r.meta_description) $('metaDesc').value = r.meta_description;
      if (r.slug && !(CUR && CUR.status === 'published')) $('slug').value = slugify(r.slug);
      if (r.keywords) $('keywords').value = r.keywords.join(', ');
      if (r.excerpt && !field('excerpt').trim()) $('excerpt').value = r.excerpt;
      if (r.tags && !field('tags').trim()) $('tags').value = r.tags.join(', ');
      dirty = true; live(); toast('SEO fields filled. Check them, then save.');
    }).finally(function () { busy(b, false); });
  };
  $('aiDraftBtn').onclick = function () {
    $('aiWarn').textContent = quill.getText().trim().length > 20 ? 'The editor already has text: the draft will replace it.' : '';
    if (!field('aiKeyword')) $('aiKeyword').value = field('focus');
    $('aiDlg').showModal();
  };
  $('aiGo').onclick = function () {
    var b = this; busy(b, true); b.innerHTML = '<span class="ms">hourglass_top</span>Writing… (about 30 seconds)';
    post({ action: 'ai_draft', topic: field('aiTopic'), keyword: field('aiKeyword'), notes: field('aiNotes') }).then(function (d) {
      if (!d.success) { toast(d.message); return; }
      var r = d.result;
      $('title').value = r.title || field('title'); if (!(CUR && CUR.status === 'published')) $('slug').value = slugify(r.title || '');
      quill.setContents([]); quill.clipboard.dangerouslyPasteHTML(r.html || '');
      $('excerpt').value = r.excerpt || ''; $('metaTitle').value = r.meta_title || ''; $('metaDesc').value = r.meta_description || '';
      $('focus').value = field('aiKeyword') || field('focus'); if (r.keywords) $('keywords').value = r.keywords.join(', ');
      $('aiDlg').close(); dirty = true; live(); toast('Draft ready. Read it, add your own examples, then save.');
    }).finally(function () { busy(b, false); b.innerHTML = '<span class="ms">auto_awesome</span>Write the draft'; });
  };

  // ── list ──
  function loadPosts() {
    var q = encodeURIComponent(field('q').trim());
    return get('action=list&q=' + q + '&status=' + st + '&category=' + field('catFilter')).then(function (d) {
      if (!d.success) { $('posts').innerHTML = '<div class="empty">' + esc(d.message) + '</div>'; return; }
      POSTS = d.posts; META.site = d.site;
      var c = d.counts;
      $('stats').innerHTML = [['Published', c.published, '#16a34a'], ['Scheduled', c.scheduled, '#2563eb'], ['Drafts', c.drafts, '#6b7280'], ['Total views', c.views, '#c1440e']]
        .map(function (s) { return '<div class="stat"><b style="color:' + s[2] + '">' + (s[1] || 0).toLocaleString('en-IN') + '</b><small>' + s[0] + '</small></div>'; }).join('');
      $('posts').innerHTML = POSTS.length ? POSTS.map(function (p) {
        var url = META.site + '/blog/' + encodeURIComponent(p.slug);
        return '<div class="post"><div class="thumb" style="' + (p.cover_url ? 'background-image:url(&quot;' + esc(p.cover_url) + '&quot;)' : '') + '">' + (p.cover_url ? '' : '<span class="ms">image</span>') + '</div>' +
          '<div style="min-width:0"><b>' + esc(p.title) + (+p.featured ? ' <span class="pill" style="background:#fef3c7;color:#92400e">Featured</span>' : '') + '</b>' +
          '<small><span class="pill p-' + p.state + '">' + p.state + '</span> ' + esc(p.category || 'No category') + ' · ' + (p.state === 'draft' ? 'edited ' + fmt(p.updated_at) : fmt(p.published_at)) +
          (p.focus_keyword ? ' · “' + esc(p.focus_keyword) + '”' : '') + ' · ' + (+p.views).toLocaleString('en-IN') + ' views · ' + p.reading_minutes + ' min</small></div>' +
          '<div class="acts"><span class="score" title="SEO score" style="background:' + scoreColor(+p.seo_score) + '">' + p.seo_score + '</span>' +
          '<button class="btn sm" data-edit="' + p.id + '">Edit</button>' +
          '<a class="btn light sm" href="' + url + (p.state === 'published' ? '' : '?preview=1') + '" target="_blank" rel="noopener" title="' + (p.state === 'published' ? 'View' : 'Preview') + '"><span class="ms">visibility</span></a>' +
          '<button class="btn light sm" data-dup="' + p.id + '" title="Make a copy"><span class="ms">content_copy</span></button></div></div>';
      }).join('') : '<div class="empty">' + (field('q') || st ? 'No posts match.' : 'No posts yet. Click <b>New post</b>, or plan keywords first in the Keywords tab.') + '</div>';
    });
  }
  function loadMeta() {
    return get('action=meta').then(function (d) {
      if (!d.success) return;
      META.categories = d.categories; META.keywords = d.keywords; META.ai = d.ai; META.site = d.site;
      $('viewBlog').href = META.site + '/blog/';
      var cv = field('catFilter');
      $('catFilter').innerHTML = '<option value="">All categories</option>' + META.categories.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + ' (' + c.posts + ')</option>'; }).join('');
      $('catFilter').value = cv;
      $('kwList').innerHTML = META.keywords.map(function (k) { return '<option value="' + esc(k.keyword) + '">'; }).join('');
      drawKeywords(); drawCategories();
      $('kwIdeas').classList.toggle('hide', !META.ai);
    });
  }
  var qt; $('q').addEventListener('input', function () { clearTimeout(qt); qt = setTimeout(loadPosts, 300); });
  $('catFilter').onchange = loadPosts;
  $('statusChips').onclick = function (e) { var b = e.target.closest('[data-st]'); if (!b) return; st = b.dataset.st; this.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === b); }); loadPosts(); };
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-edit],[data-dup],[data-tab]');
    if (!b) return;
    if (b.dataset.tab) {
      document.querySelectorAll('.tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
      ['posts', 'keywords', 'categories'].forEach(function (t) { $('tab-' + t).classList.toggle('hide', t !== b.dataset.tab); });
      try { localStorage.setItem('rgBlogTab', b.dataset.tab); } catch (x) {}
    } else if (b.dataset.edit) {
      get('action=post&id=' + b.dataset.edit).then(function (d) { if (d.success) openEditor(d.post); else toast(d.message); });
    } else if (b.dataset.dup) {
      post({ action: 'duplicate', id: +b.dataset.dup }).then(function (d) { toast(d.message); loadPosts(); });
    }
  });

  // ── keywords ──
  var INTENT = { info: 'How-to', commercial: 'Buying', local: 'Local', brand: 'Brand' };
  function drawKeywords() {
    var list = META.keywords.filter(function (k) { return !ks || (ks === 'gap' ? !k.posts.length : k.status === ks); });
    $('kwBody').innerHTML = list.length ? list.map(function (k) {
      return '<tr><td><b>' + esc(k.keyword) + '</b>' + (k.volume ? ' <small style="color:var(--muted)">· ' + esc(k.volume) + '/mo</small>' : '') + (k.notes ? '<br><small style="color:var(--muted)">' + esc(k.notes) + '</small>' : '') + '</td>' +
        '<td><span class="pill i-' + k.intent + '">' + INTENT[k.intent] + '</span></td><td>P' + k.priority + '</td>' +
        '<td><select data-kwst="' + k.id + '">' + ['idea', 'planned', 'written'].map(function (s) { return '<option' + (k.status === s ? ' selected' : '') + '>' + s + '</option>'; }).join('') + '</select></td>' +
        '<td>' + (k.posts.length ? k.posts.map(function (p) { return '<a href="#" data-edit="' + p.id + '" style="display:block;color:var(--accent)">' + esc(p.title) + (p.focus ? '' : ' <small>(also)</small>') + '</a>'; }).join('') : '<span style="color:var(--muted)">No post yet</span>') + '</td>' +
        '<td style="white-space:nowrap"><button class="btn sm" data-kwwrite="' + k.id + '">Write post</button> <button class="btn light sm" data-kwdel="' + k.id + '" title="Remove"><span class="ms">close</span></button></td></tr>';
    }).join('') : '<tr><td colspan="6" class="empty">' + (META.keywords.length ? 'Nothing here.' : 'No keywords yet. Add the searches you want to show up for, or ask AI for ideas.') + '</td></tr>';
  }
  $('kwChips').onclick = function (e) { var b = e.target.closest('[data-ks]'); if (!b) return; ks = b.dataset.ks; this.querySelectorAll('.chip').forEach(function (x) { x.classList.toggle('on', x === b); }); drawKeywords(); };
  $('kwAdd').onclick = function () {
    var b = this; busy(b, true);
    post({ action: 'keyword_save', keyword: field('kwNew'), intent: field('kwIntent'), priority: field('kwPrio'), volume: field('kwVol'), notes: field('kwNotes') })
      .then(function (d) { toast(d.message); if (d.success) { META.keywords = d.keywords; drawKeywords(); ['kwNew', 'kwVol', 'kwNotes'].forEach(function (i) { $(i).value = ''; }); loadMeta(); } }).finally(function () { busy(b, false); });
  };
  $('kwBulkAdd').onclick = function () {
    post({ action: 'keyword_bulk', text: field('kwBulk') }).then(function (d) { toast(d.message); if (d.success) { $('kwBulk').value = ''; META.keywords = d.keywords; drawKeywords(); loadMeta(); } });
  };
  $('kwIdeas').onclick = function () {
    var b = this; busy(b, true); $('kwIdeasList').innerHTML = '<p class="sub">Thinking…</p>';
    post({ action: 'ai_keywords', seed: field('kwSeed') }).then(function (d) {
      if (!d.success) { $('kwIdeasList').innerHTML = '<p class="sub">' + esc(d.message) + '</p>'; return; }
      var have = META.keywords.map(function (k) { return k.keyword; });
      var ideas = (d.result.keywords || []).filter(function (k) { return have.indexOf(String(k.keyword).toLowerCase()) === -1; });
      $('kwIdeasList').innerHTML = ideas.length ? ideas.map(function (k, i) {
        return '<label class="tog" style="align-items:flex-start"><input type="checkbox" data-idea="' + i + '" checked><span><b>' + esc(k.keyword) + '</b> <span class="pill i-' + esc(k.intent) + '">' + (INTENT[k.intent] || k.intent) + '</span> P' + k.priority + '<br><small style="color:var(--muted);font-weight:500">' + esc(k.notes) + '</small></span></label>';
      }).join('') + '<button class="btn sm" type="button" id="kwIdeasAdd" style="margin-top:6px">Add ticked keywords</button>' : '<p class="sub">All suggestions are already in your list.</p>';
      var add = $('kwIdeasAdd');
      if (add) add.onclick = function () {
        var items = ideas.filter(function (k, i) { return document.querySelector('[data-idea="' + i + '"]').checked; });
        post({ action: 'keyword_bulk', items: items }).then(function (r) { toast(r.message); if (r.success) { META.keywords = r.keywords; drawKeywords(); $('kwIdeasList').innerHTML = ''; loadMeta(); } });
      };
    }).finally(function () { busy(b, false); });
  };
  document.addEventListener('change', function (e) {
    var id = e.target.dataset && e.target.dataset.kwst;
    if (!id) return;
    var k = META.keywords.find(function (x) { return String(x.id) === id; });
    post(Object.assign({}, k, { action: 'keyword_save', status: e.target.value })).then(function (d) { toast(d.success ? 'Status saved' : d.message); if (d.success) { META.keywords = d.keywords; drawKeywords(); } });
  });
  document.addEventListener('click', function (e) {
    var w = e.target.closest('[data-kwwrite]'), del = e.target.closest('[data-kwdel]');
    if (w) {
      var k = META.keywords.find(function (x) { return String(x.id) === w.dataset.kwwrite; });
      openEditor(null, { keyword: k.keyword, notes: k.notes || '' });
      if (k.status === 'idea') post(Object.assign({}, k, { action: 'keyword_save', status: 'planned' }));
    }
    if (del && confirm('Remove this keyword from the plan?')) post({ action: 'keyword_delete', id: +del.dataset.kwdel }).then(function (d) { toast(d.message); if (d.success) { META.keywords = d.keywords; drawKeywords(); } });
  });

  // ── categories ──
  function drawCategories() {
    $('catList').innerHTML = META.categories.length ? META.categories.map(function (c) {
      return '<div class="row" style="justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6"><span><b>' + esc(c.name) + '</b> <small style="color:var(--muted)">/blog/category/' + esc(c.slug) + ' · ' + c.posts + ' post' + (+c.posts === 1 ? '' : 's') + '</small>' + (c.description ? '<br><small style="color:var(--muted)">' + esc(c.description) + '</small>' : '') + '</span>' +
        '<span class="row"><button class="btn light sm" data-catedit="' + c.id + '">Edit</button><button class="btn light sm" data-catdel="' + c.id + '"><span class="ms">delete</span></button></span></div>';
    }).join('') : '<p class="sub">No categories yet.</p>';
  }
  function catForm(c) { $('catId').value = c ? c.id : ''; $('catName').value = c ? c.name : ''; $('catDesc').value = c ? (c.description || '') : ''; $('catFormTitle').textContent = c ? 'Edit category' : 'Add a category'; $('catCancel').classList.toggle('hide', !c); }
  $('catSave').onclick = function () {
    post({ action: 'category_save', id: +field('catId') || 0, name: field('catName'), description: field('catDesc') }).then(function (d) { toast(d.message); if (d.success) { META.categories = d.categories; catForm(null); drawCategories(); loadMeta(); } });
  };
  $('catCancel').onclick = function () { catForm(null); };
  document.addEventListener('click', function (e) {
    var ed = e.target.closest('[data-catedit]'), del = e.target.closest('[data-catdel]');
    if (ed) catForm(META.categories.find(function (c) { return String(c.id) === ed.dataset.catedit; }));
    if (del && confirm('Delete this category? Its posts are kept, without a category.')) post({ action: 'category_delete', id: +del.dataset.catdel }).then(function (d) { toast(d.message); if (d.success) { META.categories = d.categories; drawCategories(); loadMeta(); } });
  });

  loadMeta().then(loadPosts);
  try { var t = localStorage.getItem('rgBlogTab'); if (t && t !== 'posts') document.querySelector('[data-tab="' + t + '"]').click(); } catch (x) {}
})();
</script>
</body>
</html>
