path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

old = "/* (no global add-ons CSS needed) */"
new = ".addon-row:hover { background: #fdf8f5 !important; }"

if old in content:
    content = content.replace(old, new)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: hover CSS added")
else:
    print("FAILED: could not find target text")
