<?php
require_once 'config.php';
requireLogin();

$stmt = $pdo->prepare("SELECT id, username, email, team_id FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$team = null;
if ($user['team_id']) {
    $stmt = $pdo->prepare("SELECT id, name, captain_id FROM teams WHERE id = ?");
    $stmt->execute([$user['team_id']]);
    $team = $stmt->fetch();
}

$hosted = $pdo->prepare("SELECT id, name, date, status FROM tournaments WHERE created_by = ? ORDER BY date DESC");
$hosted->execute([$_SESSION['user_id']]);
$hosted = $hosted->fetchAll();

// Registration history (schema-tolerant)
$hasUserId = $pdo->query("SHOW COLUMNS FROM registrations LIKE 'user_id'")->fetch();
$hasParticipantId = $pdo->query("SHOW COLUMNS FROM registrations LIKE 'participant_id'")->fetch();

$params = [$hasUserId ? $_SESSION['user_id'] : null, $user['team_id'] ?? null];
$sql = $hasUserId
    ? "SELECT r.*, t.name as tournament_name, t.date FROM registrations r JOIN tournaments t ON r.tournament_id = t.id WHERE (r.user_id = ? OR r.team_id = ?) ORDER BY r.registered_at DESC"
    : ($hasParticipantId
        ? "SELECT r.*, t.name as tournament_name, t.date FROM registrations r JOIN tournaments t ON r.tournament_id = t.id WHERE (r.participant_id = ? OR r.team_id = ?) ORDER BY r.registered_at DESC"
        : "SELECT r.*, t.name as tournament_name, t.date FROM registrations r JOIN tournaments t ON r.tournament_id = t.id WHERE r.team_id = ? ORDER BY r.registered_at DESC");
$regs = $pdo->prepare($sql);
$regs->execute($params);
$regs = $regs->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Account - <?=htmlspecialchars(SITE_NAME)?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<?php include 'navbar.php'; ?>
<div class="container">
    <h1>Account</h1>
    <div class="profile-card">
        <h3><?=htmlspecialchars($user['username'])?></h3>
        <p class="muted"><?=htmlspecialchars($user['email'])?></p>
        <?php if ($team): ?>
            <p><strong>Team:</strong> <?=htmlspecialchars($team['name'])?></p>
        <?php else: ?>
            <p class="muted">No team assigned</p>
        <?php endif; ?>
        <p><a href="teams.php" class="btn btn-primary">Manage Teams</a></p>
    </div>

    <h2>Hosted Tournaments</h2>
    <?php if ($hosted): ?>
        <ul>
            <?php foreach ($hosted as $h): ?>
                <li><?=htmlspecialchars($h['name'])?> — <?=htmlspecialchars($h['date'])?> (<?=htmlspecialchars($h['status'])?>)</li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">You haven't hosted any tournaments yet.</p>
    <?php endif; ?>

    <h2>Registration History</h2>
    <?php if ($regs): ?>
        <ul>
            <?php foreach ($regs as $r): ?>
                <li><?=htmlspecialchars($r['tournament_name'])?> — <?=htmlspecialchars($r['date'])?></li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <p class="muted">No registrations found.</p>
    <?php endif; ?>
</div>
<script src="assets/js/script.js"></script>
</body>
</html>