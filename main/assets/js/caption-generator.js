// Marketing > Social Captions
// Ready-to-post captions (Instagram / Facebook / WhatsApp) made in the browser from a post type,
// the dish or offer, details and a tone, in English, Hinglish or Hindi, with as many emojis as
// the owner likes. Every caption gets the tracked menu link (marketing-studio.js MK.link) and the
// brand hashtags. Results are editable before copying or saving.
// window.mkCaptions(opts) is also used by the poster's "Caption to post with it" box.
(function() {
  'use strict';

  var EMOJI = {
    new_item: ['🆕', '😋', '🍽️'], discount: ['🔥', '💰', '🎉'], combo: ['🍱', '👯', '🎊'], happy_hour: ['🍻', '⏰', '🥂'],
    weekend: ['🎉', '📅', '✨'], festive: ['🎊', '🪔', '✨'], now_open: ['🎉', '🍽️', '📣'], we_deliver: ['🛵', '📦', '🔥'],
    testimonial: ['⭐', '🙌', '❤️'], general: ['✨', '👀', '📣']
  };

  var HASHTAG_SETS = {
    new_item: ['#NewOnTheMenu', '#Foodie', '#MustTry', '#FreshFlavors'],
    discount: ['#Offer', '#Deal', '#LimitedTime', '#FoodieDeals'],
    combo: ['#ComboDeal', '#ValueForMoney', '#MealForTwo', '#Foodie'],
    happy_hour: ['#HappyHour', '#GoodTimes', '#Cheers', '#Foodie'],
    weekend: ['#WeekendSpecial', '#WeekendVibes', '#Foodie', '#TreatYourself'],
    festive: ['#FestiveSpecial', '#Celebration', '#GoodFood', '#Foodie'],
    now_open: ['#GrandOpening', '#NowOpen', '#NewInTown', '#Foodie'],
    we_deliver: ['#WeDeliver', '#OrderNow', '#FoodDelivery', '#Foodie'],
    testimonial: ['#CustomerLove', '#Foodie', '#GoodFood', '#Reviews'],
    general: ['#Foodie', '#EatLocal', '#GoodFood', '#Yum']
  };

  function pick(v, turn) { return Array.isArray(v) ? v[(turn != null ? turn : Math.floor(Math.random() * 7)) % v.length] : v; }
  function restaurantName() { return window.restaurantName || 'our restaurant'; }
  function brand() { return window.MK ? MK.brandNow() : {}; }
  function d1(detail) { return detail ? detail.replace(/[.!\s]+$/, '') + '. ' : ''; }

  // ---------- English ----------
  var EN = {
    new_item: function(s, d, n) { var x = s || 'our newest dish'; return {
      fun: [x + ' just landed on our menu at ' + n + '! ' + d1(d) + 'Come say hi to your new favourite. 😋🆕', 'Say hello to ' + x + ', now at ' + n + '! ' + d1(d) + 'Your taste buds will thank you. 😋'],
      elegant: ['Introducing ' + x + ', now served at ' + n + '. ' + d1(d) + 'An addition worth savouring.', n + ' proudly presents ' + x + '. ' + d1(d) + 'Made with care, ready to be enjoyed.'],
      urgent: ['NEW: ' + x + ' is here! ' + d1(d) + 'Be the first to try it. 🔥', 'Just dropped: ' + x + ' at ' + n + '. ' + d1(d) + 'Get it before everyone else does! 🔥'] }; },
    discount: function(s, d, n) { var x = s || 'a special offer'; return {
      fun: ['Guess what? ' + x + ' at ' + n + '! ' + d1(d) + 'Tag someone who needs this. 🎉', x + ' is on at ' + n + '! ' + d1(d) + 'You know what to do. 🎉'],
      elegant: [n + ' is pleased to offer ' + x + '. ' + d1(d) + 'We look forward to serving you.', 'Enjoy ' + x + ', only at ' + n + '. ' + d1(d) + 'We hope to see you soon.'],
      urgent: ["Don't miss it! " + x + ' at ' + n + '. ' + d1(d) + 'The offer won’t last long! ⏳🔥', 'Last chance: ' + x + ' at ' + n + '. ' + d1(d) + 'Once it’s gone, it’s gone! ⏳'] }; },
    combo: function(s, d, n) { var x = s || 'our combo meal'; return {
      fun: ['Double the flavour with ' + x + ' at ' + n + '! ' + d1(d) + 'Perfect for sharing (or not 😉).', x + ' at ' + n + ' hits different. ' + d1(d) + 'Grab a friend and dig in! 🍱'],
      elegant: ['Discover ' + x + ' at ' + n + ', paired for the perfect meal. ' + d1(d), n + ' presents ' + x + ', a pairing made for each other. ' + d1(d)],
      urgent: [x + ' at ' + n + '. ' + d1(d) + 'Grab it before the deal is gone! 🍱🔥', 'Only while it lasts: ' + x + ' at ' + n + '. ' + d1(d) + 'Order now! 🔥'] }; },
    happy_hour: function(s, d, n) { var x = s || 'Happy Hour'; return {
      fun: ['It’s ' + x + ' time at ' + n + '! ' + d1(d) + 'Grab your friends and unwind with us. 🍻', x + ' is calling your name at ' + n + '! ' + d1(d) + 'See you there. 🍻'],
      elegant: ['Join us for ' + x + ' at ' + n + '. ' + d1(d) + 'The perfect way to end your day.', 'Unwind in style with ' + x + ' at ' + n + '. ' + d1(d)],
      urgent: [x + ' is LIVE at ' + n + '! ' + d1(d) + 'Hurry, it won’t last all night! ⏰', 'The clock’s ticking on ' + x + ' at ' + n + '. ' + d1(d) + 'Get here before it ends! ⏰'] }; },
    weekend: function(s, d, n) { var x = s || 'our weekend special'; return {
      fun: ['Weekend plans? Sorted. ' + x + ' at ' + n + '! ' + d1(d) + 'See you there. 🎉', x + ' is here to make your weekend better. ' + d1(d) + 'Come hang out at ' + n + '! 🎉'],
      elegant: [n + ' invites you to enjoy ' + x + ' this weekend. ' + d1(d), 'Make this weekend memorable with ' + x + ' at ' + n + '. ' + d1(d)],
      urgent: ['This weekend only: ' + x + ' at ' + n + '! ' + d1(d) + 'Book your table now! 🔥', x + ' ends Sunday. ' + d1(d) + 'Don’t miss it! 🔥'] }; },
    festive: function(s, d, n) { var x = s || 'the festival'; return {
      fun: ['Celebrating ' + x + '? Let ' + n + ' handle the food! ' + d1(d) + '🎊🥳', x + ' is better with good food. ' + d1(d) + 'Come celebrate with us at ' + n + '! 🎊'],
      elegant: ['Wishing you a joyful ' + x + ', from all of us at ' + n + '. ' + d1(d), n + ' celebrates ' + x + ' with a special menu made for the occasion. ' + d1(d)],
      urgent: [x + ' special at ' + n + '. ' + d1(d) + 'Tables are filling up fast, book today! 🎈', 'Order early for ' + x + ' at ' + n + '. ' + d1(d) + 'Limited festive slots! 🎈'] }; },
    now_open: function(s, d, n) { var x = s || n; return {
      fun: ['We just opened our doors! Come find us and say hi to ' + x + '. ' + d1(d) + '🎉', x + ' is officially OPEN! ' + d1(d) + 'Come be one of our first guests! 🎉'],
      elegant: [x + ' is now open and ready to welcome you. ' + d1(d), 'We are delighted to announce the opening of ' + x + '. ' + d1(d)],
      urgent: ['NOW OPEN: ' + x + '! ' + d1(d) + 'Celebrate our launch with us today! 📣', x + ' is open. ' + d1(d) + 'Don’t miss our opening week! 📣'] }; },
    we_deliver: function(s, d, n) { var x = s || 'your favourites'; return {
      fun: ['Craving ' + x + '? We’ll bring it to you! ' + d1(d) + 'Order from ' + n + ' today. 🛵', 'No need to leave the couch: ' + x + ' from ' + n + ', straight to your door. ' + d1(d) + '🛵'],
      elegant: [n + ' now delivers ' + x + ' straight to your door. ' + d1(d), 'Enjoy ' + x + ' from ' + n + ', delivered fresh wherever you are. ' + d1(d)],
      urgent: ['Order ' + x + ' now, ' + n + ' delivers fast! ' + d1(d) + '🛵🔥', 'Hungry? ' + x + ' from ' + n + ' is a few taps away. ' + d1(d) + 'Order now! 🛵'] }; },
    testimonial: function(s, d, n) { var x = s || 'the best food in town'; return {
      fun: ['Our guests said it best: “' + x + '.” ' + d1(d) + 'Come see for yourself! ⭐', '“' + x + '”. That’s what people are saying about ' + n + '! ' + d1(d) + '🙌'],
      elegant: ['“' + x + '”. A review that means the world to us at ' + n + '. ' + d1(d), 'We’re grateful for reviews like this one: “' + x + '.” ' + d1(d)],
      urgent: ['People can’t stop talking about ' + n + ': “' + x + '.” ' + d1(d) + 'Find out why, visit today! ⭐', '“' + x + '” Come taste what everyone’s raving about. ' + d1(d) + '⭐'] }; },
    general: function(s, d, n) { var x = s || 'something special'; return {
      fun: [n + ' has ' + x + ' waiting for you! ' + d1(d) + 'Come check it out. ✨', x + ' at ' + n + '! ' + d1(d) + 'You won’t want to miss it. ✨'],
      elegant: ['Experience ' + x + ' at ' + n + '. ' + d1(d), n + ' invites you to experience ' + x + '. ' + d1(d)],
      urgent: [x + ' at ' + n + '. ' + d1(d) + 'You don’t want to miss this! 👀', 'Heads up: ' + x + ' at ' + n + '. ' + d1(d) + 'Be quick! 👀'] }; }
  };

  // ---------- Hinglish ----------
  var HG = {
    new_item: function(s, d, n) { var x = s || 'humari nayi dish'; return {
      fun: [x + ' aa gaya hai ' + n + ' ke menu par! ' + d1(d) + 'Ek baar try karo, pakka favourite banega. 😋🆕', 'Naya kya hai? ' + x + '! ' + d1(d) + 'Aaj hi ' + n + ' par taste karo. 😋'],
      elegant: ['Pesh hai ' + x + ', ab ' + n + ' par. ' + d1(d) + 'Pyaar se banaya, aapke liye.'],
      urgent: ['NEW: ' + x + ' aa gaya! ' + d1(d) + 'Sabse pehle try karo. 🔥'] }; },
    discount: function(s, d, n) { var x = s || 'special offer'; return {
      fun: [n + ' par ' + x + '! ' + d1(d) + 'Dosto ko tag karo jinhe ye chahiye. 🎉', 'Offer alert! ' + x + ' ' + n + ' par. ' + d1(d) + 'Ab wait kis baat ka? 🎉'],
      elegant: [n + ' laaya hai ' + x + '. ' + d1(d) + 'Aapka intezaar rahega.'],
      urgent: ['Jaldi karo! ' + x + ' sirf kuch dino ke liye. ' + d1(d) + 'Offer khatam hone se pehle order karo! ⏳🔥'] }; },
    combo: function(s, d, n) { var x = s || 'combo meal'; return {
      fun: [x + ' ' + n + ' par, double maza! ' + d1(d) + 'Dost ke saath share karo (ya mat karo 😉).', 'Bhook badi hai? ' + x + ' try karo. ' + d1(d) + '🍱'],
      elegant: [x + ', ek perfect meal ' + n + ' par. ' + d1(d)],
      urgent: [x + ' ka deal sirf thode time ke liye! ' + d1(d) + 'Abhi order karo! 🍱🔥'] }; },
    happy_hour: function(s, d, n) { var x = s || 'Happy Hour'; return {
      fun: [x + ' shuru ho gaya ' + n + ' par! ' + d1(d) + 'Dosto ko bulao aur chill karo. 🍻'],
      elegant: ['Din ka perfect end: ' + x + ' ' + n + ' par. ' + d1(d)],
      urgent: [x + ' LIVE hai! ' + d1(d) + 'Time khatam hone se pehle aa jao! ⏰'] }; },
    weekend: function(s, d, n) { var x = s || 'weekend special'; return {
      fun: ['Weekend plan sorted! ' + x + ' ' + n + ' par. ' + d1(d) + 'Milte hain! 🎉', 'Is weekend kuch tasty ho jaaye? ' + x + '! ' + d1(d) + '🎉'],
      elegant: ['Is weekend aaiye ' + n + ' par, ' + x + ' ke liye. ' + d1(d)],
      urgent: ['Sirf is weekend: ' + x + '! ' + d1(d) + 'Table abhi book karo! 🔥'] }; },
    festive: function(s, d, n) { var x = s || 'tyohaar'; return {
      fun: [x + ' ki dher saari shubhkamnayein! 🪔 ' + d1(d) + 'Khane ki tension ' + n + ' par chhod do. 🎊', x + ' ka maza good food ke saath double! ' + d1(d) + 'Celebrate karo ' + n + ' ke saath. 🎊'],
      elegant: [n + ' ki taraf se aapko ' + x + ' ki hardik shubhkamnayein. ' + d1(d)],
      urgent: [x + ' special sirf kuch dino ke liye! ' + d1(d) + 'Pehle se order ya booking kar lo! 🎈'] }; },
    now_open: function(s, d, n) { var x = s || n; return {
      fun: [x + ' ab khul gaya hai! ' + d1(d) + 'Aao aur humse milo. 🎉'],
      elegant: ['Humein batate hue khushi ho rahi hai ki ' + x + ' ab open hai. ' + d1(d)],
      urgent: ['NOW OPEN: ' + x + '! ' + d1(d) + 'Opening week miss mat karna! 📣'] }; },
    we_deliver: function(s, d, n) { var x = s || 'aapka favourite khana'; return {
      fun: [x + ' ki craving? Hum ghar tak pahuncha denge! ' + d1(d) + '🛵', 'Couch chhodne ki zarurat nahi, ' + n + ' deliver karta hai. ' + d1(d) + '🛵'],
      elegant: [n + ' ab ' + x + ' aapke ghar tak deliver karta hai. ' + d1(d)],
      urgent: ['Bhook lagi? ' + x + ' bas kuch taps door! ' + d1(d) + 'Abhi order karo! 🛵🔥'] }; },
    testimonial: function(s, d, n) { var x = s || 'yahan ka khana best hai'; return {
      fun: ['Humare guests ka kehna hai: “' + x + '.” ' + d1(d) + 'Khud aake dekho! ⭐'],
      elegant: ['Aise reviews ke liye shukriya: “' + x + '.” ' + d1(d)],
      urgent: ['Sab ' + n + ' ki baat kar rahe hain: “' + x + '.” ' + d1(d) + 'Aaj hi aao! ⭐'] }; },
    general: function(s, d, n) { var x = s || 'kuch special'; return {
      fun: [n + ' par aapke liye ' + x + '! ' + d1(d) + 'Zaroor check karo. ✨'],
      elegant: [n + ' par ' + x + ' ka anubhav lijiye. ' + d1(d)],
      urgent: ['Dhyan do: ' + x + ' ' + n + ' par! ' + d1(d) + 'Miss mat karna! 👀'] }; }
  };

  // ---------- Hindi ----------
  var HI = {
    new_item: function(s, d, n) { var x = s || 'हमारी नई डिश'; return {
      fun: [x + ' अब ' + n + ' के मेन्यू पर! ' + d1(d) + 'एक बार चखिए, आपकी पसंदीदा बन जाएगी। 😋🆕'],
      elegant: ['पेश है ' + x + ', अब ' + n + ' पर। ' + d1(d) + 'प्यार से बनाई, आपके लिए।'],
      urgent: ['नया: ' + x + ' आ गया है! ' + d1(d) + 'सबसे पहले चखिए। 🔥'] }; },
    discount: function(s, d, n) { var x = s || 'खास ऑफर'; return {
      fun: [n + ' पर ' + x + '! ' + d1(d) + 'अपने दोस्तों को भी बताइए। 🎉'],
      elegant: [n + ' लाया है ' + x + '। ' + d1(d) + 'आपका इंतज़ार रहेगा।'],
      urgent: ['जल्दी कीजिए! ' + x + ' सिर्फ कुछ दिनों के लिए। ' + d1(d) + 'ऑफर खत्म होने से पहले ऑर्डर करें! ⏳'] }; },
    combo: function(s, d, n) { var x = s || 'कॉम्बो मील'; return {
      fun: [x + ', ' + n + ' पर दोगुना मज़ा! ' + d1(d) + '🍱'], elegant: [x + ', ' + n + ' पर एक संपूर्ण भोजन। ' + d1(d)],
      urgent: [x + ' का ऑफर थोड़े समय के लिए! ' + d1(d) + 'अभी ऑर्डर करें! 🔥'] }; },
    happy_hour: function(s, d, n) { var x = s || 'हैप्पी आवर'; return {
      fun: [n + ' पर ' + x + ' शुरू! ' + d1(d) + 'दोस्तों के साथ आइए। 🍻'], elegant: ['दिन का सबसे अच्छा अंत: ' + x + ', ' + n + ' पर। ' + d1(d)],
      urgent: [x + ' अभी चल रहा है! ' + d1(d) + 'समय खत्म होने से पहले आइए! ⏰'] }; },
    weekend: function(s, d, n) { var x = s || 'वीकेंड स्पेशल'; return {
      fun: ['वीकेंड का प्लान तैयार! ' + x + ', ' + n + ' पर। ' + d1(d) + '🎉'], elegant: ['इस वीकेंड ' + n + ' पर आइए, ' + x + ' के लिए। ' + d1(d)],
      urgent: ['सिर्फ इस वीकेंड: ' + x + '! ' + d1(d) + 'अभी टेबल बुक करें! 🔥'] }; },
    festive: function(s, d, n) { var x = s || 'त्योहार'; return {
      fun: [x + ' की हार्दिक शुभकामनाएँ! 🪔 ' + d1(d) + 'खाने की चिंता ' + n + ' पर छोड़िए। 🎊'], elegant: [n + ' की ओर से आपको ' + x + ' की हार्दिक शुभकामनाएँ। ' + d1(d)],
      urgent: [x + ' स्पेशल कुछ ही दिनों के लिए! ' + d1(d) + 'पहले से ऑर्डर या बुकिंग करें! 🎈'] }; },
    now_open: function(s, d, n) { var x = s || n; return {
      fun: [x + ' अब खुल गया है! ' + d1(d) + 'आइए, हमसे मिलिए। 🎉'], elegant: ['हमें यह बताते हुए खुशी है कि ' + x + ' अब खुल गया है। ' + d1(d)],
      urgent: ['अब खुला है: ' + x + '! ' + d1(d) + 'ओपनिंग वीक मिस न करें! 📣'] }; },
    we_deliver: function(s, d, n) { var x = s || 'आपका पसंदीदा खाना'; return {
      fun: [x + ' खाने का मन है? हम घर तक पहुँचाएँगे! ' + d1(d) + '🛵'], elegant: [n + ' अब ' + x + ' आपके घर तक पहुँचाता है। ' + d1(d)],
      urgent: ['भूख लगी है? ' + x + ' बस कुछ टैप दूर! ' + d1(d) + 'अभी ऑर्डर करें! 🛵'] }; },
    testimonial: function(s, d, n) { var x = s || 'यहाँ का खाना सबसे अच्छा है'; return {
      fun: ['हमारे मेहमानों का कहना है: “' + x + '।” ' + d1(d) + 'खुद आकर देखिए! ⭐'], elegant: ['ऐसी समीक्षाओं के लिए धन्यवाद: “' + x + '।” ' + d1(d)],
      urgent: ['सब ' + n + ' की बात कर रहे हैं: “' + x + '।” ' + d1(d) + 'आज ही आइए! ⭐'] }; },
    general: function(s, d, n) { var x = s || 'कुछ खास'; return {
      fun: [n + ' पर आपके लिए ' + x + '! ' + d1(d) + '✨'], elegant: [n + ' पर ' + x + ' का आनंद लीजिए। ' + d1(d)],
      urgent: ['ध्यान दें: ' + x + ', ' + n + ' पर! ' + d1(d) + 'मौका न चूकें! 👀'] }; }
  };
  var BANKS = { en: EN, hinglish: HG, hi: HI };
  // the offer, in the caption's language
  var USE_CODE = { en: function(c, l) { return 'Use code ' + c + (l ? ' for ' + l : ''); }, hinglish: function(c, l) { return 'Code ' + c + ' lagao' + (l ? ' aur paao ' + l : ''); }, hi: function(c, l) { return 'कोड ' + c + ' लगाइए' + (l ? ' और पाइए ' + l : ''); } };
  var CTA = { en: ['Order now: ', 'Order here: '], hinglish: ['Abhi order karein: ', 'Order yahan karein: '], hi: ['अभी ऑर्डर करें: ', 'यहाँ ऑर्डर करें: '] };

  var EMOJI_RE = /(\p{Extended_Pictographic}|[‍️]|[\u{1F3FB}-\u{1F3FF}])/gu;
  function applyEmoji(text, level, type, turn) {
    var e = EMOJI[type] || EMOJI.general;
    if (level === 'none') return text.replace(EMOJI_RE, '').replace(/[ \t]+\n/g, '\n').replace(/ {2,}/g, ' ').replace(/\s+([.!?,])/g, '$1').trim();
    if (level === 'lots') return e[turn % e.length] + ' ' + text.trim() + ' ' + e.join('');
    return text.trim();
  }
  function capFirst(s) { s = String(s || '').trim(); return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

  function hashtagLine(postType, subject) {
    var own = String(brand().hashtags || '').split(/[\s,]+/).filter(function(t) { return t; }).map(function(t) { return t.charAt(0) === '#' ? t : '#' + t; });
    var tags = own.concat((HASHTAG_SETS[postType] || HASHTAG_SETS.general).slice());
    var clean = String(subject || '').replace(/[^a-zA-Z0-9]/g, '');
    if (clean && clean.length <= 30) tags.splice(own.length, 0, '#' + clean);
    var seen = {};
    return tags.filter(function(t) { var k = t.toLowerCase(); if (seen[k]) return false; seen[k] = 1; return true; }).slice(0, 7).join(' ');
  }

  /**
   * opts: { type, subject, detail, coupon: {code, label}, tone ('fun'|'elegant'|'urgent'), lang ('en'|'hinglish'|'hi'), emoji ('none'|'few'|'lots'), link, turn }
   * Returns three captions, the chosen tone first.
   */
  window.mkCaptions = function(o) {
    o = o || {};
    var lang = BANKS[o.lang] ? o.lang : (BANKS[brand().language] ? brand().language : 'en');
    var bank = BANKS[lang], type = bank[o.type] ? o.type : 'general';
    var turn = o.turn != null ? o.turn : Math.floor(Math.random() * 6);
    var detail = (o.detail || '').trim();
    if (o.coupon && o.coupon.code) detail = (detail ? detail.replace(/[.!s]+$/, '') + '. ' : '') + USE_CODE[lang](o.coupon.code, o.coupon.label);
    var v = bank[type]((o.subject || '').trim(), detail, restaurantName());
    var order = o.tone === 'elegant' ? ['elegant', 'fun', 'urgent'] : o.tone === 'urgent' ? ['urgent', 'fun', 'elegant'] : ['fun', 'urgent', 'elegant'];
    var link = o.link || (window.getMarketingMenuUrl ? window.getMarketingMenuUrl() : '');
    var tags = hashtagLine(type, o.subject);
    return order.map(function(k, i) {
      var body = capFirst(applyEmoji(pick(v[k], turn + i), o.emoji || 'few', type, turn + i));
      return body + '\n\n' + (link ? pick(CTA[lang], turn) + link : '') + '\n' + tags;
    });
  };

  // ---------- the Captions page ----------
  var turn = 0, savedId = null;
  function val(id) { return ((document.getElementById(id) || {}).value || ''); }
  function currentLink(subject) {
    if (!window.MK) return '';
    var c = MK.campaign();
    return MK.link({ src: MK.srcFor(subject || val('captionPostType')), coupon: c && c.coupon ? c.coupon.code : '' });
  }

  function makeCard(text, i) {
    var card = document.createElement('div');
    card.className = 'caption-card';
    card.innerHTML = '<div class="caption-card-top"><span>Option ' + (i + 1) + '</span><small>Edit anything before copying</small></div>';
    var body = document.createElement('textarea');
    body.className = 'caption-card-text';
    body.rows = Math.min(10, text.split('\n').length + 3);
    body.value = text;
    card.appendChild(body);

    var actions = document.createElement('div');
    actions.className = 'caption-card-actions';
    var btn = function(icon, label, cls, fn) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'mk-btn sm ' + (cls || '');
      b.innerHTML = '<span class="material-symbols-rounded">' + icon + '</span>' + label;
      b.addEventListener('click', function() { fn(b); });
      actions.appendChild(b);
    };
    btn('content_copy', 'Copy', '', function(b) { if (window.MK) MK.copy(body.value, b); else navigator.clipboard.writeText(body.value); });
    btn('share', 'WhatsApp', 'wa', function() { window.open('https://wa.me/?text=' + encodeURIComponent(body.value), '_blank', 'noopener'); });
    btn('save', 'Save', 'ghost', function(b) { saveCaption(body.value, b); });
    card.appendChild(actions);
    return card;
  }

  function saveCaption(text, b) {
    if (!window.MK) return;
    var subject = val('captionSubject'), c = MK.campaign();
    b.disabled = true;
    MK.saveDesign({ id: savedId, kind: 'caption', name: (c ? c.name + ' · ' : '') + (subject || text.split(/\n/)[0].slice(0, 40) || 'Caption'), src: MK.srcFor(subject || val('captionPostType')),
      coupon_code: c && c.coupon ? c.coupon.code : '', state: { text: text, type: val('captionPostType'), subject: subject, detail: val('captionDetail'), tone: val('captionTone'), lang: val('captionLang'), emoji: val('captionEmoji') } })
      .then(function(id) { b.disabled = false; if (id) savedId = id; });
  }

  // a dish name when the owner left it empty, so the caption is never about "a dish"
  var DISH_TYPES = { new_item: 1, combo: 1, we_deliver: 1 };
  function ensureSubject() {
    var el = document.getElementById('captionSubject');
    if (!el || el.value.trim() || !DISH_TYPES[val('captionPostType')] || !window.MK) return Promise.resolve();
    return MK.dishes().then(function(list) {
      var d = list.filter(function(x) { return x.src; })[0] || list[0];
      if (d && !el.value.trim()) { el.value = d.name; if (d.price && !val('captionDetail')) document.getElementById('captionDetail').value = 'Now ' + MK.cur() + Math.round(d.price); }
    });
  }

  window.generateCaptions = function(again) {
    var container = document.getElementById('captionResultsContainer');
    if (!container) return;
    if (again) turn++;
    savedId = null;
    ensureSubject().then(function() {
      var subject = val('captionSubject');
      var c = window.MK && MK.campaign();
      var detail = val('captionDetail');
      var list = window.mkCaptions({ type: val('captionPostType') || 'general', subject: subject, detail: detail, coupon: c && c.coupon && detail.indexOf(c.coupon.code) < 0 ? c.coupon : null, tone: val('captionTone') || 'fun',
        lang: val('captionLang'), emoji: val('captionEmoji') || 'few', link: currentLink(subject), turn: turn });
      container.innerHTML = '';
      list.forEach(function(text, i) { container.appendChild(makeCard(text, i)); });
    });
  };

  // extra controls (language, emojis) and dish suggestions on the form
  function enhanceForm() {
    var tone = document.getElementById('captionTone');
    if (!tone || document.getElementById('captionLang')) return;
    var row = document.createElement('div');
    row.className = 'mk-row';
    row.innerHTML = '<div class="poster-field"><label for="captionLang">Language</label><select id="captionLang"><option value="en">English</option><option value="hinglish">Hinglish</option><option value="hi">हिन्दी (Hindi)</option></select></div>' +
      '<div class="poster-field"><label for="captionEmoji">Emojis</label><select id="captionEmoji"><option value="none">None</option><option value="few" selected>A few</option><option value="lots">Lots</option></select></div>';
    tone.closest('.poster-field').after(row);
    var lang = document.getElementById('captionLang');
    lang.value = (brand().language) || 'en';
    if (window.MK) MK.brand().then(function(b) { if (!lang.dataset.touched) lang.value = b.language || 'en'; });
    lang.addEventListener('change', function() { lang.dataset.touched = 1; });
    var subj = document.getElementById('captionSubject');
    if (subj && window.MK) {
      subj.setAttribute('list', 'captionDishList');
      subj.placeholder = 'Type or pick a dish, e.g. Butter Chicken';
      var dl = document.createElement('datalist'); dl.id = 'captionDishList'; subj.after(dl);
      MK.dishes().then(function(list) { dl.innerHTML = list.map(function(d) { return '<option value="' + MK.esc(d.name) + '">'; }).join(''); });
    }
    ['captionPostType', 'captionTone', 'captionLang', 'captionEmoji'].forEach(function(id) { var el = document.getElementById(id); if (el) el.addEventListener('change', function() { window.generateCaptions(); }); });
  }

  window.prefillCaptions = function(postType, subject, detail, tone) {
    window.initCaptionStudio();
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

  window.openCaptionDesign = function(design) {
    window.initCaptionStudio();
    var s = design.state || {};
    var set = function(id, v) { var el = document.getElementById(id); if (el && v != null) el.value = v; };
    set('captionPostType', s.type); set('captionSubject', s.subject); set('captionDetail', s.detail); set('captionTone', s.tone); set('captionLang', s.lang); set('captionEmoji', s.emoji);
    var container = document.getElementById('captionResultsContainer');
    if (container) { container.innerHTML = ''; container.appendChild(makeCard(s.text || '', 0)); }
    savedId = design.id;
  };

  window.initCaptionStudio = function() {
    if (window._captionStudioInited) return;
    window._captionStudioInited = true;
    enhanceForm();
    document.addEventListener('mk:campaign', function() { if (document.getElementById('marketingCaptionsPage').classList.contains('active')) window.generateCaptions(); });
    window.generateCaptions();
  };
})();
