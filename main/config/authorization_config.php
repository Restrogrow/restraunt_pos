<?php
/**
 * Authorization Configuration with Role-Based Access Control (RBAC)
 * 
 * This file provides authorization functions for:
 * - Role-based access control (Admin, Manager, Waiter, Chef, Staff)
 * - Consistent authorization checks across all endpoints
 * - Permission-based access control
 */

// Prevent multiple includes
if (defined('AUTHORIZATION_CONFIG_LOADED')) {
    return;
}
define('AUTHORIZATION_CONFIG_LOADED', true);

// Include session configuration for session validation
require_once __DIR__ . '/session_config.php';

/**
 * User Roles
 */
define('ROLE_ADMIN', 'Admin');
define('ROLE_MANAGER', 'Manager');
define('ROLE_WAITER', 'Waiter');
define('ROLE_CHEF', 'Chef');
define('ROLE_STAFF', 'Staff');

/**
 * Permission definitions
 */
define('PERMISSION_VIEW_DASHBOARD', 'view_dashboard');
define('PERMISSION_MANAGE_ORDERS', 'manage_orders');
define('PERMISSION_MANAGE_MENU', 'manage_menu');
define('PERMISSION_MANAGE_STAFF', 'manage_staff');
define('PERMISSION_MANAGE_TABLES', 'manage_tables');
define('PERMISSION_MANAGE_CUSTOMERS', 'manage_customers');
define('PERMISSION_MANAGE_RESERVATIONS', 'manage_reservations');
define('PERMISSION_VIEW_REPORTS', 'view_reports');
define('PERMISSION_MANAGE_PAYMENTS', 'manage_payments');
define('PERMISSION_MANAGE_SETTINGS', 'manage_settings');
define('PERMISSION_VIEW_KOT', 'view_kot');
define('PERMISSION_MANAGE_AREAS', 'manage_areas');
define('PERMISSION_MANAGE_COUPONS', 'manage_coupons');

/**
 * Role to permissions mapping
 */
function getRolePermissions($role) {
    $permissions = [
        ROLE_ADMIN => [
            PERMISSION_VIEW_DASHBOARD,
            PERMISSION_MANAGE_ORDERS,
            PERMISSION_MANAGE_MENU,
            PERMISSION_MANAGE_STAFF,
            PERMISSION_MANAGE_TABLES,
            PERMISSION_MANAGE_CUSTOMERS,
            PERMISSION_MANAGE_RESERVATIONS,
            PERMISSION_VIEW_REPORTS,
            PERMISSION_MANAGE_PAYMENTS,
            PERMISSION_MANAGE_SETTINGS,
            PERMISSION_VIEW_KOT,
            PERMISSION_MANAGE_AREAS,
            PERMISSION_MANAGE_COUPONS,
        ],
        ROLE_MANAGER => [
            PERMISSION_VIEW_DASHBOARD,
            PERMISSION_MANAGE_ORDERS,
            PERMISSION_MANAGE_MENU,
            PERMISSION_MANAGE_TABLES,
            PERMISSION_MANAGE_CUSTOMERS,
            PERMISSION_MANAGE_RESERVATIONS,
            PERMISSION_VIEW_REPORTS,
            PERMISSION_MANAGE_PAYMENTS,
            PERMISSION_VIEW_KOT,
            PERMISSION_MANAGE_AREAS,
        ],
        ROLE_WAITER => [
            PERMISSION_VIEW_DASHBOARD,
            PERMISSION_MANAGE_ORDERS,
            PERMISSION_MANAGE_TABLES,
            PERMISSION_MANAGE_CUSTOMERS,
            PERMISSION_MANAGE_RESERVATIONS,
            PERMISSION_VIEW_KOT,
        ],
        ROLE_CHEF => [
            PERMISSION_VIEW_DASHBOARD,
            PERMISSION_VIEW_KOT,
            PERMISSION_MANAGE_ORDERS, // Can update order status
        ],
        ROLE_STAFF => [
            PERMISSION_VIEW_DASHBOARD,
            PERMISSION_VIEW_KOT,
        ],
    ];
    
    return $permissions[$role] ?? [];
}

/**
 * Check if user has a specific permission
 * 
 * @param string $permission Permission to check
 * @return bool True if user has permission
 */
