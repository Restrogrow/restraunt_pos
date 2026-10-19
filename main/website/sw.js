// Service Worker for PWA Install Support
// Version bump on each deploy to bust all caches
const CACHE_NAME = 'restaurant-cache-v13';   // v13: personal data (orders, wallet, points) is never stored any more
// The owner dashboard, kept so the POS opens with no internet (offline billing, assets/js/pos-offline.js).
// Cleared on logout (script.js logout()).
const SHELL_CACHE = 'rg-shell';
// fonts and pinned library files from these hosts are kept too, so icons and text look right offline
const KEEP_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'unpkg.com'];

// Install immediately — no heavy precaching (avoids slow PHP page fetches)
self.addEventListener('install', function(event) {
  event.waitUntil(Promise.resolve().then(function() {
    self.skipWaiting();
  }));
});

self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(cacheNames) {
      return Promise.all(
        cacheNames.map(function(name) {
          // Delete ALL old caches so fresh content is always served (the dashboard copy is refreshed on its next load)
          return caches.delete(name);
        })
      );
    }).then(function() {
      return self.clients.claim();
    }).catch(function(err) {
      console.error('[SW] Activation error:', err ? err.message : err);
      // Still try to claim clients even if cache cleanup failed
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function(event) {
  // Only handle GET requests
  if (event.request.method !== 'GET') return;

  // Navigation requests: network first, fallback to offline page
  if (event.request.mode === 'navigate') {
    // the dashboard: network first, the last copy when offline
    var nav = new URL(event.request.url);
    if (/\/views\/dashboard\.php$/.test(nav.pathname)) {
      var key = nav.origin + nav.pathname;
      event.respondWith(
        fetch(event.request).then(function(response) {
          if (response.status === 200 && !response.redirected) {
            var copy = response.clone();
            caches.open(SHELL_CACHE).then(function(c) { return c.put(key, copy); }).catch(function() {});
          }
          return response;
        }).catch(function() {
          return caches.open(SHELL_CACHE).then(function(c) { return c.match(key); }).then(function(hit) {
            return hit || new Response('You are offline. Open the dashboard once while online so it can work offline.', { status: 200, headers: { 'Content-Type': 'text/html; charset=UTF-8' } });
          });
        })
      );
      return;
    }
    event.respondWith(
      fetch(event.request).catch(function() {
        return new Response('You are offline. Please check your connection.', {
          status: 200,
          headers: { 'Content-Type': 'text/html; charset=UTF-8' }
        });
      })
    );
    return;
  }

  // For all other requests (CSS, JS, images): network-first with cache fallback
  // This ensures updates show immediately while still working offline
  var reqUrl = event.request.url;
  // Only our own origin (this also skips chrome-extension:// etc.).
  // Cross-origin requests (Google Maps, QR service, CDNs, fonts) go straight
  // to the network: intercepting them turned any failure into a fake
  // "Offline" 503 and hid the real error.
  var reqHost = new URL(reqUrl);
  if (reqHost.origin !== self.location.origin && KEEP_HOSTS.indexOf(reqHost.hostname) === -1) { return; }
  // Range requests (audio/video streaming) return 206 partial responses,
  // which the Cache API rejects — let the browser handle them natively.
  if (event.request.headers.has('range')) { return; }

  // Live/dynamic data (order status, delivery tracking, KOT/order polling,
  // etc. — everything under /api/) must never be served from cache when the
  // network fails. A stale cached JSON response looks identical to a fresh
  // one to the calling code, so a transient network blip could silently show
  // a customer an outdated order/delivery status with no "this may be
  // stale" indication. Let these fail visibly instead so the caller's own
  // error handling (retry/backoff, "connection lost" UI) can react.
  if (reqUrl.indexOf('/api/') !== -1) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Server pages and their data (api.php, wallet_api.php, loyalty_actions.php, customer_auth.php…) hold
  // personal details (order history, wallet, points): always live, never stored on a shared phone.
  var path = reqHost.pathname;
  var isPhotoCopy = /\/image\.php$/.test(path) && reqHost.searchParams.has('w');
  if (/\.php$/.test(path) && !isPhotoCopy) { return; }

  // Files that never change at the same address (versioned ?v= CSS/JS, fonts, small photo copies made
  // by image.php): from the device straight away after the first visit, so pages open instantly.
  if (isPhotoCopy || reqHost.searchParams.has('v') || /\.(woff2?|ttf)$/.test(path)) {
    event.respondWith(
      caches.match(event.request).then(function(hit) {
        if (hit) return hit;
        return fetch(event.request).then(function(response) {
          if (response.status === 200) {
            var copy = response.clone();
            caches.open(CACHE_NAME).then(function(cache) { return cache.put(event.request, copy); }).catch(function() {});
          }
          return response;
        });
      })
    );
    return;
  }

  event.respondWith(
    fetch(event.request).then(function(response) {
      // Cache complete responses for offline use (clone because response is
      // a stream). Only status 200: response.ok is also true for 206 partial
      // content, which cache.put() rejects ("Partial response ... unsupported").
      if (response.status === 200) {
        const clone = response.clone();
        caches.open(CACHE_NAME).then(function(cache) {
          return cache.put(event.request, clone);
        }).catch(function() { /* caching is best-effort */ });
      }
      return response;
    }).catch(function() {
      // Offline: fall back to cache
      return caches.match(event.request).then(function(cached) {
        if (cached) return cached;
        // Transparent 1x1 GIF for failed images
        if (event.request.destination === 'image') {
          return new Response(
            new Uint8Array([0x47,0x49,0x46,0x38,0x39,0x61,0x01,0x00,0x01,0x00,0x80,0x00,0x00,0xFF,0xFF,0xFF,0x00,0x00,0x00,0x21,0xF9,0x04,0x00,0x00,0x00,0x00,0x00,0x2C,0x00,0x00,0x00,0x00,0x01,0x00,0x01,0x00,0x00,0x02,0x02,0x44,0x01,0x00,0x3B]),
            { status: 200, headers: { 'Content-Type': 'image/gif' } }
          );
        }
        return new Response('Offline', { status: 503 });
      });
    })
  );
});

// ===== PUSH NOTIFICATIONS =====
// This is the service worker that actually controls the admin dashboard
// (views/dashboard.php registers this file, not admin/sw.js — its narrower
// scope of main/admin/ never covers main/views/). Without a 'push' listener
// here, push messages arrived at the browser but nothing ever displayed a
// notification for them — the whole feature silently did nothing.
self.addEventListener('push', function(event) {
  var data = {};
  try {
    if (event.data) {
      data = event.data.json();
    }
  } catch (e) {
    data = { title: 'RestroGrow', body: event.data ? event.data.text() : 'New update' };
  }

  var title = data.title || 'RestroGrow';
  var options = {
    body: data.body || 'You have a new notification',
    icon: data.icon || '../assets/images/logo-transparent.png',
    badge: '../assets/images/logo-transparent.png',
    vibrate: [200, 100, 200],
    tag: data.tag || 'default',
    renotify: true,
    requireInteraction: data.sticky !== false,   // customer order updates (config/customer_push.php) close by themselves
    data: {
      url: data.url || '../views/dashboard.php',
      orderId: data.orderId || null,
      type: data.type || (data.sticky === false ? 'order-update' : 'general')
    },
    actions: data.actions || []
  };

  event.waitUntil(
    self.registration.showNotification(title, options)
  );
});

// Browsers periodically rotate a push subscription's endpoint. Without this
// handler the old subscription just goes dead silently — push notifications
// stop arriving with no error anywhere, until someone happens to manually
// re-subscribe. Re-subscribe with the same VAPID key and save the new
// subscription, mirroring what the page's own subscribeToPush() does.
self.addEventListener('pushsubscriptionchange', function(event) {
  event.waitUntil(
    fetch('../api/get_vapid_key.php').then(function(r) { return r.json(); }).then(function(data) {
      if (!data || !data.publicKey) {
        throw new Error('VAPID public key unavailable');
      }
      return self.registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: swUrlBase64ToUint8Array(data.publicKey)
      });
    }).then(function(subscription) {
      return fetch('../api/save_push_subscription.php', {
        method: 'POST',
        credentials: 'include',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(subscription.toJSON())
      });
    }).catch(function(err) {
      console.error('[SW] pushsubscriptionchange re-subscribe failed:', err && err.message);
    })
  );
});

function swUrlBase64ToUint8Array(base64String) {
  var padding = '='.repeat((4 - base64String.length % 4) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var rawData = self.atob(base64);
  var outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

self.addEventListener('notificationclick', function(event) {
  event.notification.close();
  if (event.action === 'dismiss') return;
  var d = event.notification.data || {};
  // The link is relative to this file (e.g. ../views/dashboard.php), so compare full paths:
  // matching the relative text against an open tab's address never found it before.
  var target = new URL(d.url || '../views/dashboard.php', self.location.href);
  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        var open = new URL(client.url);
        // a customer's order update opens the tab tracking that same order (the query names the order)
        if (open.pathname === target.pathname && (d.orderId || d.type !== 'order-update' || open.search === target.search) && 'focus' in client) {
          if (d.orderId) client.postMessage({ type: 'rg-open-order', orderId: d.orderId });
          var page = target.searchParams.get('page');   // e.g. a low-stock alert opens Inventory
          if (page) client.postMessage({ type: 'rg-open-page', page: page });
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(target.href + (d.orderId ? (target.search ? '&' : '?') + 'open_order=' + encodeURIComponent(d.orderId) : ''));
      }
    })
  );
});
