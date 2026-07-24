# Fix public/script.js iframe sizing and add preview zoom CSS
import sys, os
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.chdir(os.path.dirname(os.path.abspath(__file__)))

with open('public/script.js', 'r', encoding='utf-8') as f:
    content = f.read()

changes = 0

# 1. Fix the iframe width - change from auto/min-width to 100%
old_iframe = "width:auto;min-width:300px;max-width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;margin:0 auto;"
new_iframe = "width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;"
if old_iframe in content:
    content = content.replace(old_iframe, new_iframe, 1)
    print("[OK] Changed iframe to width:100%")
    changes += 1
else:
    print("[..] Iframe pattern not found")

# 2. Add preview CSS injection after iframeDoc.close()
old_close = "    iframeDoc.close();\n  };\n  \n// Cancel Order"
new_close = """    iframeDoc.close();
    
    // Inject preview CSS for larger display
    setTimeout(function() {
      try {
        var ps = iframeDoc.createElement('style');
        ps.textContent = '\\n' +
          '/* Preview-only: make receipt larger */\\n' +
          'body { max-width: 560px !important; padding: 20px 24px !important; font-size: 14px !important; }\\n' +
          '.restaurant-name { font-size: 22px !important; }\\n' +
          '.restaurant-details { font-size: 12px !important; }\\n' +
          '.order-title h2 { font-size: 28px !important; }\\n' +
          '.items-title { font-size: 16px !important; }\\n' +
          '.item-row { font-size: 13px !important; }\\n' +
          '.item-row .col-item .item-name { font-size: 14px !important; }\\n' +
          '.item-row .col-qty-rate { font-size: 12px !important; }\\n' +
          '.item-row .col-disc { font-size: 12px !important; }\\n' +
          '.item-row .col-total { font-size: 14px !important; }\\n' +
          '.total-row { font-size: 13px !important; }\\n' +
          '.total-row.grand { font-size: 17px !important; }\\n' +
          '.info-line { font-size: 13px !important; }\\n' +
          '.pay-to .name { font-size: 15px !important; }\\n' +
          '.payment-info { font-size: 12px !important; }\\n' +
          '.footer-text { font-size: 12px !important; }\\n' +
          '.footer .powered { font-size: 10px !important; }\\n' +
          '@media print { body { max-width: 280px !important; padding: 10px !important; font-size: 11px !important; } }';
        iframeDoc.head.appendChild(ps);
      } catch(e) {}
    }, 50);
  };

// Cancel Order"""

if old_close in content:
    content = content.replace(old_close, new_close, 1)
    print("[OK] Added preview CSS injection")
    changes += 1
else:
    print("[..] Close pattern not found")
    # Debug
    idx = content.find('iframeDoc.close();')
    if idx >= 0:
        after = content[idx:idx+80]
        print(f"  Found close at {idx}: {repr(after)}")

if changes > 0:
    with open('public/script.js', 'w', encoding='utf-8') as f:
        f.write(content)
    print(f"\n[OK] {changes} changes applied to public/script.js")
else:
    print("\n[..] No changes made")
