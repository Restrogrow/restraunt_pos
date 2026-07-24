<?php
/**
 * Environment Variables Loader
 * Loads variables from .env file into $_ENV and $_SERVER
 * 
 * This file should be included early in the application lifecycle,
 * before any other config files that need environment variables.
 */

// Prevent multiple includes
if (defined('ENV_LOADER_LOADED')) {
    return;
}
define('ENV_LOADER_LOADED', true);

/**
 * Load environment variables from .env file
 * 
 * @param string $envFile Path to .env file (relative to this file's directory)
 * @return void
 */
function loadEnvFile($envFile = null, $override = false) {
    // Default to .env in the main directory (parent of config directory)
    if ($envFile === null) {
        $envFile = __DIR__ . '/../.env';
    }
    
    // If file doesn't exist, return silently (for production environments that use system env vars)
    if (!file_exists($envFile)) {
        return;
    }
    
    // Read .env file
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    if ($lines === false) {
        return;
    }
    
    foreach ($lines as $line) {
        // Skip comments
        $line = trim($line);
        if (empty($line) || $line[0] === '#') {
            continue;
        }
        
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                $value = substr($value, 1, -1);
            }
            
            // Set environment variable (override existing if requested)
            if ($override || !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
            }
            if ($override || !isset($_SERVER[$key])) {
                $_SERVER[$key] = $value;
            }
            
            // Also set as constant if not already defined (for backward compatibility)
            if (!defined($key)) {
                define($key, $value);
            }
        }
    }
}

/**
 * Get environment variable with optional default value
 * 
 * @param string $key Environment variable key
 * @param mixed $default Default value if key doesn't exist
 * @return mixed Environment variable value or default
 */
function env($key, $default = null) {
    // Check $_ENV first, then $_SERVER, then getenv()
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    if (isset($_SERVER[$key])) {
        return $_SERVER[$key];
    }
    $value = getenv($key);
    if ($value !== false) {
        return $value;
    }
    return $default;
}

// Auto-load .env file when this file is included
loadEnvFile();

// Load .env.local overrides (for local development)
$localEnv = __DIR__ . '/../.env.local';
if (file_exists($localEnv)) {
    loadEnvFile($localEnv, true);
}

