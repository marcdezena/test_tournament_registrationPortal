<?php
/**
 * Database Connection Diagnostic Tool
 * Tests database connectivity and schema completeness
 */

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Diagnostic</title>
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1a1a1a;
            color: #00ff00;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .header { background: #2a2a2a; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
        .test { 
            background: #2a2a2a; 
            padding: 15px; 
            margin-bottom: 10px; 
            border-left: 4px solid #00ff00;
            border-radius: 3px;
        }
        .test.fail {
            border-left-color: #ff0000;
            color: #ff6b6b;
        }
        .test.pass {
            border-left-color: #00ff00;
            color: #00ff00;
        }
        .test.warning {
            border-left-color: #ffaa00;
            color: #ffaa00;
        }
        code { background: #1a1a1a; padding: 2px 6px; border-radius: 3px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border: 1px solid #444; }
        th { background: #333; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Database Diagnostic Tool</h1>
            <p>Testing database connectivity and schema...</p>
        </div>

        <?php
        // Test 1: Check if config file exists
        $config_file = __DIR__ . '/config.php';
        echo '<div class="test ' . (file_exists($config_file) ? 'pass' : 'fail') . '">';
        echo file_exists($config_file) ? '✓' : '✗';
        echo ' Config file exists: <code>' . $config_file . '</code>';
        echo '</div>';

        if (!file_exists($config_file)) {
            echo '<div class="test fail">✗ Cannot proceed without config.php</div>';
            die('</div></body></html>');
        }

        require_once $config_file;

        // Test 2: Check connection
        echo '<div class="test ' . (isset($pdo) ? 'pass' : 'fail') . '">';
        echo (isset($pdo) ? '✓' : '✗');
        echo ' PDO Connection: ' . (isset($pdo) ? 'Connected to ' . DB_NAME : 'Connection failed');
        echo '</div>';

        if (!isset($pdo)) {
            echo '<div class="test fail">✗ Cannot connect to database. Check credentials in config.php</div>';
            echo '</div></body></html>';
            die();
        }

        // Test 3: Check database exists
        try {
            $stmt = $pdo->query("SELECT 1");
            echo '<div class="test pass">✓ Database is accessible</div>';
        } catch (Exception $e) {
            echo '<div class="test fail">✗ Database error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        // Test 4: Check required tables
        $required_tables = ['users', 'teams', 'tournaments', 'registrations', 'matches', 'brackets'];
        $existing_tables = [];
        
        try {
            $stmt = $pdo->query("SHOW TABLES");
            $existing_tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            echo '<div class="test fail">✗ Cannot query tables: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }

        echo '<div style="margin-top: 20px;"><h3>📋 Table Status</h3>';
        foreach ($required_tables as $table) {
            $exists = in_array($table, $existing_tables);
            echo '<div class="test ' . ($exists ? 'pass' : 'fail') . '">';
            echo ($exists ? '✓' : '✗') . ' <code>' . $table . '</code> table';
            
            if ($exists) {
                try {
                    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM $table");
                    $count = $stmt->fetch()['cnt'];
                    echo ' (' . $count . ' rows)';
                } catch (Exception $e) {
                    echo ' [Error: ' . htmlspecialchars($e->getMessage()) . ']';
                }
            }
            echo '</div>';
        }
        echo '</div>';

        // Test 5: Check critical columns
        echo '<div style="margin-top: 20px;"><h3>🔍 Column Verification</h3>';
        
        $column_checks = [
            'tournaments' => ['id', 'name', 'competition_type', 'status'],
            'registrations' => ['id', 'tournament_id', 'status', 'user_id', 'team_id'],
            'matches' => ['id', 'tournament_id', 'round', 'participant1_id', 'status'],
            'users' => ['id', 'username', 'email', 'password'],
            'teams' => ['id', 'name', 'captain_id']
        ];

        foreach ($column_checks as $table => $columns) {
            if (!in_array($table, $existing_tables)) {
                echo '<div class="test fail">✗ <code>' . $table . '</code> table does not exist</div>';
                continue;
            }

            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM $table");
                $existing_columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                foreach ($columns as $col) {
                    $has_col = in_array($col, $existing_columns);
                    echo '<div class="test ' . ($has_col ? 'pass' : 'fail') . '">';
                    echo ($has_col ? '✓' : '✗') . ' <code>' . $table . '.' . $col . '</code>';
                    echo '</div>';
                }
            } catch (Exception $e) {
                echo '<div class="test fail">✗ Cannot check columns in <code>' . $table . '</code>: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
        echo '</div>';

        // Test 6: Summary
        echo '<div style="margin-top: 20px; padding: 15px; background: #333; border-radius: 5px;">';
        echo '<h3>📊 Summary</h3>';
        $missing = array_diff($required_tables, $existing_tables);
        if (empty($missing)) {
            echo '<div class="test pass">✓ All required tables exist</div>';
        } else {
            echo '<div class="test fail">✗ Missing tables: ' . implode(', ', $missing) . '</div>';
            echo '<p style="margin-top: 10px;"><strong>Fix:</strong> Run the migration files in <code>sql/migrations/</code></p>';
        }
        echo '</div>';

        // Test 7: Connection info
        echo '<div style="margin-top: 20px; padding: 15px; background: #333; border-radius: 5px;">';
        echo '<h3>📌 Connection Details</h3>';
        echo '<table>';
        echo '<tr><th>Setting</th><th>Value</th></tr>';
        echo '<tr><td>Host</td><td><code>' . DB_HOST . '</code></td></tr>';
        echo '<tr><td>Database</td><td><code>' . DB_NAME . '</code></td></tr>';
        echo '<tr><td>User</td><td><code>' . DB_USER . '</code></td></tr>';
        echo '</table>';
        echo '</div>';
        ?>
    </div>
</body>
</html>
