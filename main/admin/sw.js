// Service Worker for RestroGrow Admin PWA
// Handles push notifications and caching

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
          return caches.delete(name);
        })
      );
    }).then(function() {
      return self.clients.claim();
    }).catch(function() {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function(event) {
  if (event.request.method !== 'GET') return;
  event.respondWith(
    fetch(event.request).catch(function() {
      return new Response('Offline. Please check your connection.', {
        status: 200,
        headers: { 'Content-Type': 'text/html; charset=UTF-8' }
      });
    })
  );
});

// ===== PUSH NOTIFICATIONS =====
self.addEventListener('push', function(event) {
  var data = {};
  try {
    if (event.data) {
      data = event.data.json();
    }
  } catch(e) {
    data = { title: 'RestroGrow Admin', body: event.data ? event.data.text() : 'New update' };
  }

  var title = data.title || 'RestroGrow Admin';
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
        if (new URL(client.url).pathname === target.pathname && 'focus' in client) {
          if (d.orderId) client.postMessage({ type: 'rg-open-order', orderId: d.orderId });
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(target.href + (d.orderId ? (target.search ? '&' : '?') + 'open_order=' + encodeURIComponent(d.orderId) : ''));
      }
    })
  );
});

self.addEventListener('notificationclose', function(event) {
  // Optional: track when user dismisses notification
});
