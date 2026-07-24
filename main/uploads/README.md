# Uploads Directory

This directory stores uploaded images for:
- Menu item images
- Restaurant logos
- Website banners
- QR codes

## Directory Structure

```
uploads/
├── banners/        # Website banner images
├── logos/          # Restaurant logos
├── menu_items/     # Menu item images
├── qr_codes/       # Generated QR codes
└── .htaccess       # Apache configuration
```

## Permissions on Hostinger

Ensure this directory has proper permissions:
```bash
chmod 755 uploads/
chmod 755 uploads/banners/
```

## Access

Images are served via `/api/image.php` endpoint for security and proper MIME type handling.

