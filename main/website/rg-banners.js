/*
 * Website banners (dashboard: Website Appearance > Banner Images / Offer Banners).
 *
 * Every <div class="rg-banners" data-placement="…"> on the page becomes a
 * slideshow of the banners the restaurant set to show there:
 *   home_top  — Home page, under the search bar
 *   home_menu — Home page, above the dish list
 *   menu_top  — Menu page, above the dishes
 *   cart      — Cart page, at the top
 * Two kinds: photo banners (uploaded images) and offer banners (made here in
 * HTML/CSS from a template + headline/code/colour, animated). Offer banners
 * come first. Auto-advances every 4 s, swipeable, with dots.
 *
 * window.RGOfferBanner.html(banner, {imgBase}) is also used by the dashboard
 * to preview offer banners exactly as customers see them.
 */
(function () {
  'use strict';

  var CSS = '' +
    '.rg-banners{display:none;padding:10px 16px 4px}' +
    '.rg-banners.on{display:block}' +
    '.rg-bn-view{position:relative;border-radius:16px;overflow:hidden;box-shadow:0 6px 18px rgba(0,0,0,.08);background:#f3f4f6}' +
    '.rg-bn-track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;scrollbar-width:none}' +
    '.rg-bn-track::-webkit-scrollbar{display:none}' +
    '.rg-bn-slide{flex:0 0 100%;scroll-snap-align:center;aspect-ratio:16/7;position:relative}' +
    '.rg-bn-slide>img{width:100%;height:100%;object-fit:cover;display:block}' +
    '.rg-bn-dots{position:absolute;left:0;right:0;bottom:8px;display:flex;justify-content:center;gap:5px;pointer-events:none;z-index:3}' +
    '.rg-bn-dots i{width:6px;height:6px;border-radius:999px;background:rgba(255,255,255,.6);box-shadow:0 0 0 1px rgba(0,0,0,.08);transition:width .3s,background .3s}' +
    '.rg-bn-dots i.on{width:18px;background:#fff}' +
    '.rg-bn-toast{position:fixed;left:50%;bottom:96px;transform:translate(-50%,20px);opacity:0;z-index:99999;padding:10px 16px;border-radius:999px;background:#111827;color:#fff;font:600 13px/1.2 Poppins,system-ui,sans-serif;transition:opacity .25s,transform .25s;pointer-events:none}' +
    '.rg-bn-toast.show{opacity:1;transform:translate(-50%,0)}' +

    /* ── Offer banner base ── */
    '.rg-ob{--c:#e11d48;--c2:color-mix(in srgb,var(--c) 62%,#000);--c3:color-mix(in srgb,var(--c) 14%,#fff);' +
    'position:relative;width:100%;height:100%;aspect-ratio:16/7;overflow:hidden;container-type:inline-size;isolation:isolate;' +
    'font-family:Poppins,system-ui,sans-serif;color:#fff;text-align:left;user-select:none}' +
    '.rg-ob *{box-sizing:border-box}' +
    '.rg-ob-copy{position:absolute;z-index:2;left:6cqw;top:50%;transform:translateY(-50%);width:56cqw;display:flex;flex-direction:column;align-items:flex-start;gap:1.6cqw}' +
    '.rg-ob-kick{font-size:2.9cqw;font-weight:700;letter-spacing:.18em;text-transform:uppercase;opacity:.9}' +
    '.rg-ob-title{display:block;font-size:10.5cqw;line-height:.95;font-weight:900;letter-spacing:-.02em;text-transform:uppercase}' +
    '.rg-ob-title.long{font-size:7cqw}' +
    '.rg-ob-title.xlong{font-size:5.2cqw;line-height:1.05}' +
    '.rg-ob-sub{font-size:3.6cqw;font-weight:500;line-height:1.25;opacity:.95;max-width:100%}' +
    '.rg-ob-row{display:flex;align-items:center;gap:2cqw;margin-top:.6cqw;flex-wrap:wrap}' +
    '.rg-ob-code{display:inline-flex;align-items:center;gap:1.2cqw;padding:1.1cqw 2.4cqw;border:.35cqw dashed currentColor;border-radius:1.6cqw;font-size:3.1cqw;font-weight:800;letter-spacing:.08em;cursor:pointer;background:rgba(255,255,255,.12)}' +
    '.rg-ob-code small{font-size:2.3cqw;font-weight:600;letter-spacing:.04em;opacity:.8}' +
    '.rg-ob-cta{display:inline-flex;align-items:center;gap:1cqw;padding:1.5cqw 3.4cqw;border-radius:999px;border:0;background:#fff;color:var(--c2);font:800 3.2cqw/1 Poppins,system-ui,sans-serif;cursor:pointer;box-shadow:0 1cqw 2.6cqw rgba(0,0,0,.18)}' +
    '.rg-ob-cta::after{content:"";width:1.4cqw;height:1.4cqw;border-top:.45cqw solid currentColor;border-right:.45cqw solid currentColor;transform:rotate(45deg)}' +
    '.rg-ob-dish{position:absolute;z-index:1;object-fit:cover}' +

    /* Burst: rotating rays + dish spinning in */
    '.rg-ob-burst{background:radial-gradient(circle at 78% 50%,var(--c) 0,var(--c2) 75%)}' +
    '.rg-ob-burst::before{content:"";position:absolute;left:78%;top:50%;width:160cqw;height:160cqw;margin:-80cqw 0 0 -80cqw;z-index:0;' +
    'background:repeating-conic-gradient(rgba(255,255,255,.10) 0 9deg,transparent 9deg 18deg);animation:rgObSpin 28s linear infinite}' +
    '.rg-ob-burst .rg-ob-dish{right:5cqw;top:50%;width:34cqw;height:34cqw;margin-top:-17cqw;border-radius:50%;border:1.2cqw solid rgba(255,255,255,.9);box-shadow:0 2cqw 5cqw rgba(0,0,0,.35);animation:rgObFloat 4s ease-in-out infinite}' +
    '.is-on .rg-ob-burst .rg-ob-dish,.rg-ob-burst.rg-ob-live .rg-ob-dish{animation:rgObSpinIn .9s cubic-bezier(.2,1.2,.4,1) both,rgObFloat 4s ease-in-out .9s infinite}' +
    '.rg-ob-burst .rg-ob-title{animation:rgObPulse 2.4s ease-in-out infinite;transform-origin:left center}' +
    '.rg-ob-burst .rg-ob-cta{color:var(--c2)}' +

    /* Ticket: coupon ticket with notches + shine sweep */
    '.rg-ob-ticket{background:var(--c3);color:#111827}' +
    '.rg-ob-ticket .rg-ob-card{position:absolute;inset:6cqw 5cqw;border-radius:3cqw;background:#fff;box-shadow:0 1.4cqw 4cqw rgba(0,0,0,.10);overflow:hidden;display:flex}' +
    '.rg-ob-ticket .rg-ob-card::before,.rg-ob-ticket .rg-ob-card::after{content:"";position:absolute;top:50%;width:5cqw;height:5cqw;margin-top:-2.5cqw;border-radius:50%;background:var(--c3);z-index:3}' +
    '.rg-ob-ticket .rg-ob-card::before{left:-2.5cqw}.rg-ob-ticket .rg-ob-card::after{right:-2.5cqw}' +
    '.rg-ob-ticket .rg-ob-copy{position:relative;left:auto;top:auto;transform:none;flex:1;width:auto;padding:0 3cqw 0 6cqw;justify-content:center}' +
    '.rg-ob-ticket .rg-ob-title{color:var(--c)}' +
    '.rg-ob-ticket .rg-ob-kick{color:var(--c2)}' +
    '.rg-ob-ticket .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-ticket .rg-ob-cta{background:var(--c);color:#fff}' +
    '.rg-ob-ticket .rg-ob-stub{position:relative;width:30cqw;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1cqw;border-left:.5cqw dashed #e5e7eb;background:linear-gradient(160deg,var(--c),var(--c2));color:#fff;text-align:center;overflow:hidden}' +
    '.rg-ob-ticket .rg-ob-stub img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;opacity:.28;mix-blend-mode:luminosity}' +
    '.rg-ob-ticket .rg-ob-stub small{position:relative;font-size:2.1cqw;font-weight:700;letter-spacing:.14em;text-transform:uppercase;opacity:.9}' +
    '.rg-ob-ticket .rg-ob-stub b{position:relative;font-size:3.6cqw;font-weight:900;letter-spacing:.06em;word-break:break-all;padding:0 1.5cqw}' +
    '.rg-ob-ticket .rg-ob-card i.rg-ob-shine{position:absolute;top:0;bottom:0;left:-40%;width:25%;z-index:4;background:linear-gradient(100deg,transparent,rgba(255,255,255,.65),transparent);transform:skewX(-18deg);animation:rgObShine 3.2s ease-in-out infinite}' +

    /* Neon: dark board, glowing flickering headline */
    '.rg-ob-neon{background:radial-gradient(ellipse at 30% 40%,#1f1633 0,#0a0a12 70%)}' +
    '.rg-ob-neon::before{content:"";position:absolute;inset:3cqw;border-radius:3cqw;border:.4cqw solid var(--c);box-shadow:0 0 2cqw var(--c),inset 0 0 2cqw var(--c);opacity:.85;z-index:0;animation:rgObGlow 2.8s ease-in-out infinite}' +
    '.rg-ob-neon .rg-ob-title{color:#fff;text-shadow:0 0 .6cqw #fff,0 0 2cqw var(--c),0 0 5cqw var(--c);animation:rgObFlicker 4s linear infinite}' +
    '.rg-ob-neon .rg-ob-kick{color:var(--c);text-shadow:0 0 1.5cqw var(--c)}' +
    '.rg-ob-neon .rg-ob-sub{color:#d1d5db}' +
    '.rg-ob-neon .rg-ob-code{color:#fff;border-color:var(--c);box-shadow:0 0 1.5cqw var(--c);background:transparent}' +
    '.rg-ob-neon .rg-ob-cta{background:var(--c);color:#fff;box-shadow:0 0 2.4cqw var(--c)}' +
    '.rg-ob-neon .rg-ob-dish{right:7cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:50%;box-shadow:0 0 0 .5cqw var(--c),0 0 4cqw var(--c);animation:rgObFloat 5s ease-in-out infinite}' +

    /* Confetti: falling colour squares, headline pops */
    '.rg-ob-confetti{background:linear-gradient(135deg,var(--c),var(--c2))}' +
    '.rg-ob-confetti .rg-ob-fx i{position:absolute;top:-6cqw;width:2.2cqw;height:2.2cqw;border-radius:.4cqw;z-index:1;animation:rgObFall linear infinite;opacity:.9}' +
    '.rg-ob-confetti .rg-ob-title{animation:rgObPop .7s cubic-bezier(.2,1.6,.4,1) both}' +
    '.rg-ob-confetti .rg-ob-dish{right:5cqw;top:50%;width:32cqw;height:32cqw;margin-top:-16cqw;border-radius:4cqw;transform:rotate(6deg);border:1cqw solid #fff;box-shadow:0 2cqw 5cqw rgba(0,0,0,.3);animation:rgObSway 5s ease-in-out infinite}' +

    /* Marquee: scrolling top strip + zooming dish */
    '.rg-ob-marquee{background:#fff;color:#111827}' +
    '.rg-ob-marquee .rg-ob-strip{position:absolute;top:0;left:0;right:0;height:8cqw;z-index:3;background:var(--c);color:#fff;display:flex;align-items:center;overflow:hidden;white-space:nowrap;font-size:2.8cqw;font-weight:800;letter-spacing:.12em}' +
    '.rg-ob-marquee .rg-ob-strip span{display:inline-block;padding-left:100%;animation:rgObTicker 12s linear -5s infinite}' +
    '.rg-ob-marquee .rg-ob-copy{top:calc(50% + 4cqw)}' +
    '.rg-ob-marquee .rg-ob-title{color:var(--c)}' +
    '.rg-ob-marquee .rg-ob-kick{color:var(--c2)}' +
    '.rg-ob-marquee .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-marquee .rg-ob-cta{background:var(--c);color:#fff}' +
    '.rg-ob-marquee .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-marquee .rg-ob-dish{right:0;top:8cqw;bottom:0;width:38cqw;height:auto;-webkit-mask:linear-gradient(90deg,transparent,#000 35%);mask:linear-gradient(90deg,transparent,#000 35%);animation:rgObZoom 9s ease-in-out infinite alternate}' +
    '.rg-ob-marquee .rg-ob-nodish{position:absolute;right:-8cqw;top:8cqw;bottom:-10cqw;width:44cqw;border-radius:50%;background:var(--c3)}' +

    /* Split: diagonal colour panel slides over the dish */
    '.rg-ob-split{background:var(--c3)}' +
    '.rg-ob-split .rg-ob-dish{inset:0 0 0 auto;width:62%;height:100%;animation:rgObZoom 10s ease-in-out infinite alternate}' +
    '.rg-ob-split .rg-ob-panel{position:absolute;inset:0;z-index:1;background:linear-gradient(135deg,var(--c),var(--c2));clip-path:polygon(0 0,66% 0,52% 100%,0 100%)}' +
    '.is-on .rg-ob-split .rg-ob-panel,.rg-ob-split.rg-ob-live .rg-ob-panel{animation:rgObWipe .8s cubic-bezier(.2,.9,.3,1) both}' +
    '.is-on .rg-ob-split .rg-ob-copy>*,.rg-ob-split.rg-ob-live .rg-ob-copy>*{animation:rgObSlideIn .6s ease both}' +
    '.rg-ob-split .rg-ob-copy>*:nth-child(2){animation-delay:.12s!important}.rg-ob-split .rg-ob-copy>*:nth-child(3){animation-delay:.24s!important}.rg-ob-split .rg-ob-copy>*:nth-child(4){animation-delay:.36s!important}' +
    '.rg-ob-split .rg-ob-copy{width:46cqw}' +

    '@keyframes rgObSpin{to{transform:rotate(360deg)}}' +
    '@keyframes rgObFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-1.6cqw)}}' +
    '@keyframes rgObSpinIn{from{transform:rotate(-120deg) scale(.4);opacity:0}to{transform:none;opacity:1}}' +
    '@keyframes rgObPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.05)}}' +
    '@keyframes rgObShine{0%,55%{left:-40%}100%{left:130%}}' +
    '@keyframes rgObGlow{0%,100%{opacity:.85}50%{opacity:.45}}' +
    '@keyframes rgObFlicker{0%,18%,22%,62%,64%,100%{opacity:1}20%,63%{opacity:.35}}' +
    '@keyframes rgObFall{0%{transform:translateY(0) rotate(0)}100%{transform:translateY(60cqw) rotate(540deg)}}' +
    '@keyframes rgObPop{from{transform:scale(.3);opacity:0}to{transform:none;opacity:1}}' +
    '@keyframes rgObSway{0%,100%{transform:rotate(6deg)}50%{transform:rotate(-2deg) translateY(-1cqw)}}' +
    '@keyframes rgObTicker{to{transform:translateX(-100%)}}' +
    '@keyframes rgObZoom{from{transform:scale(1)}to{transform:scale(1.12)}}' +
    '@keyframes rgObWipe{from{clip-path:polygon(0 0,0 0,0 100%,0 100%)}}' +
    '@keyframes rgObSlideIn{from{transform:translateX(-6cqw);opacity:0}to{transform:none;opacity:1}}' +
    '@media (prefers-reduced-motion:reduce){.rg-ob *,.rg-ob::before{animation:none!important}}';

  var CSS2 = '' +
    /* Options: accent colour, speed, text colour, font, photo shape */
    '.rg-ob{--a:#fde047;--spd:1}' +
    '.rg-ob[data-speed="calm"]{--spd:1.7}.rg-ob[data-speed="lively"]{--spd:.6}' +
    '.rg-ob[data-speed="off"] *,.rg-ob[data-speed="off"]::before,.rg-ob[data-speed="off"]::after,.rg-ob[data-speed="off"] *::before,.rg-ob[data-speed="off"] *::after{animation:none!important}' +
    '.rg-ob.tx-dark{color:#1f2937}.rg-ob.tx-dark .rg-ob-cta{background:#1f2937;color:#fff}.rg-ob.tx-dark .rg-ob-code{background:rgba(0,0,0,.06)}' +
    '.rg-ob[data-font="serif"] .rg-ob-title{font-family:"Playfair Display",Georgia,"Times New Roman",serif;text-transform:none;font-style:italic;letter-spacing:0}' +
    '.rg-ob[data-font="rounded"] .rg-ob-title,.rg-ob[data-font="rounded"] .rg-ob-sub{font-family:Nunito,"Varela Round","Trebuchet MS",sans-serif}' +
    '.rg-ob[data-font="condensed"] .rg-ob-title{font-family:Oswald,"Bebas Neue",Impact,"Arial Narrow",sans-serif;font-weight:700;letter-spacing:.01em}' +
    '.rg-ob[data-shape="rounded"] .rg-ob-dish.framed{border-radius:4cqw!important}.rg-ob[data-shape="square"] .rg-ob-dish.framed{border-radius:1cqw!important}' +
    '.rg-ob-timer{position:absolute;top:2.4cqw;right:2.4cqw;z-index:6;display:inline-flex;align-items:center;gap:1cqw;padding:.9cqw 2cqw;border-radius:999px;background:rgba(0,0,0,.55);-webkit-backdrop-filter:blur(4px);backdrop-filter:blur(4px);color:#fff;font-size:2.4cqw;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}' +
    '.rg-ob-timer::before{content:"";width:1.4cqw;height:1.4cqw;border-radius:50%;background:var(--a);box-shadow:0 0 0 .5cqw rgba(255,255,255,.18);animation:rgObBlink calc(1s * var(--spd)) steps(2) infinite}' +
    '.rg-ob-dish.framed{object-fit:cover}' +

    /* Countdown: live HH MM SS blocks */
    '.rg-ob-countdown{background:radial-gradient(circle at 80% 50%,var(--c) 0,var(--c2) 55%,#0b0b12 100%)}' +
    '.rg-ob-countdown .rg-ob-copy{width:50cqw}' +
    '.rg-ob-cd{position:absolute;right:5cqw;top:50%;transform:translateY(-50%);display:flex;gap:1.6cqw;z-index:2}' +
    '.rg-ob-cd span{display:flex;flex-direction:column;align-items:center;gap:.8cqw}' +
    '.rg-ob-cd b{width:10.5cqw;height:12.5cqw;display:grid;place-items:center;border-radius:2cqw;background:rgba(255,255,255,.12);border:.3cqw solid rgba(255,255,255,.22);font-size:6.6cqw;font-weight:900;color:#fff;font-variant-numeric:tabular-nums;box-shadow:inset 0 -3cqw 0 rgba(0,0,0,.12)}' +
    '.rg-ob-cd b[data-cd="s"]{color:var(--a);animation:rgObTick 1s ease-out infinite}' +
    '.rg-ob-cd small{font-size:1.9cqw;font-weight:700;letter-spacing:.16em;opacity:.75}' +

    /* Aurora: slowly moving colour gradient */
    '.rg-ob-aurora{background:linear-gradient(120deg,var(--c),var(--a),var(--c2),var(--c));background-size:300% 300%;animation:rgObAurora 9s ease-in-out infinite}' +
    '.rg-ob-aurora::before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 20% 30%,rgba(255,255,255,.35),transparent 40%);mix-blend-mode:soft-light}' +
    '.rg-ob-aurora .rg-ob-title{text-shadow:0 .6cqw 2cqw rgba(0,0,0,.25)}' +
    '.rg-ob-aurora .rg-ob-dish{right:6cqw;top:50%;width:30cqw;height:30cqw;margin-top:-15cqw;border-radius:50%;box-shadow:0 0 0 1cqw rgba(255,255,255,.35),0 2cqw 6cqw rgba(0,0,0,.3);animation:rgObFloat 5s ease-in-out infinite}' +

    /* Stamp: rotating circular text badge */
    '.rg-ob-stamp{background:var(--c3);color:#1f2937}' +
    '.rg-ob-stamp .rg-ob-title{color:var(--c)}.rg-ob-stamp .rg-ob-kick{color:var(--c2)}.rg-ob-stamp .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-stamp .rg-ob-cta{background:var(--c);color:#fff}.rg-ob-stamp .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-badge{position:absolute;right:5cqw;top:50%;width:34cqw;height:34cqw;margin-top:-17cqw;z-index:1}' +
    '.rg-ob-badge svg{position:absolute;inset:0;width:100%;height:100%;animation:rgObSpin 16s linear infinite;overflow:visible}' +
    '.rg-ob-badge text{font:800 8.6px Poppins,system-ui,sans-serif;letter-spacing:2.4px;fill:var(--c2)}' +
    '.rg-ob-badge .rg-ob-core{position:absolute;inset:7cqw;border-radius:50%;background:var(--c);display:grid;place-items:center;overflow:hidden;box-shadow:0 1.5cqw 4cqw rgba(0,0,0,.18)}' +
    '.rg-ob-badge .rg-ob-core img{width:100%;height:100%;object-fit:cover}' +
    '.rg-ob-badge .rg-ob-core i{width:8cqw;height:8cqw;background:var(--a);clip-path:polygon(50% 0,61% 35%,98% 35%,68% 57%,79% 91%,50% 70%,21% 91%,32% 57%,2% 35%,39% 35%)}' +

    /* Bubbles: rising bubbles */
    '.rg-ob-bubbles{background:linear-gradient(160deg,var(--c),var(--c2))}' +
    '.rg-ob-bubbles .rg-ob-fx i{position:absolute;bottom:-12cqw;border-radius:50%;z-index:1;background:radial-gradient(circle at 30% 30%,rgba(255,255,255,.55),rgba(255,255,255,.08) 60%);border:.25cqw solid rgba(255,255,255,.35);animation:rgObRise linear infinite}' +
    '.rg-ob-bubbles .rg-ob-dish{right:6cqw;top:50%;width:30cqw;height:30cqw;margin-top:-15cqw;border-radius:50%;border:1cqw solid rgba(255,255,255,.85);animation:rgObFloat 4.5s ease-in-out infinite}' +

    /* Stripes: moving diagonal stripes, copy on a white panel */
    '.rg-ob-stripes{background:repeating-linear-gradient(-45deg,var(--c) 0 3cqw,var(--c2) 3cqw 6cqw);background-size:8.49cqw 8.49cqw;animation:rgObStripes 1.6s linear infinite;color:#1f2937}' +
    '.rg-ob-stripes .rg-ob-copy{left:4cqw;width:58cqw;padding:3cqw 3.6cqw;border-radius:3cqw;background:#fff;box-shadow:0 1.5cqw 4cqw rgba(0,0,0,.2)}' +
    '.rg-ob-stripes .rg-ob-title{color:var(--c)}.rg-ob-stripes .rg-ob-kick{color:var(--c2)}.rg-ob-stripes .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-stripes .rg-ob-cta{background:var(--c);color:#fff}.rg-ob-stripes .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-stripes .rg-ob-dish{right:5cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:3cqw;border:1cqw solid #fff;transform:rotate(5deg);box-shadow:0 2cqw 5cqw rgba(0,0,0,.3);animation:rgObSway 5s ease-in-out infinite}' +

    /* Spotlight: dark stage, sweeping light over the dish */
    '.rg-ob-spotlight{background:#0b0b10}' +
    '.rg-ob-spotlight::before{content:"";position:absolute;top:-30cqw;left:50%;width:70cqw;height:120cqw;margin-left:-35cqw;z-index:0;background:radial-gradient(ellipse at 50% 70%,color-mix(in srgb,var(--c) 55%,#fff) 0,transparent 55%);opacity:.55;transform-origin:50% 0;animation:rgObSweep 6s ease-in-out infinite}' +
    '.rg-ob-spotlight .rg-ob-kick{color:var(--a)}.rg-ob-spotlight .rg-ob-cta{background:var(--c);color:#fff}' +
    '.rg-ob-spotlight .rg-ob-dish{right:7cqw;top:50%;width:30cqw;height:30cqw;margin-top:-15cqw;border-radius:50%;box-shadow:0 0 6cqw color-mix(in srgb,var(--c) 70%,transparent)}' +

    /* Gift box: lid bounces, sparkles */
    '.rg-ob-gift{background:radial-gradient(circle at 78% 55%,color-mix(in srgb,var(--c) 70%,#fff) 0,var(--c) 30%,var(--c2) 100%)}' +
    '.rg-ob-box{position:absolute;right:9cqw;bottom:6cqw;width:24cqw;height:20cqw;z-index:1}' +
    '.rg-ob-box i{position:absolute;display:block}' +
    '.rg-ob-box .b{left:1.5cqw;right:1.5cqw;bottom:0;height:14cqw;border-radius:1cqw;background:var(--a);box-shadow:inset -3cqw 0 0 rgba(0,0,0,.1)}' +
    '.rg-ob-box .l{left:0;right:0;top:2cqw;height:5cqw;border-radius:1cqw;background:color-mix(in srgb,var(--a) 85%,#fff);box-shadow:0 .8cqw 0 rgba(0,0,0,.12);animation:rgObLid 2.4s ease-in-out infinite;transform-origin:20% 100%}' +
    '.rg-ob-box .r{left:50%;width:3.6cqw;margin-left:-1.8cqw;bottom:0;height:19cqw;background:#fff;opacity:.9;border-radius:.4cqw}' +
    '.rg-ob-box .k{left:50%;top:-3cqw;width:9cqw;height:5cqw;margin-left:-4.5cqw;border:1.2cqw solid #fff;border-radius:50% 50% 0 50%;transform:rotate(45deg);border-bottom-color:transparent;border-right-color:transparent;animation:rgObLid 2.4s ease-in-out infinite}' +
    '.rg-ob-sparks i{position:absolute;width:1.6cqw;height:1.6cqw;background:#fff;clip-path:polygon(50% 0,60% 40%,100% 50%,60% 60%,50% 100%,40% 60%,0 50%,40% 40%);z-index:2;animation:rgObTwinkle 1.8s ease-in-out infinite}' +

    /* Price tag: the headline on a swinging tag */
    '.rg-ob-tag{background:var(--c3);color:#1f2937}' +
    '.rg-ob-tag .rg-ob-kick{color:var(--c2)}.rg-ob-tag .rg-ob-sub{color:#374151;font-size:4cqw;font-weight:700}' +
    '.rg-ob-tag .rg-ob-cta{background:var(--c);color:#fff}.rg-ob-tag .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-tag .rg-ob-copy{width:50cqw}' +
    '.rg-ob-hang{position:absolute;right:8cqw;top:0;width:34cqw;height:100%;z-index:1;transform-origin:50% 2cqw;animation:rgObSwing 3s ease-in-out infinite}' +
    '.rg-ob-hang::before{content:"";position:absolute;left:50%;top:0;width:.4cqw;height:7cqw;background:#9ca3af}' +
    '.rg-ob-hang .t{position:absolute;left:0;right:0;top:7cqw;bottom:5cqw;display:grid;place-items:center;padding:3cqw 3cqw 2cqw 8cqw;background:var(--c);color:#fff;border-radius:2cqw;clip-path:polygon(14% 0,100% 0,100% 100%,14% 100%,0 50%);box-shadow:0 2cqw 4cqw rgba(0,0,0,.2)}' +
    '.rg-ob-hang .t::before{content:"";position:absolute;left:5cqw;top:50%;width:3cqw;height:3cqw;margin-top:-1.5cqw;border-radius:50%;background:var(--c3)}' +
    '.rg-ob-hang .t b{font-size:7cqw;font-weight:900;line-height:1;text-align:center;text-transform:uppercase}' +
    '.rg-ob-hang .t b.long{font-size:4.8cqw}.rg-ob-hang .t b.xlong{font-size:3.4cqw}' +

    /* Waves */
    '.rg-ob-wave{background:linear-gradient(180deg,var(--c),var(--c2))}' +
    '.rg-ob-wave .w{position:absolute;left:0;right:0;bottom:0;height:12cqw;z-index:1;background:radial-gradient(circle at 50% 0,transparent 5.6cqw,rgba(255,255,255,.16) 5.8cqw) 0 0/16cqw 12cqw repeat-x;animation:rgObWaveX 6s linear infinite}' +
    '.rg-ob-wave .w2{bottom:-3cqw;opacity:.7;background-size:22cqw 12cqw;animation-duration:9s;animation-direction:reverse}' +
    '.rg-ob-wave .rg-ob-dish{right:6cqw;top:50%;width:30cqw;height:30cqw;margin-top:-17cqw;border-radius:4cqw;border:.8cqw solid rgba(255,255,255,.85);z-index:2;animation:rgObBob 4s ease-in-out infinite}' +

    /* Orbit: rings with dots circling the dish */
    '.rg-ob-orbit{background:radial-gradient(circle at 76% 50%,var(--c) 0,var(--c2) 60%)}' +
    '.rg-ob-orbit .rg-ob-dish{right:9cqw;top:50%;width:24cqw;height:24cqw;margin-top:-12cqw;border-radius:50%;border:.8cqw solid #fff;z-index:2}' +
    '.rg-ob-ring{position:absolute;right:3cqw;top:50%;width:36cqw;height:36cqw;margin-top:-18cqw;border-radius:50%;border:.3cqw dashed rgba(255,255,255,.4);z-index:1;animation:rgObSpin 10s linear infinite}' +
    '.rg-ob-ring::before,.rg-ob-ring::after{content:"";position:absolute;width:2.8cqw;height:2.8cqw;border-radius:50%;background:var(--a);box-shadow:0 0 2cqw var(--a)}' +
    '.rg-ob-ring::before{top:-1.4cqw;left:50%;margin-left:-1.4cqw}.rg-ob-ring::after{bottom:3cqw;left:3cqw;width:1.8cqw;height:1.8cqw;background:#fff}' +
    '.rg-ob-ring.r2{right:-1cqw;width:44cqw;height:44cqw;margin-top:-22cqw;animation-duration:16s;animation-direction:reverse;border-style:solid;border-color:rgba(255,255,255,.15)}' +

    /* Typewriter: the headline types itself */
    '.rg-ob-typewriter{background:#fffdf7;color:#1f2937;background-image:repeating-linear-gradient(0deg,transparent 0 5.6cqw,rgba(0,0,0,.04) 5.6cqw 5.8cqw)}' +
    '.rg-ob-typewriter .rg-ob-kick{color:var(--c2)}.rg-ob-typewriter .rg-ob-sub{color:#4b5563;background:linear-gradient(transparent 60%,color-mix(in srgb,var(--a) 70%,transparent) 60%);display:inline}' +
    '.rg-ob-typewriter .rg-ob-cta{background:var(--c);color:#fff}.rg-ob-typewriter .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-typewriter .rg-ob-title{font-family:"Courier New",ui-monospace,monospace;color:var(--c);text-transform:none;white-space:nowrap;overflow:hidden;width:0;border-right:.6cqw solid var(--c);padding-right:.4cqw;animation:rgObType 4.5s steps(var(--n),end) infinite,rgObCaret .8s steps(1) infinite}' +
    '.rg-ob-typewriter .rg-ob-dish{right:6cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:2cqw;transform:rotate(-4deg);box-shadow:0 1.6cqw 4cqw rgba(0,0,0,.18)}' +
    '.rg-ob-typewriter .rg-ob-dish+.rg-ob-tape{position:absolute;right:16cqw;top:5cqw;width:12cqw;height:3.4cqw;background:color-mix(in srgb,var(--a) 70%,#fff);opacity:.8;transform:rotate(-8deg);z-index:2}' +

    /* Gold: luxury shimmer on black */
    '.rg-ob-gold{background:radial-gradient(circle at 75% 50%,#2a2112 0,#0a0806 70%);color:#f5e7c4}' +
    '.rg-ob-gold::before{content:"";position:absolute;inset:3cqw;border:.3cqw solid #c9a24a;border-radius:2cqw;opacity:.6}' +
    '.rg-ob-gold .rg-ob-title{background:linear-gradient(100deg,#a67c2d 20%,#fff4c8 40%,#d4a843 55%,#a67c2d 80%);background-size:250% 100%;-webkit-background-clip:text;background-clip:text;color:transparent;animation:rgObShimmer 3.5s linear infinite}' +
    '.rg-ob-gold .rg-ob-kick{color:#c9a24a}.rg-ob-gold .rg-ob-code{color:#f5e7c4;border-color:#c9a24a;background:transparent}' +
    '.rg-ob-gold .rg-ob-cta{background:linear-gradient(135deg,#d4a843,#a67c2d);color:#1a1206}' +
    '.rg-ob-gold .rg-ob-dish{right:7cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:50%;box-shadow:0 0 0 .5cqw #c9a24a,0 0 6cqw rgba(201,162,74,.35)}' +

    /* Pulse: sonar rings behind the dish */
    '.rg-ob-pulse{background:linear-gradient(135deg,var(--c),var(--c2))}' +
    '.rg-ob-pulse .rg-ob-dish{right:9cqw;top:50%;width:26cqw;height:26cqw;margin-top:-13cqw;border-radius:50%;border:.8cqw solid #fff;z-index:2}' +
    '.rg-ob-sonar{position:absolute;right:9cqw;top:50%;width:26cqw;height:26cqw;margin-top:-13cqw;border-radius:50%;z-index:1}' +
    '.rg-ob-sonar i{position:absolute;inset:0;border-radius:50%;border:.5cqw solid rgba(255,255,255,.7);animation:rgObSonar 2.7s ease-out infinite}' +
    '.rg-ob-sonar i:nth-child(2){animation-delay:.9s}.rg-ob-sonar i:nth-child(3){animation-delay:1.8s}' +

    /* Bounce: each letter bounces in turn */
    '.rg-ob-bounce{background:radial-gradient(circle at 15% 20%,color-mix(in srgb,var(--c) 70%,#fff),var(--c) 45%,var(--c2))}' +
    '.rg-ob-bounce .rg-ob-title span{display:inline-block;animation:rgObHop 1.6s ease-in-out infinite}' +
    '.rg-ob-bounce .rg-ob-title span.sp{width:.3em}' +
    '.rg-ob-bounce .rg-ob-dish{right:6cqw;top:50%;width:30cqw;height:30cqw;margin-top:-15cqw;border-radius:50%;border:1cqw solid var(--a);animation:rgObBob 1.6s ease-in-out infinite}' +

    /* Polaroid: tilting photo frame */
    '.rg-ob-polaroid{background:#f4ede3;color:#1f2937;background-image:radial-gradient(rgba(0,0,0,.05) 1px,transparent 1.2px);background-size:2.4cqw 2.4cqw}' +
    '.rg-ob-polaroid .rg-ob-title{color:var(--c)}.rg-ob-polaroid .rg-ob-kick{color:var(--c2)}.rg-ob-polaroid .rg-ob-sub{color:#4b5563}' +
    '.rg-ob-polaroid .rg-ob-cta{background:var(--c);color:#fff}.rg-ob-polaroid .rg-ob-code{color:var(--c2)}' +
    '.rg-ob-frame{position:absolute;right:6cqw;top:50%;width:28cqw;margin-top:-17cqw;padding:1.6cqw 1.6cqw 6cqw;background:#fff;box-shadow:0 1.5cqw 4cqw rgba(0,0,0,.18);z-index:1;transform-origin:50% 0;animation:rgObTilt 4s ease-in-out infinite}' +
    '.rg-ob-frame img,.rg-ob-frame .ph{display:block;width:100%;aspect-ratio:1;object-fit:cover;background:var(--c3)}' +
    '.rg-ob-frame em{position:absolute;left:0;right:0;bottom:1.4cqw;text-align:center;font:italic 700 2.8cqw "Segoe Print","Bradley Hand","Comic Sans MS",cursive;color:#374151}' +
    '.rg-ob-frame::before{content:"";position:absolute;top:-1.6cqw;left:50%;width:10cqw;height:3cqw;margin-left:-5cqw;background:color-mix(in srgb,var(--a) 60%,#fff);opacity:.85;transform:rotate(-3deg)}' +

    /* Festive lights: twinkling bulbs along the top */
    '.rg-ob-festive{background:linear-gradient(160deg,var(--c2),#1a0b16)}' +
    '.rg-ob-wire{position:absolute;left:-2cqw;right:-2cqw;top:-6cqw;height:12cqw;border-bottom:.35cqw solid rgba(255,255,255,.35);border-radius:0 0 50% 50%;z-index:2}' +
    '.rg-ob-bulbs i{position:absolute;width:2.2cqw;height:3cqw;border-radius:50% 50% 50% 50%/60% 60% 40% 40%;z-index:3;animation:rgObBulb 1.4s ease-in-out infinite}' +
    '.rg-ob-festive .rg-ob-copy{top:calc(50% + 3cqw)}.rg-ob-festive .rg-ob-kick{color:var(--a)}' +
    '.rg-ob-festive .rg-ob-dish{right:6cqw;top:50%;width:28cqw;height:28cqw;margin-top:-11cqw;border-radius:50%;border:.8cqw solid var(--a);box-shadow:0 0 5cqw color-mix(in srgb,var(--a) 50%,transparent)}' +

    '@keyframes rgObBlink{50%{opacity:.25}}' +
    '@keyframes rgObTick{0%{transform:scale(1.08)}25%{transform:none}}' +
    '@keyframes rgObAurora{0%,100%{background-position:0% 50%}50%{background-position:100% 50%}}' +
    '@keyframes rgObRise{0%{transform:translateY(0) scale(.6);opacity:0}15%{opacity:1}100%{transform:translateY(-60cqw) scale(1.1);opacity:0}}' +
    '@keyframes rgObStripes{to{background-position:8.49cqw 0}}' +
    '@keyframes rgObSweep{0%,100%{transform:rotate(-22deg)}50%{transform:rotate(22deg)}}' +
    '@keyframes rgObLid{0%,60%,100%{transform:translateY(0) rotate(0)}70%{transform:translateY(-4cqw) rotate(-10deg)}80%{transform:translateY(-1cqw) rotate(4deg)}}' +
    '@keyframes rgObTwinkle{0%,100%{transform:scale(.4);opacity:.3}50%{transform:scale(1.2);opacity:1}}' +
    '@keyframes rgObSwing{0%,100%{transform:rotate(5deg)}50%{transform:rotate(-5deg)}}' +
    '@keyframes rgObWaveX{to{background-position-x:16cqw}}' +
    '@keyframes rgObBob{0%,100%{transform:translateY(0)}50%{transform:translateY(-2cqw)}}' +
    '@keyframes rgObType{0%{width:0}55%,90%{width:calc(var(--n) * 1ch + .4cqw)}100%{width:0}}' +
    '@keyframes rgObCaret{50%{border-right-color:transparent}}' +
    '@keyframes rgObShimmer{to{background-position:-250% 0}}' +
    '@keyframes rgObSonar{0%{transform:scale(1);opacity:.9}100%{transform:scale(1.8);opacity:0}}' +
    '@keyframes rgObHop{0%,60%,100%{transform:translateY(0)}30%{transform:translateY(-1.6cqw)}}' +
    '@keyframes rgObTilt{0%,100%{transform:rotate(-5deg)}50%{transform:rotate(3deg)}}' +
    '@keyframes rgObBulb{0%,100%{opacity:1;box-shadow:0 0 2.4cqw currentColor}50%{opacity:.35;box-shadow:none}}' +
    /* Text that would not fit steps down (set by fit()) */
    '.rg-ob.tight .rg-ob-copy{gap:1cqw}.rg-ob.tight .rg-ob-title{font-size:8.4cqw}.rg-ob.tight .rg-ob-title.long{font-size:5.9cqw}.rg-ob.tight .rg-ob-title.xlong{font-size:4.5cqw}' +
    '.rg-ob.tight .rg-ob-sub{font-size:3.1cqw}.rg-ob.tight .rg-ob-kick{font-size:2.5cqw}.rg-ob.tight .rg-ob-row{gap:1.4cqw;margin-top:0}' +
    '.rg-ob.tight .rg-ob-code{padding:.8cqw 1.8cqw;font-size:2.7cqw}.rg-ob.tight .rg-ob-cta{padding:1.1cqw 2.6cqw;font-size:2.8cqw}' +
    '.rg-ob.tighter .rg-ob-kick{display:none}.rg-ob.tighter .rg-ob-sub{display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}';

  var CSS3 = '' +
    /* Flash sale: hazard stripes + flickering bolt */
    '.rg-ob-flash{--safe:4.8;background:radial-gradient(circle at 80% 50%,color-mix(in srgb,var(--c) 75%,#fff),var(--c) 45%,var(--c2))}' +
    '.rg-ob-flash::before,.rg-ob-flash::after{content:"";position:absolute;left:0;right:0;height:4.4cqw;z-index:3;background:repeating-linear-gradient(-45deg,#111 0 2.5cqw,var(--a) 2.5cqw 5cqw);background-size:7.07cqw 7.07cqw;animation:rgObStripes 1.2s linear infinite}' +
    '.rg-ob-flash::before{top:0}.rg-ob-flash::after{bottom:0;animation-direction:reverse}' +
    '.rg-ob-flash .rg-ob-kick{color:var(--a)}' +
    '.rg-ob-bolt{position:absolute;right:9cqw;top:50%;width:19cqw;height:29cqw;margin-top:-14.5cqw;z-index:2;background:var(--a);clip-path:polygon(58% 0,8% 56%,44% 56%,28% 100%,92% 40%,56% 40%,78% 0);animation:rgObFlash 1.8s steps(1) infinite}' +
    '.rg-ob-flash .rg-ob-dish{right:7cqw;top:50%;width:28cqw;height:28cqw;margin-top:-14cqw;border-radius:50%;border:.8cqw solid #111}' +
    '.rg-ob-flash.has-dish .rg-ob-bolt{right:28cqw;top:22%;width:10cqw;height:15cqw;margin-top:0}' +

    /* Scratch card: foil wipes off to show the code, then covers it again */
    '.rg-ob-scratch{background:linear-gradient(135deg,var(--c),var(--c2))}' +
    '.rg-ob-scr{position:absolute;right:6cqw;top:50%;width:32cqw;margin-top:-13cqw;z-index:2;text-align:center}' +
    '.rg-ob-scr small{display:block;margin-bottom:1.2cqw;font-size:2.2cqw;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--a)}' +
    '.rg-ob-scr .card{position:relative;height:20cqw;border-radius:2.4cqw;background:#fff;display:grid;place-items:center;overflow:hidden;box-shadow:0 1.6cqw 4cqw rgba(0,0,0,.25)}' +
    '.rg-ob-scr .card b{font-size:4.6cqw;font-weight:900;letter-spacing:.06em;color:var(--c2);word-break:break-all;padding:0 2cqw}' +
    '.rg-ob-scr .foil{position:absolute;inset:0;background:linear-gradient(110deg,#9ca3af,#f3f4f6 30%,#a8b0bb 50%,#e5e7eb 70%,#9ca3af);background-size:200% 100%;animation:rgObFoil 6s ease-in-out infinite}' +
    '.rg-ob-scr .foil::after{content:"SCRATCH";position:absolute;inset:0;display:grid;place-items:center;font-size:3cqw;font-weight:900;letter-spacing:.3em;color:rgba(255,255,255,.75)}' +

    /* Hearts: hearts float up */
    '.rg-ob-hearts{background:linear-gradient(150deg,color-mix(in srgb,var(--c) 70%,#fff),var(--c) 50%,var(--c2))}' +
    '.rg-ob-hearts .rg-ob-fx i{position:absolute;bottom:-8cqw;font-style:normal;line-height:1;z-index:1;color:rgba(255,255,255,.75);animation:rgObRise linear infinite}' +
    '.rg-ob-hearts .rg-ob-fx i:nth-child(3n){color:var(--a)}' +
    '.rg-ob-hearts .rg-ob-dish{right:6cqw;top:50%;width:30cqw;height:30cqw;margin-top:-15cqw;border-radius:50%;border:1cqw solid #fff;box-shadow:0 0 0 1.2cqw rgba(255,255,255,.25);animation:rgObBeat 1.4s ease-in-out infinite}' +

    /* Snow: gentle snowfall */
    '.rg-ob-snow{background:linear-gradient(180deg,color-mix(in srgb,var(--c) 45%,#0b1e3a),#0b1e3a)}' +
    '.rg-ob-snow .rg-ob-fx i{position:absolute;top:-4cqw;border-radius:50%;background:#fff;z-index:3;opacity:.9;animation:rgObSnow linear infinite}' +
    '.rg-ob-snow::after{content:"";position:absolute;left:0;right:0;bottom:0;height:5cqw;z-index:1;background:radial-gradient(ellipse at 30% 100%,#fff 0 40%,transparent 41%) 0 100%/30cqw 5cqw repeat-x;opacity:.9}' +
    '.rg-ob-snow .rg-ob-kick{color:#bae6fd}' +
    '.rg-ob-snow .rg-ob-dish{right:6cqw;top:50%;width:29cqw;height:29cqw;margin-top:-15cqw;border-radius:50%;border:.9cqw solid #fff;box-shadow:0 0 5cqw rgba(186,230,253,.45)}' +

    /* Sunrise: sun with turning rays, dish inside */
    '.rg-ob-sunrise{background:linear-gradient(180deg,color-mix(in srgb,var(--a) 45%,var(--c)),var(--c) 60%,var(--c2))}' +
    '.rg-ob-sun{position:absolute;right:3cqw;bottom:-12cqw;width:40cqw;height:40cqw;z-index:1}' +
    '.rg-ob-sun::before{content:"";position:absolute;inset:-22cqw;border-radius:50%;background:repeating-conic-gradient(rgba(255,255,255,.22) 0 6deg,transparent 6deg 16deg);-webkit-mask:radial-gradient(circle,#000 30%,transparent 70%);mask:radial-gradient(circle,#000 30%,transparent 70%);animation:rgObSpin 30s linear infinite}' +
    '.rg-ob-sun i{position:absolute;inset:3cqw;border-radius:50%;overflow:hidden;background:var(--a);box-shadow:0 0 8cqw var(--a)}' +
    '.rg-ob-sun i img{width:100%;height:100%;object-fit:cover}' +

    /* Cinema: full-bleed dish, slow zoom, film bars */
    '.rg-ob-cinema{--safe:3.8;background:linear-gradient(135deg,var(--c),var(--c2))}' +
    '.rg-ob-cinema .rg-ob-dish{inset:0;width:100%;height:100%;z-index:0;animation:rgObKen 14s ease-in-out infinite alternate}' +
    '.rg-ob-cinema::after{content:"";position:absolute;inset:0;z-index:1;background:linear-gradient(#000,#000) top/100% 3.4cqw no-repeat,linear-gradient(#000,#000) bottom/100% 3.4cqw no-repeat,linear-gradient(90deg,rgba(0,0,0,.85) 0,rgba(0,0,0,.55) 45%,rgba(0,0,0,0) 78%)}' +
    '.rg-ob-cinema .rg-ob-kick{color:var(--a)}' +
    '.rg-ob-cinema .rg-ob-cta{background:var(--c);color:#fff}' +

    '@keyframes rgObFlash{0%,100%{opacity:1}44%{opacity:.25}48%{opacity:1}54%{opacity:.4}58%{opacity:1}}' +
    '@keyframes rgObFoil{0%,12%{clip-path:inset(0 0 0 0)}42%,78%{clip-path:inset(0 0 0 100%)}100%{clip-path:inset(0 0 0 0)}}' +
    '@keyframes rgObBeat{0%,100%{transform:scale(1)}15%{transform:scale(1.06)}30%{transform:scale(1)}45%{transform:scale(1.04)}}' +
    '@keyframes rgObSnow{0%{transform:translate(0,0)}50%{transform:translate(2cqw,25cqw)}100%{transform:translate(-1cqw,52cqw)}}' +
    '@keyframes rgObKen{from{transform:scale(1.04)}to{transform:scale(1.18) translate(-3%,-2%)}}';
  // Speed option: every animation duration is multiplied by --spd (Calm 1.7, Normal 1, Lively .6)
  function scaleAnims(css) {
    return css.replace(/animation:([^;}]+)/g, function (m) {
      return m.replace(/(-?\d*\.?\d+)s\b/g, 'calc($1s * var(--spd, 1))');
    });
  }
  function ensureCss() {
    if (document.getElementById('rgBannersCss')) return;
    var st = document.createElement('style');
    st.id = 'rgBannersCss';
    st.textContent = scaleAnims(CSS + CSS3 + CSS2);
    document.head.appendChild(st);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }

  var CONFETTI = ['#ffffff', '#fde047', '#22d3ee', '#a3e635', '#f472b6', '#fb923c'];
  var TEMPLATES = ['burst', 'ticket', 'neon', 'confetti', 'marquee', 'split', 'countdown', 'aurora', 'stamp', 'bubbles', 'stripes',
    'spotlight', 'gift', 'tag', 'wave', 'orbit', 'typewriter', 'gold', 'pulse', 'bounce', 'polaroid', 'festive',
    'flash', 'scratch', 'hearts', 'snow', 'sunrise', 'cinema'];
  // Styles whose dish photo sits in a frame (the "photo shape" option applies)
  var FRAMED = { burst: 1, neon: 1, confetti: 1, aurora: 1, bubbles: 1, stripes: 1, spotlight: 1, wave: 1, orbit: 1, typewriter: 1, gold: 1, pulse: 1, bounce: 1, festive: 1, flash: 1, hearts: 1, snow: 1 };
  var uid = 0;

  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  // Seconds left until the banner ends; without an end time the Countdown style counts to midnight
  function secondsLeft(endsTs) {
    var end = endsTs ? endsTs * 1000 : new Date().setHours(24, 0, 0, 0);
    return Math.max(0, Math.floor((end - Date.now()) / 1000));
  }
  function timerText(left) {
    if (left <= 0) return 'Offer ended';
    var d = Math.floor(left / 86400), h = Math.floor(left % 86400 / 3600), m = Math.floor(left % 3600 / 60), sec = left % 60;
    return 'Ends in ' + (d ? d + 'd ' : '') + pad2(h) + ':' + pad2(m) + ':' + pad2(sec);
  }

  /* One offer banner's HTML. b: {template,title,subtitle,code,cta,color,image, accent,kicker,speed,font,shape,text,countdown,ends_ts}; opts.imgBase for the dish photo URL. */
  function offerHtml(b, opts) {
    opts = opts || {};
    var tpl = TEMPLATES.indexOf(b.template) > -1 ? b.template : 'burst';
    var hex = /^#[0-9a-fA-F]{6}$/;
    var color = hex.test(b.color || '') ? b.color : '#e11d48';
    var accent = hex.test(b.accent || '') ? b.accent : '';
    var title = String(b.title || '');
    var tCls = title.length > 22 ? ' xlong' : (title.length > 11 ? ' long' : '');
    var img = '';
    if (b.image) {
      var ref = String(b.image);
      // a dish photo from the database: image.php's small WebP copy is plenty for the banner
      img = /^(https?:|data:)/.test(ref) ? ref : (opts.imgBase || 'image.php?id=') + encodeURIComponent(ref.replace(/^db:/, '')) + (ref.indexOf('db:') === 0 ? '&w=400' : '');
    }
    var dish = img ? '<img class="rg-ob-dish' + (FRAMED[tpl] ? ' framed' : '') + '" src="' + esc(img) + '" alt="" loading="lazy">' : '';
    var kickText = b.kicker ? String(b.kicker) : (b.code ? 'Limited time' : 'Today only');
    var kick = tpl === 'marquee' ? '' : '<span class="rg-ob-kick">' + esc(kickText) + '</span>';
    var code = b.code ? '<span class="rg-ob-code" data-rg-code="' + esc(b.code) + '" title="Tap to copy"><small>CODE</small>' + esc(b.code) + '</span>' : '';
    var cta = b.cta ? '<span class="rg-ob-cta" role="button" tabindex="0" data-rg-cta>' + esc(b.cta) + '</span>' : '';
    var timer = b.countdown && b.ends_ts && tpl !== 'countdown' ? '<span class="rg-ob-timer" data-rg-ends="' + (+b.ends_ts) + '">' + timerText(secondsLeft(b.ends_ts)) + '</span>' : '';
    var titleHtml = esc(title);
    if (tpl === 'bounce') {
      titleHtml = title.split('').map(function (ch, i) {
        return ch === ' ' ? '<span class="sp"> </span>' : '<span style="animation-delay:' + (i * 0.07).toFixed(2) + 's">' + esc(ch) + '</span>';
      }).join('');
    }
    var titleTag = tpl === 'tag' ? '' : '<b class="rg-ob-title' + tCls + '"' + (tpl === 'typewriter' ? ' style="--n:' + Math.max(1, title.length) + '"' : '') + '>' + titleHtml + '</b>';
    var copy = '<div class="rg-ob-copy">' + kick + titleTag +
      (b.subtitle ? '<span class="rg-ob-sub">' + esc(b.subtitle) + '</span>' : '') +
      (code || cta ? '<div class="rg-ob-row">' + (tpl === 'ticket' ? '' : code) + cta + '</div>' : '') + '</div>';
    var inner = '', i, fx;
    if (tpl === 'ticket') {
      inner = '<div class="rg-ob-card">' + copy +
        '<div class="rg-ob-stub">' + (img ? '<img src="' + esc(img) + '" alt="">' : '') +
        (b.code ? '<small>Use code</small><b data-rg-code="' + esc(b.code) + '" style="cursor:pointer">' + esc(b.code) + '</b>' : '<small>Offer</small><b>Today</b>') +
        '</div><i class="rg-ob-shine"></i></div>';
    } else if (tpl === 'confetti') {
      fx = '';
      for (i = 0; i < 16; i++) {
        fx += '<i style="left:' + ((i * 37) % 100) + '%;background:' + (i % 3 === 0 && accent ? accent : CONFETTI[i % CONFETTI.length]) + ';animation-duration:calc(' + (2.6 + (i % 5) * 0.5) + 's * var(--spd));animation-delay:-' + ((i * 0.43) % 3).toFixed(2) + 's"></i>';
      }
      inner = '<div class="rg-ob-fx">' + fx + '</div>' + dish + copy;
    } else if (tpl === 'marquee') {
      var line = ((b.kicker ? String(b.kicker).toUpperCase() : 'LIMITED TIME OFFER') + '  ·  ' + (b.code ? 'USE CODE ' + b.code + '  ·  ' : '') + title.toUpperCase() + '  ·  ');
      inner = '<div class="rg-ob-strip"><span>' + esc(line + line + line) + '</span></div>' + (dish || '<div class="rg-ob-nodish"></div>') + copy;
    } else if (tpl === 'split') {
      inner = dish + '<div class="rg-ob-panel"></div>' + copy;
    } else if (tpl === 'countdown') {
      var left = secondsLeft(b.ends_ts), hh = Math.floor(left / 3600), mm = Math.floor(left % 3600 / 60), ss = left % 60;
      inner = copy + '<div class="rg-ob-cd" data-rg-cd="' + (+b.ends_ts || 0) + '">' +
        '<span><b data-cd="h">' + pad2(Math.min(hh, 99)) + '</b><small>HRS</small></span>' +
        '<span><b data-cd="m">' + pad2(mm) + '</b><small>MIN</small></span>' +
        '<span><b data-cd="s">' + pad2(ss) + '</b><small>SEC</small></span></div>';
    } else if (tpl === 'stamp') {
      var pid = 'rgObArc' + (++uid);
      var ring = (b.kicker ? String(b.kicker) : (b.code ? 'Use code ' + b.code : 'Special offer')).toUpperCase();
      inner = copy + '<div class="rg-ob-badge"><svg viewBox="0 0 100 100" aria-hidden="true"><defs><path id="' + pid + '" d="M50,50 m-41,0 a41,41 0 1,1 82,0 a41,41 0 1,1 -82,0"/></defs>' +
        '<text><textPath href="#' + pid + '">' + esc(ring + ' • ' + ring + ' • ') + '</textPath></text></svg>' +
        '<div class="rg-ob-core">' + (img ? '<img src="' + esc(img) + '" alt="">' : '<i></i>') + '</div></div>';
    } else if (tpl === 'bubbles') {
      fx = '';
      for (i = 0; i < 12; i++) {
        var sz = 3 + (i * 7) % 7;
        fx += '<i style="left:' + ((i * 29 + 7) % 96) + '%;width:' + sz + 'cqw;height:' + sz + 'cqw;animation-duration:calc(' + (4 + (i % 4)) + 's * var(--spd));animation-delay:-' + ((i * 0.8) % 5).toFixed(1) + 's"></i>';
      }
      inner = '<div class="rg-ob-fx">' + fx + '</div>' + dish + copy;
    } else if (tpl === 'gift') {
      var sp = '';
      [[62, 18], [90, 22], [70, 70], [94, 58], [58, 45]].forEach(function (p, k) { sp += '<i style="left:' + p[0] + '%;top:' + p[1] + '%;animation-delay:' + (k * 0.35).toFixed(2) + 's"></i>'; });
      inner = copy + '<div class="rg-ob-sparks">' + sp + '</div><div class="rg-ob-box"><i class="b"></i><i class="r"></i><i class="l"></i><i class="k"></i></div>';
    } else if (tpl === 'tag') {
      inner = copy + '<div class="rg-ob-hang"><div class="t"><b class="' + tCls.trim() + '">' + esc(title) + '</b></div></div>';
    } else if (tpl === 'wave') {
      inner = '<i class="w"></i><i class="w w2"></i>' + dish + copy;
    } else if (tpl === 'orbit') {
      inner = '<i class="rg-ob-ring r2"></i><i class="rg-ob-ring"></i>' + dish + copy;
    } else if (tpl === 'typewriter') {
      inner = dish + (img ? '<i class="rg-ob-tape"></i>' : '') + copy;
    } else if (tpl === 'pulse') {
      inner = '<div class="rg-ob-sonar"><i></i><i></i><i></i></div>' + dish + copy;
    } else if (tpl === 'polaroid') {
      inner = copy + '<div class="rg-ob-frame">' + (img ? '<img src="' + esc(img) + '" alt="">' : '<span class="ph"></span>') + '<em>' + esc(b.cta || 'Today only') + '</em></div>';
    } else if (tpl === 'festive') {
      var bulbs = '', cols = [accent || '#fde047', '#f472b6', '#22d3ee', '#a3e635', '#fb923c'];
      for (i = 0; i < 11; i++) {
        var x = 4 + i * 9.2, y = 6 - 4 * Math.pow((x - 50) / 50, 2) + 4;
        bulbs += '<i style="left:' + x.toFixed(1) + '%;top:' + y.toFixed(1) + 'cqw;background:' + cols[i % cols.length] + ';color:' + cols[i % cols.length] + ';animation-delay:' + ((i % 3) * 0.45).toFixed(2) + 's"></i>';
      }
      inner = '<i class="rg-ob-wire"></i><div class="rg-ob-bulbs">' + bulbs + '</div>' + dish + copy;
    } else if (tpl === 'flash') {
      inner = dish + '<i class="rg-ob-bolt"></i>' + copy;
    } else if (tpl === 'scratch') {
      inner = copy + '<div class="rg-ob-scr"><small>' + (b.code ? 'Scratch for your code' : 'Scratch & see') + '</small><div class="card"><b>' + esc(b.code || title) + '</b><i class="foil"></i></div></div>';
    } else if (tpl === 'hearts') {
      fx = '';
      for (i = 0; i < 12; i++) {
        fx += '<i style="left:' + ((i * 31 + 5) % 95) + '%;font-size:' + (3 + (i * 5) % 5) + 'cqw;animation-duration:calc(' + (4.5 + (i % 4)) + 's * var(--spd));animation-delay:-' + ((i * 0.7) % 5).toFixed(1) + 's">\u2665</i>';
      }
      inner = '<div class="rg-ob-fx">' + fx + '</div>' + dish + copy;
    } else if (tpl === 'snow') {
      fx = '';
      for (i = 0; i < 26; i++) {
        var fl = 0.6 + (i * 7) % 5 * 0.35;
        fx += '<i style="left:' + ((i * 37 + 3) % 99) + '%;width:' + fl.toFixed(2) + 'cqw;height:' + fl.toFixed(2) + 'cqw;animation-duration:calc(' + (5 + (i % 5)) + 's * var(--spd));animation-delay:-' + ((i * 0.53) % 6).toFixed(1) + 's"></i>';
      }
      inner = '<div class="rg-ob-fx">' + fx + '</div>' + dish + copy;
    } else if (tpl === 'sunrise') {
      inner = '<div class="rg-ob-sun"><i>' + (img ? '<img src="' + esc(img) + '" alt="">' : '') + '</i></div>' + copy;
    } else if (tpl === 'cinema') {
      inner = dish + copy;
    } else {
      inner = dish + copy;
    }
    var attrs = ' data-speed="' + (['calm', 'lively', 'off'].indexOf(b.speed) > -1 ? b.speed : 'normal') + '"' +
      (['serif', 'rounded', 'condensed'].indexOf(b.font) > -1 ? ' data-font="' + b.font + '"' : '') +
      (['rounded', 'square'].indexOf(b.shape) > -1 ? ' data-shape="' + b.shape + '"' : '');
    return '<div class="rg-ob rg-ob-' + tpl + (img ? ' has-dish' : '') + (b.text === 'dark' ? ' tx-dark' : '') + (opts.live ? ' rg-ob-live' : '') + '"' + attrs +
      ' style="--c:' + color + (accent ? ';--a:' + accent : '') + '">' + inner + timer + '</div>';
  }

  // Live timers: "Ends in" chips and Countdown blocks, once a second
  setInterval(function () {
    document.querySelectorAll('[data-rg-ends]').forEach(function (el) { el.textContent = timerText(secondsLeft(+el.getAttribute('data-rg-ends'))); });
    document.querySelectorAll('[data-rg-cd]').forEach(function (el) {
      var left = secondsLeft(+el.getAttribute('data-rg-cd') || 0);
      var v = { h: pad2(Math.min(Math.floor(left / 3600), 99)), m: pad2(Math.floor(left % 3600 / 60)), s: pad2(left % 60) };
      el.querySelectorAll('[data-cd]').forEach(function (n) { var t = v[n.getAttribute('data-cd')]; if (n.textContent !== t) n.textContent = t; });
    });
  }, 1000);
  function toast(msg) {
    if (window.RGNotify) return RGNotify.toast(msg, 'success');
    var t = document.createElement('div');
    t.className = 'rg-bn-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    requestAnimationFrame(function () { t.classList.add('show'); });
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 1800);
  }

  function menuUrl() {
    if (window.RG_MENU_URL) return window.RG_MENU_URL; // set by the page (works with custom domains)
    return location.pathname.replace(/\/(menu|cart|profile|social|plans|reservations)\/?$/, '').replace(/\/$/, '') + '/menu';
  }

  // Steps the text down (tight, then tighter) when the headline + sub-line + code + button don't fit
  function fit(scope) {
    var list = scope && scope.classList && scope.classList.contains('rg-ob') ? [scope] : (scope || document).querySelectorAll('.rg-ob');
    Array.prototype.forEach.call(list, function (el) {
      var copy = el.querySelector('.rg-ob-copy');
      if (!copy || !el.clientHeight) return;
      el.classList.remove('tight', 'tighter');
      // --safe: a band (in % of the width) kept clear at top and bottom, e.g. Flash sale stripes
      var safe = (parseFloat(getComputedStyle(el).getPropertyValue('--safe')) || 0) * el.clientWidth / 100 + 1;
      var over = function () {
        var r = el.getBoundingClientRect(), cr = copy.getBoundingClientRect();
        return cr.top < r.top + safe || cr.bottom > r.bottom - safe || copy.scrollHeight > copy.clientHeight + 1;
      };
      if (over()) { el.classList.add('tight'); if (over()) el.classList.add('tighter'); }
    });
  }
  var fitT;
  window.addEventListener('resize', function () { clearTimeout(fitT); fitT = setTimeout(function () { fit(); }, 150); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { fit(); });

  window.RGOfferBanner = { html: offerHtml, ensureCss: ensureCss, templates: TEMPLATES, fit: fit };

  var mounts = document.querySelectorAll('.rg-banners[data-placement]');
  if (!mounts.length) return;
  ensureCss();

  var meta = document.querySelector('meta[name=restaurant-id]');
  var rid = (meta && meta.content) || window.websiteRestaurantId || '';
  if (!rid) return;

  function photoSrc(b) {
    var p = String(b.banner_path || '');
    if (/^https?:\/\//.test(p)) return p;
    if (p.indexOf('db:') === 0) return 'image.php?type=banner&id=' + encodeURIComponent(b.id) + '&p=' + encodeURIComponent(p.slice(3)); // new upload = new path, so no stale cache
    return '../' + p;
  }

  // Code tap: copy it. CTA tap: go to the menu.
  document.addEventListener('click', function (e) {
    var codeEl = e.target.closest && e.target.closest('.rg-banners [data-rg-code]');
    if (codeEl) {
      var code = codeEl.getAttribute('data-rg-code');
      var done = function () { toast('Code ' + code + ' copied'); };
      if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(code).then(done, done); else done();
      return;
    }
    if (e.target.closest && e.target.closest('.rg-banners [data-rg-cta]')) location.href = menuUrl();
  });

  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.closest && e.target.closest('.rg-banners [data-rg-cta]')) { e.preventDefault(); location.href = menuUrl(); }
  });

  function build(mount, slides) {
    mount.innerHTML = '<div class="rg-bn-view"><div class="rg-bn-track">' +
      slides.map(function (s, i) { return '<div class="rg-bn-slide' + (i ? '' : ' is-on') + '">' + s + '</div>'; }).join('') +
      '</div>' + (slides.length > 1 ? '<div class="rg-bn-dots">' + slides.map(function (s, i) { return '<i' + (i ? '' : ' class="on"') + '></i>'; }).join('') + '</div>' : '') + '</div>';
    mount.classList.add('on');
    if (slides.length < 2) return;
    var track = mount.querySelector('.rg-bn-track'), dots = mount.querySelectorAll('.rg-bn-dots i'), els = mount.querySelectorAll('.rg-bn-slide');
    var idx = 0, paused = false;
    function at() { return Math.round(track.scrollLeft / Math.max(1, track.clientWidth)); }
    function mark(n) {
      dots.forEach(function (d, k) { d.classList.toggle('on', k === n); });
      els.forEach(function (el, k) { el.classList.toggle('is-on', k === n); }); // replays entrance animations
    }
    track.addEventListener('scroll', function () { var n = at(); if (n !== idx) { idx = n; mark(idx); } }, { passive: true });
    track.addEventListener('touchstart', function () { paused = true; }, { passive: true });
    track.addEventListener('touchend', function () { setTimeout(function () { paused = false; }, 2500); }, { passive: true });
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) setInterval(function () {
      if (paused || document.hidden) return;
      track.scrollTo({ left: ((at() + 1) % slides.length) * track.clientWidth, behavior: 'smooth' });
    }, 4000);
  }

  fetch('theme_api.php?action=get_banners&restaurant_id=' + encodeURIComponent(rid))
    .then(function (r) { return r.json(); })
    .then(function (d) {
      var photos = (d && d.success && d.banners) || [];
      var offers = (d && d.success && d.offer_banners) || [];
      mounts.forEach(function (m) {
        var place = m.getAttribute('data-placement');
        var slides = offers.filter(function (b) { return (b.placement || 'home_top') === place && !(b.ends_ts && b.ends_ts * 1000 <= Date.now()); }).map(function (b) { return offerHtml(b); })
          .concat(photos.filter(function (b) { return (b.placement || 'home_top') === place; }).map(function (b, i) {
            return '<img src="' + photoSrc(b) + '" alt="" loading="' + (i ? 'lazy' : 'eager') + '">';
          }));
        if (slides.length) { build(m, slides); fit(m); }
      });
    })
    .catch(function () {});
})();
