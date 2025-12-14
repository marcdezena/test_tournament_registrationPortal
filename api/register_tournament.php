<?php
/**
 * Handle tournament registration via AJAX
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Get tournament ID
$tournamentId = filter_input(INPUT_POST, 'tournament_id', FILTER_VALIDATE_INT);
if (!$tournamentId) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid tournament ID']);
    exit;
}

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Check if tournament exists and has available slots
    $stmt = $pdo->prepare("
        SELECT t.*, 
               (SELECT COUNT(*) FROM registrations WHERE tournament_id = t.id) as registered_count
        FROM tournaments t 
        WHERE t.id = ? AND t.status = 'upcoming'
        FOR UPDATE
    ");
    $stmt->execute([$tournamentId]);
    $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tournament) {
        throw new Exception('Tournament not found or registration closed');
    }
    
    // Check if tournament is full
    if ($tournament['registered_count'] >= $tournament['max_teams']) {
        throw new Exception('Tournament is full');
    }
    
    // Check if user/team is already registered
    $checkStmt = $pdo->prepare("
        SELECT id FROM registrations 
        WHERE tournament_id = ? AND (user_id = ? OR (team_id = ? AND team_id IS NOT NULL))
    ");
    $checkStmt->execute([
        $tournamentId, 
        $_SESSION['user_id'],
        $_SESSION['team_id'] ?? null
    ]);
    
    if ($checkStmt->fetch()) {
        throw new Exception('You are already registered for this tournament');
    }
    
    // Register user/team
    $registerStmt = $pdo->prepare("
        INSERT INTO registrations 
        (tournament_id, user_id, team_id, status, registered_at)
        VALUES (?, ?, ?, 'registered', NOW())
    ");
    
    $registered = $registerStmt->execute([
        $tournamentId,
        $_SESSION['user_id'],
        $tournament['competition_type'] === 'team' ? ($_SESSION['team_id'] ?? null) : null
    ]);
    
    if (!$registered) {
        throw new Exception('Failed to register for tournament');
    }
    
    // Log the registration
    logAction('tournament_register', [
        'tournament_id' => $tournamentId,
        'user_id' => $_SESSION['user_id'],
        'team_id' => $_SESSION['team_id'] ?? null
    ]);
    
    // Commit transaction
    $pdo->commit();
    
    // Return success
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Successfully registered for the tournament!',
        'redirect' => isset($_POST['redirect']) ? $_POST['redirect'] : null
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
