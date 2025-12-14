<?php
/**
 * Database Schema Setup Tool
 * Creates all required tables if they don't exist
 */

require_once 'config.php';

// Security check - only allow in development or for admins
if (!defined('ALLOW_SETUP') || ALLOW_SETUP !== true) {
    if (!isset($_SESSION['user_id']) || !isAdmin()) {
        die('Access denied. Setup is restricted.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Setup</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            
            .container { 
                max-width: 800px;
                width: 100%;
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            }
            
            h1 {
                color: #333;
                margin-bottom: 10px;
                font-size: 28px;
            }
            
            .subtitle {
                color: #666;
                margin-bottom: 30px;
                font-size: 14px;
            }
            
            .warning { 
                background: #fff3cd;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
                border-left: 4px solid #ffc107;
                color: #856404;
            }
            
            .warning strong {
                display: block;
                margin-bottom: 10px;
                font-size: 16px;
            }
            
            .info-box {
                background: #d1ecf1;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
                border-left: 4px solid #17a2b8;
                color: #0c5460;
            }
            
            .info-box h3 {
                margin-bottom: 10px;
                font-size: 16px;
            }
            
            .info-box ul {
                margin-left: 20px;
                line-height: 1.8;
            }
            
            button { 
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 15px 30px;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-size: 16px;
                font-weight: 600;
                transition: transform 0.2s, box-shadow 0.2s;
                width: 100%;
            }
            
            button:hover { 
                transform: translateY(-2px);
                box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            }
            
            button:active {
                transform: translateY(0);
            }
            
            .button-group {
                display: flex;
                gap: 15px;
                margin-top: 20px;
            }
            
            .btn-secondary {
                background: #6c757d;
                flex: 1;
            }
            
            .btn-secondary:hover {
                box-shadow: 0 10px 25px rgba(108, 117, 125, 0.4);
            }
            
            .btn-primary {
                flex: 2;
            }
            
            .icon {
                margin-right: 8px;
            }
            
            .back-link {
                display: inline-block;
                margin-top: 20px;
                color: #667eea;
                text-decoration: none;
                font-size: 14px;
            }
            
            .back-link:hover {
                text-decoration: underline;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔧 Database Setup</h1>
            <p class="subtitle">Initialize or update your tournament management database</p>
            
            <div class="warning">
                <strong>⚠️ Important Warning</strong>
                <p>This action will run all database migrations. If tables already exist, only missing columns or tables will be added. Existing data should be preserved, but it's recommended to backup your database first.</p>
            </div>
            
            <div class="info-box">
                <h3>📋 What this will do:</h3>
                <ul>
                    <li>Create missing database tables</li>
                    <li>Add missing columns to existing tables</li>
                    <li>Apply all pending migrations</li>
                    <li>Set up proper indexes and constraints</li>
                    <li>Initialize default values where needed</li>
                </ul>
            </div>
            
            <form method="POST">
                <div class="button-group">
                    <a href="index.php" class="btn-secondary" style="text-align: center; line-height: 50px; text-decoration: none;">
                        <span class="icon">←</span> Cancel
                    </a>
                    <button type="submit" name="setup" value="1" class="btn-primary">
                        <span class="icon">🚀</span> Run Database Setup
                    </button>
                </div>
            </form>
            
            <a href="index.php" class="back-link">← Back to Home</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle setup
if (isset($_POST['setup'])) {
    $migrations = [
        'sql/migrations/001_initial_schema.sql',
        'sql/migrations/002_add_competition_type.sql',
        'sql/migrations/003_add_brackets_table.sql',
        'sql/migrations/004_add_registration_status.sql',
        'sql/migrations/005_add_match_results.sql',
        'sql/migrations/006_optimize_bracket_tables.sql',
        'sql/migrations/007_fix_registration_columns.sql',
        'sql/migrations/008_fix_bracket_tables.sql',
        'sql/migrations/009_add_bracket_management.sql',
        'sql/migrations/010_add_leave_requests.sql',
        'sql/migrations/fix_all_tables.sql'
    ];

    $results = [];
    $errors = [];
    $success_count = 0;
    $skip_count = 0;
    $error_count = 0;

    foreach ($migrations as $migration_file) {
        $filepath = __DIR__ . '/' . $migration_file;
        
        if (!file_exists($filepath)) {
            $skip_count++;
            $results[] = [
                'type' => 'skip',
                'file' => $migration_file,
                'message' => 'File not found'
            ];
            continue;
        }

        $sql_content = file_get_contents($filepath);
        
        // Split by semicolon and execute each statement
        $statements = array_filter(
            array_map('trim', explode(';', $sql_content)),
            function($s) {
                $s = trim($s);
                // Skip empty statements and comments
                return !empty($s) && 
                       substr($s, 0, 2) !== '--' && 
                       substr($s, 0, 2) !== '/*' &&
                       strtoupper(substr($s, 0, 3)) !== 'REM';
            }
        );

        foreach ($statements as $statement) {
            try {
                $pdo->exec($statement);
                $success_count++;
                $results[] = [
                    'type' => 'success',
                    'statement' => substr($statement, 0, 100) . (strlen($statement) > 100 ? '...' : ''),
                    'file' => basename($migration_file)
                ];
            } catch (PDOException $e) {
                $error_msg = $e->getMessage();
                
                // Some errors are expected (e.g., "column already exists")
                $expected_errors = [
                    'duplicate column',
                    'already exists',
                    'duplicate key',
                    'duplicate entry',
                    'table already exists'
                ];
                
                $is_expected = false;
                foreach ($expected_errors as $expected) {
                    if (stripos($error_msg, $expected) !== false) {
                        $is_expected = true;
                        break;
                    }
                }
                
                if ($is_expected) {
                    $skip_count++;
                    $results[] = [
                        'type' => 'skip',
                        'statement' => substr($statement, 0, 100) . '...',
                        'message' => 'Already exists',
                        'file' => basename($migration_file)
                    ];
                } else {
                    $error_count++;
                    $results[] = [
                        'type' => 'error',
                        'statement' => substr($statement, 0, 100) . '...',
                        'message' => $error_msg,
                        'file' => basename($migration_file)
                    ];
                    $errors[] = [
                        'file' => $migration_file,
                        'statement' => $statement,
                        'error' => $error_msg
                    ];
                }
            }
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Database Setup Results</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body { 
                font-family: 'Courier New', monospace;
                background: #1a1a1a;
                color: #00ff00;
                padding: 20px;
                line-height: 1.6;
            }
            
            .container { 
                max-width: 1200px;
                margin: 0 auto;
            }
            
            h1 {
                color: #00ff00;
                margin-bottom: 20px;
                font-size: 24px;
                text-shadow: 0 0 10px rgba(0, 255, 0, 0.5);
            }
            
            .summary {
                background: #2a2a2a;
                padding: 20px;
                border-radius: 8px;
                margin-bottom: 30px;
                border: 1px solid #00ff00;
            }
            
            .summary h2 {
                color: #00ffff;
                margin-bottom: 15px;
                font-size: 18px;
            }
            
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }
            
            .stat-box {
                background: #1a1a1a;
                padding: 15px;
                border-radius: 5px;
                border-left: 4px solid;
            }
            
            .stat-box.success {
                border-left-color: #00ff00;
            }
            
            .stat-box.skip {
                border-left-color: #ffaa00;
            }
            
            .stat-box.error {
                border-left-color: #ff6b6b;
            }
            
            .stat-box .label {
                font-size: 12px;
                opacity: 0.8;
                margin-bottom: 5px;
            }
            
            .stat-box .value {
                font-size: 32px;
                font-weight: bold;
            }
            
            .log { 
                background: #2a2a2a;
                padding: 20px;
                border-radius: 8px;
                max-height: 600px;
                overflow-y: auto;
                border: 1px solid #444;
            }
            
            .log-entry {
                padding: 8px 0;
                border-bottom: 1px solid #333;
            }
            
            .log-entry:last-child {
                border-bottom: none;
            }
            
            .success { color: #00ff00; }
            .error { color: #ff6b6b; }
            .warning { color: #ffaa00; }
            .info { color: #00ffff; }
            
            .icon {
                margin-right: 8px;
                font-weight: bold;
            }
            
            .file-tag {
                display: inline-block;
                background: #333;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 11px;
                margin-left: 10px;
                color: #888;
            }
            
            .action-buttons {
                margin-top: 30px;
                display: flex;
                gap: 15px;
            }
            
            .btn {
                display: inline-block;
                padding: 12px 24px;
                background: #00ff00;
                color: #1a1a1a;
                text-decoration: none;
                border-radius: 5px;
                font-weight: bold;
                transition: all 0.3s;
                text-align: center;
                flex: 1;
            }
            
            .btn:hover {
                background: #00cc00;
                box-shadow: 0 0 20px rgba(0, 255, 0, 0.5);
            }
            
            .btn-secondary {
                background: #444;
                color: #fff;
            }
            
            .btn-secondary:hover {
                background: #555;
                box-shadow: 0 0 20px rgba(255, 255, 255, 0.2);
            }
            
            .error-details {
                background: #3a1a1a;
                padding: 15px;
                border-radius: 5px;
                margin-top: 20px;
                border: 1px solid #ff6b6b;
            }
            
            .error-details h3 {
                color: #ff6b6b;
                margin-bottom: 10px;
            }
            
            .error-item {
                margin-bottom: 15px;
                padding: 10px;
                background: #2a1a1a;
                border-radius: 3px;
            }
            
            ::-webkit-scrollbar {
                width: 10px;
            }
            
            ::-webkit-scrollbar-track {
                background: #1a1a1a;
            }
            
            ::-webkit-scrollbar-thumb {
                background: #00ff00;
                border-radius: 5px;
            }
            
            ::-webkit-scrollbar-thumb:hover {
                background: #00cc00;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🔧 Database Setup Complete</h1>
            
            <div class="summary">
                <h2>📊 Summary</h2>
                <div class="stats">
                    <div class="stat-box success">
                        <div class="label">Successful</div>
                        <div class="value"><?php echo $success_count; ?></div>
                    </div>
                    <div class="stat-box skip">
                        <div class="label">Skipped</div>
                        <div class="value"><?php echo $skip_count; ?></div>
                    </div>
                    <div class="stat-box error">
                        <div class="label">Errors</div>
                        <div class="value"><?php echo $error_count; ?></div>
                    </div>
                </div>
                
                <?php if ($error_count === 0): ?>
                    <p style="margin-top: 20px; color: #00ff00;">
                        <span class="icon">✓</span> All migrations completed successfully!
                    </p>
                <?php else: ?>
                    <p style="margin-top: 20px; color: #ffaa00;">
                        <span class="icon">⚠</span> Setup completed with <?php echo $error_count; ?> error(s). Check details below.
                    </p>
                <?php endif; ?>
            </div>
            
            <div class="log">
                <h2 style="margin-bottom: 15px; color: #00ffff;">📋 Detailed Log</h2>
                <?php foreach ($results as $result): ?>
                    <div class="log-entry">
                        <?php if ($result['type'] === 'success'): ?>
                            <span class="success icon">✓</span>
                            <span class="success"><?php echo htmlspecialchars($result['statement']); ?></span>
                            <span class="file-tag"><?php echo htmlspecialchars($result['file']); ?></span>
                        <?php elseif ($result['type'] === 'skip'): ?>
                            <span class="warning icon">⊘</span>
                            <span class="warning"><?php echo htmlspecialchars($result['statement'] ?? $result['file']); ?></span>
                            <span class="info"> (<?php echo htmlspecialchars($result['message']); ?>)</span>
                            <?php if (isset($result['file'])): ?>
                                <span class="file-tag"><?php echo htmlspecialchars($result['file']); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="error icon">✗</span>
                            <span class="error"><?php echo htmlspecialchars($result['statement']); ?></span>
                            <span class="file-tag"><?php echo htmlspecialchars($result['file']); ?></span>
                            <br>
                            <span class="error" style="margin-left: 25px; font-size: 12px;">
                                Error: <?php echo htmlspecialchars($result['message']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (!empty($errors)): ?>
            <div class="error-details">
                <h3>⚠️ Critical Errors (<?php echo count($errors); ?>)</h3>
                <?php foreach ($errors as $error): ?>
                    <div class="error-item">
                        <strong>File:</strong> <?php echo htmlspecialchars($error['file']); ?><br>
                        <strong>SQL:</strong> <code><?php echo htmlspecialchars(substr($error['statement'], 0, 200)) . '...'; ?></code><br>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error['error']); ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="action-buttons">
                <a href="setup_database.php" class="btn btn-secondary">
                    🔄 Run Again
                </a>
                <a href="index.php" class="btn">
                    🏠 Go to Home
                </a>
            </div>
        </div>
    </body>
    </html>
    <?php
}