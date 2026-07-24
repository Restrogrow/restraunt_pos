# Dashboard Debug Guide

## What Was Fixed

✅ All API files now use PDO consistently  
✅ Added console logging for debugging  
✅ Added auto-load on dashboard page  
✅ Fixed database connection methods  
✅ Added error handling  

## How to Test

### Method 1: Direct Test
1. Visit: `http://localhost/menu/dashboard_debug.html`
2. Click "Call get_dashboard_stats.php"
3. See if API returns data
4. Check console for errors

### Method 2: Dashboard Test
1. Visit: `http://localhost/menu/dashboard.php`
2. Open browser console (F12)
3. Look for:
   - "Loading dashboard stats..."
   - "Dashboard API response:"
   - Any error messages

### Method 3: Direct API Test
1. Visit: `http://localhost/menu/get_dashboard_stats.php`
2. Should see JSON output
3. If you see error, check:
   - Database connection
   - Session login
   - Table existence

## Common Issues & Fixes

### Issue 1: "Loading..." but no data
**Cause:** JavaScript errors  
**Fix:** Check browser console for errors

### Issue 2: "Error" in stats
**Cause:** API not returning data  
**Fix:** 
- Visit `test_dashboard_api.php`
- Check database has data
- Ensure logged in

### Issue 3: Blank dashboard
**Cause:** Elements not found  
**Fix:**
- Open console
- Type: `document.getElementById('todayRevenue')`
- Should return element (not null)

### Issue 4: API returns error
**Cause:** Database connection  
**Fix:**
- Check XAMPP MySQL is running
- Verify database `restro2` exists
- Run `test_connection.php`

## Console Commands

Test if function exists:
```javascript
typeof loadDashboardStats
```

Test if elements exist:
```javascript
document.getElementById('todayRevenue')
document.getElementById('totalItems')
document.getElementById('pendingOrders')
```

Force load dashboard:
```javascript
loadDashboardStats()
```

## Files Modified

✅ `get_dashboard_stats.php` - Fixed PDO connection  
✅ `get_tables.php` - Fixed PDO connection  
✅ `get_menu_items.php` - Fixed PDO connection  
✅ `script.js` - Added logging and auto-load  
✅ `dashboard.php` - Added force-load script  

## Next Steps

1. Open dashboard: `http://localhost/menu/dashboard.php`
2. Open console: Press F12
3. Look for logs showing data
4. If errors appear, check:
   - Database is connected
   - Tables exist
   - You're logged in
   - Session is active

## Debug Checklist

- [ ] Can access `get_dashboard_stats.php` directly
- [ ] Returns valid JSON
- [ ] No PHP errors in response
- [ ] Dashboard page loads
- [ ] Console shows loading message
- [ ] Console shows API response
- [ ] Stats update with data

## Status

The dashboard should now:
- ✅ Load stats automatically
- ✅ Show data from database
- ✅ Display in beautiful cards
- ✅ Update on refresh
- ✅ Show recent orders
- ✅ Show popular items

