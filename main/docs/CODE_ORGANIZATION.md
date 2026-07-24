# Code Organization

This document describes the folder structure and organization of the Restaurant POS system.

## Folder Structure

```
restraunt_pos/
├── admin/              # Admin authentication and login
├── api/                # API endpoints (GET requests, webhooks, etc.)
├── assets/             # Static assets (CSS, JS, images)
│   ├── css/
│   ├── js/
│   └── images/
├── config/             # Configuration files (database, migrations)
├── controllers/        # Business logic and operations (POST requests)
├── database/           # SQL schema files
├── docs/               # Documentation files
├── frontend/           # Frontend files (legacy)
├── migrations/         # Database migration files
├── public/             # Public-facing files (legacy/old root files)
├── superadmin/         # Superadmin panel
├── uploads/            # User-uploaded files (images, logos, banners)
├── website/            # Customer-facing website
└── views/              # Dashboard and view files
```

## File Categories

### API Endpoints (`api/`)
All API endpoints that handle GET requests, webhooks, and public-facing APIs:
- `get_*.php` - Data retrieval endpoints
- `check_*.php` - Status checking endpoints
- `create_*.php` - Public creation endpoints
- `process_*.php` - Public processing endpoints
- `phonepe_*.php` - Payment gateway callbacks
- `update_order_status.php` - Order status updates
- `image.php` - Image serving endpoint

### Controllers (`controllers/`)
Business logic and operations that handle POST requests:
- `*_operations.php` - CRUD operations for different entities
- `staff_logout.php` - Logout functionality

### Views (`views/`)
Dashboard and view files:
- `dashboard.php` - Main admin dashboard
- `chef_dashboard.php` - Chef dashboard
- `waiter_dashboard.php` - Waiter dashboard
- `manager_dashboard.php` - Manager dashboard

### Configuration (`config/`)
Configuration and migration files:
- `db_connection.php` - Database connection (if exists)
- `db_migration.php` - Database migration system
- `run_migrations.php` - Migration runner

### Documentation (`docs/`)
All documentation files:
- `*.md` - Markdown documentation
- `*.html` - HTML documentation

### Database (`database/`)
SQL schema files:
- `database/database_schema.sql` - Main database schema
- `database/database_schema_clean.sql` - Clean schema

## Path References

### From Views
- API endpoints: `../api/`
- Controllers: `../controllers/`
- Assets: `../assets/`
- Admin: `../admin/`
- Website: `../website/`
- Uploads: `../uploads/`

### From API/Controllers
- Database config: `../config/db_connection.php` or `../db_connection.php`
- Uploads directory: `__DIR__ . '/../uploads/'`
- Return upload paths: `'uploads/'` (relative to root)

### From Assets/JS
- API endpoints: `../api/`
- Controllers: `../controllers/`
- Images: `../api/image.php?path=`

## Important Notes

1. **Database Connection**: Files check for `config/db_connection.php` first, then fall back to root `db_connection.php`
2. **Upload Paths**: Upload directory paths use `__DIR__ . '/../uploads/'` but return values use `'uploads/'` (root-relative)
3. **Image Serving**: Images are served through `api/image.php` endpoint
4. **Root Index**: The root `index.php` redirects to `views/dashboard.php` for logged-in users

## Migration Notes

When accessing files after this reorganization:
- Update any hardcoded paths to use the new folder structure
- API calls from JavaScript should use `../api/` or `../controllers/` paths
- Dashboard files are now in `views/` folder
- All documentation is in `docs/` folder

