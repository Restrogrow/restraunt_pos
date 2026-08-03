// Service Worker for PWA Install Support
// Version bump on each deploy to bust all caches
const CACHE_NAME = 'restaurant-cache-v10';

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
          // Delete ALL old caches so fresh content is always served
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
  // Only cache http/https requests (not chrome-extension:// or other schemes)
  var canCache = reqUrl.startsWith('http://') || reqUrl.startsWith('https://');
  if (!canCache) { return; }

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

  event.respondWith(
    fetch(event.request).then(function(response) {
      // Cache successful responses for offline use (clone because response is a stream)
      if (response.ok) {
        const clone = response.clone();
        caches.open(CACHE_NAME).then(function(cache) {
          cache.put(event.request, clone);
        });
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
    requireInteraction: true,
    data: {
      url: data.url || '../views/dashboard.php',
      orderId: data.orderId || null,
      type: data.type || 'general'
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

  var targetUrl = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : '../views/dashboard.php';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
      for (var i = 0; i < clientList.length; i++) {
        var client = clientList[i];
        if (client.url.indexOf(targetUrl) !== -1 && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});
