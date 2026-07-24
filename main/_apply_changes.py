# Apply print preview and invoice redesign changes
import os, sys

os.chdir(os.path.dirname(os.path.abspath(__file__)))

# Avoid UnicodeEncodeError for terminal output
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

def replace_in_file(filepath, old, new, desc):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    if old in content:
        content = content.replace(old, new, 1)
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
        print(f"  [OK] {desc}")
        return True
    else:
        print(f"  [..] NOT FOUND: {desc}")
        return False

def fix_show_print_preview(filepath):
    print(f"\n--- Fixing showPrintPreview (full scale) ---")
    
    # Change max-width:440px to max-width:95vw
    ok = replace_in_file(filepath, 
        "max-width:440px;max-height:92vh",
        "max-width:95vw;max-height:98vh;width:95vw",
        "Remove 440px width limit")
    
    # Change content area padding and centering
    replace_in_file(filepath,
        "flex:1;overflow:auto;padding:12px;background:#f3f4f6;",
        "flex:1;overflow:auto;padding:20px;background:#f3f4f6;display:flex;align-items:flex-start;justify-content:center;",
        "Updated content area padding + centering")
    
    # Change iframe to be auto-sized with proper centering
    replace_in_file(filepath,
        "var iframe = document.createElement('iframe');\n    iframe.style.cssText = 'width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;';\n    content.appendChild(iframe);",
        "var iframe = document.createElement('iframe');\n    iframe.style.cssText = 'width:auto;min-width:300px;max-width:100%;height:100%;border:none;border-radius:8px;background:#fff;display:block;margin:0 auto;';\n    content.appendChild(iframe);",
        "Updated iframe to auto-width with min-width 300px")


def fix_footer_powered_by(filepath):
    print(f"\n--- Fixing 'Powered by' footer text ---")
    replace_in_file(filepath,
        "Powered by RestroGrow",
        "Powered by Restro Grow",
        "Changed RestroGrow to Restro Grow")


