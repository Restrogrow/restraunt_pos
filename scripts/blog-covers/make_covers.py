"""Draws the blog cover images (1200x630 SVG) for restrogrow.com/blog.

    python scripts/blog-covers/make_covers.py      # writes assets/blog/src/<slug>.svg
    node scripts/blog-covers/build.mjs              # turns them into assets/blog/<slug>.webp

Brand: orange #FC5301, black #00000C, cream #FBF6F1. Each cover has a short headline, the topic
as a small label, a simple illustration of the topic and "restrogrow.com" in the corner, so the
brand travels with the image when it shows up in Google Images or a WhatsApp share.
Add a new post: add a COVERS entry (slug, kicker, headline lines, alt text, motif).
"""
import os
from html import escape

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
OUT = os.path.join(ROOT, 'assets', 'blog', 'src')
F = "Segoe UI, Roboto, Helvetica, Arial, sans-serif"
OR, BK, CR, OR2 = '#FC5301', '#00000C', '#FBF6F1', '#FFE3D5'

# ---------- motifs: each draws inside a 420x420 box at (x, y) ----------

def kot(x, y, lines=('2 x Paneer Butter Masala', '4 x Butter Naan', '1 x Jeera Rice', '** less spicy **')):
    s = f'<g transform="translate({x+60} {y+10})" filter="url(#sh)">'
    s += '<path d="M0 0h300v360l-20-14-20 14-20-14-20 14-20-14-20 14-20-14-20 14-20-14-20 14-20-14-20 14-20-14-20 14-20-14V0z" fill="#fff"/>'
    s += f'<text x="150" y="52" text-anchor="middle" font-size="28" font-weight="800" fill="{BK}">KOT #148</text>'
    s += f'<text x="150" y="84" text-anchor="middle" font-size="17" fill="#555">Table 6 · 08:42 PM</text>'
    s += '<line x1="24" y1="104" x2="276" y2="104" stroke="#bbb" stroke-dasharray="6 6" stroke-width="2"/>'
    for i, l in enumerate(lines):
        s += f'<text x="30" y="{146 + i*44}" font-size="19" font-weight="{700 if "**" not in l else 400}" fill="{OR if "**" in l else BK}">{escape(l)}</text>'
    s += '</g>'
    return s

def printer(x, y, warn=False):
    s = f'<g transform="translate({x+40} {y+60})" filter="url(#sh)">'
    s += f'<rect x="90" y="0" width="160" height="150" rx="6" fill="#fff"/>'
    s += ''.join(f'<rect x="112" y="{30+i*26}" width="{110 - i*18}" height="10" rx="5" fill="#ccc"/>' for i in range(4))
    s += f'<rect x="0" y="120" width="340" height="190" rx="28" fill="{BK}"/>'
    s += f'<rect x="40" y="150" width="260" height="20" rx="10" fill="#333"/>'
    s += f'<circle cx="290" cy="270" r="12" fill="{OR}"/>'
    s += f'<rect x="30" y="250" width="120" height="16" rx="8" fill="#444"/>'
    if warn:
        s += f'<g transform="translate(250 -30)"><path d="M60 0l60 104H0z" fill="{OR}"/><text x="60" y="88" text-anchor="middle" font-size="64" font-weight="800" fill="#fff">!</text></g>'
    s += '</g>'
    return s

def phone(x, y, inner):
    s = f'<g transform="translate({x+100} {y})" filter="url(#sh)">'
    s += f'<rect x="0" y="0" width="230" height="420" rx="34" fill="{BK}"/><rect x="14" y="40" width="202" height="340" rx="10" fill="#fff"/>'
    s += f'<rect x="85" y="16" width="60" height="8" rx="4" fill="#333"/>'
    s += f'<g transform="translate(14 40)">{inner}</g></g>'
    return s

def chat_inner():
    b = '<rect x="0" y="0" width="202" height="46" fill="#075E54"/><circle cx="26" cy="23" r="14" fill="#fff"/>'
    b += '<text x="50" y="29" font-size="16" font-weight="700" fill="#fff">Spice Kitchen</text>'
    b += '<rect x="10" y="62" width="150" height="40" rx="10" fill="#eee"/><text x="20" y="88" font-size="14" fill="#222">Menu please 🙂</text>'.replace('🙂', '')
    b += f'<rect x="40" y="114" width="152" height="64" rx="10" fill="#DCF8C6"/><text x="50" y="140" font-size="14" fill="#222">Here is our menu.</text><text x="50" y="162" font-size="14" fill="#222">Order by 9 PM!</text>'
    b += '<rect x="10" y="190" width="160" height="40" rx="10" fill="#eee"/><text x="20" y="216" font-size="14" fill="#222">2 biryani, 1 raita</text>'
    b += f'<rect x="40" y="242" width="152" height="40" rx="10" fill="#DCF8C6"/><text x="50" y="268" font-size="14" fill="#222">Confirmed ✓ ₹540</text>'
    return b

