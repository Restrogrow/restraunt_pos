import sys, re

path = 'main/website/cart.php'
with open(path, 'r', encoding='utf-8') as f:
    content = f.read()

edits = []

# === 1. Remove the per-item CSS block (/* Cart Add-on Chips */ to </style>) ===
css_start = '/* Cart Add-on Chips */'
css_end = '</style>'

start_pos = content.find(css_start)
if start_pos >= 0:
    # Find the next </style> after the CSS block
    style_pos = content.find(css_end, start_pos)
    if style_pos >= 0:
        # Remove everything from css_start to just before </style>
        # But keep the blank line before </style>
        block_to_remove = content[start_pos:style_pos]
        content = content.replace(block_to_remove, '', 1)
        edits.append('Removed per-item addon CSS')

# === 2. Remove the entire "// --- Cart Add-ons Selection ---" JS block ===
js_start_marker = '// --- Cart Add-ons Selection ---'
js_end_marker = '// --- End Cart Add-ons Selection ---\r\n'

start_pos = content.find(js_start_marker)
if start_pos >= 0:
    end_pos = content.find(js_end_marker, start_pos)
    if end_pos >= 0:
        # Extend to end of the line after the end marker
        line_end = content.find('\n', end_pos)
        if line_end >= 0:
            block_to_remove = content[start_pos:line_end+1]
            content = content.replace(block_to_remove, '', 1)
            edits.append('Removed per-item addon JS block')
        else:
            sys.stdout.write('FAIL: line end not found\n')
    else:
        sys.stdout.write('FAIL: JS end marker not found\n')
else:
    sys.stdout.write('FAIL: JS start marker not found\n')

# === 3. Remove the renderAddonChips() call inside renderCart() ===
# The call is: "  renderAddonChips();\n" right before the closing "}" of renderCart
old_render_call = '  renderAddonChips();\r\n'
if old_render_call in content:
    content = content.replace(old_render_call, '', 1)
    edits.append('Removed renderAddonChips() call from renderCart')
else:
    # Try without \r
    old_render_call2 = '  renderAddonChips();\n'
    if old_render_call2 in content:
        content = content.replace(old_render_call2, '', 1)
        edits.append('Removed renderAddonChips() call from renderCart')

# === 4. Add global addon CSS and HTML section below Special Instructions in renderCart() ===
# The Special Instructions section in cartHtml ends with:
# '</div>' +
# '</div>';
# Right after this, the price details card starts.

# I need to add a global add-ons card between the Special Instructions card and the Price Details card.

# Find the exact text that ends the Special Instructions section
old_instr_end = "'</div>' +\n    '</div>';\n    var discountAmount = 0;"

# The Special Instructions card closing is at the end of the HTML building, before the discount calculation
# From the file I read:
#       '</div>' +
#       '</div>' +
#     '</div>';
#     var discountAmount = 0;

# The instruction section is followed by 
# '</div>' + (closes the instr-body-inner)
# '</div>' + (closes the instr-card)
# '</div>'; (closes the outer <div> wrapper)
# Then: var discountAmount = 0;

# So I need to find:
new_global_addon = '''
    '</div>' +
    '<div class="card card-pad" id="cartAddonCard">' +
      '<div class="coupon-header">' +
        '<div class="icon-wrap">\u2795</div>' +
        '<div class="header-text">' +
          '<strong>Add-ons</strong>' +
          '<span>Customise your order with extras</span>' +
        '</div>' +
      '</div>' +
      '<div id="cartAddonChips" style="display:flex;flex-wrap:wrap;gap:8px;padding:4px 0"></div>' +
    '</div>' +
  '</div>';
'''

# Try different patterns
old_patterns = [
    "'</div>' +\n    '</div>';\n    var discountAmount",
    "'</div>' +\r\n    '</div>';\r\n    var discountAmount"
]

applied = False
for old in old_patterns:
    if old in content:
        content = content.replace(old, new_global_addon + '\n    var discountAmount', 1)
        applied = True
        edits.append('Added global addon section below Special Instructions')
        sys.stdout.write('Applied with pattern: ' + repr(old[:40]) + '\n')
        break

if not applied:
    sys.stdout.write('FAIL: Special Instructions end pattern not found\n')

# === 5. Replace the fetchAddons() function with a simplified version ===
# Since we removed the per-item functions but fetchAddons() is still called in DOMContentLoaded,
# we need to keep fetchAddons() but simplify it.
# Let me add a simple fetchAddons and a function to populate global addons.

