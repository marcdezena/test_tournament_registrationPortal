<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/bracket.php';

if (!isLoggedIn() || !isAdmin()){
    die('Unauthorized');
}

$tournament_id = intval($_GET['tournament_id'] ?? 0);
if (!$tournament_id) die('Invalid tournament id');

// get registered teams/users (only approved/registered)
$stmt = $pdo->prepare("SELECT COALESCE(team_id, user_id) as participant, team_id IS NULL as is_user FROM registrations WHERE tournament_id = ? AND status IN ('registered','approved')");
$stmt->execute([$tournament_id]);
$rows = $stmt->fetchAll();
$participants = array_map(function($r){ return $r['participant']; }, $rows);

$bracket = generate_single_elim_bracket($participants);
$bracket_id = persist_bracket($pdo, $tournament_id, $bracket);

// simple message
header('Location: /tournament_portal/tournaments.php');
exit();
?>
