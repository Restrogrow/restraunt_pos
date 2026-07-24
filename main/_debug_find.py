import sys, os
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.chdir(os.path.dirname(os.path.abspath(__file__)))

with open('assets/js/script.js', 'r', encoding='utf-8') as f:
    content = f.read()

po_idx = content.find('// Print Order')
print(f'printOrder at: {po_idx}')

# Search for patterns
pat = 'const html = `'
idx = content.find(pat, po_idx)
if idx >= 0:
    print(f'FOUND "{pat}" at {idx}')
    print(f'Context: {repr(content[idx:idx+80])}')
else:
    # Try finding just "const html" 
    idx2 = content.find('const html', po_idx)
    print(f"'const html' at: {idx2}")
    if idx2 >= 0:
        print(f'Context: {repr(content[idx2:idx2+100])}')

# Find itemsHtml
items_start = content.find('const itemsHtml', po_idx)
if items_start >= 0:
    print(f"'const itemsHtml' found at {items_start}")
    print(f'Context: {repr(content[items_start:items_start+100])}')

# Find the showPrintPreview call
invoice_call = content.find("showPrintPreview('Invoice #'", po_idx)
if invoice_call >= 0:
    print(f'showPrintPreview call at {invoice_call}')
    before = content[invoice_call-30:invoice_call]
    print(f'Before call: {repr(before)}')
    
    # Find the end of the HTML template (backtick before showPrintPreview)
    if idx >= 0:
        end_marker = content.rfind('`;', idx, invoice_call)
        print(f'Last backtick+; before call: {end_marker}')
        if end_marker >= 0:
            between = content[end_marker:invoice_call]
            print(f'Between end and call: {repr(between[:50])}')
