path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Fix 1: Change the first pass to use regQty/regPrice instead of totalQty/totalPrice
old1 = "    totalQty += qty;\n    totalPrice += price * qty;\n"
new1 = "    regQty += qty;\n    regPrice += price * qty;\n"

if old1 in content:
    count = content.count(old1)
    content = content.replace(old1, new1, 1)
    print(f"FIX 1: Changed first pass to use regQty/regPrice (found {count} occurrences, replaced first)")
else:
    print("FIX 1: Could not find old totalQty/totalPrice in first pass")

# Fix 2: Also update the checkout label to use totalQty (which is already computed from combine)
# Check if checkoutLabel already uses totalQty
old2 = "  document.getElementById('checkoutLabel').textContent = sym + finalTotal.toFixed(2) + ' (' + totalQty + ' Items)';"
if old2 in content:
    print("FIX 2: checkoutLabel already uses totalQty - OK")

# Debug: Check what's around line 1218
idx = content.find("var price = getCartPrice(key);")
if idx >= 0:
    after = content[idx:idx+100]
    print(f"Context around getCartPrice: {repr(after)}")

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("FIX DONE")
