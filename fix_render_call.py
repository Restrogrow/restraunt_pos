import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# The problem: renderAddonChips() is outside the renderCart() function.
# It's placed after the closing } of renderCart but before the next function.
# Let me find the exact pattern.

# Look for: "}\n  renderAddonChips();\n\nfunction changeQty"
# This is the end of renderCart followed by standalone renderAddonChips() and then changeQty

# The issue is that renderAddonChips() at line 1482 is outside renderCart.
# I need to move it INSIDE renderCart, right before the closing brace.

# The current pattern (outside function):
#   }\n  renderAddonChips();\n\nfunction changeQty
# Should become:
#   renderAddonChips();\n  }\n\nfunction changeQty

# Let me find the exact text. Looking at the file:
# The last lines of renderCart() are:
#     document.getElementById('checkoutBtn').disabled = false;
#   }
# }
#   renderAddonChips();
# 
# function changeQty(key, delta) {

# So I need to change:
#   }\n  renderAddonChips();\n\nfunction changeQty
# to:
#   renderAddonChips();\n  }\n\nfunction changeQty

# Find: closing brace of renderCart, then renderAddonChips call, then newlines, then function changeQty
# The closing brace of renderCart() is the LAST } in the function body.
# I need to find the pattern:
#   }\n  renderAddonChips();\n\nfunction changeQty

# But this assumes the file has \n line endings. It might have \r\n.
# Let me try both.

old_pattern = '}\n  renderAddonChips();\n\nfunction changeQty'
new_pattern = '  renderAddonChips();\n}\n\nfunction changeQty'

if old_pattern in content:
    content = content.replace(old_pattern, new_pattern, 1)
    sys.stdout.write('OK: moved renderAddonChips() inside renderCart\n')
else:
    # Try with \r\n
    old_pattern2 = '}\r\n  renderAddonChips();\r\n\r\nfunction changeQty'
    new_pattern2 = '  renderAddonChips();\r\n}\r\n\r\nfunction changeQty'
    if old_pattern2 in content:
        content = content.replace(old_pattern2, new_pattern2, 1)
        sys.stdout.write('OK: moved renderAddonChips() inside renderCart (CRLF)\n')
    else:
        # Debug: show what's around that area
        idx = content.find('function changeQty')
        if idx >= 0:
            sys.stdout.write('Found changeQty at ' + str(idx) + '\n')
            before = content[max(0, idx-100):idx]
            sys.stdout.write('Before changeQty: ' + repr(before) + '\n')
        sys.stdout.write('FAIL: pattern not found\n')
        sys.exit(1)

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
sys.stdout.write('File written\n')
