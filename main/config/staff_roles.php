<?php
/**
 * Staff roles added after the first ones (Admin, Manager, Waiter, Chef):
 *   Delivery  delivery rider (config/rider_engine.php)
 *   Cashier   the billing counter: POS, payments, orders incl. accepting online orders, customers, tables
 * The live database's staff.role is an ENUM, so the new values are added on first use. Not inside a transaction.
 */
if (!function_exists('staffRolesEnsure')) {
    function staffRolesEnsure(PDO $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $type = (string)$conn->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'role'")->fetchColumn();
            if (stripos($type, 'enum(') !== 0) return;
            $vals = substr($type, 5, -1);
            $add = '';
            foreach (['Delivery', 'Cashier'] as $r) if (stripos($vals, "'$r'") === false) $add .= ",'$r'";
            if ($add !== '') $conn->exec("ALTER TABLE staff MODIFY COLUMN role ENUM($vals$add) DEFAULT 'Waiter'");
        } catch (PDOException $e) {
            error_log('staffRolesEnsure: ' . $e->getMessage());
        }
    }

    /** What the role is called on screen. */
    function staffRoleLabel(string $role): string
    {
        return ['Delivery' => 'Delivery rider'][$role] ?? $role;
    }
}