def fix_print_order_template_main(filepath):
    """Replace the printOrder HTML template with PDF-style invoice format"""
    print(f"\n--- Redesigning printOrder HTML (POS Invoice style) ---")
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    po_idx = content.find('// Print Order')
    if po_idx < 0:
        print("  [..] Could not find // Print Order section")
        return
    
    # Find the itemsHtml generation (the map function that creates item rows)
    # We need to replace the items.map(...) inside printOrder
    # to generate rows with columns: Item | Qty & (Rate) | Disc | Total
    
    # Find the items map section
    items_map_start = content.find('const itemsHtml = items.map', po_idx)
    if items_map_start < 0 or items_map_start > po_idx + 5000:
        items_map_start = content.find("itemsHtml = items.map", po_idx)
    if items_map_start < 0 or items_map_start > po_idx + 5000:
        print("  [..] Could not find itemsHtml = items.map in printOrder")
        items_map_start = -1
    
    if items_map_start > 0:
        # Find the closing of .join('') for itemsHtml
        items_map_end = content.find('.join(\'\')', items_map_start)
        if items_map_end < 0:
            items_map_end = content.find(".join('')", items_map_start)
        if items_map_end < 0:
            items_map_end = content.find('.join("")', items_map_start)
        
        if items_map_end > 0:
            items_map_end += 9  # include .join('')
            
            old_items_map = content[items_map_start:items_map_end]
            
            # New items map with Disc column
            new_items_map = """    const itemsHtml = items.map((it, idx) => {
      const itemTotal = (parseFloat(it.total_price) || 0);
      const itemRate = (parseFloat(it.price) || 0);
      const qty = it.quantity || 1;
      // Calculate per-item discount if available
      let itemDisc = 0;
      if (it.discount) { itemDisc = parseFloat(it.discount); }
      else if (it.discount_percent) { itemDisc = (itemRate * qty * parseFloat(it.discount_percent) / 100); }
      else if (discount > 0 && items.length > 0) {
        // Distribute total discount proportionally
        const subtotalNoDisc = items.reduce((sum, i) => sum + (parseFloat(i.total_price) || 0), 0);
        if (subtotalNoDisc > 0) { itemDisc = (itemTotal * discount / subtotalNoDisc); }
      }
      const afterDisc = itemTotal - itemDisc;
      const rateStr = formatCurrency(itemRate);
      return `
        <div class="item-row">
          <div class="col-item">
            <div class="item-name">${escapeHtml(it.item_name || it.name)}</div>
          </div>
          <div class="col-qty-rate">${qty} @ ${rateStr}</div>
          <div class="col-disc">${itemDisc > 0 ? '-' + formatCurrency(itemDisc) : '-'}</div>
          <div class="col-total">${formatCurrency(afterDisc)}</div>
        </div>
      `;
    }).join('')"""
            
            content = content.replace(old_items_map, new_items_map, 1)
            print("  [OK] Updated itemsHtml generation with Disc column")
        else:
            print("  [..] Could not find end of items.map join")
    else:
        print("  [..] Skipping items map replacement (not found)")
    
    # Now replace the entire HTML template
    # Find where the template starts
    html_start = content.find('const html = `', po_idx)
    if html_start < 0:
        print("  [..] Could not find const html = ` in printOrder")
    else:
        # Find the closing backtick and showPrintPreview call
        html_end_marker = "showPrintPreview('Invoice #'"
        invoice_call = content.find(html_end_marker, html_start)
        if invoice_call < 0:
            print("  [..] Could not find Invoice showPrintPreview call")
        else:
            # The template ends with `; right before showPrintPreview
            template_end_candidates = [
                content.rfind('`;\n\n    showPrintPreview', html_start, invoice_call),
                content.rfind('`;\n    showPrintPreview', html_start, invoice_call),
                content.rfind('`;\r\n\r\n    showPrintPreview', html_start, invoice_call),
                content.rfind('`;\r\n    showPrintPreview', html_start, invoice_call),
            ]
            html_end = max(c for c in template_end_candidates if c >= 0)
            
            if html_end >= 0:
                html_end += 1  # include the backtick
                old_html_template = content[html_start:html_end]
                
                # The NEW template - POS invoice style matching the PDF
                # Note: we use ${{...}} instead of ${...} in f-strings/Python strings
                # But this is raw JS template literal, so ${...} is correct
                # In the Python string we need to escape ${} as ${} since Python's str doesn't interpret it
                NEW_TEMPLATE = """    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice #${order.order_number}</title>
      <style>
        @media print { @page { margin:6mm; size:80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Courier New', Courier, monospace; padding:10px; max-width:280px; margin:0 auto; background:#fff; color:#111827; font-size:11px; }
        .header { text-align:center; border-bottom:2px solid #111827; padding-bottom:8px; margin-bottom:8px; }
        .restaurant-name { font-size:16px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:9px; color:#6b7280; line-height:1.3; font-family:'Segoe UI',Tahoma,sans-serif; }
        .bill-meta { font-size:10px; margin:6px 0; }
        .info-line { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .info-line .label { color:#6b7280; }
        .info-line .value { font-weight:700; color:#111827; text-align:right; }
        .pay-to { text-align:center; margin:6px 0; padding:4px 8px; border:1px dashed #9ca3af; font-size:10px; }
        .pay-to .label { color:#6b7280; font-size:9px; }
        .pay-to .name { font-weight:700; font-size:12px; color:#111827; }
        .items-section { margin:8px 0; }
        .items-header { display:flex; font-size:9px; font-weight:700; text-transform:uppercase; padding:4px 0; border-bottom:2px solid #111827; color:#111827; letter-spacing:0.5px; }
        .items-header .col-item { flex:1; }
        .items-header .col-qty-rate { width:80px; text-align:center; }
        .items-header .col-disc { width:45px; text-align:center; }
        .items-header .col-total { width:60px; text-align:right; }
        .item-row { display:flex; font-size:10px; padding:4px 0; border-bottom:1px dashed #d1d5db; }
        .item-row .col-item { flex:1; }
        .item-row .col-item .item-name { font-weight:600; font-size:11px; }
        .item-row .col-qty-rate { width:80px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-disc { width:45px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-total { width:60px; text-align:right; font-weight:600; font-size:11px; }
        .sep-line { border:none; border-top:1px dashed #9ca3af; margin:4px 0; }
        .divider { border:none; border-top:2px dashed #9ca3af; margin:8px 0; }
        .totals { margin-top:4px; }
        .total-row { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .total-row .label { color:#6b7280; }
        .total-row .value { font-weight:600; }
        .total-row.gross { border-top:1px solid #9ca3af; padding-top:3px; font-weight:700; font-size:11px; }
        .total-row.grand { font-size:14px; font-weight:800; border-top:2px solid #111827; padding-top:4px; margin-top:2px; }
        .payment-info { text-align:center; margin:6px 0; padding:4px; border:1px dashed #d1d5db; font-size:9px; }
        .footer { text-align:center; margin-top:8px; padding-top:6px; border-top:1px dashed #d1d5db; }
        .footer-text { font-size:9px; color:#6b7280; margin-bottom:1px; }
        .footer .powered { font-size:8px; color:#9ca3af; margin-top:3px; }
        .asterisk { text-align:center; color:#9ca3af; font-size:10px; margin:6px 0; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Mob: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
      </div>

      <div class="bill-meta">
        <div class="info-line"><span class="label">${tableOrType.includes('Table') ? 'DineIn' : 'Order'}</span><span class="value">${escapeHtml(tableOrType)}</span></div>
        <div class="info-line"><span class="label">Date</span><span class="value">${dateStr} | ${timeStr}</span></div>
        <div class="info-line"><span class="label">Bill</span><span class="value">#${escapeHtml(order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Invoice</span><span class="value">#INV-${new Date(order.created_at).toISOString().split('T')[0].replace(/-/g, '')}-${escapeHtml(order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Order No</span><span class="value">#${escapeHtml(order.id || order.order_number)}</span></div>
      </div>

      <div class="pay-to">
        <div class="label">Pay To</div>
        <div class="name">${escapeHtml(restaurantInfo.name)}</div>
      </div>

      <hr class="sep-line">

      <div class="items-section">
        <div class="items-header">
          <span class="col-item">Items</span>
          <span class="col-qty-rate">Qty &amp; (Rate)</span>
          <span class="col-disc">Disc</span>
          <span class="col-total">Total</span>
        </div>
        ${itemsHtml}
      </div>

      <hr class="sep-line">

      <div class="totals">
        <div class="total-row gross">
          <span class="label">GROSS AMOUNT</span>
          <span class="value">${formatCurrency(subtotal + tax)}</span>
        </div>
        ${discount > 0 ? `
        <div class="total-row">
          <span class="label">Discount</span>
          <span class="value">-${formatCurrency(discount)}</span>
        </div>
        ` : ''}
        <div class="total-row grand">
          <span class="label">TOTAL AMOUNT</span>
          <span class="value">${formatCurrency(total)}</span>
        </div>
      </div>

      ${restaurantInfo.phone ? `
      <div class="asterisk">* * * * * * * * * * * * *</div>
      <div class="payment-info">
        <strong>Need help? Contact us:</strong><br>
        ${escapeHtml(restaurantInfo.phone)}
      </div>
      ` : ''}

      ${businessQRHtml}

      <div class="footer">
        <div class="footer-text">Thank you for visiting</div>
        <div class="powered">Powered by Restro Grow</div>
      </div>
    </body></html>`"""
                
                if old_html_template in content:
                    content = content.replace(old_html_template, NEW_TEMPLATE, 1)
                    print("  [OK] Replaced printOrder HTML template with POS invoice format")
                    with open(filepath, 'w', encoding='utf-8') as f:
                        f.write(content)
                else:
                    print("  [..] Could not match old HTML template exactly")
                    # Try with different backtick patterns
                    print(f"  Debug: html_end marker found at {html_end}")
                    print(f"  Debug: old_html length = {len(old_html_template)}")
                    print(f"  Debug: first 200 chars = {repr(old_html_template[:200])}")
            else:
                print("  [..] Could not find end of HTML template backtick")


