# Payment System Setup - Complete Guide

## ✅ What Was Created

### 1. Database Table
- **Payments table** added to `database/database_schema.sql`
- Fields: ID, Amount, Payment Method, Transaction ID, Order, Status, Date

### 2. Migration File
- **`migrations/002_create_payments_table.sql`** - Run this to add the payments table

### 3. API Files
- **`get_payments.php`** - Fetches all payments with filters
- **`save_payment.php`** - Saves payment records

### 4. Dashboard Integration
- **Payments menu** added (above Settings)
- **Payments page** with beautiful table matching your image
- **Filters**: Search, Method, Status

### 5. Auto-Save Feature
- **`pos_operations.php`** - Saves payment when order is created

## 📋 How to Set Up

### Step 1: Run Migration
```bash
# Option 1: Via phpMyAdmin
# Import file: migrations/002_create_payments_table.sql

# Option 2: Via Command Line
mysql -u root -p restro2 < migrations/002_create_payments_table.sql
```

### Step 2: Test the System
1. Login to dashboard
2. Click "Payments" in sidebar
3. Should see "No payments found" (empty initially)
4. Create an order via POS with payment method
5. Go back to Payments - should now see the payment

## 🎨 Features

### Payments Page
- **ID** - Payment record ID
- **Amount** - In Rupees (₹)
- **Payment Method** - Cash, UPI, Card, etc.
- **Transaction ID** - Optional reference
- **Order** - Clickable order link
- **Status** - Success/Failed/Pending/Refunded
- **Date & Time** - Formatted timestamp

### Filters
- **Search** - By order, amount, transaction ID
- **Method Filter** - Cash, UPI, Card, Online, Wallet
- **Status Filter** - Success, Failed, Pending, Refunded

### Colors
- **Cash**: Green (#48bb78)
- **UPI**: Purple (#667eea)
- **Card**: Orange (#f6ad55)
- **Success**: Green
- **Failed**: Red
- **Pending**: Orange

## 💾 Auto-Save When Creating Orders

When you create an order in POS with a payment method, the payment is **automatically saved** to the database. This happens in `pos_operations.php`.

## 📁 Files Created/Modified

### Created:
✅ `get_payments.php` - Payment API  
✅ `save_payment.php` - Save payment API  
✅ `migrations/002_create_payments_table.sql` - Migration  

### Modified:
✅ `database/database_schema.sql` - Added payments table  
✅ `dashboard.php` - Added Payments menu and page  
✅ `script.js` - Added loadPayments() function  
✅ `pos_operations.php` - Auto-saves payments  

## 🧪 Testing

1. **Run migration** to add the payments table
2. **Create an order** via POS with payment method selected
3. **Go to Payments page** to see the payment record
4. **Test filters** to search by method/status

## 📊 Database Schema

```sql
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    restaurant_id VARCHAR(10) NOT NULL,
    order_id INT NOT NULL,
    transaction_id VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    payment_method ENUM('Cash', 'Card', 'UPI', 'Online', 'Wallet') DEFAULT 'Cash',
    payment_status ENUM('Success', 'Failed', 'Pending', 'Refunded') DEFAULT 'Success',
    reference_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

**Status:** ✅ Complete  
**Next:** Run the migration file  
**Usage:** Payments auto-save when orders are created

