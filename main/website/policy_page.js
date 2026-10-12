// Restaurant policy pages: "On this page" list from the headings, highlights the
// section being read, reading progress bar, back-to-top button.
(function () {
  var body = document.getElementById('ppBody');
  var toc = document.getElementById('ppToc');
  if (!body) return;
  var heads = Array.prototype.slice.call(body.querySelectorAll('h2'));
  var links = [];
  var picked = -1, pickedAt = 0; // section just picked from the list wins while the page settles

  if (toc && heads.length >= 2) {
    var used = {};
    var list = toc.querySelector('ol');
    heads.forEach(function (h, i) {
      var text = (h.textContent || '').trim();
      var id = h.id || text.toLowerCase().replace(/^\d+[.)]\s*/, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'section-' + (i + 1);
      while (used[id]) id += '-' + (i + 1);
      used[id] = 1;
      h.id = id;
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = '#' + id;
      a.textContent = text.replace(/^\d+[.)]\s*/, '');
      a.addEventListener('click', function (e) {
        e.preventDefault();
        if (window.innerWidth <= 900) toc.open = false; // fold first so the page does not shift after the jump
        picked = i; pickedAt = Date.now();
        h.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        if (history.replaceState) history.replaceState(null, '', '#' + id);
        h.classList.remove('pp-hit'); void h.offsetWidth; h.classList.add('pp-hit');
      });
      li.appendChild(a);
      list.appendChild(li);
      links.push(a);
    });
    toc.hidden = false;
    if (window.innerWidth <= 900) toc.open = false;
  }

  var bar = document.querySelector('.pp-progress span');
  var up = document.getElementById('ppUp');
  if (up) up.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });

  var ticking = false;
  function update() {
    ticking = false;
    var doc = document.documentElement;
    var max = doc.scrollHeight - window.innerHeight;
    var y = window.scrollY || doc.scrollTop;
    if (bar) bar.style.width = (max > 0 ? Math.min(100, (y / max) * 100) : 0) + '%';
    if (up) up.classList.toggle('show', y > 700);
    if (!links.length) return;
    var current = 0;
    for (var i = 0; i < heads.length; i++) {
      if (heads[i].getBoundingClientRect().top <= 120) current = i; else break;
    }
    if (max > 0 && y >= max - 4) current = heads.length - 1;
    if (picked > -1 && Date.now() - pickedAt < 1500) current = picked;
    links.forEach(function (a, i) { a.classList.toggle('on', i === current); });
  }
  window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