def fix_print_order_items_public(filepath):
    """Fix the printOrder itemsHtml for public/script.js - simpler format"""
    print(f"\n--- Fixing printOrder itemsHtml (public) ---")
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    po_idx = content.find('// Print Order')
    if po_idx < 0:
        print("  [..] Could not find // Print Order section")
        return False
    
    # Find items.map
    items_map_start = content.find('const itemsHtml = items.map', po_idx)
    if items_map_start < 0:
        items_map_start = content.find("itemsHtml = items.map", po_idx)
    
    if items_map_start > 0:
        items_map_end = content.find('.join(\'\')', items_map_start)
        if items_map_end < 0:
            items_map_end = content.find(".join('')", items_map_start)
        if items_map_end > 0:
            items_map_end += 9
            old_map = content[items_map_start:items_map_end]
            
            new_map = """    const itemsHtml = items.map((it, idx) => {
      const itemTotal = (parseFloat(it.total_price) || 0);
      const itemRate = (parseFloat(it.price) || 0);
      const qty = it.quantity || 1;
      let itemDisc = 0;
      if (it.discount) { itemDisc = parseFloat(it.discount); }
      else if (discount > 0 && items.length > 0) {
        const subtotalNoDisc = items.reduce((sum, i) => sum + (parseFloat(i.total_price) || 0), 0);
        if (subtotalNoDisc > 0) { itemDisc = (itemTotal * discount / subtotalNoDisc); }
      }
      const afterDisc = itemTotal - itemDisc;
      const rateStr = formatCurrency(itemRate);
      return `
        <div class="item-row">
          <div class="col-item">
            <div class="item-name">${escapeHtml(it.item_name || it.name)}</div>
          </div>
          <div class="col-qty-rate">${qty} @ ${rateStr}</div>
          <div class="col-disc">${itemDisc > 0 ? '-' + formatCurrency(itemDisc) : '-'}</div>
          <div class="col-total">${formatCurrency(afterDisc)}</div>
        </div>
      `;
    }).join('')"""
            
            content = content.replace(old_map, new_map, 1)
            print("  [OK] Updated itemsHtml generation for public/script.js")
            with open(filepath, 'w', encoding='utf-8') as f:
                f.write(content)
            return True
    
    print("  [..] Could not find items.map in public printOrder")
    return False