new_simple_functions = '''
var addonsList = [];

function fetchAddons() {
  var rid = window.websiteRestaurantId || document.querySelector('meta[name="restaurant-id"]')?.content || 'RES001';
  fetch('api.php?restaurant_id=' + encodeURIComponent(rid) + '&action=getAddons')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      if (data.success && data.data) {
        addonsList = data.data;
        renderGlobalAddons();
      }
    })
    .catch(function() {});
}

function renderGlobalAddons() {
  var container = document.getElementById('cartAddonChips');
  if (!container) return;
  if (addonsList.length === 0) {
    container.innerHTML = '<div style="font-size:12px;color:#aaa;padding:4px 0">No add-ons available</div>';
    return;
  }
  var sym = getCurrency();
  var html = '';
  for (var i = 0; i < addonsList.length; i++) {
    var a = addonsList[i];
    if (a.is_available == 0) continue;
    var aName = a.addon_name || a.name || 'Add-on';
    var aPrice = parseFloat(a.addon_price || a.price || 0);
    html += '<button onclick="addGlobalAddon(' + a.id + ',\\'' + aName.replace(/'/g, "\\\\'") + '\\',' + aPrice + ')" style="padding:8px 14px;border:1.5px solid #e0d8d0;border-radius:20px;background:#fff;color:#555;font-size:12px;font-weight:500;font-family:Poppins,sans-serif;cursor:pointer;transition:all 0.15s;white-space:nowrap" onmouseover="this.style.borderColor=\\'#e17055\\';this.style.color=\\'#e17055\\'" onmouseout="this.style.borderColor=\\'#e0d8d0\\';this.style.color=\\'#555\\'">' +
      escapeHtml(aName) + ' <span style="font-weight:400;opacity:0.8">+' + sym + aPrice.toFixed(2) + '</span></button>';
  }
  container.innerHTML = html;
}

function addGlobalAddon(addonId, addonName, addonPrice) {
  var key = 'addon_' + addonId;
  if (cartItems[key]) {
    if (typeof cartItems[key] === 'object') {
      cartItems[key].qty = (cartItems[key].qty || 0) + 1;
    } else {
      cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
    }
  } else {
    cartItems[key] = { qty: 1, addonName: addonName, addonPrice: addonPrice, isAddon: true };
  }
  saveCart();
  renderCart();
}
'''

# Find the end of // --- End Add-ons Helpers --- and insert the simplified functions
old_helpers_end = '// --- End Add-ons Helpers ---\r\n'

# But we removed the old JS block, so we need to find what's after the helpers now.
# The helpers end with "// --- End Add-ons Helpers ---" and then there's either a newline
# or the fetchAddons function (if we removed the JS block)

# Let me check what's currently after the helpers
helpers_marker = '// --- End Add-ons Helpers ---\r\n'
if helpers_marker in content:
    # Remove anything between this marker and the function renderCart() declaration
    # And replace with our simplified functions
    after_helpers = content[content.find(helpers_marker) + len(helpers_marker):]
    # Find the next function declaration
    next_func = after_helpers.find('function ')
    if next_func >= 0:
        old_content = content[content.find(helpers_marker):content.find(helpers_marker) + len(helpers_marker) + next_func]
        new_content = helpers_marker + '\r\n' + new_simple_functions.strip().replace('\n', '\r\n') + '\r\n\r\n'
        content = content.replace(old_content, new_content, 1)
        edits.append('Added simplified global addon functions')
        sys.stdout.write('Replaced JS block\n')
    else:
        sys.stdout.write('FAIL: next function not found\n')
else:
    sys.stdout.write('FAIL: helpers marker not found\n')

# === 6. Add CSS for the addon chips in the global section ===
# Add before </style>
global_css = '''
/* Global Add-ons */
#cartAddonChips button:hover {
  border-color: #e17055 !important;
  color: #e17055 !important;
}
'''

if '</style>' in content:
    # Add the CSS right before </style> (after existing styles)
    content = content.replace('</style>', global_css + '\n</style>', 1)
    edits.append('Added global addon CSS')

# Write back
if edits:
    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    sys.stdout.write('DONE: ' + str(len(edits)) + ' edits - ' + ', '.join(edits) + '\n')
else:
    sys.stdout.write('FAIL: no edits applied\n')
