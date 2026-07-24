import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find: addonKeys.push(key); continue;
# Replace with: addonKeys.push(key); addonQty += qty; addonPrice += (raw.addonPrice || 0) * qty; continue;

old = "    if (raw && typeof raw === 'object' && raw.isAddon) {\n      addonKeys.push(key);\n      continue;\n    }"

new = "    if (raw && typeof raw === 'object' && raw.isAddon) {\n      addonKeys.push(key);\n      addonQty += qty;\n      addonPrice += (raw.addonPrice || 0) * qty;\n      continue;\n    }"

if old in content:
    content = content.replace(old, new)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print('SUCCESS: First pass now adds addon qty/price to addonQty/addonPrice')
else:
    print('FAILED: Could not find the target pattern in the file')
    # Try to find where addonKeys.push is
    idx = content.find('addonKeys.push')
    if idx >= 0:
        print(f'Found addonKeys.push at position {idx}')
        print(content[idx-200:idx+100])
