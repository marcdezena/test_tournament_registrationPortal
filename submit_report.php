<?php
require_once 'config.php';
requireLogin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// CSRF validation
if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Input validation and sanitization
$category = trim($_POST['category'] ?? 'other');
$description = trim($_POST['description'] ?? '');
$tournament_id = !empty($_POST['tournament_id']) && is_numeric($_POST['tournament_id']) ? intval($_POST['tournament_id']) : null;
$match_id = !empty($_POST['match_id']) && is_numeric($_POST['match_id']) ? intval($_POST['match_id']) : null;

// Validate inputs
$allowed_categories = ['bug', 'abuse', 'cheating', 'technical', 'other'];
if (!in_array($category, $allowed_categories, true)) {
    $category = 'other';
}

if (empty($description)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Description is required']);
    exit;
}

if (strlen($description) > 5000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Description is too long (max 5000 characters)']);
    exit;
}

try {
    // Ensure reports table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS reports (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reporter_id INT NOT NULL,
        tournament_id INT NULL,
        match_id INT NULL,
        category VARCHAR(128) DEFAULT 'other',
        description TEXT,
        status ENUM('pending','reviewed','closed') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reporter (reporter_id),
        INDEX idx_tournament (tournament_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Insert report
    $stmt = $pdo->prepare("INSERT INTO reports (reporter_id, tournament_id, match_id, category, description) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$_SESSION['user_id'], $tournament_id, $match_id, $category, $description]);
    $report_id = $pdo->lastInsertId();

    // Log the action
    logAction('submit_report', [
        'report_id' => $report_id,
        'category' => $category,
        'tournament_id' => $tournament_id,
        'match_id' => $match_id
    ]);

    // Prepare notification payload
    $payload = json_encode([
        'report_id' => (int)$report_id,
        'reporter_id' => (int)$_SESSION['user_id'],
        'tournament_id' => $tournament_id,
        'match_id' => $match_id,
        'category' => $category,
        'message' => mb_substr($description, 0, 180)
    ], JSON_THROW_ON_ERROR);

    // Dynamically detect notification read column
    $read_column = 'is_read'; // default
    try {
        $colStmt = $pdo->query("SHOW COLUMNS FROM notifications");
        $notif_columns = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $possible_columns = ['is_read', 'read_flag', 'is_read_flag', 'read'];
        foreach ($possible_columns as $col) {
            if (in_array($col, $notif_columns, true)) {
                $read_column = $col;
                break;
            }
        }
    } catch (PDOException $e) {
        error_log("Column detection failed: " . $e->getMessage());
    }

    // Prepare notification insert statement with dynamic column
    $notif_sql = $read_column === 'read' 
        ? "INSERT INTO notifications (user_id, type, payload, `read`) VALUES (?, 'report', ?, 0)"
        : "INSERT INTO notifications (user_id, type, payload, {$read_column}) VALUES (?, 'report', ?, 0)";
    
    $notifStmt = $pdo->prepare($notif_sql);

    // Notify admins
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($admins as $adminId) {
        try {
            $notifStmt->execute([$adminId, $payload]);
        } catch (PDOException $e) {
            error_log("Failed to notify admin {$adminId}: " . $e->getMessage());
        }
    }

    // Notify tournament creator if present and not already an admin
    if ($tournament_id) {
        try {
            $creator = $pdo->prepare("SELECT created_by FROM tournaments WHERE id = ?");
            $creator->execute([$tournament_id]);
            $row = $creator->fetch();
            
            if ($row && !in_array($row['created_by'], $admins, true)) {
                $notifStmt->execute([$row['created_by'], $payload]);
            }
        } catch (PDOException $e) {
            error_log("Failed to notify tournament creator: " . $e->getMessage());
        }
    }

    echo json_encode([
        'success' => true,
        'id' => $report_id,
        'message' => 'Report submitted successfully. Admins will review it shortly.'
    ]);

} catch (PDOException $e) {
    error_log("Report submission error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to submit report. Please try again later.'
    ]);
} catch (Exception $e) {
    error_log("Unexpected error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An unexpected error occurred. Please try again later.'
    ]);
}