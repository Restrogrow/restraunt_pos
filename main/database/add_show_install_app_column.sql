-- Add show_install_app column to users table
-- Superadmin can toggle this per restaurant to show/hide the "Install App" option
ALTER TABLE users ADD COLUMN IF NOT EXISTS show_install_app TINYINT(1) DEFAULT 0;
