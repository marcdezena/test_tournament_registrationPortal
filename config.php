<?php
/**
 * config.php - Application Configuration & Bootstrap
 * 
 * Handles environment setup, database connection, and core functionality initialization.
 * Implements security best practices and error handling.
 */

// ==========  1.  ERROR REPORTING  ==========
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't show errors to users
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// ==========  2.  ENVIRONMENT  ==========
// Define application environment
if (!defined('APP_ENV')) {
    define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
}

// Load .env file if it exists
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        
        // Handle quoted values and comments at the end of lines
        if (preg_match('/^([A-Z0-9_]+)=(.*?)(?:\s*#.*)?$/', $line, $matches)) {
            $key = $matches[1];
            $value = $matches[2];
            
            // Remove surrounding quotes if present
            $value = preg_replace('/^([\'\"])(.*)\1$/', '$2', $value);
            
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value; // Also set in $_SERVER for compatibility
        }
    }
}

// ==========  3.  CONSTANTS  ==========
// Database configuration
define('DB_HOST',      $_ENV['DB_HOST']      ?? 'localhost');
define('DB_NAME',      $_ENV['DB_NAME']      ?? 'tournament_portal');
define('DB_USER',      $_ENV['DB_USER']      ?? 'root');
define('DB_PASS',      $_ENV['DB_PASS']      ?? '');
define('DB_CHARSET',   $_ENV['DB_CHARSET']   ?? 'utf8mb4');

// Application settings
define('SITE_NAME',    $_ENV['SITE_NAME']    ?? 'Tournament Portal');
define('SITE_URL',     $_ENV['SITE_URL']     ?? 'http://localhost/tournament_portal');
define('APP_DEBUG',    filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));
define('ALLOW_SETUP',  filter_var($_ENV['ALLOW_SETUP'] ?? false, FILTER_VALIDATE_BOOLEAN));

// Security settings
define('HASH_COST',    (int)($_ENV['HASH_COST'] ?? 12));
define('TOKEN_EXPIRY', (int)($_ENV['TOKEN_EXPIRY'] ?? 3600)); // 1 hour

// ==========  4.  SESSION CONFIGURATION  ==========
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   isset($_SERVER['HTTPS']) ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_lifetime', '0'); // Until browser closes
ini_set('session.gc_maxlifetime', '14400'); // 4 hours
ini_set('session.name', 'TOURNAMENT_SESSION');

// Custom session save path (optional)
$sessionPath = __DIR__ . '/../sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}
ini_set('session.save_path', $sessionPath);

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        die('CSRF seed failure');
    }
}

// Regenerate session ID periodically to prevent session fixation
if (!isset($_SESSION['last_regeneration'])) {
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
} elseif (time() - $_SESSION['last_regeneration'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['last_regeneration'] = time();
}

// ==========  5.  DATABASE CONNECTION  ==========
$pdo = null;
try {
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        DB_HOST,
        DB_NAME,
        DB_CHARSET
    );
    
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false, // Use connection pooling if needed
    ];
    
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Set timezone to UTC for database operations
    $pdo->exec("SET time_zone = '+00:00'");
    
} catch (PDOException $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
    } else {
        die('A database error occurred. Please try again later.');
    }
}

// ==========  6.  LOAD CORE FILES  ==========
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/security.php';

// ==========  7.  ERROR HANDLER  ==========
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $errorTypes = [
        E_ERROR             => 'Error',
        E_WARNING           => 'Warning',
        E_PARSE             => 'Parse Error',
        E_NOTICE            => 'Notice',
        E_CORE_ERROR        => 'Core Error',
        E_CORE_WARNING      => 'Core Warning',
        E_COMPILE_ERROR     => 'Compile Error',
        E_COMPILE_WARNING   => 'Compile Warning',
        E_USER_ERROR        => 'User Error',
        E_USER_WARNING      => 'User Warning',
        E_USER_NOTICE       => 'User Notice',
        E_STRICT            => 'Runtime Notice',
        E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
        E_DEPRECATED        => 'Deprecated',
        E_USER_DEPRECATED   => 'User Deprecated',
    ];
    
    $type = $errorTypes[$errno] ?? 'Unknown Error';
    $message = sprintf(
        '%s: %s in %s on line %d',
        $type,
        $errstr,
        $errfile,
        $errline
    );
    
    error_log($message);
    
    // Don't execute PHP internal error handler
    return true;
});

// Handle uncaught exceptions
set_exception_handler(function($e) {
    error_log('Uncaught Exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    if (defined('APP_DEBUG') && APP_DEBUG) {
        http_response_code(500);
        die('An error occurred: ' . htmlspecialchars($e->getMessage()));
    } else {
        http_response_code(500);
        die('An error occurred. Please try again later.');
    }
});

// Handle fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        error_log(sprintf(
            'Fatal Error: %s in %s on line %d',
            $error['message'],
            $error['file'],
            $error['line']
        ));
        
        if (defined('APP_DEBUG') && APP_DEBUG) {
            echo "<h1>Fatal Error</h1>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . "</p>";
            echo "<p><strong>Line:</strong> " . htmlspecialchars($error['line']) . "</p>";
        } else {
            echo "<h1>500 Internal Server Error</h1>";
            echo "<p>Sorry, something went wrong. The error has been logged.</p>";
        }
    }
});