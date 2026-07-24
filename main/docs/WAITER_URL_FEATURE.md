# Waiter URL Parameter Feature

## ✅ What This Does

### URL Parameter Support
- Visit: `http://localhost/menu/website/index.php?table=ID-01`
- Or: `http://localhost/menu/website/index.php?tableno=ID-01`
- Skips table selection modal
- Shows confirmation popup directly: "Do you want to notify the waiter for ID-01 - Indoor Dining?"

## 📋 How It Works

### Without URL Parameter:
1. Visit: `http://localhost/menu/website/index.php`
2. Click "Call Waiter"
3. Modal opens showing all tables
4. Select a table
5. Confirmation popup appears

### With URL Parameter:
1. Visit: `http://localhost/menu/website/index.php?table=ID-01`
2. Click "Call Waiter"
3. **Skips modal**
4. Shows confirmation popup: "Do you want to notify the waiter for ID-01 - Indoor Dining?"
5. Click "Yes, Notify" → Done!

## 🎯 Use Cases

### For QR Codes:
Generate QR codes with URLs like:
```
http://localhost/menu/website/index.php?table=T02
```

When customer scans:
- Opens menu directly for that table
- Clicking "Call Waiter" uses that table automatically

### For Direct Links:
Send customers direct links to their table's menu:
```
http://localhost/menu/website/index.php?table=ID-01
```

## 🔧 How It's Implemented

### JavaScript Logic:
```javascript
// Checks URL for table parameter
getTableFromURL() // Looks for ?table= or ?tableno=

// If table found in URL:
showConfirmForTableFromURL(tableNumber)
  ↓
  Fetches table info
  ↓
  Shows confirmation popup directly

// If no table in URL:
openCallWaiterModal() // Shows table selection
```

## 📝 Example URLs

### Using Table Number:
```
http://localhost/menu/website/index.php?table=T02
http://localhost/menu/website/index.php?tableno=ID-01
```

### Standard (No table):
```
http://localhost/menu/website/index.php
```

## 🎉 Benefits

- ✅ Faster for customers at specific tables
- ✅ Perfect for QR codes on tables
- ✅ Reduces clicks
- ✅ Seamless experience
- ✅ Fallback to modal if table not found

