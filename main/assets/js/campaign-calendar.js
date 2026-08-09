// Marketing > Campaign Calendar
// Surfaces upcoming occasions worth promoting and links straight into the
// Poster Studio / Social Captions tools, pre-filled. Entirely client-side -
// only fixed and easily-computable recurring dates are used (no lunar
// festivals) so nothing is ever wrong for the current year.
(function() {
  'use strict';

  function nextFixedDate(month, day) {
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var d = new Date(now.getFullYear(), month - 1, day);
    if (d < today) d = new Date(now.getFullYear() + 1, month - 1, day);
    return d;
  }

  function nextNthWeekday(month, weekday, nth) {
    function calc(year) {
      var first = new Date(year, month - 1, 1);
      var offset = (weekday - first.getDay() + 7) % 7;
      return new Date(year, month - 1, 1 + offset + (nth - 1) * 7);
    }
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var d = calc(now.getFullYear());
    if (d < today) d = calc(now.getFullYear() + 1);
    return d;
  }

  function nextWeekday(weekday) {
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    var diff = (weekday - today.getDay() + 7) % 7;
    var d = new Date(today);
    d.setDate(today.getDate() + diff);
    return d;
  }

  function daysUntil(date) {
    var now = new Date();
    var today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    return Math.round((date - today) / 86400000);
  }

  var OCCASIONS = [
    {
      emoji: '🎉', name: "New Year's Day", getDate: function() { return nextFixedDate(1, 1); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: "Happy New Year!", subtext: 'Ring in the new year with us', badge: 'New Year Special' },
      captionType: 'festive', captionSubject: "New Year's Day", captionTone: 'fun'
    },
    {
      emoji: '❤️', name: "Valentine's Day", getDate: function() { return nextFixedDate(2, 14); },
      posterTemplateId: 'combo',
      posterFields: { headline: 'Valentine’s Dinner for Two', subtext: 'Reserve your table today' },
      captionType: 'combo', captionSubject: "Valentine's dinner for two", captionTone: 'elegant'
    },
    {
      emoji: '💐', name: "Women's Day", getDate: function() { return nextFixedDate(3, 8); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: "Happy Women's Day", subtext: 'A treat, on us', badge: "Women's Day Special" },
      captionType: 'festive', captionSubject: "Women's Day", captionTone: 'elegant'
    },
    {
      emoji: '🌸', name: "Mother's Day", getDate: function() { return nextNthWeekday(5, 0, 2); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: "Happy Mother's Day", subtext: "Treat mom to a meal she'll love", badge: "Mother's Day Special" },
      captionType: 'festive', captionSubject: "Mother's Day", captionTone: 'elegant'
    },
    {
      emoji: '👔', name: "Father's Day", getDate: function() { return nextNthWeekday(6, 0, 3); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: "Happy Father's Day", subtext: 'Treat dad to something special', badge: "Father's Day Special" },
      captionType: 'festive', captionSubject: "Father's Day", captionTone: 'elegant'
    },
    {
      emoji: '👯', name: 'Friendship Day', getDate: function() { return nextNthWeekday(8, 0, 1); },
      posterTemplateId: 'combo',
      posterFields: { headline: 'Friendship Day Feast', subtext: 'Better with friends' },
      captionType: 'combo', captionSubject: 'Friendship Day feast', captionTone: 'fun'
    },
    {
      emoji: '🇮🇳', name: 'Independence Day', getDate: function() { return nextFixedDate(8, 15); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: 'Happy Independence Day', subtext: 'Celebrate with great food', badge: 'Independence Day Special' },
      captionType: 'festive', captionSubject: 'Independence Day', captionTone: 'fun'
    },
    {
      emoji: '🍎', name: "Teacher's Day", getDate: function() { return nextFixedDate(9, 5); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: "Happy Teacher's Day", subtext: 'A thank-you treat for teachers', badge: "Teacher's Day Special" },
      captionType: 'festive', captionSubject: "Teacher's Day", captionTone: 'elegant'
    },
    {
      emoji: '🎃', name: 'Halloween', getDate: function() { return nextFixedDate(10, 31); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: 'Spooky Season Specials', subtext: 'Treats all season long', badge: 'Halloween Special' },
      captionType: 'festive', captionSubject: 'Halloween', captionTone: 'fun'
    },
    {
      emoji: '🎄', name: 'Christmas', getDate: function() { return nextFixedDate(12, 25); },
      posterTemplateId: 'festive_special',
      posterFields: { headline: 'Merry Christmas', subtext: 'Celebrate the season with us', badge: 'Christmas Special' },
      captionType: 'festive', captionSubject: 'Christmas', captionTone: 'elegant'
    },
    {
      emoji: '🥂', name: "New Year's Eve", getDate: function() { return nextFixedDate(12, 31); },
      posterTemplateId: 'happy_hour',
      posterFields: { headline: "New Year's Eve", subtext: 'Ring it in with us', badge: 'NYE Party' },
      captionType: 'happy_hour', captionSubject: "New Year's Eve party", captionTone: 'urgent'
    },
    {
      emoji: '📅', name: 'Weekend Special', getDate: function() { return nextWeekday(5); }, recurring: true,
      posterTemplateId: 'bold_discount',
      posterFields: { headline: 'Weekend Special', subtext: 'This Sat-Sun only', badge: 'Weekend Offer' },
      captionType: 'weekend', captionSubject: 'our weekend special', captionTone: 'fun'
    }
  ];

  function formatDate(d) {
    return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  }

  function daysLabel(n) {
    if (n === 0) return 'Today!';
    if (n === 1) return 'Tomorrow';
    return 'In ' + n + ' days';
  }

  function goToPoster(occasion) {
    if (window.showPage) window.showPage('marketingPostersPage');
    setTimeout(function() {
      if (window.initPosterStudio) window.initPosterStudio();
      if (window.prefillPoster) window.prefillPoster(occasion.posterTemplateId, occasion.posterFields);
    }, 60);
  }

  function goToCaption(occasion) {
    if (window.showPage) window.showPage('marketingCaptionsPage');
    setTimeout(function() {
      if (window.initCaptionStudio) window.initCaptionStudio();
      if (window.prefillCaptions) {
        window.prefillCaptions(occasion.captionType, occasion.captionSubject, '', occasion.captionTone);
      }
    }, 60);
  }

  function makeCard(occasion, date, n) {
    var card = document.createElement('div');
    card.className = 'campaign-card';

    var top = document.createElement('div');
    top.className = 'campaign-card-top';
    var title = document.createElement('div');
    title.className = 'campaign-card-title';
    title.textContent = occasion.emoji + ' ' + occasion.name;
    var pill = document.createElement('div');
    pill.className = 'campaign-card-pill' + (n <= 1 ? ' campaign-card-pill-soon' : '');
    pill.textContent = daysLabel(n);
    top.appendChild(title);
    top.appendChild(pill);

    var dateEl = document.createElement('div');
    dateEl.className = 'campaign-card-date';
    dateEl.textContent = formatDate(date) + (occasion.recurring ? ' (weekly)' : '');

    var actions = document.createElement('div');
    actions.className = 'campaign-card-actions';

    var posterBtn = document.createElement('button');
    posterBtn.type = 'button';
    posterBtn.className = 'btn-secondary';
    posterBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:16px;">imagesmode</span> Create Poster';
    posterBtn.addEventListener('click', function() { goToPoster(occasion); });

    var captionBtn = document.createElement('button');
    captionBtn.type = 'button';
    captionBtn.className = 'btn-secondary';
    captionBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:16px;">chat_bubble</span> Create Caption';
    captionBtn.addEventListener('click', function() { goToCaption(occasion); });

    actions.appendChild(posterBtn);
    actions.appendChild(captionBtn);

    card.appendChild(top);
    card.appendChild(dateEl);
    card.appendChild(actions);
    return card;
  }

  function render() {
    var grid = document.getElementById('campaignCalendarGrid');
    if (!grid) return;
    grid.innerHTML = '';

    var upcoming = OCCASIONS.map(function(o) {
      var date = o.getDate();
      return { occasion: o, date: date, days: daysUntil(date) };
    }).filter(function(item) {
      return item.occasion.recurring || item.days <= 90;
    }).sort(function(a, b) { return a.days - b.days; });

    upcoming.forEach(function(item) {
      grid.appendChild(makeCard(item.occasion, item.date, item.days));
    });
  }

  window.initCampaignCalendar = function() {
    render();
  };
})();
