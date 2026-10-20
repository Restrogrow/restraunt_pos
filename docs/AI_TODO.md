# AI features: to do (researched 13 October 2026)

Status: **researched, not built.** Ordered by how much each should raise sales for the least new work.
Most published numbers come from companies that sell these tools, so treat them as rough. Before building,
measure the "before" numbers in **Analytics** (average bill, repeat rate, order rate on the website,
waste in Inventory reports) so each feature's real effect can be checked.

General rules for every AI feature:
- The restaurant can switch each one off (per restaurant, plus a superadmin switch).
- Nothing is sent to a customer without a rule the owner set (or their tap).
- AI calls run on the server; keys stay in `.env`, never in the browser.
- Keep a log of what was suggested and what happened (shown / added / ignored), so Analytics can show the result.
- Cost guard: cache results, limit calls per restaurant per day, fall back to the non-AI version if the API fails.
- Default model: the latest Claude models (Haiku for fast, cheap tasks; Sonnet for harder ones).

---

## 1. Smart upsell at checkout (website + POS)  — do first

**What:** at the cart and checkout, suggest the 1–3 items this customer is most likely to add right now
("Add a Cold Coffee for ₹129?"). On the POS, show the same as small chips for the cashier.

**Why:** vendors report 12–25% bigger average bills from AI upselling (some claim more). 35%+ of orders on
the demo restaurant have just one item, and Analytics already finds "ordered together" pairs.

**Builds on:** the existing upsell module (`upsell_pairs`, `upsell_settings`, `upsell_stats`), Analytics
pairs query, loyalty / customer history.

**How:**
1. Score candidates without AI first: items often ordered with what is in the cart (pairs), the customer's
   own past orders, time of day (drinks in the afternoon, desserts at night), items in stock only
   (inventory), margin when recipes exist.
2. Optional AI layer: short, friendly wording of the suggestion; pick between close candidates.
3. Log shown / added in `upsell_stats`; show "upsell added ₹X this month" in Analytics.

**Measure:** average bill and items per order (Analytics), % of suggestions added.
**Effort:** medium (mostly ranking logic; little AI cost).

---

## 2. WhatsApp ordering assistant

**What:** customers message the restaurant on WhatsApp ("2 butter chicken, 3 naan, delivery") and an
assistant understands it, builds the cart from the real menu, asks for missing details (size, address),
sends the order summary and a payment link, then places the order into Online Orders like a website order.

**Why:** direct orders avoid the 18–30% commission of food apps. Many Indian customers prefer chatting to
installing an app.

**Builds on:** RepeatGrow / WhatsApp connect (`wa_settings`, `wa_outbox`, `docs/REPEATGROW_CONNECT.md`),
`process_website_order.php`, payment links, the menu.

**How:**
1. Incoming WhatsApp webhook → conversation state per phone (cart, step, last message time).
2. AI turns free text (Hindi, English, Hinglish) into menu item ids + quantities + variations; anything
   unclear becomes a question with buttons, never a guess.
3. Confirm summary + total → payment link or cash on delivery → create the order through the normal
   order code (same validation, abuse guard, inventory).
4. Hand over to a human: "talk to staff" or after two misunderstandings.
5. Rules: only reply to people who message first; respect WhatsApp's 24-hour window and templates;
   never send from local (test numbers only).

**Measure:** WhatsApp orders per week, share of orders finished without staff, repeat orders.
**Effort:** large.

---

## 3. Demand forecast for prep and stock

**What:** each morning: "Today expect ~45 orders. Prep 6 kg chicken, 3 kg paneer. Order 10 L milk."
Uses weekday patterns (already in the Analytics 7-day forecast), recent trend, festivals / holidays,
and optionally weather.

**Why:** vendors claim 15–50% less food waste (most cited ~30%), plus fewer "sold out" rejections.

**Builds on:** Analytics forecast, inventory recipes, reorder list, stock counts, waste in reports.

**How:**
1. Forecast dishes, not just orders: same-weekday average × recent trend × event factor.
2. Convert dish forecast to ingredients through recipes; compare with stock → prep list + buy list.
3. AI is optional here (simple statistics go far): use it to explain the plan in plain words and to
   pick event factors (festival list per city).
4. Show on the dashboard home and in a morning WhatsApp to the owner (opt-in).

**Measure:** waste value in Inventory reports, "out of stock" rejections (Analytics › orders you lost).
**Effort:** medium.

---

## 4. Review replies and complaint summary

**What:** draft a polite reply to each review (owner edits and posts), and group complaints into themes
("late delivery 6, cold food 3") with the one thing to fix first.

**Why:** answering reviews and fixing the top complaint lifts ratings and repeat orders.

**Builds on:** `order_feedback`, Analytics reviews card, Feedback page.

**How:** AI drafts replies in the restaurant's tone; monthly theme summary shown in Analytics tips.
Never auto-post.
**Effort:** small.

---

## 5. Weekly owner summary on WhatsApp

**What:** every Monday, a short message to the owner with last week's sales vs before, top dish, slowest
day and the top 3 "What to do now" tips from Analytics.

**Builds on:** `api/analytics_studio.php` tips (already worked out), WhatsApp sending.
**Effort:** small. Good first AI-adjacent win (wording by AI, numbers from Analytics).

