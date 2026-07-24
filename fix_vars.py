path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

old = "  var totalQty = 0, totalPrice = 0;\n  var cartHtml = '';"
new = "  var totalQty = 0, totalPrice = 0;\n  var regQty = 0, regPrice = 0;\n  var addonQty = 0, addonPrice = 0;\n  var cartHtml = '';"

if old in content:
    content = content.replace(old, new, 1)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: Added regQty and addonQty declarations")
else:
    print("FAILED: Could not find target string")
