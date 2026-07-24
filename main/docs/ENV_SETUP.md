# Environment Variables Setup

This document explains how environment variables are configured in the application.

## Overview

All sensitive credentials (database, email, payment gateway) have been moved to environment variables stored in `.env` files. This ensures credentials are not committed to version control.

## Files Created

1. **`.env`** - Contains actual credentials (gitignored, not committed)
2. **`.env.example`** - Template file with placeholder values (committed to git)
3. **`config/env_loader.php`** - Helper functions to load environment variables

## Environment Variables

### Database Configuration
- `DB_HOST_REMOTE` - Remote database host (when not on Hostinger server)
- `DB_HOST_LOCAL` - Local database host (when on Hostinger server or localhost)
- `DB_NAME` - Database name
- `DB_USER` - Database username
- `DB_PASS` - Database password

### Email/SMTP Configuration
- `SMTP_ENABLED` - Enable/disable SMTP (true/false)
- `SMTP_HOST` - SMTP server hostname
- `SMTP_PORT` - SMTP server port
- `SMTP_SECURE` - Security protocol (tls/ssl)
- `SMTP_USERNAME` - SMTP username
- `SMTP_PASSWORD` - SMTP password
- `SMTP_FROM_EMAIL` - From email address
- `SMTP_FROM_NAME` - From name

### PhonePe Payment Gateway Configuration
- `PHONEPE_ENVIRONMENT` - Environment (test/production)
- `PHONEPE_MERCHANT_ID` - PhonePe merchant ID
- `PHONEPE_SALT_KEY` - PhonePe salt key
- `PHONEPE_SALT_INDEX` - PhonePe salt index
- `PHONEPE_BASE_URL_TEST` - Test API base URL
- `PHONEPE_BASE_URL_PRODUCTION` - Production API base URL
- `PHONEPE_CALLBACK_URL` - Callback URL
- `PHONEPE_REDIRECT_URL` - Redirect URL
- `PHONEPE_DEMO_MODE` - Enable demo mode (true/false)

## Setup Instructions

1. **Copy the example file:**
   ```bash
   cp .env.example .env
   ```

2. **Edit `.env` file** with your actual credentials

3. **Verify `.env` is in `.gitignore`** (it should be automatically ignored)

## How It Works

1. The `env_loader.php` file is automatically loaded by configuration files
2. It reads the `.env` file and loads variables into `$_ENV` and `$_SERVER`
3. The `env()` helper function retrieves values with optional defaults
4. Configuration files use `env('KEY', 'default')` to get values

## Files Updated

The following files now use environment variables:

- `db_connection.php` - Database credentials
- `config/email_config.php` - SMTP credentials
- `api/phonepe_payment.php` - PhonePe credentials
- `api/phonepe_callback.php` - PhonePe credentials

## Security Notes

- ✅ `.env` file is gitignored and will not be committed
- ✅ `.env.example` is committed as a template
- ✅ Fallback values in code are only used if `.env` file is missing
- ✅ System environment variables take precedence over `.env` file values
- ⚠️ Never commit `.env` file with real credentials
- ⚠️ Keep `.env` file secure and restrict file permissions on production servers

## Production Deployment

For production servers, you can either:
1. Use the `.env` file (ensure proper file permissions)
2. Set system environment variables (recommended for some hosting providers)
3. Use a combination of both (system env vars take precedence)

## Troubleshooting

If the application can't connect to the database or send emails:
1. Verify `.env` file exists in the `main/` directory
2. Check that all required variables are set
3. Verify file permissions on `.env` file
4. Check error logs for specific error messages

