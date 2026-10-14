# Google Business — what's left to switch it on

The **Google Business** section (dashboard sidebar) is fully built but shows **"Coming soon"**
to restaurants until the Google keys are on the server. Nothing in the code needs to change:
add the keys to `.env` and it switches on by itself.

Code: `main/config/gbp_helpers.php`, `main/api/gbp.php`, `main/api/google_business_callback.php`,
`main/assets/js/gbp-studio.js`, `main/website/review.php`. Google Cloud project: **restrogrow-gmb**
(organization teamrestrogrow-org).

## Done

- [x] Google Cloud project `restrogrow-gmb` created
- [x] APIs enabled: My Business Account Management, My Business Business Information,
      Business Profile Performance, Places API (New)

## To do

### 1. A Business Profile to apply with
Google wants the applying company to have a **verified Business Profile active for about 60+ days**,
with the **same website** as the application.

- [ ] Create a Business Profile for **RestroGrow** (software company, office address, website
      https://restrogrow.com) and verify it (postcard / phone / video — whatever Google offers)
- [ ] Or use a restaurant profile your Google account genuinely **owns or manages** (Owner/Manager
      for 60+ days) — never a profile you don't manage

### 2. Apply for Business Profile API access
- [ ] Find the **Project number** (12 digits): Google Cloud → ☰ → IAM & Admin → **Settings**.
      Not the project ID, not the organization ID (995562936909)
- [ ] Apply at https://support.google.com/business/contact/api_default →
      **"Application for Basic API Access"**, from the Google account that **owns** the project
  - Business: RestroGrow · Website: https://restrogrow.com · Project number: (from above)
  - Use case: *RestroGrow is restaurant management software. Restaurant owners connect their own
    Google Business Profile to read and reply to reviews, publish posts and offers, update hours
    and see performance insights from our dashboard. We only access locations the owner
    authorizes via OAuth.*
- [ ] Wait for Google's email (days to ~2 weeks)
- [ ] Check approval: My Business Business Information API → **Quotas** → Requests per minute
      **0 = waiting, 300 = approved**
- [ ] After approval, enable **Google My Business API** (reviews, replies, posts):
      https://console.cloud.google.com/apis/library/mybusiness.googleapis.com?project=restrogrow-gmb
      (this page fails to load until approved — that's normal)

### 3. OAuth consent screen and client
- [ ] OAuth consent screen: External, app name **RestroGrow**, domain `restrogrow.com`,
      scope `https://www.googleapis.com/auth/business.manage`
- [ ] While in **Testing**, add each restaurant owner's Google account under **Test users**
      (later: publish the app; Google may ask for a verification review)
- [ ] Credentials → OAuth client ID → **Web application**, authorized redirect URIs:
  - `https://restrogrow.com/main/api/google_business_callback.php`
  - `http://localhost/menuwebsite/main/api/google_business_callback.php`
- [ ] Credentials → **API key**, restricted to **Places API (New)**

### 4. Keys on the server
Add to `main/.env` on Hostinger (and locally to test). Never paste the secret in chat or git.

```
GOOGLE_GBP_CLIENT_ID=xxxx.apps.googleusercontent.com
GOOGLE_GBP_CLIENT_SECRET=...
GOOGLE_PLACES_API_KEY=...
```

- `GOOGLE_PLACES_API_KEY` alone: the section switches on with the Google rating, newest
  reviews, review link, QR and table cards (no Google approval needed).
- `GOOGLE_GBP_CLIENT_ID` + `GOOGLE_GBP_CLIENT_SECRET`: **Connect Google** appears; replies,
  posts, profile edits and insights work once Google has approved API access.
- AI replies use `GEMINI_API_KEY` (the local one is invalid; check the live one works).

### 5. Test on restrogrow.com
- [ ] Google Business → Find your restaurant → rating and reviews show
- [ ] Get reviews → scan the QR → lands on Google's review form; scan count goes up
- [ ] Connect Google → choose location → reviews load
- [ ] Reply to a review (and try "Write with AI"), post an offer / a combo, edit hours, open Insights

## Ideas for later
- "Reply on Google" button on each review (opens the owner's own profile) for use before approval
- Review QR printed on the bill
- Notify the owner (WhatsApp / dashboard) about new 1–3 star reviews
