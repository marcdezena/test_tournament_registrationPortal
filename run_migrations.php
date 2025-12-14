<?php
/**
 * Database Migration Runner
 * 
 * This script applies all pending database migrations in the correct order.
 * It should be run after deploying new code that includes database changes.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';

// Only allow admins to run migrations
if (!isAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    die('Access denied. Only administrators can run migrations.');
}

// Create migrations table if it doesn't exist
$pdo->exec("CREATE TABLE IF NOT EXISTS migrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL,
    batch INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_migration (migration)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Get all migration files
$migrationsPath = __DIR__ . '/sql/migrations';
$migrationFiles = glob("$migrationsPath/*.sql");

if (empty($migrationFiles)) {
    die("No migration files found in $migrationsPath");
}

// Sort migrations by name to ensure correct order
sort($migrationFiles);

// Get already run migrations
$stmt = $pdo->query("SELECT migration FROM migrations");
$runMigrations = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Get next batch number
$batch = (int)$pdo->query("SELECT COALESCE(MAX(batch), 0) + 1 FROM migrations")->fetchColumn();

$pdo->beginTransaction();

try {
    $applied = 0;
    
    foreach ($migrationFiles as $file) {
        $migrationName = basename($file);
        
        // Skip already run migrations
        if (in_array($migrationName, $runMigrations)) {
            continue;
        }
        
        // Read and execute migration
        $sql = file_get_contents($file);
        
        // Split into individual statements
        $statements = array_filter(
            array_map('trim', 
                preg_split("/;\s*(?=([^'\"]*['\"][^'\"]*['\"])*[^'\"]*$)/", $sql)
            )
        );
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                $pdo->exec($statement);
            }
        }
        
        // Record migration
        $stmt = $pdo->prepare("INSERT INTO migrations (migration, batch) VALUES (?, ?)");
        $stmt->execute([$migrationName, $batch]);
        
        $applied++;
        echo "Applied migration: $migrationName\n";
    }
    
    $pdo->commit();
    
    if ($applied > 0) {
        echo "\nSuccessfully applied $applied migration(s).\n";
    } else {
        echo "\nNo new migrations to apply.\n";
    }
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\nMigration failed: " . $e->getMessage() . "\n";
    exit(1);
}