---

## 6. Menu helpers

- Dish descriptions written from the name + ingredients (English plus the restaurant's language).
- Photo touch-up / background clean-up for dish photos (more photos = more website orders).
- Price suggestions for "Workhorses" in Menu analysis (popular, low margin): show the food-cost % and a
  suggested small rise; owner decides.

**Builds on:** menu-from-photos import (superadmin), Menu, Inventory recipes, Analytics menu analysis.
**Effort:** small to medium each.

---

## 7. AI phone-order agent (Hindi + English) — later

**What:** answers the restaurant's phone, takes orders and table bookings, sends a WhatsApp confirmation.
**Why:** no missed calls at peak time. Only worth it for restaurants with many phone orders.
**Effort:** large; needs a telephony provider and per-minute costs. Revisit after 1–3 are live.

---

## 8. Voice call with a sales agent in the website chatbot — built, PAUSED (21 October 2026)

**Status:** built and pushed, then **switched off** on the site the same day (owner: "abhi discontinue, baad me dekhenge").
Nothing is deleted; it only needs the two switches turned back on.

**What it does:** the restrogrow.com chat gets a Talk button. "Riya" talks to the visitor like a salesperson
(asks about their restaurant, matches features to their problems, handles price doubts, pushes for the free trial
or a demo). If they give name + number and say yes to a call back, they become a chat lead and the team gets the
new-lead email. Every line is saved in Super Admin › Chat leads, tagged "voice call".

**How it is built now (version 1):** the browser listens and speaks (Web Speech API: works in Chrome, Edge, Safari;
not Firefox); each sentence goes to `main/api/chatbot.php` (actions `voiceStart`, `voice`) and Gemini writes the
reply (`rgChatbotVoiceReply` in `main/config/chatbot_store.php`). Lines are stored in `chatbot_messages` with
`channel = 'voice'`. Weak spots: it waits for a pause before answering and the browser voice sounds robotic.

**To switch it back on:** `VOICE_ON = true` in `index.html` and `CHAT_VOICE_ON = true` in `main/api/chatbot.php`, then push.

**Next version when we pick it up again ("live", catches speech easily, answers fast):** Gemini Live API.
Tested on 21 Oct 2026 with the RestroGrow key: model `gemini-3.8-live` works; the server makes a one-time token
(`POST v1alpha/auth_tokens` with the setup locked in: system prompt, voice, transcripts, a `save_contact` tool), the
browser opens `wss://…/BidiGenerateContentConstrained?access_token=…`, streams 16 kHz mic audio and plays the
24 kHz PCM reply. First audio came back about 0.5 s after the question; the key never reaches the browser.
Still to build: mic capture (AudioWorklet), audio playback queue with barge-in, saving the transcripts of each turn
to `chatbot_messages`, and the `save_contact` tool call → lead. Keep version 1 as the fallback.

---

## Sources (mostly vendor material; figures not independently verified)

- [Loman: AI restaurant upselling](https://loman.ai/blog/using-ai-to-enhance-upselling-strategies-in-restaurants)
- [Loman: best AI systems for upselling](https://loman.ai/blog/best-ai-systems-restaurant-upselling-revenue-growth)
- [CloudKitchens: AI in restaurants 2026](https://cloudkitchens.com/blog/ai-in-restaurants)
- [QSR Magazine: AI and QSR ordering in 2026](https://www.qsrmagazine.com/story/how-ai-is-changing-quick-service-restaurant-ordering-in-2026/)
- [Checkmate: AI upselling](https://www.itsacheckmate.com/blog/how-to-increase-revenue-with-ai-for-upselling-in-restaurants)
- [Munchem: AI upselling and order value](https://www.munchem.com/Blog/Munchem/AI-Powered-Upselling-How-Restaurants-Can-Increase-Average-Order-Value)
- [VaniAgent: AI voice agents for Indian restaurants](https://vaniagent.com/resources/blog/ai-voice-agents-restaurants-india)
- [YuVerse: voice ordering in India](https://www.yuverse.ai/resources/posts/ai-restaurant-reservation-food-ordering-voice-india)
- [CallMissed: AI phone answering guide](https://www.callmissed.com/blog/ai-phone-answering-restaurants-2026-implementation-guide)
- [WaitWhiz: AI WhatsApp ordering](https://blog.waitwhiz.in/blog/ai-whatsapp-ordering-for-restaurants)
- [SoundHound AI (Wikipedia)](https://en.wikipedia.org/wiki/SoundHound_AI)
- [LowCode Agency: AI demand forecasting](https://www.lowcode.agency/blog/how-to-use-ai-to-forecast-restaurant-demand-and-reduce-food-waste)
- [GeekyAnts: AI demand forecasting](https://geekyants.com/blog/ai-demand-forecasting-for-restaurants-cut-food-waste-boost-margins)
- [Nory: AI and food waste](https://www.nory.ai/blog/how-can-ai-reduce-food-waste-in-restaurants)
- [Hospitality Hangout: AI restaurant operations 2026](https://www.thehospitalityhangout.com/blog/ai-restaurant-operations-in-2026-an-operators-playbook-for-margins/)
