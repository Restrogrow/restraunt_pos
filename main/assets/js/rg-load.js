/* ─────────────────────────────────────────────────────────────────────────────
   Owner and staff dashboards: loading skeletons and "Try again", on every page and every new page,
   without any page doing anything (loaded in <head> next to rg_net.php's rgNetScript(false), which retries
   the data requests themselves).

   - A placeholder whose whole text is "Loading…" ('<div class="loading">Loading menu items...</div>',
     showLoading(), a table row "Loading...", "Loading riders…", the three dots of .sx-loading / .cbx-loading)
     is drawn as grey shimmering rows. Its text stays
     in the page (hidden), so code that looks for "Loading" still works; the page's own code replaces it when
     the data arrives, as before.
   - A short value set to "Loading..." (a stat number, the restaurant name) becomes a small grey bar.
   - Still loading after 20 s (and the device is online), or an "Error loading …" / "Could not load …"
     message without a button: a Try again button. It opens the page again (showPage reloads its data); if that
     doesn't bring the data, the dashboard reloads on the same page. When the connection comes back, a page
     that failed tries again by itself.
   Opt out for one element (and what's inside it) with data-no-skel.
   ───────────────────────────────────────────────────────────────────────────── */
(function () {
  if (window.rgLoad) return;

  var LOADING = /^(loading|refreshing)\b[\s\S]{0,60}$/i;
  var FAILED = /^(error loading|failed to load|could ?n[o’']?t load|unable to load|network error|connection error|something went wrong)\b/i;
  var HINT = /load|refresh|fail|error|could|unable|wrong/i;
  var SKIP = 'select, option, button, textarea, input, script, style, noscript, [contenteditable], [data-no-skel], .swal2-container';
  var STOP = '.page, .page-content, .section-body, .section-card, .modal-content, table, tbody, thead, tr, ul, ol, form, main, body';
  // spinners with no words (three bouncing dots and the like): drawn as a skeleton too
  var DOTS = '.sx-loading, .cbx-loading, .ar-loading, .an-loading, .loading-spinner-modern';
  var ICON = '.material-symbols-rounded, .material-icons, .material-symbols-outlined, svg, i.fa, i[class*="fa-"]';
  var GIVE_UP_MS = 20000;

  var css = '@keyframes rgShimmer{0%{background-position:100% 0}100%{background-position:0 0}}'
    + '.rg-skel-box:not(td):not(li){display:block!important}'
    + '.rg-skel-box{grid-column:1/-1;text-align:left!important;color:transparent!important;padding:10px 6px!important;min-height:0!important;border-color:transparent!important;background:none!important;box-shadow:none!important}'
    + '.rg-skel-sr{position:absolute!important;width:1px!important;height:1px!important;overflow:hidden!important;clip:rect(0 0 0 0)!important;white-space:nowrap!important}'
    + '.rg-sk-row{display:flex;align-items:center;gap:12px;padding:10px 0}'
    + '.rg-sk-row+.rg-sk-row{border-top:1px solid rgba(17,24,39,.06)}'
    + '.rg-sk-c{flex:none;width:40px;height:40px;border-radius:12px}'
    + '.rg-sk-l{flex:1;min-width:0;display:grid;gap:8px}'
    + '.rg-sk-b{display:block;height:11px;border-radius:6px}'
    + '.rg-sk-c,.rg-sk-b,.rg-skel-inline{background-image:linear-gradient(90deg,#eceef2 25%,#f7f8fa 37%,#eceef2 63%)!important;background-size:400% 100%!important;animation:rgShimmer 1.3s ease infinite}'
    + '.rg-skel-inline{color:transparent!important;border-radius:6px;min-width:3.5em;-webkit-text-fill-color:transparent}'
    + '.rg-fail{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;padding:28px 16px;color:#6b7280;font-size:14px;line-height:1.5}'
    + '.rg-fail b{color:#1f2937;font-size:15px}'
    + '.rg-retry{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:8px 18px;border:0;border-radius:10px;background:#111827;color:#fff;font:600 13.5px/1.2 inherit;cursor:pointer}'
    + '.rg-retry:hover{background:#000}'
    + '.rg-retry.rg-retry-sm{margin:6px 0 0 8px;padding:5px 12px;font-size:12.5px;vertical-align:middle}'
    + '@media (prefers-reduced-motion:reduce){.rg-sk-c,.rg-sk-b,.rg-skel-inline{animation:none}}';

  function addStyle() {
    if (document.getElementById('rgLoadCss')) return;
    var st = document.createElement('style');
    st.id = 'rgLoadCss';
    st.textContent = css;
    (document.head || document.documentElement).appendChild(st);
  }

  // an element's words, without icon-font ligatures ("history", "refresh") and our own pieces
  function textOf(el) {
    var out = '', w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT, null);
    for (var n = w.nextNode(); n; n = w.nextNode()) {
      var p = n.parentElement;
      if (p && p.closest(ICON) && el.contains(p.closest(ICON))) continue;
      out += n.nodeValue;
    }
    return out.replace(/\s+/g, ' ').trim();
  }
  function onlyIcons(el) {
    for (var i = 0; i < el.children.length; i++) if (!el.children[i].matches(ICON)) return false;
    return true;
  }

  function rows(el, n) {
    var withPic = el.tagName !== 'TD', h = '';
    for (var i = 0; i < n; i++) {
      h += '<span class="rg-sk-row" aria-hidden="true">' + (withPic ? '<span class="rg-sk-c"></span>' : '')
        + '<span class="rg-sk-l"><span class="rg-sk-b" style="width:' + [62, 48, 70][i % 3] + '%"></span>'
        + '<span class="rg-sk-b" style="width:' + [38, 30, 44][i % 3] + '%"></span></span></span>';
    }
    return h;
  }

  var boxes = new Set(), inlines = new Set();

  // the placeholder the page put in: climb from the text's element while the parent says the same and isn't a
  // container the page's code fills (it has an id, or it's a list / table / section)
  function placeholder(start, text) {
    var el = start;
    while (el.parentElement && !el.id && !el.parentElement.matches(STOP) && !el.parentElement.id
      && textOf(el.parentElement) === text) el = el.parentElement;
    if (el.matches(STOP) && el !== start) el = start;
    return el;
  }

  function skeleton(el, text) {
    if (el.hasAttribute('data-rg-skel') || el.matches(STOP)) return;
    var r = el.getBoundingClientRect(), cs = getComputedStyle(el);
    var small = onlyIcons(el) && (/^inline/.test(cs.display) || (r.width > 0 && r.width < 220 && r.height < 44));
    if (small || (/^(SPAN|B|STRONG)$/.test(el.tagName) && onlyIcons(el))) {
      el.setAttribute('data-rg-skel', 'inline');
      el.classList.add('rg-skel-inline');
      inlines.add(el);
      return;
    }
    // a word-less spinner: drop its own class, or its CSS (".sx-loading span") would draw our rows as dots
    if (el.matches(DOTS)) el.className = '';
    el.setAttribute('data-rg-skel', String(Date.now()));
    el.classList.add('rg-skel-box');
    el.setAttribute('aria-busy', 'true');
    var sr = document.createElement('span');
    sr.className = 'rg-skel-sr';
    sr.textContent = text;
    el.innerHTML = '';
    el.appendChild(sr);
    el.insertAdjacentHTML('beforeend', rows(el, el.tagName === 'TD' ? 3 : (r.height > 260 ? 4 : 3)));
    boxes.add(el);
  }

  function retry(el) {
    var page = el && el.closest('.page[id]');
    var id = page ? page.id : '';
    if (id && typeof window.showPage === 'function') {
      try { window.showPage(id); } catch (e) {}
      // showPage didn't bring the data back (the box is still there): load the dashboard again on this page
      setTimeout(function () {
        if (el.isConnected && (el.querySelector('.rg-fail') || el.classList.contains('rg-fail'))) reload(id);
      }, 4000);
    } else reload(id);
  }
  function reload(id) {
    var u = new URL(location.href);
    if (id && /^[a-zA-Z]+Page$/.test(id)) u.searchParams.set('page', id);
    location.href = u.toString();
  }

  function failBox(el) {
    boxes.delete(el);
    el.classList.remove('rg-skel-box');
    el.removeAttribute('aria-busy');
    el.setAttribute('data-rg-skel', 'failed');
    var what = (el.querySelector('.rg-skel-sr') || {}).textContent || '';
    what = what.replace(/^(loading|refreshing)\s*/i, '').replace(/[.…]+$/, '').trim();
    el.innerHTML = '<div class="rg-fail" role="alert"><b>Couldn’t load ' + (what ? escapeHtml(what) : 'this') + '</b>'
      + 'Check your internet connection, then try again.'
      + '<button type="button" class="rg-retry">Try again</button></div>';
    el.querySelector('.rg-retry').onclick = function () { retry(el); };
  }

  // the whole error message ("Network error" + "Please try again."), not only its title
  function errorBox(el) {
    while (el.parentElement && !el.id && !el.parentElement.id && !el.parentElement.matches(STOP)
      && textOf(el.parentElement).length < 220 && !el.parentElement.querySelector('button, a[href], .btn, input, select')) el = el.parentElement;
    return el;
  }

  function addRetry(el) {
    if (el.hasAttribute('data-rg-retry') || el.querySelector('button, a[href], .btn')) return;
    var host = el.closest('.page[id]');
    if (!host && typeof window.showPage === 'function') return;   // not in a dashboard section
    el.setAttribute('data-rg-retry', '1');
    var b = document.createElement('button');
    b.type = 'button';
    var block = el.getBoundingClientRect().height > 60 || /flex|grid/.test(getComputedStyle(el).display);
    b.className = block ? 'rg-retry' : 'rg-retry rg-retry-sm';
    b.textContent = 'Try again';
    b.onclick = function (e) { e.stopPropagation(); retry(el); };
    el.appendChild(b);
  }

  function escapeHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function look(root) {
    if (!root || root.nodeType !== 1 && root.nodeType !== 3) return;
    var start = root.nodeType === 3 ? root.parentElement : root;
    if (!start || start.closest(SKIP) || start.closest('.rg-fail, .rg-skel-sr')) return;
    var w = document.createTreeWalker(start, NodeFilter.SHOW_TEXT, null), seen = [];
    for (var n = w.nextNode(); n; n = w.nextNode()) {
      if (!HINT.test(n.nodeValue)) continue;
      var p = n.parentElement;
      if (!p || p.closest(SKIP) || p.closest('.rg-fail, .rg-skel-sr, [data-rg-skel]') || p.closest(ICON)) continue;
      if (seen.indexOf(p) > -1) continue;
      seen.push(p);
    }
    var dots = start.matches(DOTS) ? [start] : [].slice.call(start.querySelectorAll(DOTS));
    dots.forEach(function (d) {
      if (!d.hasAttribute('data-rg-skel') && !d.closest(SKIP) && !textOf(d) && d.getBoundingClientRect().width > 120) skeleton(d, 'Loading');
    });
    seen.forEach(function (p) {
      var t = textOf(p);
      if (LOADING.test(t)) skeleton(placeholder(p, t), t);
      else if (FAILED.test(t) && t.length < 160) addRetry(errorBox(p));
    });
  }

  // the page's code put its data in: drop our pieces
  function tidy() {
    inlines.forEach(function (el) {
      if (!el.isConnected || !LOADING.test(textOf(el))) {
        el.classList.remove('rg-skel-inline'); el.removeAttribute('data-rg-skel'); inlines.delete(el);
      }
    });
    boxes.forEach(function (el) {
      if (!el.isConnected) { boxes.delete(el); return; }
      if (!el.querySelector('.rg-skel-sr')) {
        el.classList.remove('rg-skel-box'); el.removeAttribute('data-rg-skel'); el.removeAttribute('aria-busy'); boxes.delete(el);
        return;
      }
      // something else was added next to the skeleton: the data is here
      for (var i = 0; i < el.childNodes.length; i++) {
        var c = el.childNodes[i];
        if (c.nodeType === 1 && (c.classList.contains('rg-skel-sr') || c.classList.contains('rg-sk-row'))) continue;
        if (c.nodeType === 3 && !c.nodeValue.trim()) continue;
        el.querySelectorAll('.rg-skel-sr, .rg-sk-row').forEach(function (x) { x.remove(); });
        el.classList.remove('rg-skel-box'); el.removeAttribute('data-rg-skel'); el.removeAttribute('aria-busy'); boxes.delete(el);
        return;
      }
    });
  }

  var queue = [], planned = false;
  function plan() {
    if (planned) return;
    planned = true;
    (window.requestAnimationFrame || setTimeout)(function () {
      planned = false;
      var q = queue; queue = [];
      tidy();
      q.forEach(look);
    });
  }

  function start() {
    addStyle();
    look(document.body);
    new MutationObserver(function (list) {
      list.forEach(function (m) {
        if (m.type === 'characterData') queue.push(m.target);
        else for (var i = 0; i < m.addedNodes.length; i++) queue.push(m.addedNodes[i]);
      });
      plan();
    }).observe(document.body, { childList: true, subtree: true, characterData: true });

    // still loading after 20 s on screen while online: Try again. Only time on screen counts: a placeholder in a
    // closed section (the POS before it's opened) starts its 20 s when it shows.
    setInterval(function () {
      var now = Date.now(), off = navigator.onLine === false;
      boxes.forEach(function (el) {
        var t = +el.getAttribute('data-rg-skel');
        if (!el.isConnected || !t) return;
        if (off || !el.getClientRects().length) { el.setAttribute('data-rg-skel', String(now)); return; }
        if (now - t > GIVE_UP_MS) failBox(el);
      });
    }, 2000);

    // connection back: the open page tries again by itself if something on it failed
    window.addEventListener('online', function () {
      var page = document.querySelector('.page.active[id]') || document.body;
      var bad = page.querySelector('[data-rg-skel="failed"], [data-rg-retry]');
      if (bad) setTimeout(function () { retry(bad); }, 800);
    });
  }

  window.rgLoad = { look: look, retry: retry };
  if (document.body) start();
  else document.addEventListener('DOMContentLoaded', start);
})();
