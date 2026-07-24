# Hostinger Deployment Guide

## ✅ Deployment Status

Your repository is connected to Hostinger via Git. The deployment should work automatically.

## 📋 Pre-Deployment Checklist

1. ✅ **Database Imported** - Import `database/full_database_dump.sql` via phpMyAdmin
2. ✅ **Database Connection** - Auto-detects Hostinger environment
3. ✅ **.htaccess** - Created for proper routing and security

## 🚀 Deployment Steps

### 1. Import Database
- Go to: https://auth-db1336.hstgr.io
- Select database: `u509616587_restrogrow`
- Import: `database/full_database_dump.sql`

### 2. Git Deployment (Automatic)
- Hostinger will auto-deploy when you push to GitHub
- Repository: `https://github.com/sujaysarraf-dev/restraunt_pos`

### 3. Verify Deployment
- Check your domain after deployment
- Login at: `your-domain.com/admin/login.php`
- Test database connection

## 🔧 Configuration

### Database Connection
- **On Hostinger Server**: Uses `localhost` automatically
- **From Local Machine**: Uses `auth-db1336.hstgr.io` (if remote access enabled)

### File Structure
```
/
├── admin/          # Admin panel
├── api/            # API endpoints
├── views/          # Dashboards
├── website/        # Public website
├── config/         # Configuration files
├── controllers/    # Business logic
└── index.php       # Main entry point
```

## 🔒 Security Notes

- `.htaccess` protects sensitive files
- Database credentials in `db_connection.php` (not in .gitignore for deployment)
- Error display enabled for debugging (disable in production)

## 📝 Post-Deployment

1. Test login functionality
2. Verify database connection
3. Check API endpoints
4. Test file uploads (if applicable)
5. Disable error display in production

## 🐛 Troubleshooting

### Database Connection Failed
- Check if database is imported
- Verify credentials in `db_connection.php`
- Check Hostinger MySQL is running

### 404 Errors
- Check `.htaccess` is uploaded
- Verify mod_rewrite is enabled
- Check file permissions

### Permission Errors
- Set folder permissions: 755
- Set file permissions: 644
- Check uploads folder: 755

