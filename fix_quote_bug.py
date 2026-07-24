import sys

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

# Find the exact problematic block in renderAddonChips
# The section.innerHTML assignment with the broken onclick
old_section = '''      section.innerHTML =
        '<button class="cart-addon-toggle" onclick="var k=this.parentElement.getAttribute('data-item-key');_addonOpenKeys[k]=this.classList.toggle('open');var s=this.parentElement;var b=s.querySelector('.cart-addon-body');if(b)b.classList.toggle('visible');renderAddonChips()">' +
          '<span>+ Add extras</span>' +
          '<span class="addon-chevron">&#9660;</span>' +
        '</button>' +
        '<div class="cart-addon-body">' +
          '<div class="cart-addon-chips"></div>' +
        '</div>';
      card.appendChild(section);
    if (_addonOpenKeys[key]) {
      var btn = section.querySelector('.cart-addon-toggle');
      var body = section.querySelector('.cart-addon-body');
      if (btn) btn.classList.add('open');
      if (body) body.classList.add('visible');
    }'''

new_section = '''      section.innerHTML =
        '<button class="cart-addon-toggle" id="addonTgl-' + key.replace(/[^a-zA-Z0-9]/g, '') + '">' +
          '<span>+ Add extras</span>' +
          '<span class="addon-chevron">&#9660;</span>' +
        '</button>' +
        '<div class="cart-addon-body">' +
          '<div class="cart-addon-chips"></div>' +
        '</div>';
      card.appendChild(section);
      var toggleBtn = section.querySelector('.cart-addon-toggle');
      if (toggleBtn) {
        toggleBtn.addEventListener('click', function() {
          var k = this.parentElement.getAttribute('data-item-key');
          _addonOpenKeys[k] = this.classList.toggle('open');
          var body = this.parentElement.querySelector('.cart-addon-body');
          if (body) body.classList.toggle('visible');
        });
      }
    if (_addonOpenKeys[key]) {
      var btn = section.querySelector('.cart-addon-toggle');
      var body = section.querySelector('.cart-addon-body');
      if (btn) btn.classList.add('open');
      if (body) body.classList.add('visible');
    }'''

if old_section in content:
    content = content.replace(old_section, new_section, 1)
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    sys.stdout.write('OK: replaced section.innerHTML with addEventListener approach\n')
else:
    sys.stdout.write('FAIL: pattern not found\n')
    # Show what the first 200 chars around section.innerHTML look like
    idx = content.find('section.innerHTML =')
    if idx >= 0:
        sys.stdout.write('Found at ' + str(idx) + ': ' + repr(content[idx:idx+200]) + '\n')
    else:
        sys.stdout.write('section.innerHTML = not found\n')
