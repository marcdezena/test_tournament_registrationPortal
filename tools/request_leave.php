<?php
require_once __DIR__ . '/../config.php';

if (!isLoggedIn()) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated']);
        exit;
    }
    redirect('login.php');
}

$user_id = $_SESSION['user_id'];
$type = $_POST['type'] ?? ($_GET['type'] ?? 'team');
$type = ($type === 'tournament') ? 'tournament' : 'team';
$team_id = isset($_POST['team_id']) ? intval($_POST['team_id']) : (isset($_GET['team_id']) ? intval($_GET['team_id']) : null);
$tournament_id = isset($_POST['tournament_id']) ? intval($_POST['tournament_id']) : (isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : null);
$message = trim($_POST['message'] ?? '');

// Basic validation
if ($type === 'team' && !$team_id) {
    $msg = 'Invalid team specified';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit; }
    $_SESSION['flash'] = $msg; redirect('teams.php');
}
if ($type === 'tournament' && !$tournament_id) {
    $msg = 'Invalid tournament specified';
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) { header('Content-Type: application/json'); echo json_encode(['success' => false, 'message' => $msg]); exit; }
    $_SESSION['flash'] = $msg; redirect('tournaments.php');
}

try {
    $stmt = $pdo->prepare("INSERT INTO leave_requests (user_id, team_id, tournament_id, type, message, status) VALUES (?, ?, ?, ?, ?, 'pending')");
    $stmt->execute([$user_id, $team_id, $tournament_id, $type, $message]);
    $insertedId = $pdo->lastInsertId();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'id' => $insertedId, 'message' => 'Leave request submitted']);
        exit;
    }

    $_SESSION['flash'] = 'Leave request submitted. The admins or team captain will review it shortly.';
    if ($type === 'team') redirect('teams.php');
    redirect('tournaments.php');

} catch (PDOException $e) {
    $msg = 'Failed to submit leave request: ' . $e->getMessage();
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
    $_SESSION['flash'] = $msg;
    if ($type === 'team') redirect('teams.php');
    redirect('tournaments.php');
}
