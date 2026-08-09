// Marketing > Social Captions
// Generates ready-to-post captions (Instagram/Facebook/WhatsApp) entirely
// client-side from a post type, subject, detail and tone. No server cost.
// Each tone maps to a small array of phrasings; buildCaptions() picks one
// at random per generate so re-running the tool yields fresh wording.
(function() {
  'use strict';

  var EMOJI = {
    new_item: ['🆕', '😋', '🍽️'],
    discount: ['🔥', '💰', '🎉'],
    combo: ['🍱', '👯', '🎊'],
    happy_hour: ['🍻', '⏰', '🥂'],
    weekend: ['🎉', '📅', '✨'],
    festive: ['🎊', '🥳', '🎈'],
    now_open: ['🎉', '🍽️', '📣'],
    we_deliver: ['🛵', '📦', '🔥'],
    testimonial: ['⭐', '🙌', '❤️'],
    general: ['✨', '👀', '📣']
  };

  var HASHTAG_SETS = {
    new_item: ['#NewOnTheMenu', '#Foodie', '#MustTry', '#FreshFlavors'],
    discount: ['#Offer', '#Deal', '#LimitedTime', '#FoodieDeals'],
    combo: ['#ComboDeal', '#ValueForMoney', '#MealForTwo', '#Foodie'],
    happy_hour: ['#HappyHour', '#DrinksOnUs', '#GoodTimes', '#Cheers'],
    weekend: ['#WeekendSpecial', '#WeekendVibes', '#Foodie', '#TreatYourself'],
    festive: ['#Celebration', '#FestiveSpecial', '#GoodFood', '#Foodie'],
    now_open: ['#GrandOpening', '#NowOpen', '#NewInTown', '#Foodie'],
    we_deliver: ['#WeDeliver', '#OrderNow', '#FoodDelivery', '#Foodie'],
    testimonial: ['#CustomerLove', '#Foodie', '#GoodFood', '#Testimonial'],
    general: ['#Foodie', '#EatLocal', '#GoodFood', '#Yum']
  };

  function pickVariant(v) {
    if (Array.isArray(v)) return v[Math.floor(Math.random() * v.length)];
    return v;
  }

  function restaurantName() { return window.restaurantName || 'our restaurant'; }

  function menuUrl() {
    try { return window.getMarketingMenuUrl ? window.getMarketingMenuUrl() : ''; }
    catch (e) { return ''; }
  }

  function hashtagLine(postType, subject) {
    var tags = (HASHTAG_SETS[postType] || HASHTAG_SETS.general).slice();
    if (subject) {
      var clean = String(subject).replace(/[^a-zA-Z0-9]/g, '');
      if (clean) tags.unshift('#' + clean);
    }
    return tags.slice(0, 5).join(' ');
  }

  function ctaLine() {
    var url = menuUrl();
    return url ? 'Order now: ' + url : 'Order now - link in bio!';
  }

  // Each builder returns {fun, elegant, urgent}; each value is a string or
  // an array of alternate phrasings (picked at random per generate).
  var BUILDERS = {
    new_item: function(subject, detail, name) {
      var dish = subject || 'a brand new dish';
      return {
        fun: [
          dish + ' just landed on our menu at ' + name + '! ' + (detail ? detail + ' ' : '') + 'Come say hi to your new favorite. 😋🆕',
          'Say hello to ' + dish + ', now at ' + name + '! ' + (detail ? detail + ' ' : '') + 'Your taste buds will thank you. 😋'
        ],
        elegant: [
          'Introducing ' + dish + ', now served at ' + name + '. ' + (detail ? detail + '. ' : '') + 'An addition worth savoring.',
          name + ' proudly presents ' + dish + '. ' + (detail ? detail + '. ' : '') + 'Crafted with care, ready to be enjoyed.'
        ],
        urgent: [
          'NEW: ' + dish + ' is here! ' + (detail ? detail + '. ' : '') + "Don't wait - come taste it before everyone else does. 🔥",
          'Just dropped: ' + dish + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Get in before it becomes the talk of the town! 🔥'
        ]
      };
    },
    discount: function(subject, detail, name) {
      var offer = subject || 'a great deal';
      return {
        fun: [
          'Guess what? ' + offer + ' at ' + name + '! ' + (detail ? detail + ' ' : '') + 'Tag someone who needs this. 🎉',
          offer + ' is happening at ' + name + '! ' + (detail ? detail + ' ' : '') + 'You know what to do. 🎉'
        ],
        elegant: [
          name + ' is pleased to offer ' + offer + '. ' + (detail ? detail + '. ' : '') + 'We look forward to serving you.',
          'Enjoy ' + offer + ', exclusively at ' + name + '. ' + (detail ? detail + '. ' : '') + 'We hope to see you soon.'
        ],
        urgent: [
          "Don't miss out! " + offer + ' at ' + name + ' - ' + (detail ? detail + '. ' : '') + 'Offer won’t last long! ⏳🔥',
          'Last chance: ' + offer + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Once it’s gone, it’s gone! ⏳'
        ]
      };
    },
    combo: function(subject, detail, name) {
      var combo = subject || 'our combo deal';
      return {
        fun: [
          'Double the flavor with ' + combo + ' at ' + name + '! ' + (detail ? detail + ' ' : '') + 'Perfect for sharing (or not 😉).',
          combo + ' at ' + name + ' hits different. ' + (detail ? detail + ' ' : '') + 'Grab a friend and dig in! 🍱'
        ],
        elegant: [
          'Discover ' + combo + ' at ' + name + ', thoughtfully paired for the perfect meal. ' + (detail ? detail + '.' : ''),
          name + ' presents ' + combo + ' - a pairing made for each other. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          combo + ' at ' + name + ' - ' + (detail ? detail + '. ' : '') + 'Grab it before the deal is gone! 🍱🔥',
          'Only while it lasts: ' + combo + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Order now! 🔥'
        ]
      };
    },
    happy_hour: function(subject, detail, name) {
      var offer = subject || 'Happy Hour';
      return {
        fun: [
          'It’s ' + offer + ' time at ' + name + '! ' + (detail ? detail + '. ' : '') + 'Grab your friends and unwind with us. 🍻',
          offer + ' is calling your name at ' + name + '! ' + (detail ? detail + '. ' : '') + 'See you at the bar. 🍻'
        ],
        elegant: [
          'Join us for ' + offer + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'The perfect way to end your day.',
          'Unwind in style with ' + offer + ' at ' + name + '. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          offer + ' is LIVE at ' + name + '! ' + (detail ? detail + '. ' : '') + "Hurry, it won't last all night! ⏰",
          'Clock’s ticking on ' + offer + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Get here before it ends! ⏰'
        ]
      };
    },
    weekend: function(subject, detail, name) {
      var offer = subject || 'our weekend special';
      return {
        fun: [
          'Weekend plans? Sorted. ' + offer + ' at ' + name + '! ' + (detail ? detail + ' ' : '') + 'See you there. 🎉',
          offer + ' is here to make your weekend better. ' + (detail ? detail + ' ' : '') + 'Come hang out at ' + name + '! 🎉'
        ],
        elegant: [
          name + ' invites you to enjoy ' + offer + ' this weekend. ' + (detail ? detail + '.' : ''),
          'Make this weekend memorable with ' + offer + ' at ' + name + '. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          'Only THIS weekend: ' + offer + ' at ' + name + '! ' + (detail ? detail + '. ' : '') + 'Book your table now! 🔥',
          offer + ' ends Sunday. ' + (detail ? detail + '. ' : '') + "Don't sleep on it! 🔥"
        ]
      };
    },
    festive: function(subject, detail, name) {
      var occasion = subject || 'this celebration';
      return {
        fun: [
          'Celebrating ' + occasion + '? Let ' + name + ' handle the food! ' + (detail ? detail + ' ' : '') + '🎊🥳',
          occasion + ' hits different with good food. ' + (detail ? detail + ' ' : '') + 'Come celebrate with us at ' + name + '! 🎊'
        ],
        elegant: [
          name + ' warmly celebrates ' + occasion + ' with a special menu crafted just for the occasion. ' + (detail ? detail + '.' : ''),
          'Wishing you a wonderful ' + occasion + ', from all of us at ' + name + '. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          occasion + ' special at ' + name + ' - ' + (detail ? detail + '. ' : '') + 'Limited seats, reserve today! 🎈',
          'Book now for ' + occasion + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Tables are filling up fast! 🎈'
        ]
      };
    },
    now_open: function(subject, detail, name) {
      var place = subject || name;
      return {
        fun: [
          'We just opened our doors! Come find us and say hi to ' + place + '. ' + (detail ? detail + ' ' : '') + '🎉',
          place + ' is officially OPEN! ' + (detail ? detail + ' ' : '') + 'Come be one of our first guests! 🎉'
        ],
        elegant: [
          place + ' is now open and ready to welcome you. ' + (detail ? detail + '.' : ''),
          'We are delighted to announce the opening of ' + place + '. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          'NOW OPEN: ' + place + '! ' + (detail ? detail + '. ' : '') + 'Come celebrate our launch with us today! 📣',
          place + ' is open for business. ' + (detail ? detail + '. ' : '') + "Don't miss our opening week! 📣"
        ]
      };
    },
    we_deliver: function(subject, detail, name) {
      var thing = subject || 'your favorites';
      return {
        fun: [
          'Craving ' + thing + '? We’ll bring it to you! ' + (detail ? detail + ' ' : '') + 'Order from ' + name + ' today. 🛵',
          'No need to leave the couch - ' + thing + ' from ' + name + ' delivers straight to your door. 🛵 ' + (detail || '')
        ],
        elegant: [
          name + ' now delivers ' + thing + ' straight to your door. ' + (detail ? detail + '.' : ''),
          'Enjoy ' + thing + ' from ' + name + ', delivered fresh to wherever you are. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          'Order ' + thing + ' now - ' + name + ' delivers fast! ' + (detail ? detail + '. ' : '') + '🛵🔥',
          'Hungry? ' + thing + ' from ' + name + ' is just a few taps away. ' + (detail ? detail + '. ' : '') + 'Order now! 🛵'
        ]
      };
    },
    testimonial: function(subject, detail, name) {
      var quote = subject || 'the best food in town';
      return {
        fun: [
          'Our guests said it best: “' + quote + '.” ' + (detail ? detail + ' ' : '') + 'Come see for yourself! ⭐',
          '“' + quote + '” - that’s what people are saying about ' + name + '! ' + (detail ? detail + ' ' : '') + '🙌'
        ],
        elegant: [
          '“' + quote + '” - a review that means the world to us at ' + name + '. ' + (detail ? detail + '.' : ''),
          'We’re grateful for reviews like this one: “' + quote + '.” ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          'People can’t stop talking about ' + name + ': “' + quote + '.” ' + (detail ? detail + ' ' : '') + 'Find out why - visit today! ⭐',
          '“' + quote + '” Come taste what everyone’s raving about. ' + (detail ? detail + ' ' : '') + '⭐'
        ]
      };
    },
    general: function(subject, detail, name) {
      var thing = subject || 'something special';
      return {
        fun: [
          name + ' has ' + thing + ' waiting for you! ' + (detail ? detail + ' ' : '') + 'Come check it out. ✨',
          thing + ' is happening at ' + name + '! ' + (detail ? detail + ' ' : '') + 'You won’t want to miss it. ✨'
        ],
        elegant: [
          'Experience ' + thing + ' at ' + name + '. ' + (detail ? detail + '.' : ''),
          name + ' invites you to experience ' + thing + '. ' + (detail ? detail + '.' : '')
        ],
        urgent: [
          thing + ' at ' + name + ' - ' + (detail ? detail + '. ' : '') + "You don't want to miss this! 👀",
          'Heads up: ' + thing + ' at ' + name + '. ' + (detail ? detail + '. ' : '') + 'Act fast! 👀'
        ]
      };
    }
  };

  function buildCaptions(postType, subject, detail, tone) {
    var name = restaurantName();
    var builder = BUILDERS[postType] || BUILDERS.general;
    var variants = builder(subject.trim(), detail.trim(), name);
    var order = tone === 'elegant' ? ['elegant', 'fun', 'urgent']
      : tone === 'urgent' ? ['urgent', 'fun', 'elegant']
      : ['fun', 'urgent', 'elegant'];

    var tags = hashtagLine(postType, subject);
    var cta = ctaLine();

    return order.map(function(key) {
      return pickVariant(variants[key]) + '\n\n' + cta + '\n' + tags;
    });
  }

  function makeCard(text) {
    var card = document.createElement('div');
    card.className = 'caption-card';

    var body = document.createElement('div');
    body.className = 'caption-card-text';
    body.textContent = text;
    card.appendChild(body);

    var actions = document.createElement('div');
    actions.className = 'caption-card-actions';

    var copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.className = 'btn-secondary';
    copyBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:16px;">content_copy</span> Copy';
    copyBtn.addEventListener('click', function() {
      navigator.clipboard.writeText(text).then(function() {
        var original = copyBtn.innerHTML;
        copyBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:16px;">check</span> Copied';
        setTimeout(function() { copyBtn.innerHTML = original; }, 1500);
      }).catch(function() {
        if (window.showMessage) window.showMessage('Could not copy. Select and copy manually.', 'error');
      });
    });

    var waBtn = document.createElement('button');
    waBtn.type = 'button';
    waBtn.className = 'btn-secondary';
    waBtn.innerHTML = '<span class="material-symbols-rounded" style="font-size:16px;">share</span> Share on WhatsApp';
    waBtn.addEventListener('click', function() {
      window.open('https://wa.me/?text=' + encodeURIComponent(text), '_blank');
    });

    actions.appendChild(copyBtn);
    actions.appendChild(waBtn);
    card.appendChild(actions);
    return card;
  }

  window.generateCaptions = function() {
    var postType = (document.getElementById('captionPostType') || {}).value || 'general';
    var subject = (document.getElementById('captionSubject') || {}).value || '';
    var detail = (document.getElementById('captionDetail') || {}).value || '';
    var tone = (document.getElementById('captionTone') || {}).value || 'fun';

    var container = document.getElementById('captionResultsContainer');
    if (!container) return;
    container.innerHTML = '';

    var captions = buildCaptions(postType, subject, detail, tone);
    captions.forEach(function(text) { container.appendChild(makeCard(text)); });
  };

  window.prefillCaptions = function(postType, subject, detail, tone) {
    var typeEl = document.getElementById('captionPostType');
    var subjEl = document.getElementById('captionSubject');
    var detEl = document.getElementById('captionDetail');
    var toneEl = document.getElementById('captionTone');
    if (typeEl) typeEl.value = postType || 'general';
    if (subjEl) subjEl.value = subject || '';
    if (detEl) detEl.value = detail || '';
    if (toneEl) toneEl.value = tone || 'fun';
    window.generateCaptions();
  };

  window.initCaptionStudio = function() {
    if (window._captionStudioInited) return;
    window._captionStudioInited = true;
    generateCaptions();
  };
})();
