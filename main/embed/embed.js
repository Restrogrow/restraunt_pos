(function() {
  'use strict';

  var script = document.currentScript || document.scripts[document.scripts.length - 1];
  var src = new URL(script.src);
  var restaurantId = src.searchParams.get('restaurant') || src.searchParams.get('restaurant_id') || '';
  if (!restaurantId) {
    console.error('[RestroGrow Embed] Missing restaurant parameter');
    return;
  }

  var baseUrl = src.origin + src.pathname.replace(/\/embed\/embed\.js.*$/, '');
  var containerId = 'rg-embed-' + restaurantId;

  var container = document.getElementById('rg-restaurant-menu') || document.getElementById('rg-menu-root') || document.getElementById(containerId);
  if (!container) {
    container = document.createElement('div');
    container.id = containerId;
    container.style.cssText = 'width:100%;max-width:480px;margin:0 auto;position:relative;overflow:hidden';
    script.parentNode.insertBefore(container, script.nextSibling);
  }

  // Load the actual customer website (index.php) in the iframe.
  // This ensures the embedded version always shows the EXACT same content
  // as the main customer website — any updates (menu, prices, features, design)
  // reflect automatically with zero maintenance.
  var cacheBuster = '&_v=' + Date.now();
  var iframe = document.createElement('iframe');
  iframe.id = 'rg-frame-' + restaurantId;
  iframe.src = baseUrl + '/website/index.php?restaurant_id=' + encodeURIComponent(restaurantId) + cacheBuster;
  iframe.style.cssText = 'width:100%;border:none;overflow:hidden;display:block';
  iframe.allow = 'geolocation *';
  iframe.loading = 'lazy';
  iframe.title = 'Restaurant Menu';

  var loader = document.createElement('div');
  loader.id = 'rg-loader-' + restaurantId;
  loader.style.cssText = 'display:flex;align-items:center;justify-content:center;padding:60px 20px;font-family:Poppins,sans-serif;color:#999;font-size:14px';
  loader.textContent = 'Loading menu...';
  container.appendChild(loader);

  iframe.onload = function() {
    var l = document.getElementById('rg-loader-' + restaurantId);
    if (l) l.remove();
  };

  container.appendChild(iframe);

  window.addEventListener('message', function(e) {
    if (e.data && e.data.type === 'rg-embed-resize' && e.data.height) {
      iframe.style.height = e.data.height + 'px';
    }
  });

  var fallbackResize = function() {
    if (iframe.contentWindow) {
      try {
        var h = iframe.contentWindow.document.body.scrollHeight;
        if (h > 100) iframe.style.height = h + 'px';
      } catch(e) {}
    }
  };
  setTimeout(fallbackResize, 2000);
  window.addEventListener('resize', fallbackResize);
})();