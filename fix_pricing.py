path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Step 1: Update the addon total loop to use addonPrice already computed
old1 = """  // Add addon item prices to total
  for (var ai = 0; ai < addonKeys.length; ai++) {
    var key = addonKeys[ai];
    var qty = getCartQty(key);
    if (qty <= 0) continue;
    var raw = cartItems[key];
    var price = raw.addonPrice || 0;
    totalQty += qty;
    totalPrice += price * qty;
  }"""

new1 = """  // Combine totals
  totalQty = regQty + addonQty;
  totalPrice = regPrice + addonPrice;"""

if old1 in content:
    content = content.replace(old1, new1)
    print("STEP 1: Updated addon total loop to use regPrice/addonPrice")
else:
    print("STEP 1: Could not find old addon total loop")

# Step 2: Update Price Details to show separate lines
old2 = """        '<h3>Price Details</h3>' +
        '<div class=\"price-row\">' +
          '<span>Total Items (' + totalQty + ' Items)</span>' +
          '<span class=\"val\">' + getCurrency() + totalPrice.toFixed(2) + '</span>' +
        '</div>' +"""

new2 = """        '<h3>Price Details</h3>' +
        '<div class=\"price-row\">' +
          '<span>Total Items (' + regQty + ' Items)</span>' +
          '<span class=\"val\">' + getCurrency() + regPrice.toFixed(2) + '</span>' +
        '</div>' +
        (addonQty > 0 ? '<div class=\"price-row\">' +
          '<span>Add-ons (' + addonQty + ' Items)</span>' +
          '<span class=\"val\">' + getCurrency() + addonPrice.toFixed(2) + '</span>' +
        '</div>' : '') +"""

if old2 in content:
    content = content.replace(old2, new2)
    print("STEP 2: Updated Price Details to show separate lines")
else:
    print("STEP 2: Could not find Price Details section")

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
print("DONE")
