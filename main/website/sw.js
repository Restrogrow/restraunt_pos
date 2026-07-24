// Service Worker for PWA Install Support
// Version bump on each deploy to bust all caches
const CACHE_NAME = 'restaurant-cache-v8';

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
