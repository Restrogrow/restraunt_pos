import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

edits = 0

# === 1. Remove old JS functions block ===
old_start = '// --- Cart Add-ons Selection (loaded from server) ---'
old_end = '// --- End Cart Add-ons Selection ---\r\n'

start_pos = content.find(old_start)
if start_pos >= 0:
    end_pos = content.find(old_end, start_pos)
    if end_pos >= 0:
        end_of_block = content.find('\n', end_pos) + 1
        block = content[start_pos:end_of_block]
        content = content.replace(block, '', 1)
        edits += 1
        sys.stdout.write('OK1\n')
    else:
        sys.stdout.write('FAIL1: no end\n')
else:
    sys.stdout.write('FAIL1: no start\n')

# === 2. Remove duplicate renderAddonChips() calls ===
dupe = '  renderAddonChips();\r\n  renderAddonChips();'
if dupe in content:
    content = content.replace(dupe, '  renderAddonChips();', 1)
    edits += 1
    sys.stdout.write('OK2\n')
else:
    # try without \r
    dupe2 = '  renderAddonChips();\n  renderAddonChips();'
    if dupe2 in content:
        content = content.replace(dupe2, '  renderAddonChips();', 1)
        edits += 1
        sys.stdout.write('OK2b\n')
    else:
        sys.stdout.write('FAIL2\n')

# Write
if edits > 0:
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    sys.stdout.write('DONE: ' + str(edits) + ' edits\n')
else:
    sys.stdout.write('FAIL: no edits\n')