def receipt_inner():
    b = f'<rect x="0" y="0" width="202" height="46" fill="{OR}"/><text x="101" y="30" text-anchor="middle" font-size="17" font-weight="700" fill="#fff">New Bill</text>'
    items = [('Masala Dosa', '120'), ('Filter Coffee', '40'), ('Idli (2)', '60'), ('Vada', '50')]
    for i, (n, p) in enumerate(items):
        b += f'<text x="16" y="{84+i*34}" font-size="15" fill="#222">{n}</text><text x="186" y="{84+i*34}" text-anchor="end" font-size="15" fill="#222">₹{p}</text>'
    b += '<line x1="14" y1="222" x2="188" y2="222" stroke="#ccc" stroke-width="2"/>'
    b += f'<text x="16" y="254" font-size="17" font-weight="800" fill="{BK}">Total</text><text x="186" y="254" text-anchor="end" font-size="17" font-weight="800" fill="{BK}">₹270</text>'
    b += f'<rect x="16" y="282" width="170" height="44" rx="22" fill="{OR}"/><text x="101" y="310" text-anchor="middle" font-size="16" font-weight="700" fill="#fff">Print bill</text>'
    return b

def qr(x, y):
    import random
    random.seed(7)
    s = f'<g transform="translate({x+70} {y+10})" filter="url(#sh)">'
    s += f'<path d="M20 400 L60 70 H220 L260 400 Z" fill="{BK}"/>'
    s += f'<rect x="40" y="0" width="200" height="290" rx="16" fill="#fff" stroke="{OR}" stroke-width="6"/>'
    s += f'<text x="140" y="44" text-anchor="middle" font-size="20" font-weight="800" fill="{BK}">SCAN TO ORDER</text>'
    cell = 9; ox, oy = 72, 66
    for r in range(15):
        for c in range(15):
            corner = (r < 4 and c < 4) or (r < 4 and c > 10) or (r > 10 and c < 4)
            if corner:
                continue
            if random.random() < 0.48:
                s += f'<rect x="{ox+c*cell}" y="{oy+r*cell}" width="{cell}" height="{cell}" fill="{BK}"/>'
    for cx, cy in ((ox, oy), (ox + 11*cell, oy), (ox, oy + 11*cell)):
        s += f'<rect x="{cx}" y="{cy}" width="{4*cell}" height="{4*cell}" fill="none" stroke="{BK}" stroke-width="6"/><rect x="{cx+11}" y="{cy+11}" width="{4*cell-22}" height="{4*cell-22}" fill="{BK}"/>'
    s += f'<text x="140" y="268" text-anchor="middle" font-size="18" fill="#555">Table 4</text></g>'
    return s

def pie(x, y, pct='18–30%'):
    s = f'<g transform="translate({x+210} {y+200})" filter="url(#sh)">'
    s += f'<circle r="170" fill="#fff"/><path d="M0 0 L0 -170 A170 170 0 0 1 150 -80 Z" fill="{OR}"/>'
    s += f'<circle r="74" fill="{CR}"/><text y="-4" text-anchor="middle" font-size="38" font-weight="800" fill="{BK}">{pct}</text>'
    s += f'<text y="30" text-anchor="middle" font-size="18" fill="#555">commission</text></g>'
    return s

def bars(x, y, vals=(90, 150, 120, 210, 260), coin=True):
    s = f'<g transform="translate({x+30} {y+20})">'
    for i, v in enumerate(vals):
        s += f'<rect x="{i*70}" y="{340-v}" width="48" height="{v}" rx="8" fill="{OR if i == len(vals)-1 else BK}" opacity="{1 if i == len(vals)-1 else 0.85}"/>'
    s += '<line x1="-10" y1="342" x2="350" y2="342" stroke="#999" stroke-width="3"/>'
    if coin:
        s += f'<g transform="translate(330 60)" filter="url(#sh)"><circle r="54" fill="{OR}"/><circle r="42" fill="none" stroke="#fff" stroke-width="4"/><text y="20" text-anchor="middle" font-size="58" font-weight="800" fill="#fff">₹</text></g>'
    s += '</g>'
    return s