function hasPermission($permission) {
    // Ensure session is valid
    if (!isSessionValid()) {
        return false;
    }
    
    // Get user role
    $role = getUserRole();
    
    if (!$role) {
        return false;
    }
    
    // Admin has all permissions
    if ($role === ROLE_ADMIN) {
        return true;
    }
    
    // Check if role has the permission
    $permissions = getRolePermissions($role);
    return in_array($permission, $permissions);
}

/**
 * Get current user's role
 * 
 * @return string|null User role or null if not logged in
 */
function getUserRole() {
    if (!isSessionValid()) {
        return null;
    }
    
    // Check if admin
    if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'admin') {
        return ROLE_ADMIN;
    }
    
    // Branch admin inherits admin role
    if (isset($_SESSION['branch_admin_id'])) {
        return ROLE_ADMIN;
    }
    
    // Check if staff
    if (isset($_SESSION['staff_id']) && isset($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    
    // Fallback to role in session
    if (isset($_SESSION['role'])) {
        return $_SESSION['role'];
    }
    
    return null;
}

/**
 * Check if user is admin
 * 
 * @return bool True if user is admin
 */
function isAdmin() {
    if (!isSessionValid()) {
        return false;
    }
    
    return isset($_SESSION['user_id']) && 
           isset($_SESSION['user_type']) && 
           $_SESSION['user_type'] === 'admin';
}

/**
 * Check if user is staff
 * 
 * @return bool True if user is staff
 */
function isStaff() {
    if (!isSessionValid()) {
        return false;
    }
    
    return isset($_SESSION['staff_id']) || 
           (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'staff');
}

/**
 * Check if user is logged in (admin or staff)
 * 
 * @return bool True if user is logged in
 */
function isLoggedIn() {
    if (!isSessionValid()) {
        return false;
    }
    
    return (isset($_SESSION['user_id']) || isset($_SESSION['staff_id']) || isset($_SESSION['branch_admin_id'])) &&
           isset($_SESSION['restaurant_id']);
}

/**
 * Require user to be logged in
 * Throws exception or returns 401 if not logged in
 * 
 * @param bool $returnJson If true, returns JSON response; if false, throws exception
 * @return void
 * @throws Exception If not logged in and $returnJson is false
 */
function requireLogin($returnJson = true) {
    if (!isLoggedIn()) {
        if ($returnJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Session expired. Please login again.'
            ]);
            exit();
        } else {
            throw new Exception('You must be logged in to access this resource');
        }
    }
}

/**
 * Require specific permission
 * Throws exception or returns 403 if permission denied
 * 
 * @param string $permission Permission required
 * @param bool $returnJson If true, returns JSON response; if false, throws exception
 * @return void
 * @throws Exception If permission denied and $returnJson is false
 */
function requirePermission($permission, $returnJson = true) {
    requireLogin($returnJson);
    
    if (!hasPermission($permission)) {
        if ($returnJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. You do not have permission to perform this action.'
            ]);
            exit();
        } else {
            throw new Exception('Access denied. You do not have permission to perform this action.');
        }
    }
}

/**
 * Require admin access
 * Throws exception or returns 403 if not admin
 * 
 * @param bool $returnJson If true, returns JSON response; if false, throws exception
 * @return void
 * @throws Exception If not admin and $returnJson is false
 */
function requireAdmin($returnJson = true) {
    requireLogin($returnJson);
    
    if (!isAdmin()) {
        if ($returnJson) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Admin access required.'
            ]);
            exit();
        } else {
            throw new Exception('Access denied. Admin access required.');
        }
    }
}



/**
 * Get current user's restaurant ID
 * 
 * @return string|null Restaurant ID or null if not logged in
 */
function getRestaurantId() {
    if (!isLoggedIn()) {
        return null;
    }
    
    return $_SESSION['restaurant_id'] ?? null;
}



/**
 * Check if user belongs to a specific restaurant
 * 
 * @param string $restaurantId Restaurant ID to check
 * @return bool True if user belongs to the restaurant
 */
function belongsToRestaurant($restaurantId) {
    if (!isLoggedIn()) {
        return false;
    }
    
    return getRestaurantId() === $restaurantId;
}


?>

