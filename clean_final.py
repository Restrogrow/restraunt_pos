import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Remove the old per-item block starting with "// --- Cart Add-ons Selection ---"
# and ending with "// --- End Cart Add-ons Selection ---"
# This block sits between the new simplified functions and the renderCart function.

old_start = '// --- Cart Add-ons Selection ---\n'
old_end = '// --- End Cart Add-ons Selection ---\n'

start_pos = content.find(old_start)
if start_pos >= 0:
    end_pos = content.find(old_end, start_pos)
    if end_pos >= 0:
        # Remove from start_pos to after the newline after old_end
        end_of_block = content.find('\n', end_pos) + 1
        if end_of_block == 0:
            end_of_block = end_pos + len(old_end)
        block = content[start_pos:end_of_block]
        content = content.replace(block, '', 1)
        sys.stdout.write('OK: removed old per-item block\n')
    else:
        # Try with \r
        old_end2 = '// --- End Cart Add-ons Selection ---\r\n'
        end_pos2 = content.find(old_end2, start_pos)
        if end_pos2 >= 0:
            end_of_block2 = content.find('\n', end_pos2) + 1
            if end_of_block2 == 0:
                end_of_block2 = end_pos2 + len(old_end2)
            block = content[start_pos:end_of_block2]
            content = content.replace(block, '', 1)
            sys.stdout.write('OK: removed old per-item block (CRLF)\n')
        else:
            sys.stdout.write('FAIL: end marker not found\n')
else:
    sys.stdout.write('FAIL: start marker not found\n')

# Also remove any duplicate var addonsList = []; inside the simplified functions
# The simplified functions already have var addonsList = [] and fetchAddons(),
# but the first script may have added a duplicate.
# Let's check: is there a second "var addonsList = [];" after the first one?
first = content.find('var addonsList = [];')
if first >= 0:
    second = content.find('var addonsList = [];', first + 5)
    if second >= 0:
        # Remove the second one and the following newline
        line_end = content.find('\n', second)
        if line_end >= 0:
            content = content[:second] + content[line_end+1:]
            sys.stdout.write('OK: removed duplicate addonsList\n')

# Check for duplicate fetchAddons function
first_f = content.find('function fetchAddons()')
if first_f >= 0:
    second_f = content.find('function fetchAddons()', first_f + 5)
    if second_f >= 0:
        # Find the end of the second fetchAddons (before function renderGlobalAddons or function renderCart)
        next_func = content.find('function ', second_f + 20)
        if next_func >= 0:
            # Remove from second_f to before next_func
            block2 = content[second_f:next_func]
            # Trim trailing whitespace
            while block2 and block2[-1] in ' \r\n':
                block2 = block2[:-1]
            content = content.replace(content[second_f:next_func], '', 1)
            sys.stdout.write('OK: removed duplicate fetchAddons\n')

with open(path, 'w', encoding='utf-8') as f:
    f.write(content)
sys.stdout.write('DONE: file written\n')
