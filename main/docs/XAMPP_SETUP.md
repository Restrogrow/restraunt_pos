# XAMPP Setup Guide

## How to Run with XAMPP (No Port 8000)

### 1. **Start XAMPP Services**
- Open XAMPP Control Panel
- Start **Apache** service
- Start **MySQL** service

### 2. **Database Setup**
1. Open browser and go to: `http://localhost/phpmyadmin`
2. Create a new database called `restro2`
3. Import the SQL file: `database/database_schema.sql`
4. Or run the SQL commands from `database/database_schema.sql` in phpMyAdmin

### 3. **Access the Application**
- Open browser and go to: `http://localhost/menu/`
- This will automatically redirect to: `http://localhost/menu/admin/login.php`
- After login, redirects to: `http://localhost/menu/dashboard.php`

### 4. **Demo Login Credentials**
- **Username**: `admin`
- **Password**: `password`

### 5. **File Structure in XAMPP**
```
C:\xampp\htdocs\menu\
├── index.php (main redirect)
├── dashboard.php (main dashboard with session check)
├── index.html (original dashboard - not used directly)
├── script.js
├── style.css
├── database/database_schema.sql
├── db_connection.php
├── menu_operations.php
├── menu_items_operations.php
├── menu_items_operations_base64.php
├── get_menus.php
├── get_menu_items.php
├── test_setup.php (verification)
├── admin/
│   ├── login.php (auth page)
│   ├── auth.php (backend)
│   └── get_session.php (session data)
└── uploads/ (auto-created for images)
```

### 6. **Database Configuration**
Make sure your `db_connection.php` has the correct XAMPP settings:
```php
$host = 'localhost';
$dbname = 'restro2';
$username = 'root';
$password = ''; // XAMPP default is empty
```

### 7. **Troubleshooting**
- If you get database errors, check MySQL is running
- If you get permission errors, check Apache is running
- If images don't upload, make sure `uploads/` folder exists and is writable

### 8. **URLs to Access**
- **Main App**: `http://localhost/menu/`
- **Login Page**: `http://localhost/menu/admin/login.php`
- **Dashboard**: `http://localhost/menu/admin/index.php` (after login)

That's it! No need for port 8000 or PHP development server. Just use XAMPP's built-in Apache server.
