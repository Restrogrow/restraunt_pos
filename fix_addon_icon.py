import re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace the ➕ emoji in the Add-ons card header with a proper Font Awesome icon
old = '\'<div class=\"icon-wrap\">➕</div>\' +'

# Use fa-plus-circle - clean, professional, indicates "additional items"
new = '\'<div class=\"icon-wrap\"><i class=\"fa fa-plus-circle\"></i></div>\' +'

count = content.count(old)
if count > 0:
    content = content.replace(old, new)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print(f'SUCCESS: Replaced {count} instance(s) of ➕ with Font Awesome fa-plus-circle icon')
else:
    print('FAILED: Could not find the target pattern')
    idx = content.find('icon-wrap')
    if idx >= 0:
        print(f'Found icon-wrap at position {idx}')
        print(content[idx:idx+60])
    else:
        print('icon-wrap not found at all')
