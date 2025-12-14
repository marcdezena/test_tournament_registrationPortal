<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as count FROM tournaments");
$tournament_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM teams");
$team_count = $stmt->fetch()['count'];

$stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
$user_count = $stmt->fetch()['count'];

// Get upcoming tournaments
$stmt = $pdo->query("SELECT t.*, u.username as creator, 
    (SELECT COUNT(*) FROM registrations WHERE tournament_id = t.id) as participant_count
    FROM tournaments t 
    JOIN users u ON t.created_by = u.id 
    WHERE t.status = 'upcoming' 
    ORDER BY t.date ASC LIMIT 5");
$upcoming_tournaments = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home - Tournament Portal</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container">
        <div class="hero">
            <h1>Welcome to Tournament Portal</h1>
            <p>Compete, Connect, Conquer</p>
        </div>
        
        <div class="stats-grid">
            <div class="stat-card">
                <svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3><?php echo $tournament_count; ?></h3>
                <p>Active Tournaments</p>
            </div>
            
            <div class="stat-card">
                <svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <h3><?php echo $team_count; ?></h3>
                <p>Registered Teams</p>
            </div>
            
            <div class="stat-card">
                <svg class="stat-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <h3><?php echo $user_count; ?></h3>
                <p>Total Players</p>
            </div>
        </div>
        
        <div class="section">
            <h2>Upcoming Tournaments</h2>
            <div class="tournament-list">
                <?php foreach ($upcoming_tournaments as $tournament): ?>
                    <?php
                    $is_registered = false;
                    if ($_SESSION['team_id']) {
                        $stmt = $pdo->prepare("SELECT id FROM registrations WHERE tournament_id = ? AND team_id = ?");
                        $stmt->execute([$tournament['id'], $_SESSION['team_id']]);
                        $is_registered = $stmt->fetch() !== false;
                    }
                    ?>
                    <div class="tournament-card">
                        <div class="tournament-info">
                            <h3><?php echo htmlspecialchars($tournament['name']); ?></h3>
                            <p>Game: <?php echo htmlspecialchars($tournament['game']); ?></p>
                            <p>Date: <?php echo date('F j, Y', strtotime($tournament['date'])); ?></p>
                            <p>Teams: <?php echo $tournament['participant_count']; ?>/<?php echo $tournament['max_teams']; ?></p>
                        </div>
                        <div class="tournament-actions">
                            <?php if ($_SESSION['team_id'] && !$is_registered && $tournament['participant_count'] < $tournament['max_teams']): ?>
                              <form method="POST" action="register_tournament.php" class="inline">
                                    <input type="hidden" name="tournament_id" value="<?php echo $tournament['id']; ?>">
                                    <button type="submit" class="btn btn-primary">Register Team</button>
                                </form>
                            <?php elseif ($is_registered): ?>
                                <span class="badge badge-success">Registered</span>
                            <?php elseif (!$_SESSION['team_id']): ?>
                                <span class="badge badge-warning">Need Team</span>
                            <?php else: ?>
                                <span class="badge badge-error">Full</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (empty($upcoming_tournaments)): ?>
                    <p class="empty-state">No upcoming tournaments</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="assets/js/script.js"></script>
</body>
</html>