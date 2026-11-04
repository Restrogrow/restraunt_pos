# Call Waiter System - Complete Implementation

## ✅ What Was Created

### 1. Customer Website (website/index.php)
- ✅ **"Call Waiter" button** beside cart in navigation
- ✅ Modal that shows all tables
- ✅ Table selection with area display
- ✅ Confirmation prompt: "Do you want to notify the waiter?"

### 2. Waiter Dashboard (dashboard.php)
- ✅ **"Waiter Requests" tab** in sidebar
- ✅ Requests grouped by area (like your image)
- ✅ Shows: Table number, Time ago, Waiter assigned
- ✅ Action buttons: "Mark Attended" and "Show Order"
- ✅ Empty state when no requests

### 3. API Files
- ✅ `create_waiter_request.php` - Creates waiter request from customer
- ✅ `get_waiter_requests.php` - Fetches requests grouped by area

### 4. JavaScript
- ✅ Opens modal with all tables
- ✅ Customer selects table
- ✅ Confirmation dialog
- ✅ Saves to database
- ✅ Displays in dashboard grouped by area

## 📋 How It Works

### Customer Side:
1. Click **"Call Waiter"** button
2. Modal opens showing all tables grouped by area
3. Select a table (e.g., "T02 - Indoor Dining")
4. Confirmation: "Do you want to notify the waiter for T02 - Indoor Dining?"
5. Click Yes → Request saved
6. See success message

### Dashboard Side:
1. Go to **"Waiter Requests"** tab
2. See requests grouped by area:
   - **Rooftop** (1 Table)
   - **room num 1** (0 Table)
3. Each request shows:
   - Table number badge (blue)
   - Time ago
   - Who said they are going, if anyone
   - Request notes
   - Action buttons

## 🙋 Who is going (added 2026-11-05)

No call is ever **assigned** to a waiter. The board stays the open list it always was: every
waiter sees every call, and any of them can finish any of them. What was missing was simply
knowing whether somebody had already set off, so two waiters stopped walking to the same table.

- **I'm going** — stamps `waiter_requests.taken_by` / `taken_by_name` / `taken_at` and leaves the
  call on everyone's board, now reading "Ramesh is going". First tap wins; anyone else tapping is
  told who got there first, so nobody is quietly overwritten.
- **Not me** — puts it back for anyone. Only the waiter who took it may do this.
- **Attended** — anybody can still finish any call. Whoever *took* it keeps the credit
  (`COALESCE`), and a call nobody took gets the finisher's name.
- **Filters** on the waiter and manager boards: All / Nobody going / Mine. The chips only appear
  once at least one call has been taken, so a quiet shift sees no extra furniture.

"Mine" follows the person, not the device: `StaffUI.isMine()` matches on staff id, falling back to
the name for an owner working the floor without a staff row.

Columns are added on first use by `waiterCallEnsureSchema()` (`config/waiter_call.php`), which also
owns `users.call_waiter_enabled`. Who is pressing the button comes from `waiterCallWhoAmI()`.

## 🎨 Design Features

### Customer Modal:
- Clean white background
- Table cards with hover effects
- Selected state (red background)
- Area labels below table numbers

### Dashboard Display:
- Grouped by area name
- Table count badge
- Time indicators ("X minutes ago")
- Waiter assignment
- Empty state icons

## 📁 Files Created/Modified

### Created:
✅ `create_waiter_request.php` - Save request API  
✅ `get_waiter_requests.php` - Fetch requests API  

### Modified:
✅ `website/index.php` - Added Call Waiter button and modal  
✅ `website/script.js` - Added waiter call functionality  
✅ `website/style.css` - Added modal and button styles  
✅ `script.js` - Updated waiter requests display  

## 🧪 Testing

### Test Customer Side:
1. Visit: `http://localhost/menu/website/index.php`
2. Click **"Call Waiter"** button
3. Select a table
4. Confirm to notify

### Test Dashboard:
1. Login to dashboard
2. Go to **"Waiter Requests"** tab
3. See requests grouped by area
4. Click "Mark Attended" to remove request

## 🎯 Features

### Customer Features:
- ✅ One-click call waiter
- ✅ Table selection
- ✅ Confirmation dialog
- ✅ Success notification

### Dashboard Features:
- ✅ Requests by area
- ✅ Time tracking
- ✅ Waiter assignment
- ✅ Mark attended
- ✅ Show order
- ✅ Empty state handling

---

**Status:** ✅ Complete  
**API:** Ready to use  
**UI:** Matches your image design

