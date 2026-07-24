import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find the getCartTotal function - it currently does:
#   totalPrice += getCartPrice(key) * getCartQty(key);
# For regular items, it needs to also include per-item addon prices

old = '''    var item = getItemByKey(key);
    if (item) {
      totalQty += getCartQty(key);
      totalPrice += getCartPrice(key) * getCartQty(key);
    }'''

new = '''    var item = getItemByKey(key);
    if (item) {
      var qty = getCartQty(key);
      var basePrice = getCartPrice(key);
      var addonTotal = getAddonTotalForItem(key);
      totalQty += qty;
      totalPrice += (basePrice * qty) + addonTotal;
    }'''

count = content.count(old)
if count > 0:
    content = content.replace(old, new)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f'SUCCESS: Fixed getCartTotal() to include per-item addon prices ({count} match(es))')
else:
    print('FAILED: Could not find the target pattern in getCartTotal()')
    idx = content.find('function getCartTotal()')
    if idx >= 0:
        print(f'Found getCartTotal() at position {idx}')
        # Print 300 chars after
        print(content[idx:idx+500])
