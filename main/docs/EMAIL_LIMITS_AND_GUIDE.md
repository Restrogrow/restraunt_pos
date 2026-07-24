# Email System Limits and Configuration Guide

## ✅ Easy Email Change

**Yes, you can easily change the email address anytime!**

### How to Change Email:

1. Open `config/email_config.php`
2. Update these lines:
   ```php
   define('SMTP_USERNAME', 'your-new-email@gmail.com');
   define('SMTP_FROM_EMAIL', 'your-new-email@gmail.com');
   define('SMTP_PASSWORD', 'new-app-password'); // Get new app password for new email
   ```
3. Save the file - that's it!

**Note:** You'll need to generate a new Gmail App Password for the new email address.

---

## 📊 Gmail SMTP Limits

### Free Gmail Account Limits:

1. **Daily Sending Limit:**
   - **500 emails per day** (24-hour period)
   - Resets at midnight Pacific Time
   - If exceeded, emails will be blocked until limit resets

2. **Per-Email Limits:**
   - **100 recipients per email** (To, CC, BCC combined)
   - **25MB attachment size limit** per email
   - **25MB total message size** (including attachments)

3. **Rate Limiting:**
   - **~20 emails per minute** (to prevent spam)
   - Sending too fast may trigger temporary blocks

4. **Account Requirements:**
   - Must have **2-Step Verification enabled**
   - Must use **App Password** (not regular password)
   - Account must be in good standing (not suspended)

---

## ⚠️ Important Limitations

### 1. **Daily Email Limit (500/day)**
- **Impact:** If you send more than 500 emails in 24 hours, new emails will fail
- **Solution:** 
  - Monitor email count
  - Use multiple Gmail accounts if needed
  - Consider paid email service for higher limits

### 2. **Rate Limiting (20/minute)**
- **Impact:** Sending emails too quickly may cause temporary blocks
- **Solution:** 
  - Add delays between bulk emails
  - Queue emails if sending to many users

### 3. **App Password Required**
- **Impact:** Regular Gmail password won't work
- **Solution:** Always use App Password from Google Account settings

### 4. **2-Step Verification Required**
- **Impact:** Can't generate App Password without 2-Step Verification
- **Solution:** Enable 2-Step Verification in Google Account

### 5. **Network/Firewall**
- **Impact:** Port 587 (TLS) must be open
- **Solution:** 
  - Check firewall settings
  - Some networks block SMTP ports
  - May need to use different port (465 for SSL) or VPN

---

## 🔄 Changing Email Address

### Step-by-Step:

1. **Get New Gmail App Password:**
   - Go to Google Account → Security
   - Generate new App Password for new email
   - Copy the 16-character password

2. **Update Config File:**
   ```php
   // In config/email_config.php
   define('SMTP_USERNAME', 'newemail@gmail.com');
   define('SMTP_PASSWORD', 'new-app-password-here');
   define('SMTP_FROM_EMAIL', 'newemail@gmail.com');
   ```

3. **Test:**
   - Go to `admin/test_email.php`
   - Send test email to verify it works

---

## 📧 Alternative Email Services

If you need higher limits, consider:

### 1. **Gmail Workspace (Paid)**
- **Limit:** 2,000 emails/day
- **Cost:** ~$6/month per user
- **Best for:** Business use

### 2. **SendGrid**
- **Limit:** 100 emails/day (free), 40,000+ (paid)
- **Cost:** Free tier available, paid plans start at $15/month
- **Best for:** High volume sending

### 3. **Mailgun**
- **Limit:** 5,000 emails/month (free), unlimited (paid)
- **Cost:** Free tier, paid plans start at $35/month
- **Best for:** Transactional emails

### 4. **Amazon SES**
- **Limit:** 62,000 emails/month (free), then pay per email
- **Cost:** $0.10 per 1,000 emails after free tier
- **Best for:** Very high volume

---

## 🎯 Current Configuration

**Your Current Setup:**
- **Email:** sujaysarraf55@gmail.com
- **Service:** Gmail SMTP
- **Daily Limit:** 500 emails
- **Rate Limit:** ~20 emails/minute

**What This Means:**
- ✅ Perfect for password resets (low volume)
- ✅ Good for notifications to restaurant owners
- ⚠️ May hit limit if sending to many customers daily
- ⚠️ Need to monitor if sending bulk emails

---

## 💡 Best Practices

1. **Monitor Email Count:**
   - Track how many emails you send per day
   - Set up alerts if approaching limit

2. **Handle Failures Gracefully:**
   - Always show reset link in UI (already implemented)
   - Log email failures
   - Retry failed emails later

3. **Use Queues for Bulk:**
   - Don't send 100+ emails at once
   - Add delays between sends
   - Use background jobs if possible

4. **Keep App Password Secure:**
   - Never commit to version control
   - Store in environment variables if possible
   - Rotate passwords periodically

5. **Test Regularly:**
   - Test email sending after any changes
   - Verify emails are received
   - Check spam folders

---

## 🔧 Configuration Options

### Change SMTP Provider:

If you want to use a different email service, update `config/email_config.php`:

**For Gmail (Current):**
```php
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

**For Outlook/Hotmail:**
```php
define('SMTP_HOST', 'smtp-mail.outlook.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

**For Yahoo:**
```php
define('SMTP_HOST', 'smtp.mail.yahoo.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
```

**For SendGrid:**
```php
define('SMTP_HOST', 'smtp.sendgrid.net');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
define('SMTP_USERNAME', 'apikey'); // Always 'apikey' for SendGrid
define('SMTP_PASSWORD', 'your-sendgrid-api-key');
```

---

## 📝 Summary

**Can you change email easily?** ✅ **YES**
- Just edit `config/email_config.php`
- Update email and app password
- No code changes needed

**Limits to be aware of:**
- ⚠️ **500 emails/day** (Gmail free account)
- ⚠️ **~20 emails/minute** (rate limit)
- ⚠️ **25MB per email** (attachment size)
- ⚠️ **100 recipients per email**

**For your use case (password resets):**
- ✅ **Perfect** - Password resets are low volume
- ✅ **No issues expected** - Rarely need more than a few emails/day
- ✅ **Easy to change** - Just update config file

---

*Last Updated: January 2025*