def fix_print_order_template_public(filepath):
    """Replace printOrder HTML template in public/script.js"""
    print(f"\n--- Replacing printOrder HTML template (public) ---")
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    po_idx = content.find('// Print Order')
    if po_idx < 0:
        print("  [..] Could not find // Print Order section")
        return
    
    html_start = content.find('const html = `', po_idx)
    if html_start < 0:
        print("  [..] Could not find const html = `")
        return
    
    html_end_marker = "showPrintPreview('Invoice #'"
    invoice_call = content.find(html_end_marker, html_start)
    if invoice_call < 0:
        print("  [..] Could not find Invoice showPrintPreview")
        return
    
    template_end_candidates = [
        content.rfind('`;\n\n    showPrintPreview', html_start, invoice_call),
        content.rfind('`;\n    showPrintPreview', html_start, invoice_call),
        content.rfind('`;\r\n\r\n    showPrintPreview', html_start, invoice_call),
        content.rfind('`;\r\n    showPrintPreview', html_start, invoice_call),
    ]
    html_end = max(c for c in template_end_candidates if c >= 0)
    
    if html_end < 0:
        print("  [..] Could not find end of HTML template")
        return
    
    html_end += 1
    old_html = content[html_start:html_end]
    
    NEW_PUBLIC_TEMPLATE = """    const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice #${order.order_number}</title>
      <style>
        @media print { @page { margin:6mm; size:80mm auto; } body { margin:0; padding:0; } }
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Courier New', Courier, monospace; padding:10px; max-width:280px; margin:0 auto; background:#fff; color:#111827; font-size:11px; }
        .header { text-align:center; border-bottom:2px solid #111827; padding-bottom:8px; margin-bottom:8px; }
        .restaurant-name { font-size:16px; font-weight:800; text-transform:uppercase; letter-spacing:0.5px; font-family:'Segoe UI',Tahoma,sans-serif; }
        .restaurant-details { font-size:9px; color:#6b7280; line-height:1.3; font-family:'Segoe UI',Tahoma,sans-serif; }
        .bill-meta { font-size:10px; margin:6px 0; }
        .info-line { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .info-line .label { color:#6b7280; }
        .info-line .value { font-weight:700; color:#111827; text-align:right; }
        .pay-to { text-align:center; margin:6px 0; padding:4px 8px; border:1px dashed #9ca3af; font-size:10px; }
        .pay-to .label { color:#6b7280; font-size:9px; }
        .pay-to .name { font-weight:700; font-size:12px; color:#111827; }
        .items-section { margin:8px 0; }
        .items-header { display:flex; font-size:9px; font-weight:700; text-transform:uppercase; padding:4px 0; border-bottom:2px solid #111827; color:#111827; letter-spacing:0.5px; }
        .items-header .col-item { flex:1; }
        .items-header .col-qty-rate { width:80px; text-align:center; }
        .items-header .col-disc { width:45px; text-align:center; }
        .items-header .col-total { width:60px; text-align:right; }
        .item-row { display:flex; font-size:10px; padding:4px 0; border-bottom:1px dashed #d1d5db; }
        .item-row .col-item { flex:1; }
        .item-row .col-item .item-name { font-weight:600; font-size:11px; }
        .item-row .col-qty-rate { width:80px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-disc { width:45px; text-align:center; font-size:9px; color:#6b7280; }
        .item-row .col-total { width:60px; text-align:right; font-weight:600; font-size:11px; }
        .sep-line { border:none; border-top:1px dashed #9ca3af; margin:4px 0; }
        .totals { margin-top:4px; }
        .total-row { display:flex; justify-content:space-between; padding:2px 0; font-size:10px; }
        .total-row .label { color:#6b7280; }
        .total-row .value { font-weight:600; }
        .total-row.gross { border-top:1px solid #9ca3af; padding-top:3px; font-weight:700; font-size:11px; }
        .total-row.grand { font-size:14px; font-weight:800; border-top:2px solid #111827; padding-top:4px; margin-top:2px; }
        .payment-info { text-align:center; margin:6px 0; padding:4px; border:1px dashed #d1d5db; font-size:9px; }
        .footer { text-align:center; margin-top:8px; padding-top:6px; border-top:1px dashed #d1d5db; }
        .footer-text { font-size:9px; color:#6b7280; margin-bottom:1px; }
        .footer .powered { font-size:8px; color:#9ca3af; margin-top:3px; }
        .asterisk { text-align:center; color:#9ca3af; font-size:10px; margin:6px 0; }
      </style>
    </head><body>
      <div class="header">
        ${logoHtml}
        <div class="restaurant-name">${escapeHtml(restaurantInfo.name)}</div>
        ${restaurantInfo.address ? `<div class="restaurant-details">${escapeHtml(restaurantInfo.address)}</div>` : ''}
        ${restaurantInfo.phone ? `<div class="restaurant-details">Mob: ${escapeHtml(restaurantInfo.phone)}</div>` : ''}
      </div>

      <div class="bill-meta">
        <div class="info-line"><span class="label">${tableOrType.includes('Table') ? 'DineIn' : 'Order'}</span><span class="value">${escapeHtml(tableOrType)}</span></div>
        <div class="info-line"><span class="label">Date</span><span class="value">${dateStr} | ${timeStr}</span></div>
        <div class="info-line"><span class="label">Bill</span><span class="value">#${escapeHtml(order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Invoice</span><span class="value">#INV-${new Date(order.created_at).toISOString().split('T')[0].replace(/-/g, '')}-${escapeHtml(order.order_number || order.id)}</span></div>
        <div class="info-line"><span class="label">Order No</span><span class="value">#${escapeHtml(order.id || order.order_number)}</span></div>
      </div>

      <div class="pay-to">
        <div class="label">Pay To</div>
        <div class="name">${escapeHtml(restaurantInfo.name)}</div>
      </div>

      <hr class="sep-line">

      <div class="items-section">
        <div class="items-header">
          <span class="col-item">Items</span>
          <span class="col-qty-rate">Qty &amp; (Rate)</span>
          <span class="col-disc">Disc</span>
          <span class="col-total">Total</span>
        </div>
        ${itemsHtml}
      </div>

      <hr class="sep-line">

      <div class="totals">
        <div class="total-row gross">
          <span class="label">GROSS AMOUNT</span>
          <span class="value">${formatCurrency(subtotal + tax)}</span>
        </div>
        ${discount > 0 ? `
        <div class="total-row">
          <span class="label">Discount</span>
          <span class="value">-${formatCurrency(discount)}</span>
        </div>
        ` : ''}
        <div class="total-row grand">
          <span class="label">TOTAL AMOUNT</span>
          <span class="value">${formatCurrency(total)}</span>
        </div>
      </div>

      ${restaurantInfo.phone ? `
      <div class="asterisk">* * * * * * * * * * * * *</div>
      <div class="payment-info">
        <strong>Need help? Contact us:</strong><br>
        ${escapeHtml(restaurantInfo.phone)}
      </div>
      ` : ''}

      ${businessQRHtml}

      <div class="footer">
        <div class="footer-text">Thank you for visiting</div>
        <div class="powered">Powered by Restro Grow</div>
      </div>
    </body></html>`"""
    
    if old_html in content:
        content = content.replace(old_html, NEW_PUBLIC_TEMPLATE, 1)
        print("  [OK] Replaced printOrder HTML template for public/script.js")
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
    else:
        print("  [..] Could not match old HTML template in public/script.js")
        print(f"  Debug: html_end={html_end}, old_html length={len(old_html)}")
        print(f"  Debug: first 300 chars = {repr(old_html[:300])}")


