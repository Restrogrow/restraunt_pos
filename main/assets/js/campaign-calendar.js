// Marketing > Campaign Calendar
// Every occasion worth an offer: national days, food days, and the big festivals of India.
// Each card has a ready offer idea and opens the Poster Studio, Social Captions or Coupons,
// already filled in. Entirely client-side.
//
// Dates:
//  - fixed days (e.g. 26 Jan) and rule-based days (e.g. 2nd Sunday of May) work every year;
//  - Easter / Good Friday are calculated for any year;
//  - festivals that follow the moon (Diwali, Holi, Eid...) use the dates listed below, from the New Delhi
//    panchang (drikpanchang.com) and the Government of India holiday list, for 2026 to 2031. Eid and
//    Bakrid follow the moon sighting and can move by a day (the card says so).
//    ADD THE NEXT YEARS TO `dates` BEFORE THE LIST RUNS OUT (last year listed: 2031);
//    an occasion with no upcoming date simply doesn't show.
(function() {
  'use strict';

  function today0() { var n = new Date(); return new Date(n.getFullYear(), n.getMonth(), n.getDate()); }
  function nextFixedDate(month, day) {
    var t = today0(), d = new Date(t.getFullYear(), month - 1, day);
    if (d < t) d = new Date(t.getFullYear() + 1, month - 1, day);
    return d;
  }
  function nextNthWeekday(month, weekday, nth) {
    function calc(year) {
      var first = new Date(year, month - 1, 1);
      var offset = (weekday - first.getDay() + 7) % 7;
      return new Date(year, month - 1, 1 + offset + (nth - 1) * 7);
    }
    var t = today0(), d = calc(t.getFullYear());
    if (d < t) d = calc(t.getFullYear() + 1);
    return d;
  }
  function nextWeekday(weekday) {
    var t = today0(), d = new Date(t);
    d.setDate(t.getDate() + (weekday - t.getDay() + 7) % 7);
    return d;
  }
  // the first listed date that is today or later (null when none is known yet)
  function nextListed(dates) {
    var t = today0();
    for (var i = 0; i < dates.length; i++) {
      var p = dates[i].split('-'), d = new Date(+p[0], +p[1] - 1, +p[2]);
      if (d >= t) return d;
    }
    return null;
  }
  function daysUntil(date) { return Math.round((date - today0()) / 86400000); }
  function easter(y) {
    var a = y % 19, b = Math.floor(y / 100), c = y % 100, d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3),
      h = (19 * a + b - d - g + 15) % 30, i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451),
      month = Math.floor((h + l - 7 * m + 114) / 31), day = ((h + l - 7 * m + 114) % 31) + 1;
    return new Date(y, month - 1, day);
  }
  function nextEaster(offset) {
    var t = today0(), y = t.getFullYear();
    for (var k = 0; k < 3; k++) { var d = easter(y + k); d.setDate(d.getDate() + offset); if (d >= t) return d; }
    return null;
  }

  // f = fixed [month, day]; nth = [month, weekday (0 = Sun), nth]; dates = listed festival dates
  // type: festival / national / food / season (for the filter), moon: date depends on the moon
  var OCCASIONS = [
    // ── January ──
    { icon: 'celebration', name: "New Year's Day", f: [1, 1], type: 'national', idea: 'New Year brunch or a “first meal of the year” combo', poster: 'festive_special', fields: { headline: 'Happy New Year!', subtext: 'Ring in the new year with us', badge: 'New Year Special' }, cap: 'festive', subject: "New Year's Day", tone: 'fun' },
    { icon: 'local_fire_department', name: 'Lohri', f: [1, 13], type: 'festival', idea: 'Makki di roti & sarson da saag, gajak and rewari on the house', poster: 'festive_special', fields: { headline: 'Happy Lohri', subtext: 'Warm food for a festive night', badge: 'Lohri Special' }, cap: 'festive', subject: 'Lohri', tone: 'fun' },
    { icon: 'light', name: 'Makar Sankranti / Pongal', f: [1, 14], dates: ['2027-01-15', '2028-01-15', '2029-01-14', '2030-01-14', '2031-01-15'], type: 'festival', idea: 'Til-gud sweets, khichdi or a Pongal thali', poster: 'festive_special', fields: { headline: 'Happy Makar Sankranti', subtext: 'Til-gud, khichdi and festive thalis', badge: 'Sankranti Special' }, cap: 'festive', subject: 'Makar Sankranti and Pongal', tone: 'fun' },
    { icon: 'flag', name: 'Republic Day', f: [1, 26], type: 'national', idea: 'Tricolour dessert or 26% off on one dish', poster: 'festive_special', fields: { headline: 'Happy Republic Day', subtext: 'Celebrate with great food', badge: 'Republic Day Special' }, cap: 'festive', subject: 'Republic Day', tone: 'fun' },
    // ── February ──
    { icon: 'fastfood', name: 'World Pizza Day', f: [2, 9], type: 'food', idea: 'Buy one pizza, get one at half price', poster: 'bold_discount', fields: { headline: 'World Pizza Day', subtext: 'A slice of happiness, today only', badge: 'Pizza Day' }, cap: 'combo', subject: 'World Pizza Day', tone: 'fun' },
    { icon: 'favorite', name: "Valentine's Day", f: [2, 14], type: 'national', idea: 'Dinner for two with a free dessert; take table bookings', poster: 'combo', fields: { headline: 'Valentine’s Dinner for Two', subtext: 'Reserve your table today' }, cap: 'combo', subject: "Valentine's dinner for two", tone: 'elegant' },
    // ── March ──
    { icon: 'local_florist', name: "Women's Day", f: [3, 8], type: 'national', idea: 'A free dessert or drink for every woman who orders', poster: 'festive_special', fields: { headline: "Happy Women's Day", subtext: 'A treat, on us', badge: "Women's Day Special" }, cap: 'festive', subject: "Women's Day", tone: 'elegant' },
    // ── April / May ──
    { icon: 'eco', name: 'Baisakhi', f: [4, 14], type: 'festival', idea: 'Punjabi thali, lassi and chole bhature', poster: 'festive_special', fields: { headline: 'Happy Baisakhi', subtext: 'A harvest feast for everyone', badge: 'Baisakhi Special' }, cap: 'festive', subject: 'Baisakhi', tone: 'fun' },
    { icon: 'groups', name: 'Labour Day / Maharashtra Day', f: [5, 1], type: 'national', idea: 'Holiday lunch combo; family meals', poster: 'combo', fields: { headline: 'Holiday Lunch Combos', subtext: 'Take the day off, we cook' }, cap: 'combo', subject: 'Labour Day holiday lunch', tone: 'fun' },
    { icon: 'volunteer_activism', name: "Mother's Day", nth: [5, 0, 2], type: 'national', idea: 'Free dessert for mom; family table bookings', poster: 'festive_special', fields: { headline: "Happy Mother's Day", subtext: "Treat mom to a meal she'll love", badge: "Mother's Day Special" }, cap: 'festive', subject: "Mother's Day", tone: 'elegant' },
    { icon: 'coffee', name: 'International Tea Day', f: [5, 21], type: 'food', idea: 'Chai + snack combo; a free refill all day', poster: 'bold_discount', fields: { headline: 'International Tea Day', subtext: 'Chai and snacks, made for sharing', badge: 'Tea Day' }, cap: 'combo', subject: 'International Tea Day', tone: 'fun' },
    { icon: 'lunch_dining', name: 'World Burger Day', f: [5, 28], type: 'food', idea: 'Burger + fries + drink combo at a special price', poster: 'bold_discount', fields: { headline: 'World Burger Day', subtext: 'Combo deals all day', badge: 'Burger Day' }, cap: 'combo', subject: 'World Burger Day', tone: 'fun' },
    // ── June / July / August ──
    { icon: 'eco', name: 'International Yoga Day', f: [6, 21], type: 'national', idea: 'Healthy bowls, salads and fresh juices', poster: 'festive_special', fields: { headline: 'International Yoga Day', subtext: 'Eat light, feel great', badge: 'Healthy Menu' }, cap: 'festive', subject: 'International Yoga Day healthy menu', tone: 'elegant' },
    { icon: 'man', name: "Father's Day", nth: [6, 0, 3], type: 'national', idea: 'Dad eats free with a family order, or a big grill platter', poster: 'festive_special', fields: { headline: "Happy Father's Day", subtext: 'Treat dad to something special', badge: "Father's Day Special" }, cap: 'festive', subject: "Father's Day", tone: 'elegant' },
    { icon: 'cookie', name: 'World Chocolate Day', f: [7, 7], type: 'food', idea: 'Chocolate desserts and shakes at a special price', poster: 'bold_discount', fields: { headline: 'World Chocolate Day', subtext: 'Sweet deals all day', badge: 'Chocolate Day' }, cap: 'combo', subject: 'World Chocolate Day', tone: 'fun' },
    { icon: 'group', name: 'Friendship Day', nth: [8, 0, 1], type: 'national', idea: 'Group combo: 4 friends, one price', poster: 'combo', fields: { headline: 'Friendship Day Feast', subtext: 'Better with friends' }, cap: 'combo', subject: 'Friendship Day feast', tone: 'fun' },
    { icon: 'flag', name: 'Independence Day', f: [8, 15], type: 'national', idea: 'Tricolour thali or 15% off for the day', poster: 'festive_special', fields: { headline: 'Happy Independence Day', subtext: 'Celebrate with great food', badge: 'Independence Day Special' }, cap: 'festive', subject: 'Independence Day', tone: 'fun' },
    // ── September / October / November / December ──
    { icon: 'school', name: "Teacher's Day", f: [9, 5], type: 'national', idea: 'A thank-you treat for teachers (show ID)', poster: 'festive_special', fields: { headline: "Happy Teacher's Day", subtext: 'A thank-you treat for teachers', badge: "Teacher's Day Special" }, cap: 'festive', subject: "Teacher's Day", tone: 'elegant' },
    { icon: 'coffee', name: 'International Coffee Day', f: [10, 1], type: 'food', idea: 'Second coffee free, or coffee + snack combo', poster: 'bold_discount', fields: { headline: 'International Coffee Day', subtext: 'Your second cup is on us', badge: 'Coffee Day' }, cap: 'combo', subject: 'International Coffee Day', tone: 'fun' },
    { icon: 'flag', name: 'Gandhi Jayanti', f: [10, 2], type: 'national', idea: 'Simple satvik thali; holiday family lunch', poster: 'festive_special', fields: { headline: 'Gandhi Jayanti', subtext: 'A simple, wholesome meal', badge: 'Holiday Special' }, cap: 'festive', subject: 'Gandhi Jayanti', tone: 'elegant' },
    { icon: 'restaurant', name: 'World Food Day', f: [10, 16], type: 'food', idea: 'Chef’s tasting platter; feature a local dish', poster: 'festive_special', fields: { headline: 'World Food Day', subtext: 'Taste what we love to cook', badge: 'Chef’s Special' }, cap: 'festive', subject: 'World Food Day', tone: 'elegant' },
    { icon: 'dark_mode', name: 'Halloween', f: [10, 31], type: 'national', idea: 'Spooky-named dishes and a dark dessert special', poster: 'festive_special', fields: { headline: 'Spooky Season Specials', subtext: 'Treats all season long', badge: 'Halloween Special' }, cap: 'festive', subject: 'Halloween', tone: 'fun' },
    { icon: 'family_restroom', name: "Children's Day", f: [11, 14], type: 'national', idea: 'Kids eat free with a family meal; a kids’ menu', poster: 'festive_special', fields: { headline: "Happy Children's Day", subtext: 'Little ones eat free today', badge: "Children's Day" }, cap: 'festive', subject: "Children's Day", tone: 'fun' },
    { icon: 'sell', name: 'Black Friday', nth: [11, 5, 4], type: 'national', idea: 'Biggest discount of the year for one day', poster: 'bold_discount', fields: { headline: 'Black Friday Deals', subtext: 'Today only', badge: 'Black Friday' }, cap: 'weekend', subject: 'Black Friday deals', tone: 'urgent' },
    { icon: 'park', name: 'Christmas', f: [12, 25], type: 'festival', idea: 'Plum cake, Christmas dinner set menu; take bookings', poster: 'festive_special', fields: { headline: 'Merry Christmas', subtext: 'Celebrate the season with us', badge: 'Christmas Special' }, cap: 'festive', subject: 'Christmas', tone: 'elegant' },
    { icon: 'nightlife', name: "New Year's Eve", f: [12, 31], type: 'national', idea: 'Party platters and a late-night countdown menu', poster: 'happy_hour', fields: { headline: "New Year's Eve", subtext: 'Ring it in with us', badge: 'NYE Party' }, cap: 'happy_hour', subject: "New Year's Eve party", tone: 'urgent' },

    // ── Festivals that follow the moon: New Delhi panchang / Govt. of India dates, 2026–2031 ──
    { icon: 'local_florist', name: 'Vasant Panchami', dates: ['2027-02-11', '2028-01-31', '2029-01-19', '2030-02-07', '2031-01-27'], type: 'festival', idea: 'Yellow specials: kesari halwa, boondi, saffron rice', poster: 'festive_special', fields: { headline: 'Happy Vasant Panchami', subtext: 'Yellow treats for spring', badge: 'Festival Special' }, cap: 'festive', subject: 'Vasant Panchami', tone: 'elegant' },
    { icon: 'eco', name: 'Maha Shivaratri', dates: ['2027-03-06', '2028-02-23', '2029-02-11', '2030-03-02', '2031-02-20'], type: 'festival', idea: 'Vrat (fasting) menu: sabudana, kuttu, fruit', poster: 'festive_special', fields: { headline: 'Maha Shivaratri', subtext: 'Special vrat menu today', badge: 'Vrat Menu' }, cap: 'festive', subject: 'Maha Shivaratri vrat menu', tone: 'elegant' },
    { icon: 'dark_mode', name: 'Ramadan (iftar specials)', dates: ['2027-02-09', '2028-01-29', '2029-01-17', '2030-01-07', '2030-12-27'], moon: true, about: true, type: 'festival', idea: 'Iftar platters and sehri boxes every evening of the month', poster: 'combo', fields: { headline: 'Iftar Specials', subtext: 'Every evening this Ramadan' }, cap: 'combo', subject: 'Ramadan iftar specials', tone: 'elegant' },
    { icon: 'dark_mode', name: 'Eid ul-Fitr', dates: ['2027-03-10', '2028-02-27', '2029-02-15', '2030-02-05', '2031-01-25'], moon: true, type: 'festival', idea: 'Sheer khurma, biryani and Eid family feasts', poster: 'festive_special', fields: { headline: 'Eid Mubarak', subtext: 'Celebrate Eid with a feast', badge: 'Eid Special' }, cap: 'festive', subject: 'Eid ul-Fitr', tone: 'elegant' },
    { icon: 'palette', name: 'Holi', dates: ['2027-03-22', '2028-03-11', '2029-03-01', '2030-03-20', '2031-03-09'], type: 'festival', idea: 'Thandai, gujiya and colourful party platters', poster: 'festive_special', fields: { headline: 'Happy Holi', subtext: 'Gujiya, thandai and festive treats', badge: 'Holi Special' }, cap: 'festive', subject: 'Holi', tone: 'fun' },
    { icon: 'star', name: 'Good Friday & Easter', easter: -2, type: 'festival', idea: 'Easter brunch on Sunday; hot cross buns', poster: 'festive_special', fields: { headline: 'Happy Easter', subtext: 'Easter brunch with family', badge: 'Easter Special' }, cap: 'festive', subject: 'Easter', tone: 'elegant' },
    { icon: 'celebration', name: 'Ugadi / Gudi Padwa (Hindu New Year)', dates: ['2027-04-07', '2028-03-27', '2029-04-14', '2030-04-03', '2031-03-24'], type: 'festival', idea: 'Ugadi pachadi, puran poli, shrikhand; New Year thali', poster: 'festive_special', fields: { headline: 'Happy Ugadi & Gudi Padwa', subtext: 'Start the new year with a festive thali', badge: 'New Year Thali' }, cap: 'festive', subject: 'Ugadi and Gudi Padwa', tone: 'fun' },
    { icon: 'auto_awesome', name: 'Ram Navami', dates: ['2027-04-15', '2028-04-03', '2029-04-23', '2030-04-12', '2031-04-01'], type: 'festival', idea: 'Satvik thali, no onion or garlic', poster: 'festive_special', fields: { headline: 'Happy Ram Navami', subtext: 'A satvik thali for the festival', badge: 'Festival Thali' }, cap: 'festive', subject: 'Ram Navami', tone: 'elegant' },
    { icon: 'savings', name: 'Akshaya Tritiya', dates: ['2027-05-09', '2028-04-27', '2029-05-16', '2030-05-05', '2031-04-24'], type: 'festival', idea: 'Lucky-day offer: a sweet with every order, or “start something new” combo', poster: 'festive_special', fields: { headline: 'Happy Akshaya Tritiya', subtext: 'A lucky day for a sweet treat', badge: 'Festival Special' }, cap: 'festive', subject: 'Akshaya Tritiya', tone: 'fun' },
    { icon: 'local_florist', name: 'Buddha Purnima', dates: ['2027-05-20', '2028-05-08', '2029-05-27', '2030-05-17', '2031-05-07'], type: 'festival', idea: 'Light vegetarian set meal', poster: 'festive_special', fields: { headline: 'Buddha Purnima', subtext: 'A peaceful vegetarian meal', badge: 'Festival Special' }, cap: 'festive', subject: 'Buddha Purnima', tone: 'elegant' },
    { icon: 'dark_mode', name: 'Eid al-Adha (Bakrid)', dates: ['2027-05-17', '2028-05-05', '2029-04-24', '2030-04-14', '2031-04-03'], moon: true, type: 'festival', idea: 'Mutton specials, biryani and family platters', poster: 'festive_special', fields: { headline: 'Eid Mubarak', subtext: 'Bakrid feasts for the family', badge: 'Bakrid Special' }, cap: 'festive', subject: 'Bakrid (Eid al-Adha)', tone: 'elegant' },
    { icon: 'favorite', name: 'Raksha Bandhan', dates: ['2027-08-17', '2028-08-05', '2029-08-23', '2030-08-13', '2031-08-02'], type: 'festival', idea: 'Sibling combo meal; sweets box for gifting', poster: 'combo', fields: { headline: 'Happy Raksha Bandhan', subtext: 'A treat for brothers and sisters' }, cap: 'combo', subject: 'Raksha Bandhan sibling combo', tone: 'fun' },
    { icon: 'celebration', name: 'Janmashtami', dates: ['2027-08-25', '2028-08-13', '2029-09-01', '2030-08-21', '2031-08-09'], type: 'festival', idea: 'Makhan mishri, panjiri and a vrat menu', poster: 'festive_special', fields: { headline: 'Happy Janmashtami', subtext: 'Festive sweets and vrat specials', badge: 'Janmashtami Special' }, cap: 'festive', subject: 'Janmashtami', tone: 'fun' },
    { icon: 'lunch_dining', name: 'Onam', dates: ['2027-09-12', '2028-09-01', '2029-08-22', '2030-09-09', '2031-08-30'], type: 'festival', idea: 'Onam sadya on a banana leaf; payasam', poster: 'festive_special', fields: { headline: 'Happy Onam', subtext: 'A grand Onam sadya', badge: 'Onam Sadya' }, cap: 'festive', subject: 'Onam sadya', tone: 'fun' },
    { icon: 'cake', name: 'Ganesh Chaturthi', dates: ['2027-09-04', '2028-08-23', '2029-09-11', '2030-09-01', '2031-09-20'], type: 'festival', idea: 'Modak specials and a festive thali', poster: 'festive_special', fields: { headline: 'Ganpati Bappa Morya', subtext: 'Modak and festive thalis', badge: 'Ganesh Chaturthi' }, cap: 'festive', subject: 'Ganesh Chaturthi', tone: 'fun' },
    { icon: 'eco', name: 'Navratri begins', dates: ['2027-09-30', '2028-09-19', '2029-10-08', '2030-09-28', '2031-10-17'], type: 'festival', idea: 'Navratri vrat thali for all nine days (no onion, garlic or grains); Durga Puja specials', poster: 'festive_special', fields: { headline: 'Navratri Vrat Thali', subtext: 'Special fasting menu, all 9 days', badge: 'Navratri Special' }, cap: 'festive', subject: 'Navratri vrat thali', tone: 'elegant' },
    { icon: 'local_fire_department', name: 'Dussehra', dates: ['2026-10-20', '2027-10-09', '2028-09-27', '2029-10-16', '2030-10-06', '2031-10-25'], type: 'festival', idea: 'Festive thali and family combos', poster: 'festive_special', fields: { headline: 'Happy Dussehra', subtext: 'Celebrate with a festive feast', badge: 'Dussehra Special' }, cap: 'festive', subject: 'Dussehra', tone: 'fun' },
    { icon: 'dark_mode', name: 'Karva Chauth', dates: ['2026-10-29', '2027-10-18', '2028-10-07', '2029-10-26', '2030-10-15', '2031-11-02'], type: 'festival', idea: 'Dinner for couples after moonrise; take bookings', poster: 'combo', fields: { headline: 'Karva Chauth Dinner', subtext: 'Break the fast with a special dinner for two' }, cap: 'combo', subject: 'Karva Chauth dinner for two', tone: 'elegant' },
    { icon: 'savings', name: 'Dhanteras', dates: ['2026-11-06', '2027-10-27', '2028-10-15', '2029-11-04', '2030-10-24', '2031-11-12'], type: 'festival', idea: 'Start Diwali offers; sweets boxes for gifting', poster: 'festive_special', fields: { headline: 'Happy Dhanteras', subtext: 'Diwali offers start today', badge: 'Dhanteras Special' }, cap: 'festive', subject: 'Dhanteras', tone: 'fun' },
    { icon: 'light', name: 'Diwali', dates: ['2026-11-08', '2027-10-29', '2028-10-17', '2029-11-05', '2030-10-26', '2031-11-14'], type: 'festival', idea: 'Sweets and snack boxes, party platters, bulk orders for offices', poster: 'festive_special', fields: { headline: 'Happy Diwali', subtext: 'Sweets, snacks and festive feasts', badge: 'Diwali Special' }, cap: 'festive', subject: 'Diwali', tone: 'fun' },
    { icon: 'restaurant', name: 'Govardhan Puja (Annakut)', dates: ['2026-11-10', '2027-10-30', '2028-10-18', '2029-11-06', '2030-10-27', '2031-11-15'], type: 'festival', idea: 'Annakut thali with many dishes; family feasts', poster: 'festive_special', fields: { headline: 'Govardhan Puja', subtext: 'An Annakut feast of many dishes', badge: 'Annakut Thali' }, cap: 'festive', subject: 'Govardhan Puja Annakut', tone: 'fun' },
    { icon: 'volunteer_activism', name: 'Bhai Dooj', dates: ['2026-11-11', '2027-10-31', '2028-10-19', '2029-11-07', '2030-10-28', '2031-11-16'], type: 'festival', idea: 'Sibling meal combo', poster: 'combo', fields: { headline: 'Happy Bhai Dooj', subtext: 'A treat for brothers and sisters' }, cap: 'combo', subject: 'Bhai Dooj sibling combo', tone: 'fun' },
    { icon: 'light', name: 'Chhath Puja', dates: ['2026-11-15', '2027-11-04', '2028-10-23', '2029-11-11', '2030-11-01', '2031-11-20'], type: 'festival', idea: 'Thekua and festive prasad-style sweets', poster: 'festive_special', fields: { headline: 'Happy Chhath Puja', subtext: 'Festive treats for the family', badge: 'Chhath Special' }, cap: 'festive', subject: 'Chhath Puja', tone: 'elegant' },
    { icon: 'auto_awesome', name: 'Guru Nanak Jayanti', dates: ['2026-11-24', '2027-11-14', '2028-11-02', '2029-11-21', '2030-11-10', '2031-11-28'], type: 'festival', idea: 'Langar-style thali; kada prasad', poster: 'festive_special', fields: { headline: 'Happy Gurpurab', subtext: 'A wholesome Punjabi thali', badge: 'Gurpurab Special' }, cap: 'festive', subject: 'Guru Nanak Jayanti', tone: 'elegant' },

    // ── Nepal (np: shown to Nepal restaurants only). New Year = 1 Baisakh from config/nepali_date.php;
    // Dashain's Vijaya Dashami and Tihar's Laxmi Puja fall with Dussehra and Diwali above ──
    { icon: 'celebration', name: 'Nepali New Year', np: true, dates: ['2027-04-14', '2028-04-13', '2029-04-14', '2030-04-14', '2031-04-15'], type: 'festival', idea: 'New Year family set menu; sel roti and sweets', poster: 'festive_special', fields: { headline: 'Happy New Year', subtext: 'Celebrate the new year with us', badge: 'Naya Barsha' }, cap: 'festive', subject: 'Nepali New Year', tone: 'fun' },
    { icon: 'temple_hindu', name: 'Dashain (Vijaya Dashami)', np: true, dates: ['2026-10-20', '2027-10-09', '2028-09-27', '2029-10-16', '2030-10-06', '2031-10-25'], type: 'festival', idea: 'Goat curry and family feast sets; party orders for tika day', poster: 'festive_special', fields: { headline: 'Happy Dashain', subtext: 'Family feasts for the festival', badge: 'Dashain Special' }, cap: 'festive', subject: 'Dashain', tone: 'fun' },
    { icon: 'light', name: 'Tihar (Laxmi Puja)', np: true, dates: ['2026-11-08', '2027-10-29', '2028-10-17', '2029-11-05', '2030-10-26', '2031-11-14'], type: 'festival', idea: 'Sel roti and sweets boxes; deusi-bhailo party platters', poster: 'festive_special', fields: { headline: 'Happy Tihar', subtext: 'Sweets and festive feasts', badge: 'Tihar Special' }, cap: 'festive', subject: 'Tihar', tone: 'fun' },

    // ── Every week ──
    { icon: 'weekend', name: 'Weekend Special', weekly: 5, recurring: true, type: 'season', idea: 'A weekend-only combo or happy hours', poster: 'bold_discount', fields: { headline: 'Weekend Special', subtext: 'This Sat-Sun only', badge: 'Weekend Offer' }, cap: 'weekend', subject: 'our weekend special', tone: 'fun' }
  ];
  // Nepal restaurants get the Nepal festivals; everyone else does not (window.restaurantCountryCode from countries.php)
  OCCASIONS = OCCASIONS.filter(function (o) { return !o.np || window.restaurantCountryCode === 'NP'; });

  function dateOf(o) {
    if (o.dates) { var listed = nextListed(o.dates); if (listed || !o.f) return listed; }   // exact dates first, the fixed day after the list ends
    if (o.easter != null) return nextEaster(o.easter);
    if (o.f) return nextFixedDate(o.f[0], o.f[1]);
    if (o.nth) return nextNthWeekday(o.nth[0], o.nth[1], o.nth[2]);
    if (o.weekly != null) return nextWeekday(o.weekly);
    return null;
  }
  // every date of an occasion from today up to `range` days ahead (one per year; the weekly card once)
  function datesOf(o, range) {
    if (o.weekly != null) return [nextWeekday(o.weekly)];
    var t = today0(), end = new Date(t); end.setDate(end.getDate() + range);
    var out = [];
    for (var y = t.getFullYear(); y <= end.getFullYear(); y++) {
      var list = [];
      var listed = (o.dates || []).filter(function(x) { return +x.slice(0, 4) === y; });
      if (listed.length) list = listed.map(function(x) { var p = x.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); });
      else if (o.dates && !o.f) list = [];                                   // moon festival: only checked dates
      else if (o.easter != null) { var e = easter(y); e.setDate(e.getDate() + o.easter); list = [e]; }
      else if (o.f) list = [new Date(y, o.f[0] - 1, o.f[1])];
      else if (o.nth) { var first = new Date(y, o.nth[0] - 1, 1); list = [new Date(y, o.nth[0] - 1, 1 + (o.nth[1] - first.getDay() + 7) % 7 + (o.nth[2] - 1) * 7)]; }
      list.forEach(function(d) { if (d >= t && d <= end) out.push(d); });
    }
    return out;
  }

  function formatDate(d) { return d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }); }
  function daysLabel(n) { return n === 0 ? 'Today!' : n === 1 ? 'Tomorrow' : 'In ' + n + ' days'; }

  function goToPoster(o) {
    if (window.showPage) window.showPage('marketingPostersPage');
    setTimeout(function() {
      if (window.initPosterStudio) window.initPosterStudio();
      if (window.prefillPoster) window.prefillPoster(o.poster, o.fields);
    }, 60);
  }
  function goToCaption(o) {
    if (window.showPage) window.showPage('marketingCaptionsPage');
    setTimeout(function() {
      if (window.initCaptionStudio) window.initCaptionStudio();
      if (window.prefillCaptions) window.prefillCaptions(o.cap, o.subject, '', o.tone);
    }, 60);
  }
  function goToOffer() {
    if (window.showPage && document.getElementById('couponsPage')) window.showPage('couponsPage');
  }
  // one campaign for the occasion: the poster, video and caption made next share its link and offer
  function startCampaign(o, date) {
    var name = o.recurring ? 'Weekend Special ' + date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) : o.name.split(' / ')[0].replace(/ \(.*\)$/, '') + ' ' + date.getFullYear();
    if (window.MK) MK.setCampaign({ name: name, idea: o.idea || '' });
    goToPoster(o);
    if (window.SX) SX.notify('Campaign “' + name + '” started. Attach an offer in step 3.', 'success');
  }

  function makeCard(o, date, n) {
    var card = document.createElement('div');
    card.className = 'campaign-card t-' + (o.type || 'national');
    var top = document.createElement('div');
    top.className = 'campaign-card-top';
    var title = document.createElement('div');
    title.className = 'campaign-card-title';
    title.innerHTML = '<span class="campaign-card-ic material-symbols-rounded">' + (o.icon || 'event') + '</span>';
    title.appendChild(document.createTextNode(o.name));
    var pill = document.createElement('div');
    pill.className = 'campaign-card-pill' + (n <= 1 ? ' campaign-card-pill-soon' : '');
    pill.textContent = daysLabel(n);
    top.appendChild(title); top.appendChild(pill);

    var dateEl = document.createElement('div');
    dateEl.className = 'campaign-card-date';
    dateEl.textContent = (o.about ? 'Starts around ' : '') + formatDate(date) + (o.recurring ? ' (weekly)' : '') + (o.moon ? ' · may move a day (moon sighting)' : '');
    var idea = document.createElement('div');
    idea.className = 'campaign-card-idea';
    idea.innerHTML = '<span class="material-symbols-rounded">lightbulb</span>';
    idea.appendChild(document.createTextNode(o.idea || ''));
    var tip = document.createElement('div');
    tip.className = 'campaign-card-tip';
    var lead = o.recurring ? 1 : (o.type === 'festival' ? 7 : 3);   // festivals: plan a week ahead
    var postBy = new Date(date); postBy.setDate(postBy.getDate() - lead);
    tip.textContent = n <= lead ? 'Post today so customers see it in time' : 'Best to post by ' + formatDate(postBy);

    var actions = document.createElement('div');
    actions.className = 'campaign-card-actions';
    var main = document.createElement('button');
    main.type = 'button';
    main.className = 'mk-btn campaign-card-go';
    main.innerHTML = '<span class="material-symbols-rounded">campaign</span>Create campaign';
    main.title = 'Poster, caption and offer for ' + o.name + ', with one tracked link';
    main.addEventListener('click', function() { startCampaign(o, date); });
    actions.appendChild(main);
    var more = document.createElement('div');
    more.className = 'campaign-card-more';
    [['imagesmode', 'Poster', function() { goToPoster(o); }], ['chat_bubble', 'Caption', function() { goToCaption(o); }], ['sell', 'Coupon', goToOffer]].forEach(function(b) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'mk-link';
      btn.innerHTML = '<span class="material-symbols-rounded">' + b[0] + '</span>' + b[1];
      btn.addEventListener('click', b[2]);
      more.appendChild(btn);
    });
    actions.appendChild(more);
    card.appendChild(top); card.appendChild(dateEl);
    if (o.idea) card.appendChild(idea);
    card.appendChild(tip); card.appendChild(actions);
    return card;
  }

  var view = { range: 90, type: 'all' };
  try { view.range = +(localStorage.getItem('rg_cal_range') || 90) || 90; view.type = localStorage.getItem('rg_cal_type') || 'all'; } catch (e) {}

  function toolbar(grid, counts) {
    var bar = document.getElementById('campaignCalendarBar');
    if (!bar) { bar = document.createElement('div'); bar.id = 'campaignCalendarBar'; bar.className = 'campaign-cal-bar'; grid.parentNode.insertBefore(bar, grid); }
    var seg = function (list, cur, key) { return '<div class="campaign-seg">' + list.map(function (x) { return '<button type="button" data-cal-' + key + '="' + x[0] + '"' + (String(cur) === String(x[0]) ? ' class="on"' : '') + '>' + x[1] + (x[2] != null ? ' <em>' + x[2] + '</em>' : '') + '</button>'; }).join('') + '</div>'; };
    bar.innerHTML = seg([[30, 'Next 30 days'], [90, 'Next 3 months'], [365, 'Whole year'], [1830, 'Next 5 years']], view.range, 'range') +
      seg([['all', 'All', counts.all], ['festival', 'Festivals', counts.festival], ['national', 'Days', counts.national], ['food', 'Food days', counts.food]], view.type, 'type');
  }

  function render() {
    var grid = document.getElementById('campaignCalendarGrid');
    if (!grid) return;
    var items = [];
    OCCASIONS.forEach(function(o) { datesOf(o, view.range).forEach(function(d) { items.push({ o: o, date: d, days: daysUntil(d) }); }); });
    var counts = { all: items.length, festival: 0, national: 0, food: 0 };
    items.forEach(function(x) { if (counts[x.o.type] != null) counts[x.o.type]++; });
    toolbar(grid, counts);
    items = items.filter(function(x) { return view.type === 'all' || x.o.type === view.type || x.o.recurring; }).sort(function(a, b) { return (a.o.recurring ? 1 : 0) - (b.o.recurring ? 1 : 0) || a.days - b.days; });   // weekly card last
    grid.innerHTML = '';
    var month = '';
    items.forEach(function(x) {
      var m = x.o.recurring ? 'Every week' : x.date.toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
      if (m !== month) {
        month = m;
        var h = document.createElement('div');
        h.className = 'campaign-month';
        h.textContent = m;
        grid.appendChild(h);
      }
      grid.appendChild(makeCard(x.o, x.date, x.days));
    });
  }

  document.addEventListener('click', function(e) {
    var r = e.target.closest('[data-cal-range]'), t = e.target.closest('[data-cal-type]');
    if (!r && !t) return;
    if (r) view.range = +r.dataset.calRange;
    if (t) view.type = t.dataset.calType;
    try { localStorage.setItem('rg_cal_range', view.range); localStorage.setItem('rg_cal_type', view.type); } catch (er) {}
    render();
  });

  window.initCampaignCalendar = function() { render(); };
  // the next occasions (not the weekly card), for the dashboard home: [{name, icon, idea, date, days, type}]
  window.rgUpcomingOccasions = function(max, withinDays) {
    var out = [];
    OCCASIONS.forEach(function(o) { if (o.recurring) return; var d = dateOf(o); if (d) { var n = daysUntil(d); if (n <= (withinDays || 30)) out.push({ o: o, name: o.name, icon: o.icon, idea: o.idea, date: d, days: n, type: o.type }); } });
    out.sort(function(a, b) { return a.days - b.days; });
    return out.slice(0, max || 3);
  };
  window.rgStartCampaign = function(name) {
    var hit = OCCASIONS.filter(function(o) { return o.name === name; })[0];
    if (!hit) return;
    startCampaign(hit, dateOf(hit));
  };
})();
