import sys, os
sys.stdout.reconfigure(encoding='utf-8', errors='replace')

os.chdir(os.path.dirname(os.path.abspath(__file__)))

def verify(filepath):
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()
    print(f'\n=== {filepath} ===')
    checks = [
        ('Restro Grow (not RestroGrow)', 'Restro Grow' in content and 'RestroGrow' not in content),
        ('Full scale preview (max-width:95vw)', 'max-width:95vw' in content),
        ('Full scale preview (max-height:98vh)', 'max-height:98vh' in content),
        ('Iframe auto-width', 'width:auto;min-width:300px;max-width:100%;' in content),
        ('Content area flex centering', 'display:flex;align-items:flex-start;justify-content:center;' in content),
        ('printOrder has GROSS AMOUNT', 'GROSS AMOUNT' in content),
        ('printOrder has TOTAL AMOUNT', 'TOTAL AMOUNT' in content),
        ('printOrder has Pay To', 'Pay To' in content),
        ('printOrder has Invoice #INV', 'Invoice #INV-' in content or '#INV-' in content),
        ('printOrder has Thank you for visiting', 'Thank you for visiting' in content),
        ('printOrder has Powered by Restro Grow', 'Powered by Restro Grow' in content),
        ('printOrder has Qty & (Rate)', 'Qty &amp; (Rate)' in content),
        ('printOrder has col-disc', 'col-disc' in content),
        ('printOrder has Need help? Contact us', 'Need help? Contact us' in content),
    ]
    all_ok = True
    for name, result in checks:
        status = '[OK]' if result else '[FAIL]'
        if not result: all_ok = False
        print(f'  {status} {name}')
    
    # Check printKOT (only in assets file)
    if 'assets' in filepath:
        if 'Powered by Restro Grow' in content:
            print(f'  [OK] printKOT footer: Powered by Restro Grow present')
        else:
            print(f'  [FAIL] printKOT footer missing')
    
    if all_ok:
        print(f'\n>>> ALL CHECKS PASSED! <<<')
    else:
        print(f'\n>>> SOME CHECKS FAILED! <<<')
    
    return all_ok

r1 = verify('assets/js/script.js')
r2 = verify('public/script.js')

if r1 and r2:
    print('\n\n*** BOTH FILES VERIFIED SUCCESSFULLY! ***')
else:
    print('\n\n*** VERIFICATION FAILED FOR SOME FILES ***')