def fix_public_print_kot(filepath):
    """Add proper footer with 'Powered by Restro Grow' to public printKOT"""
    print(f"\n--- Fixing public printKOT footer ---")
    
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    
    # In public/script.js, the printKOT is simple. Add a footer line.
    # Find: Items: ${(kot.items||[]).length}
    old_footer = "Items: ${(kot.items||[]).length}"
    new_footer = "Items: ${(kot.items||[]).length}</div>\n      <hr>\n      <div class=\"meta\" style=\"font-size:10px;color:#999;margin-top:12px;\">Powered by Restro Grow</div>"
    
    if old_footer in content:
        content = content.replace(old_footer, new_footer, 1)
        print("  [OK] Added Powered by Restro Grow to public printKOT")
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)
    else:
        print("  [..] Could not find printKOT footer in public/script.js")


# ===== Main execution =====

# Fix main/assets/js/script.js
fix_show_print_preview('assets/js/script.js')
fix_footer_powered_by('assets/js/script.js')
fix_print_order_template_main('assets/js/script.js')

print("\n" + "="*60)

# Fix main/public/script.js
fix_show_print_preview('public/script.js')
fix_footer_powered_by('public/script.js')
fix_print_order_template_public('public/script.js')
fix_public_print_kot('public/script.js')

print("\n" + "="*60)
print("ALL DONE!")
