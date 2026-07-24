path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

old = "  container.innerHTML = cartHtml;\n  var sym = getCurrency();"
new = "  container.innerHTML = cartHtml;\n  renderGlobalAddons();\n  var sym = getCurrency();"

if old in content:
    content = content.replace(old, new, 1)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: renderGlobalAddons() added inside renderCart()")
else:
    print("FAILED: Could not find the target string")
