/*
 * Settings page layout: section list on the left (chips on phones), one section open at a
 * time, and one save bar for everything.
 *
 * Saving still goes through the existing handlers, untouched: the restaurant, profile and
 * system forms (submitted through their hidden buttons) and savePrinterSettingsForm().
 * Each card says which of those it belongs to (data-st-form); only the ones with changes
 * are saved. Markup: #settingsPage in views/dashboard.php. Styles: st-* in studio-ui.css.
 */
(function () {
  'use strict';
  var page, dirty = {}, saving = false;
  var KEY = 'rg_settings_section';
  var FORMS = { restaurant: 'restaurantSettingsForm', profile: 'profileSettingsForm', system: 'systemSettingsForm' };

  function $(s, el) { return (el || document).querySelector(s); }
  function $$(s, el) { return Array.prototype.slice.call((el || document).querySelectorAll(s)); }

  // ── sections ──
  function open(key, focus) {
    var pane = $('[data-st-pane="' + key + '"]', page);
    if (!pane) return;
    $$('[data-st-pane]', page).forEach(function (p) { p.classList.toggle('on', p === pane); });
    $$('[data-st-go]', page).forEach(function (b) {
      var on = b.getAttribute('data-st-go') === key;
      b.classList.toggle('on', on);
      if (on) { b.setAttribute('aria-current', 'page'); if (b.scrollIntoView && window.innerWidth <= 900) b.scrollIntoView({ block: 'nearest', inline: 'center' }); }
      else b.removeAttribute('aria-current');
    });
    try { localStorage.setItem(KEY, key); } catch (e) {}
    syncMirrors(); syncWhen();
    if (focus && window.innerWidth <= 900) { var top = page.querySelector('.st-panes').getBoundingClientRect().top + window.scrollY - 80; if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' }); }
  }
  window.openSettingsSection = function (key) { if (page) open(key, true); };

  // fields that only matter when a switch is on (tax name / %, business ID label / number)
  function syncWhen() {
    $$('[data-st-when]', page).forEach(function (el) {
      var sw = document.getElementById(el.getAttribute('data-st-when'));
      el.classList.toggle('st-off', !!sw && !sw.checked);
    });
  }

  // printer width / connection: big choice buttons that drive the real <select>
  function syncMirrors() {
    $$('[data-st-mirror]', page).forEach(function (box) {
      var sel = document.getElementById(box.getAttribute('data-st-mirror'));
      if (!sel) return;
      $$('button[data-v]', box).forEach(function (b) { var on = b.getAttribute('data-v') === sel.value; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); });
    });
  }

  // ── unsaved changes ──
  function markDirty(form) {
    if (!form) return;
    dirty[form] = true;
    var bar = document.getElementById('stSavebar');
    if (bar) { bar.hidden = false; bar.classList.remove('done'); }
    var msg = document.getElementById('stSaveMsg');
    if (msg) msg.textContent = 'You have unsaved changes';
  }
  window.stMarkDirty = markDirty;
  function anyDirty() { for (var k in dirty) if (dirty[k]) return true; return false; }

  // run one of the existing form handlers and wait until it has finished
  function submitForm(id) {
    return new Promise(function (resolve) {
      var form = document.getElementById(id);
      if (!form) return resolve();
      var btn = form.querySelector('button[type="submit"]');
      var started = false, t0 = Date.now();
      if (form.requestSubmit) form.requestSubmit(btn || undefined); else form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
      (function wait() {
        if (btn && btn.disabled) started = true;
        if ((started && !btn.disabled) || (!started && Date.now() - t0 > 600) || Date.now() - t0 > 20000) return resolve();
        setTimeout(wait, 80);
      })();
    });
  }

  function save() {
    if (saving || !anyDirty()) return;
    var name = document.getElementById('restaurantNameSetting');
    if (dirty.restaurant && name && !name.value.trim()) { open('profile', true); name.focus(); if (window.showNotification) showNotification('Restaurant name is required', 'error'); return; }
    var user = document.getElementById('usernameSetting'), mail = document.getElementById('profileEmailSetting');
    if (dirty.profile && ((user && !user.value.trim()) || (mail && !mail.value.trim()))) { open('account', true); (user && !user.value.trim() ? user : mail).focus(); if (window.showNotification) showNotification('Username and email are required', 'error'); return; }
    saving = true;
    var btn = document.getElementById('stSave');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="material-symbols-rounded">hourglass_top</span>Saving…'; }
    var todo = Object.keys(dirty).filter(function (k) { return dirty[k]; });
    var chain = Promise.resolve();
    todo.forEach(function (k) {
      chain = chain.then(function () {
        dirty[k] = false;
        if (k === 'printer') { if (window.savePrinterSettingsForm) window.savePrinterSettingsForm(); return; }
        return submitForm(FORMS[k]);
      });
    });
    chain.then(function () {
      saving = false;
      if (btn) { btn.disabled = false; btn.innerHTML = '<span class="material-symbols-rounded">save</span>Save changes'; }
      if (anyDirty()) return;   // something changed again while saving
      var bar = document.getElementById('stSavebar');
      var msg = document.getElementById('stSaveMsg');
      if (msg) msg.textContent = 'All changes saved';
      if (bar) { bar.classList.add('done'); setTimeout(function () { if (!anyDirty()) { bar.hidden = true; bar.classList.remove('done'); } }, 1400); }
    });
  }

  function discard() {
    dirty = {};
    var bar = document.getElementById('stSavebar');
    if (bar) bar.hidden = true;
    if (typeof window.loadSettingsData === 'function') window.loadSettingsData();
    else if (typeof loadSettingsData === 'function') loadSettingsData();
    // printer settings live on this device
    if (window.getPrinterSettings) {
      var s = window.getPrinterSettings(), w = document.getElementById('printerWidthSelect'), m = document.getElementById('printerModeSelect');
      if (w) w.value = String(s.widthMM);
      if (m) { m.value = s.mode; m.dispatchEvent(new Event('change', { bubbles: true })); }
      var ip = document.getElementById('printerNetworkIp'), port = document.getElementById('printerNetworkPort');
      if (ip) ip.value = s.networkIp || '';
      if (port) port.value = s.networkPort || 9100;
      dirty.printer = false;
    }
    setTimeout(function () { syncMirrors(); syncWhen(); dirty = {}; var b = document.getElementById('stSavebar'); if (b) b.hidden = true; }, 600);
  }

  function copyField(id, btn) {
    var el = document.getElementById(id);
    if (!el || !el.value) return;
    var done = function () { var i = btn.querySelector('.material-symbols-rounded'); if (i) { i.textContent = 'check'; setTimeout(function () { i.textContent = 'content_copy'; }, 1400); } };
    if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(el.value).then(done);
    else { el.removeAttribute('readonly'); el.select(); try { document.execCommand('copy'); done(); } catch (e) {} el.setAttribute('readonly', ''); }
  }

  function init() {
    page = document.getElementById('settingsPage');
    if (!page || page._stReady) return;
    page._stReady = true;

    page.addEventListener('click', function (e) {
      var go = e.target.closest('[data-st-go]');
      if (go) { open(go.getAttribute('data-st-go'), true); return; }
      var choice = e.target.closest('[data-st-mirror] button[data-v]');
      if (choice) {
        var sel = document.getElementById(choice.parentNode.getAttribute('data-st-mirror'));
        if (sel && sel.value !== choice.getAttribute('data-v')) { sel.value = choice.getAttribute('data-v'); sel.dispatchEvent(new Event('change', { bubbles: true })); }
        syncMirrors(); return;
      }
      var cp = e.target.closest('[data-st-copy]');
      if (cp) { copyField(cp.getAttribute('data-st-copy'), cp); return; }
      if (e.target.closest('#stSave')) { save(); return; }
      if (e.target.closest('#stDiscard')) { discard(); return; }
      // the tagline format button changes a hidden field without an input event
      if (e.target.closest('#descFormatToggleSettings')) markDirty('restaurant');
    });
    var onEdit = function (e) {
      var t = e.target;
      if (!t || t.type === 'file' || t.id === 'restaurantIdSetting') return;
      var card = t.closest('[data-st-form]');
      if (card) markDirty(card.getAttribute('data-st-form'));
      if (t.type === 'checkbox') syncWhen();
    };
    page.addEventListener('input', onEdit);
    page.addEventListener('change', onEdit);
    // Enter in a text box saves, like the old forms did
    page.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.matches('input:not([type=checkbox]):not([type=file])') && e.target.closest('[data-st-form]')) { e.preventDefault(); markDirty(e.target.closest('[data-st-form]').getAttribute('data-st-form')); save(); }
    });
    // the map picker sets the address and pin without input events
    var pick = document.getElementById('restaurantMapPickerBtn');
    if (pick) pick.addEventListener('click', function () { var lat = document.getElementById('restaurantAddressLat'), was = lat ? lat.value : ''; var iv = setInterval(function () { if (!document.getElementById('settingsPage').classList.contains('active')) return clearInterval(iv); if (lat && lat.value !== was) { markDirty('restaurant'); clearInterval(iv); } }, 500); setTimeout(function () { clearInterval(iv); }, 300000); });

    // payment QR: show the chosen file name in the drop box
    var qr = document.getElementById('businessQRUpload');
    if (qr) qr.addEventListener('change', function () {
      var box = qr.closest('.st-file'), txt = box && box.querySelector('span:not(.material-symbols-rounded)');
      var f = qr.files && qr.files[0];
      if (box) box.classList.toggle('has-file', !!f);
      if (txt) txt.textContent = f ? f.name + ' - now press Upload' : 'Choose an image';
    });

    window.addEventListener('beforeunload', function (e) { if (anyDirty()) { e.preventDefault(); e.returnValue = ''; } });

    var start = 'profile';
    try { start = localStorage.getItem(KEY) || 'profile'; } catch (e) {}
    open(start);
    // values arrive after the page loads (loadSettingsData / printer settings)
    setTimeout(function () { syncMirrors(); syncWhen(); }, 800);
    new MutationObserver(function () { if (page.classList.contains('active')) { syncMirrors(); syncWhen(); } }).observe(page, { attributes: true, attributeFilter: ['class'] });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