def screen(x, y):
    s = f'<g transform="translate({x+10} {y+40})" filter="url(#sh)">'
    s += f'<rect x="0" y="0" width="400" height="260" rx="18" fill="{BK}"/><rect x="16" y="16" width="368" height="228" rx="8" fill="#1d1d24"/>'
    cols = [('#4CAF50', 'READY'), (OR, 'COOKING'), ('#2196F3', 'NEW')]
    for i, (c, t) in enumerate(cols):
        s += f'<g transform="translate({30+i*120} 32)"><rect width="104" height="190" rx="8" fill="#fff"/><rect width="104" height="30" rx="8" fill="{c}"/><text x="52" y="21" text-anchor="middle" font-size="14" font-weight="700" fill="#fff">{t}</text>'
        s += ''.join(f'<rect x="12" y="{44+j*26}" width="{80 - (j%2)*24}" height="10" rx="5" fill="#ccc"/>' for j in range(5)) + '</g>'
    s += f'<rect x="170" y="260" width="60" height="70" fill="{BK}"/><rect x="110" y="320" width="180" height="20" rx="10" fill="{BK}"/></g>'
    return s

def wifi_off(x, y):
    s = f'<g transform="translate({x+20} {y+20})">'
    s += phone(-20, 0, receipt_inner())
    s += f'<g transform="translate(300 40)" filter="url(#sh)"><circle r="70" fill="#fff"/>'
    for i, r in enumerate((44, 30, 16)):
        s += f'<path d="M{-r} {10-r*0.2} A{r} {r} 0 0 1 {r} {10-r*0.2}" fill="none" stroke="{BK}" stroke-width="8" stroke-linecap="round" transform="translate(0 {i*6})"/>'
    s += f'<circle cy="34" r="7" fill="{BK}"/><line x1="-46" y1="-40" x2="46" y2="52" stroke="{OR}" stroke-width="10" stroke-linecap="round"/></g></g>'
    return s

def diya(x, y):
    s = f'<g transform="translate({x+60} {y+40})">'
    for i, (cx, cy, r) in enumerate(((40, 40, 6), (300, 20, 8), (330, 120, 5), (20, 170, 7), (280, 260, 6))):
        s += f'<g transform="translate({cx} {cy})"><path d="M0 {-r*3} L{r} {-r} L{r*3} 0 L{r} {r} L0 {r*3} L{-r} {r} L{-r*3} 0 L{-r} {-r}Z" fill="{OR}" opacity="0.7"/></g>'
    s += f'<g transform="translate(160 170)" filter="url(#sh)">'
    s += f'<path d="M-130 60 Q0 200 130 60 Z" fill="#B23A00"/><ellipse cx="0" cy="60" rx="130" ry="26" fill="{OR}"/>'
    s += '<path d="M0 50 C-30 0 -10 -50 0 -90 C10 -50 30 0 0 50 Z" fill="#FFC107"/><path d="M0 40 C-14 10 -6 -16 0 -40 C6 -16 14 10 0 40 Z" fill="#FFF3C4"/></g>'
    s += f'<g transform="translate(240 230)" filter="url(#sh)"><rect x="0" y="0" width="120" height="96" rx="8" fill="{BK}"/><rect x="52" y="0" width="16" height="96" fill="{OR}"/><rect x="-8" y="-20" width="136" height="26" rx="6" fill="{BK}"/><rect x="52" y="-20" width="16" height="26" fill="{OR}"/></g>'
    s += '</g>'
    return s

def storefront(x, y):
    s = f'<g transform="translate({x+30} {y+40})" filter="url(#sh)">'
    s += f'<rect x="0" y="80" width="360" height="270" fill="#fff"/>'
    s += ''.join(f'<path d="M{i*60} 80 h60 v40 a30 30 0 0 1 -60 0z" fill="{OR if i % 2 == 0 else "#fff"}" stroke="{OR}" stroke-width="3"/>' for i in range(6))
    s += f'<rect x="40" y="0" width="280" height="70" rx="12" fill="{BK}"/><text x="180" y="48" text-anchor="middle" font-size="34" font-weight="800" fill="#fff" letter-spacing="4">RESTRO</text>'
    s += f'<rect x="40" y="170" width="120" height="180" fill="{BK}" opacity="0.9"/><rect x="200" y="170" width="120" height="90" rx="6" fill="{OR2}" stroke="{BK}" stroke-width="4"/></g>'
    return s

