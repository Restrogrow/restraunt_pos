import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find the start of the old JS block
old_start = '// --- Cart Add-ons Selection (loaded from server) ---'
start_pos = content.find(old_start)

if start_pos >= 0:
    # Find the next "function renderCart()" after this block
    next_func = content.find('function renderCart()', start_pos)
    if next_func >= 0:
        # Go back from renderCart to the previous newline to keep the file clean
        # Remove from start_pos to just before renderCart
        block = content[start_pos:next_func]
        # Remove any leading/trailing whitespace/newlines
        # We want to replace this block including the newline before function renderCart
        content = content.replace(block, '', 1)
        with open(path, 'w', encoding='utf-8') as f:
            f.write(content)
        sys.stdout.write('OK: removed old block\n')
    else:
        sys.stdout.write('FAIL: renderCart not found\n')
else:
    sys.stdout.write('SKIP: old block not found\n')
