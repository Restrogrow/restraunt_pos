# Email Setup Instructions

## Gmail SMTP Configuration

To enable email sending in the Restaurant POS System, you need to configure Gmail SMTP.

### Step 1: Get Gmail App Password

1. Go to your Google Account: https://myaccount.google.com/
2. Click on **Security** in the left sidebar
3. Enable **2-Step Verification** (if not already enabled)
4. Scroll down to **App passwords**
5. Click **App passwords**
6. Select **Mail** as the app and **Other (Custom name)** as the device
7. Enter "Restaurant POS" as the name
8. Click **Generate**
9. Copy the 16-character password (it will look like: `abcd efgh ijkl mnop`)

### Step 2: Configure Email Settings

1. Open `config/email_config.php`
2. Update the following settings:

```php
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USERNAME', 'restrogrow@gmail.com'); // Your Gmail address
define('SMTP_PASSWORD', 'your-16-character-app-password'); // Paste the app password here
define('SMTP_FROM_EMAIL', 'restrogrow@gmail.com');
define('SMTP_FROM_NAME', 'Restaurant POS System');
```

3. Replace `your-16-character-app-password` with the actual app password you copied

### Step 3: Test Email

1. Go to `admin/test_email.php`
2. Click "Send Test Email"
3. Check your inbox for the test email

## Important Notes

- **Never use your regular Gmail password** - Always use an App Password
- **Keep your App Password secure** - Don't share it or commit it to version control
- The App Password is 16 characters (remove spaces when pasting)
- If email still doesn't work, check:
  - Firewall settings (port 587 should be open)
  - Gmail account security settings
  - Error logs in PHP error log

## Alternative: Use PHP mail() Function

If you don't want to use SMTP, you can disable it:

```php
define('SMTP_ENABLED', false);
```

However, this requires proper mail server configuration on your server, which is not available on localhost/XAMPP.

