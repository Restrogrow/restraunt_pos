/**
 * Restaurant dashboard: announcements from RestroGrow (bar at the top) and Help & support
 * (open a request, read replies). Data: api/platform.php. Styles below (pn-*), self-contained.
 */
(function () {
  'use strict';
  var API = '../api/platform.php';
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var when = function (d) { if (!d) return ''; var t = new Date(String(d).replace(' ', 'T')); return isNaN(t) ? d : t.toLocaleString([], { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }); };
  function api(action, body, q) {
    return fetch(API + '?action=' + action + (q || ''), body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : { cache: 'no-store' })
      .then(function (r) { return r.json(); });
  }
  function toast(msg, type) { if (typeof window.showNotification === 'function') window.showNotification(msg, type || 'success'); }

  var css = document.createElement('style');
  css.textContent = [
    '.pn-wrap{display:flex;flex-direction:column;gap:8px;margin:0 0 14px}',
    '.pn-bar{display:flex;align-items:flex-start;gap:12px;padding:12px 14px;border:1px solid #bfdbfe;border-radius:14px;background:#eff6ff;color:#1e3a8a}',
    '.pn-bar.success{border-color:#bbf7d0;background:#f0fdf4;color:#14532d}.pn-bar.warning{border-color:#fde68a;background:#fffbeb;color:#78350f}.pn-bar.danger{border-color:#fecaca;background:#fef2f2;color:#7f1d1d}',
    '.pn-bar>.material-symbols-rounded{flex-shrink:0;font-size:22px;margin-top:1px}.pn-bar div{flex:1;min-width:0}.pn-bar b{display:block;font-size:.92rem}.pn-bar p{margin:2px 0 0;font-size:.86rem;line-height:1.45;white-space:pre-line}',
    '.pn-x{flex-shrink:0;width:30px;height:30px;display:grid;place-items:center;border:0;border-radius:9px;background:transparent;color:inherit;cursor:pointer;opacity:.7}.pn-x:hover{background:rgba(0,0,0,.06);opacity:1}',
    '.pn-ov{position:fixed;inset:0;z-index:10050;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(17,24,39,.55);backdrop-filter:blur(3px)}.pn-ov.show{display:flex}',
    '.pn-card{width:100%;max-width:620px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden;border-radius:22px;background:#fff;box-shadow:0 30px 60px -20px rgba(0,0,0,.45);font-family:inherit}',
    '.pn-hd{display:flex;align-items:center;gap:10px;padding:16px 18px;border-bottom:1px solid #eef0f3}.pn-hd h3{flex:1;margin:0;font-size:1.1rem;font-weight:800;color:#111827}',
    '.pn-hd .pn-back{display:none}.pn-card.thread .pn-hd .pn-back{display:grid}',
    '.pn-bd{flex:1;overflow-y:auto;padding:16px 18px}',
    '.pn-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;height:40px;padding:0 16px;border:1.5px solid #e5e7eb;border-radius:12px;background:#fff;color:#111827;font:inherit;font-size:.88rem;font-weight:700;cursor:pointer}',
    '.pn-btn.pri{border-color:#2563eb;background:#2563eb;color:#fff}.pn-btn:disabled{opacity:.5;cursor:default}',
    '.pn-f{display:flex;flex-direction:column;gap:6px;margin-bottom:12px}.pn-f label{font-size:.8rem;font-weight:700;color:#374151}',
    '.pn-f input,.pn-f select,.pn-f textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:12px;font:inherit;font-size:.9rem;color:#111827;background:#fff}.pn-f textarea{min-height:110px;resize:vertical}',
    '.pn-list{display:flex;flex-direction:column;gap:8px;margin-top:6px}.pn-t{display:flex;align-items:center;gap:12px;width:100%;padding:12px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;text-align:left;font:inherit;cursor:pointer}.pn-t:hover{border-color:#93c5fd}',
    '.pn-t div{flex:1;min-width:0}.pn-t b{display:block;font-size:.9rem;color:#111827}.pn-t small{display:block;overflow:hidden;color:#6b7280;font-size:.78rem;white-space:nowrap;text-overflow:ellipsis}',
    '.pn-st{flex-shrink:0;padding:3px 9px;border-radius:999px;font-size:.72rem;font-weight:800;background:#f3f4f6;color:#4b5563}.pn-st.answered{background:#ecfdf5;color:#047857}.pn-st.open,.pn-st.waiting{background:#eff6ff;color:#1d4ed8}',
    '.pn-dot{width:9px;height:9px;flex-shrink:0;border-radius:50%;background:#ef4444}',
    '.pn-msgs{display:flex;flex-direction:column;gap:10px}.pn-m{max-width:85%;padding:10px 12px;border-radius:14px;background:#f3f4f6;font-size:.9rem;line-height:1.45;white-space:pre-line;color:#111827}',
    '.pn-m.me{align-self:flex-end;background:#2563eb;color:#fff}.pn-m small{display:block;margin-top:4px;font-size:.72rem;opacity:.7}',
    '.pn-ft{display:flex;gap:8px;padding:12px 18px 16px;border-top:1px solid #eef0f3}.pn-ft textarea{flex:1;min-height:44px;max-height:140px;padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:12px;font:inherit;font-size:.9rem;resize:vertical}',
    '.pn-empty{padding:18px;border:1px dashed #e5e7eb;border-radius:14px;color:#6b7280;font-size:.88rem;text-align:center}',
    '.pn-badge{display:inline-grid;place-items:center;min-width:18px;height:18px;margin-left:6px;padding:0 5px;border-radius:999px;background:#ef4444;color:#fff;font-size:.68rem;font-weight:800}',
  ].join('');
  document.head.appendChild(css);

  /* ── announcements ── */
  var icons = { info: 'campaign', success: 'celebration', warning: 'warning', danger: 'error' };
  function renderNotices(list) {
    var main = document.querySelector('main.main-content');
    if (!main) return;
    var box = document.getElementById('pnWrap');
    if (!list.length) { if (box) box.remove(); return; }
    if (!box) { box = document.createElement('div'); box.id = 'pnWrap'; box.className = 'pn-wrap'; main.insertBefore(box, main.firstChild); }
    box.innerHTML = list.map(function (a) {
      return '<div class="pn-bar ' + esc(a.level) + '" data-id="' + (+a.id) + '"><span class="material-symbols-rounded">' + (icons[a.level] || 'campaign') + '</span><div><b>' + esc(a.title) + '</b>' +
        (a.body ? '<p>' + esc(a.body) + '</p>' : '') + '</div>' +
        (+a.dismissible ? '<button type="button" class="pn-x" data-pn-dismiss="' + (+a.id) + '" aria-label="Dismiss"><span class="material-symbols-rounded">close</span></button>' : '') + '</div>';
    }).join('');
  }
  function setBadge(n) {
    var link = document.getElementById('pnSupportLink');
    if (!link) return;
    var b = link.querySelector('.pn-badge');
    if (n > 0) { if (!b) { b = document.createElement('span'); b.className = 'pn-badge'; link.querySelector('.nav-label').appendChild(b); } b.textContent = n; }
    else if (b) b.remove();
  }
  function loadNotices() {
    api('notices').then(function (d) { if (d && d.success) { renderNotices(d.announcements || []); setBadge(d.support_unread || 0); } }).catch(function () {});
  }

  /* ── support window ── */
  var ov, card, cur = 0;
  function build() {
    if (ov) return;
    ov = document.createElement('div');
    ov.className = 'pn-ov';
    ov.innerHTML = '<div class="pn-card" role="dialog" aria-modal="true" aria-labelledby="pnTitle"><div class="pn-hd">' +
      '<button type="button" class="pn-x pn-back" aria-label="Back"><span class="material-symbols-rounded">arrow_back</span></button>' +
      '<h3 id="pnTitle">Help & support</h3><button type="button" class="pn-x pn-close" aria-label="Close"><span class="material-symbols-rounded">close</span></button></div>' +
      '<div class="pn-bd"></div><div class="pn-ft" style="display:none"></div></div>';
    document.body.appendChild(ov);
    card = ov.querySelector('.pn-card');
    ov.addEventListener('click', function (e) {
      if (e.target === ov || e.target.closest('.pn-close')) close();
      else if (e.target.closest('.pn-back')) home();
      else if (e.target.closest('[data-pn-open]')) thread(+e.target.closest('[data-pn-open]').dataset.pnOpen);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && ov.classList.contains('show')) close(); });
  }
  function close() { ov.classList.remove('show'); cur = 0; loadNotices(); }
  function home() {
    cur = 0;
    card.classList.remove('thread');
    card.querySelector('#pnTitle').textContent = 'Help & support';
    card.querySelector('.pn-ft').style.display = 'none';
    var bd = card.querySelector('.pn-bd');
    bd.innerHTML = '<p style="margin:0 0 12px;color:#6b7280;font-size:.9rem">Tell us what is wrong or what you need. The RestroGrow team replies here (you also see a red count on Help & support).</p>' +
      '<form class="pn-new"><div class="pn-f"><label for="pnSub">Subject</label><input id="pnSub" maxlength="160" placeholder="e.g. Bill is not printing" required></div>' +
      '<div class="pn-f"><label for="pnCat">About</label><select id="pnCat"><option value="orders">Orders</option><option value="printing">Printing</option><option value="payments">Payments</option><option value="menu">Menu</option><option value="website">Website</option><option value="whatsapp">WhatsApp</option><option value="billing">My plan / billing</option><option value="other" selected>Something else</option></select></div>' +
      '<div class="pn-f"><label for="pnBody">What happened?</label><textarea id="pnBody" maxlength="5000" placeholder="What you did, what you expected, and what happened instead" required></textarea></div>' +
      '<button type="submit" class="pn-btn pri"><span class="material-symbols-rounded">send</span>Send to RestroGrow</button></form>' +
      '<h4 style="margin:20px 0 4px;font-size:.95rem;color:#111827">Your requests</h4><div class="pn-list" id="pnList"><div class="pn-empty">Loading…</div></div>';
    bd.querySelector('.pn-new').addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = e.target.querySelector('button[type=submit]');
      btn.disabled = true;
      api('createTicket', { subject: bd.querySelector('#pnSub').value, category: bd.querySelector('#pnCat').value, body: bd.querySelector('#pnBody').value })
        .then(function (d) { btn.disabled = false; if (!d.success) { toast(d.message || 'Could not send', 'error'); return; } toast(d.message); thread(d.id); })
        .catch(function () { btn.disabled = false; toast('Could not send. Check your internet and try again.', 'error'); });
    });
    api('tickets').then(function (d) {
      var l = bd.querySelector('#pnList');
      if (!l) return;
      var rows = (d && d.rows) || [];
      l.innerHTML = rows.length ? rows.map(function (t) {
        return '<button type="button" class="pn-t" data-pn-open="' + (+t.id) + '">' + (+t.unread_restaurant ? '<span class="pn-dot" title="New reply"></span>' : '') +
          '<div><b>' + esc(t.subject) + '</b><small>' + esc(t.last_message || '') + '</small></div><span class="pn-st ' + esc(t.status) + '">' + esc({ open: 'Waiting for us', waiting: 'Waiting for us', answered: 'Replied', closed: 'Closed' }[t.status] || t.status) + '</span></button>';
      }).join('') : '<div class="pn-empty">No requests yet.</div>';
    }).catch(function () {});
  }
  function thread(id) {
    cur = id;
    card.classList.add('thread');
    var bd = card.querySelector('.pn-bd'), ft = card.querySelector('.pn-ft');
    bd.innerHTML = '<div class="pn-empty">Loading…</div>';
    api('ticket', null, '&id=' + id).then(function (d) {
      if (!d.success) { bd.innerHTML = '<div class="pn-empty">' + esc(d.message) + '</div>'; return; }
      card.querySelector('#pnTitle').textContent = d.ticket.subject;
      bd.innerHTML = '<div class="pn-msgs">' + d.messages.map(function (m) {
        return '<div class="pn-m' + (m.sender === 'restaurant' ? ' me' : '') + '">' + esc(m.body) + '<small>' + esc(m.sender === 'restaurant' ? (m.sender_name || 'You') : 'RestroGrow support') + ' · ' + esc(when(m.created_at)) + '</small></div>';
      }).join('') + '</div>';
      bd.scrollTop = bd.scrollHeight;
      ft.style.display = '';
      ft.innerHTML = '<textarea placeholder="Write a reply" maxlength="5000"></textarea><button type="button" class="pn-btn pri"><span class="material-symbols-rounded">send</span></button>';
      ft.querySelector('button').addEventListener('click', function () {
        var ta = ft.querySelector('textarea'), b = this;
        if (!ta.value.trim()) return;
        b.disabled = true;
        api('reply', { id: id, body: ta.value }).then(function (r) { b.disabled = false; if (!r.success) { toast(r.message, 'error'); return; } thread(id); }).catch(function () { b.disabled = false; });
      });
    });
  }
  window.rgOpenSupport = function (e) { if (e && e.preventDefault) e.preventDefault(); build(); ov.classList.add('show'); home(); return false; };

  document.addEventListener('click', function (e) {
    var x = e.target.closest('[data-pn-dismiss]');
    if (!x) return;
    var id = +x.dataset.pnDismiss, bar = x.closest('.pn-bar');
    if (bar) bar.remove();
    api('dismiss', { id: id }).catch(function () {});
  });

  function start() { loadNotices(); setInterval(function () { if (!document.hidden) loadNotices(); }, 5 * 60 * 1000); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
