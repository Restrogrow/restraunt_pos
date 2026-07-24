<?php
/**
 * Database Configuration for Website
 * This file provides database connection for the website API
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Use the main db_connection.php as the single source of truth
require_once __DIR__ . '/../db_connection.php';
?>
