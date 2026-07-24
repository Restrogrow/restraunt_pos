import sys, os
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.chdir(os.path.dirname(os.path.abspath(__file__)))

with open('assets/js/script.js', 'r', encoding='utf-8') as f:
    content = f.read()

po_idx = content.find('// Print Order')
template_start = content.find('const html = `', po_idx)
invoice_call = content.find("showPrintPreview('Invoice #'", po_idx)

# Old template: from `const html = \`` to the closing backtick + semicolon
template_end = content.rfind('`;', template_start, invoice_call)
old_template = content[template_start:template_end + 2]  # include backtick and ;

print(f'Found old template: {template_start} to {template_end + 2}')
print(f'Old template length: {len(old_template)}')
print(f'First 100 chars: {repr(old_template[:100])}')
print(f'Last 100 chars: {repr(old_template[-100:])}')

# New template - POS Invoice PDF style
new_template = """const html = `<!DOCTYPE html><html><head><meta charset="utf-8"><title>Invoice #${order.order_number}</title>
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
        <div class="info-line"><span class="label">Invoice</span><span class="value">#INV-${escapeHtml(String(order.order_number || order.id))}</span></div>
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

if old_template in content:
    content = content.replace(old_template, new_template, 1)
    with open('assets/js/script.js', 'w', encoding='utf-8') as f:
        f.write(content)
    print('[OK] Template replaced successfully!')
else:
    print('[ERROR] Could not find old template in content!')
    # Write old template to file for analysis
    with open('_old_template_found.txt', 'w', encoding='utf-8') as f:
        f.write(old_template)
    print(f'Written old template to _old_template_found.txt')
