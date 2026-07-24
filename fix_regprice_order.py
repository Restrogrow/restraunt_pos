import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# The bug: regPrice is calculated BEFORE the addonTotal adjustment.
# Fix: move regPrice += price * qty to AFTER the addonTotal adjustment.
# 
# Current order:
#   var price = getCartPrice(key);
#   regQty += qty;
#   regPrice += price * qty;              <-- uses unadjusted price
#   ...
#   var addonTotal = getAddonTotalForItem(key);
#   if (addonTotal > 0) {
#     price = price + (addonTotal / qty);  <-- price is adjusted
#   }
#   cartHtml += ... (price * qty) ...    <-- uses adjusted price
#
# Fixed order:
#   var price = getCartPrice(key);
#   regQty += qty;
#   ...
#   var addonTotal = getAddonTotalForItem(key);
#   if (addonTotal > 0) {
#     price = price + (addonTotal / qty);
#   }
#   regPrice += price * qty;              <-- uses adjusted price (matches card)
#   cartHtml += ... (price * qty) ...    <-- uses adjusted price

# Find the pattern: regPrice += price * qty; immediately followed by varDisplay
old = "    var price = getCartPrice(key);\n    regQty += qty;\n    regPrice += price * qty;\n\n    var varDisplay = '';"

new = "    var price = getCartPrice(key);\n    regQty += qty;\n\n    var varDisplay = '';"

if old in content:
    content = content.replace(old, new)
    print('SUCCESS: Removed regPrice from before addon adjustment')
else:
    print('FAILED: Could not find the target pattern')
    idx = content.find('var price = getCartPrice(key)')
    if idx >= 0:
        print(f'Found at position {idx}')
        print(content[idx:idx+300])

# Now add regPrice AFTER the addonTotal adjustment block, before cartHtml
# Find: "if (addonTotal > 0) {" ... closing "}" then cartHtml +=
old2 = "    if (addonTotal > 0) {\n      price = price + (addonTotal / qty);\n    }\n\n    cartHtml +="
new2 = "    if (addonTotal > 0) {\n      price = price + (addonTotal / qty);\n    }\n    regPrice += price * qty;\n\n    cartHtml +="

if old2 in content:
    content = content.replace(old2, new2)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('SUCCESS: Added regPrice after addon adjustment - totals will now match cart card')
else:
    print('FAILED: Could not find the second target pattern')
    idx = content.find('addonTotal > 0')
    if idx >= 0:
        print(f'Found addonTotal > 0 at position {idx}')
        print(content[idx:idx+400])
