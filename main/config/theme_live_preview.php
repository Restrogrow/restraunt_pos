<?php
/**
 * Live preview on a phone (Website Appearance > "On your phone").
 *
 * The dashboard starts a session and gets a private link (with a QR). Every
 * unsaved Website Appearance change is pushed here (theme JSON + version).
 * The phone opens the website with ?live_preview=<token>: header.php applies
 * the session's theme to that page view only (same allow-lists as the
 * dashboard's own "Current" preview, never saved), and a tiny script checks
 * the version every ~1.2 s and reloads in place when it changes.
 *
 * Sessions belong to one restaurant and end 2 hours after the last change.
 */
if (!defined('RG_LIVE_PREVIEW_SCRIPT')) define('RG_LIVE_PREVIEW_SCRIPT', '<script>
/* Live preview on a phone (Website Appearance > "On your phone"): this page shows the
   dashboard\'s unsaved choices and reloads in place when they change. */
(function () {
  var T = __TOKEN__, V = __VERSION__;
  var url = new URL(\'theme_live.php?t=\' + T, document.baseURI).href;
  try { var y = sessionStorage.getItem(\'rgLpY\'); if (y !== null) { sessionStorage.removeItem(\'rgLpY\'); window.addEventListener(\'load\', function () { setTimeout(function () { window.scrollTo(0, +y); }, 60); }); } } catch (e) {}
  var chip = document.createElement(\'div\');
  chip.setAttribute(\'style\', \'position:fixed;top:10px;left:50%;transform:translateX(-50%);z-index:2147483000;display:flex;align-items:center;gap:8px;padding:6px 6px 6px 12px;border-radius:999px;background:rgba(17,24,39,.88);color:#fff;font:600 12px/1 system-ui,sans-serif;box-shadow:0 8px 20px rgba(0,0,0,.25);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)\');
  chip.innerHTML = \'<span id="rgLpDot" style="width:8px;height:8px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.3)"></span><span id="rgLpTxt">Live preview</span><button type="button" style="border:0;border-radius:999px;padding:6px 10px;background:#fff;color:#111827;font:700 11px system-ui,sans-serif;cursor:pointer">Exit</button>\';
  document.body.appendChild(chip);
  chip.querySelector(\'button\').onclick = function () {
    document.cookie = \'rg_live_preview=; Max-Age=0; path=/\';
    var u = new URL(location.href); u.searchParams.delete(\'live_preview\'); location.replace(u.toString());
  };
  function poll() {
    if (document.hidden) { setTimeout(poll, 2000); return; }
    fetch(url, { cache: \'no-store\' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.alive) { document.getElementById(\'rgLpTxt\').textContent = \'Live preview ended\'; document.getElementById(\'rgLpDot\').style.background = \'#9ca3af\'; return; }
      if (d.v !== V) { try { sessionStorage.setItem(\'rgLpY\', String(window.scrollY)); } catch (e) {} location.reload(); return; }
      setTimeout(poll, 1200);
    }).catch(function () { setTimeout(poll, 3000); });
  }
  setTimeout(poll, 1200);
})();
</script>');

if (!function_exists('ensureThemeLivePreviewTable')) {

    function ensureThemeLivePreviewTable(PDO $conn): void {
        static $done = false;
        if ($done || $conn->inTransaction()) return;
        $done = true;
        $conn->exec("CREATE TABLE IF NOT EXISTS theme_live_previews (
            token CHAR(32) PRIMARY KEY,
            restaurant_id VARCHAR(50) NOT NULL,
            theme TEXT NULL,
            version INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL,
            INDEX idx_tlp_rest (restaurant_id)
        )");
    }

    /** A live session that hasn't expired (2 hours after its last change), or null. */
    function themeLivePreviewGet(PDO $conn, string $token): ?array {
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
        try {
            $st = $conn->prepare('SELECT token, restaurant_id, theme, version FROM theme_live_previews WHERE token = ? AND updated_at > NOW() - INTERVAL 2 HOUR');
            $st->execute([$token]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            return null;   // table not created yet: no sessions
        }
    }
}