def bag_rupee(x, y):
    s = f'<g transform="translate({x+40} {y+50})" filter="url(#sh)">'
    s += f'<rect x="0" y="60" width="220" height="250" rx="18" fill="{OR}"/><path d="M60 60 v-30 a50 50 0 0 1 100 0 v30" fill="none" stroke="{BK}" stroke-width="12"/>'
    s += f'<text x="110" y="220" text-anchor="middle" font-size="40" font-weight="800" fill="#fff">ORDER</text>'
    s += f'<g transform="translate(290 120)"><circle r="70" fill="#fff"/><circle r="56" fill="none" stroke="{OR}" stroke-width="5"/><text y="26" text-anchor="middle" font-size="74" font-weight="800" fill="{OR}">₹</text></g>'
    s += f'<rect x="210" y="230" width="150" height="70" rx="12" fill="{BK}"/><text x="285" y="275" text-anchor="middle" font-size="24" font-weight="700" fill="#fff">Join fee?</text></g>'
    return s

def tag_receipt(x, y):
    s = phone(x, y, receipt_inner())
    s += f'<g transform="translate({x+250} {y+260}) rotate(-12)" filter="url(#sh)"><path d="M0 0 h110 l40 40 -40 40 h-110 z" fill="{OR}"/><circle cx="112" cy="40" r="8" fill="#fff"/><text x="52" y="50" text-anchor="middle" font-size="26" font-weight="800" fill="#fff">PRICE</text></g>'
    return s

def calculator(x, y):
    s = f'<g transform="translate({x+70} {y+20})" filter="url(#sh)">'
    s += f'<rect x="0" y="0" width="260" height="380" rx="26" fill="{BK}"/><rect x="22" y="24" width="216" height="80" rx="10" fill="{OR2}"/>'
    s += f'<text x="222" y="80" text-anchor="end" font-size="40" font-weight="800" fill="{BK}">₹25,300</text>'
    for r in range(4):
        for c in range(3):
            s += f'<rect x="{22+c*76}" y="{126+r*62}" width="60" height="48" rx="10" fill="{OR if (r, c) == (3, 2) else "#333"}"/>'
    s += '</g>'
    return s

COVERS = [
    ('kot-full-form-restaurant', 'Restaurant basics', ['KOT Full Form', 'and How It Works'], 'A printed KOT (kitchen order ticket) for table 6 listing dishes and a less spicy note', lambda: kot(720, 110)),
    ('kot-printer-price-in-india', 'Price guide 2026', ['KOT Printer Price', 'in India'], 'A thermal KOT printer printing a kitchen order ticket', lambda: printer(720, 120)),
    ('kot-printer-not-working', 'Quick fixes', ['KOT Printer', 'Not Working?'], 'A thermal KOT printer with a warning sign, for printer troubleshooting', lambda: printer(720, 120, warn=True)),
    ('swiggy-joining-charges-for-restaurants', 'Delivery apps', ['Swiggy Joining', 'Charges 2026'], 'A food delivery bag next to a rupee coin and a joining fee label', lambda: bag_rupee(700, 100)),
    ('zomato-commission-rates', 'Delivery apps', ['Zomato Commission', 'Every Fee Explained'], 'A pie chart showing the share of each order that goes to delivery app commission', lambda: pie(700, 110, '8–28%')),
    ('how-to-take-orders-on-whatsapp-restaurant', 'Direct orders', ['Take Food Orders', 'on WhatsApp'], 'A phone showing a restaurant taking a biryani order in a WhatsApp chat', lambda: phone(730, 110, chat_inner())),
    ('cheapest-restaurant-billing-software-india', 'Software prices', ['Restaurant Billing', 'Software Prices'], 'A phone showing a restaurant bill with a price tag', lambda: tag_receipt(730, 110)),
    ('free-restaurant-billing-software-for-android', 'Free apps', ['Free Billing Apps', 'for Android'], 'An Android phone showing a restaurant bill with a print button', lambda: phone(730, 110, receipt_inner())),
    ('dhaba-business-profit-per-month', 'Business numbers', ['Dhaba Business', 'Profit per Month'], 'A bar chart of rising monthly profit with a rupee coin', lambda: bars(720, 120)),
    ('kitchen-display-system-price-in-india', 'Kitchen tech', ['Kitchen Display', 'System Price'], 'A kitchen display screen with new, cooking and ready order columns', lambda: screen(710, 120)),
    ('how-to-make-qr-code-menu-for-restaurant', 'Dine-in', ['Make a QR Code', 'Menu for Free'], 'A table standee with a scan-to-order QR code menu for table 4', lambda: qr(720, 110)),
    ('free-restaurant-billing-software-offline', 'Billing', ['Billing That Works', 'Offline'], 'A phone still printing a restaurant bill while the Wi-Fi is off', lambda: wifi_off(700, 110)),
    ('restaurant-billing-software-price-in-india', 'Price guide 2026', ['Billing Software', 'Price in India'], 'A calculator adding up the first-year cost of restaurant billing software', lambda: calculator(720, 110)),
    ('diwali-offers-for-restaurants', 'Diwali 2026 · 8 Nov', ['Diwali Offers', 'for Restaurants'], 'A lit diya with a gift box, for Diwali restaurant offers', lambda: diya(700, 110)),
    ('what-is-restro-meaning', 'Restaurant basics', ['What Is a', 'Restro?'], 'A restaurant storefront with a RESTRO signboard', lambda: storefront(710, 110)),
]


def cover(slug, kicker, lines, alt, motif):
    head = ''.join(
        f'<text x="80" y="{258 + i*82}" font-size="{68 if max(len(l) for l in lines) <= 17 else 58}" font-weight="800" fill="{BK}">{escape(l)}</text>'
        for i, l in enumerate(lines))
    return f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" role="img" aria-labelledby="t d">
  <title id="t">{escape(alt)}</title>
  <desc id="d">Cover image for the RestroGrow blog post "{escape(' '.join(lines))}". Illustration by RestroGrow (restrogrow.com).</desc>
  <defs>
    <filter id="sh" x="-20%" y="-20%" width="140%" height="140%"><feDropShadow dx="0" dy="10" stdDeviation="12" flood-color="#000" flood-opacity="0.18"/></filter>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="{CR}"/><stop offset="1" stop-color="#FFF1E8"/></linearGradient>
  </defs>
  <rect width="1200" height="630" fill="url(#bg)"/>
  <circle cx="930" cy="330" r="270" fill="{OR2}"/>
  <rect x="0" y="0" width="16" height="630" fill="{OR}"/>
  <g font-family="{F}">
    <rect x="80" y="118" width="{24 + len(kicker)*13}" height="44" rx="22" fill="{OR}"/>
    <text x="{92}" y="148" font-size="21" font-weight="700" fill="#fff">{escape(kicker)}</text>
    {head}
    <rect x="80" y="{300 + len(lines)*82 - 40}" width="90" height="8" rx="4" fill="{OR}"/>
    <text x="80" y="560" font-size="26" font-weight="800" fill="{BK}">Restro<tspan fill="{OR}">Grow</tspan></text>
    <text x="232" y="560" font-size="26" fill="#555">Blog</text>
    {motif()}
    <text id="rg-brand" x="1172" y="606" text-anchor="end" font-size="20" font-weight="600" fill="{BK}" fill-opacity="0.55">restrogrow.com</text>
  </g>
</svg>
'''


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)
    for slug, kicker, lines, alt, motif in COVERS:
        with open(os.path.join(OUT, slug + '.svg'), 'w', encoding='utf-8', newline='\n') as fh:
            fh.write(cover(slug, kicker, lines, alt, motif))
    print(f'{len(COVERS)} covers written to {OUT}')


def home_share():
    """Share image for the homepage (og:image), saved as assets/blog/og-home.svg, then turned into og-home.jpg."""
    svg = cover('og-home', 'Restaurant software', ['Restaurant POS,', 'KOT & Online Orders'],
                'RestroGrow restaurant POS: billing, KOT and your own online ordering on a phone',
                lambda: phone(730, 110, receipt_inner()))
    svg = svg.replace('Blog</text>', '</text>')
    path = os.path.join(ROOT, 'assets', 'blog', 'og-home.svg')
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(svg)
    print('home share image written to', path)


if __name__ == '__main__':
    home_share()
