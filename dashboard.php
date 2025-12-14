<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

// Quick stats for the logged-in user
$user_id = $_SESSION['user_id'];

// helper to check if column exists in current database
function columnExists(PDO $pdo, string $table, string $column): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $q->execute([$table, $column]);
    return (bool)$q->fetchColumn();
}

// Registered tournaments (count) - be defensive if column names differ
$registered_count = 0;
try {
    $team_id = $_SESSION['team_id'] ?? null;
    if (columnExists($pdo, 'registrations', 'user_id')) {
        $sql = "SELECT COUNT(DISTINCT tournament_id) as count FROM registrations WHERE (user_id = ? OR team_id = ?) AND status IN ('registered','approved')";
        $params = [$user_id, $team_id];
    } elseif (columnExists($pdo, 'registrations', 'participant_id')) {
        $sql = "SELECT COUNT(DISTINCT tournament_id) as count FROM registrations WHERE (participant_id = ? OR team_id = ?) AND status IN ('registered','approved')";
        $params = [$user_id, $team_id];
    } else {
        // fallback: count registrations by team only
        $sql = "SELECT COUNT(DISTINCT tournament_id) as count FROM registrations WHERE team_id = ? AND status IN ('registered','approved')";
        $params = [$team_id];
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $registered_count = (int)($stmt->fetch()['count'] ?? 0);
} catch (PDOException $e) {
    // keep registered_count at 0 on error
    $registered_count = 0;
}

// Upcoming matches (next 5) — matches where participant is the user's team or user
$upcoming_matches = [];
$team_id = $_SESSION['team_id'] ?? null;
$participant_identifier = $team_id ?: $user_id; // simplistic; assumes slot fields store team ids
try {
    $stmt = $pdo->prepare("SELECT m.*, t.name as tournament_name FROM matches m JOIN tournaments t ON m.tournament_id = t.id WHERE (m.slot_a = ? OR m.slot_b = ? ) ORDER BY m.scheduled_at ASC LIMIT 5");
    $stmt->execute([$participant_identifier, $participant_identifier]);
    $upcoming_matches = $stmt->fetchAll();
} catch (PDOException $e) {
    $upcoming_matches = [];
}

// Win/Loss record (simple count from matches where winner_id matches team/user)
$wins = 0; $losses = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as wins FROM matches WHERE winner_id = ? AND status = 'finished'");
    $stmt->execute([$participant_identifier]);
    $wins = (int)($stmt->fetch()['wins'] ?? 0);
    $stmt = $pdo->prepare("SELECT COUNT(*) as losses FROM matches WHERE (slot_a = ? OR slot_b = ?) AND status = 'finished' AND winner_id IS NOT NULL AND winner_id != ?");
    $stmt->execute([$participant_identifier, $participant_identifier, $participant_identifier]);
    $losses = (int)($stmt->fetch()['losses'] ?? 0);
} catch (PDOException $e) {
    $wins = 0; $losses = 0;
}

// Recent activity - tolerant to schema differences (user_id may not exist)
$activity = [];
try {
    // Attempt to detect columns in activity_log
    $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log'");
    $colStmt->execute();
    $activityCols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
    $hasUserId = in_array('user_id', $activityCols, true);
    $hasParticipantId = in_array('participant_id', $activityCols, true);

    if ($hasUserId) {
        $stmt = $pdo->prepare("SELECT * FROM activity_log WHERE user_id = ? OR team_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$user_id, $team_id]);
        $activity = $stmt->fetchAll();
    } elseif ($hasParticipantId) {
        // Some schemas store participant_id instead of user_id
        $stmt = $pdo->prepare("SELECT * FROM activity_log WHERE participant_id = ? OR team_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$user_id, $team_id]);
        $activity = $stmt->fetchAll();
    } else {
        // Fallback to team-only activity
        $stmt = $pdo->prepare("SELECT * FROM activity_log WHERE team_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$team_id]);
        $activity = $stmt->fetchAll();
    }
} catch (PDOException $e) {
    // don't let activity log errors break the dashboard
    $activity = [];
}

// If captain/admin, fetch admin stats
$is_admin = isAdmin();
if ($is_admin) {
    $stmt = $pdo->query("SELECT COUNT(*) as total_registrations FROM registrations");
    $admin_registrations = $stmt->fetch()['total_registrations'];
    $stmt = $pdo->query("SELECT game, COUNT(*) as cnt FROM tournaments GROUP BY game ORDER BY cnt DESC LIMIT 5");
    $popular_games = $stmt->fetchAll();
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Dashboard - Tournament Portal</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <!-- styles moved to assets/css/styles.css -->
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="hero">
            <h1>Dashboard</h1>
            <p class="muted">Personalized overview</p>
        </div>

        <div class="dashboard-grid">
            <div>
                <div class="kpi-container">
                    <div class="kpi-header">
                        <h2><i class="fas fa-chart-line"></i> Performance Dashboard</h2>
                        <p class="text-muted">Your tournament statistics at a glance</p>
                    </div>
                    <div class="kpi-grid">
                        <div class="kpi-card kpi-card-primary">
                            <div class="kpi-icon"><i class="fas fa-trophy"></i></div>
                            <div class="kpi-label">Win Rate</div>
                            <div class="kpi-value"><?php echo ($wins + $losses > 0) ? round(($wins / ($wins + $losses)) * 100, 1) : 0; ?>%</div>
                            <div class="kpi-detail"><?php echo $wins; ?> wins / <?php echo $losses; ?> losses</div>
                            <div class="kpi-bar">
                                <div class="kpi-bar-fill" style="width: <?php echo ($wins + $losses > 0) ? round(($wins / ($wins + $losses)) * 100, 1) : 0; ?>%"></div>
                            </div>
                        </div>
                        <div class="kpi-card kpi-card-success">
                            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
                            <div class="kpi-label">Active Tournaments</div>
                            <div class="kpi-value"><?php echo $registered_count; ?></div>
                            <div class="kpi-detail">Registered & Approved</div>
                            <div class="kpi-trend">📈 Ongoing</div>
                        </div>
                        <div class="kpi-card kpi-card-info">
                            <div class="kpi-icon"><i class="fas fa-gamepad"></i></div>
                            <div class="kpi-label">Upcoming Matches</div>
                            <div class="kpi-value"><?php echo count($upcoming_matches); ?></div>
                            <div class="kpi-detail">Next scheduled</div>
                            <div class="kpi-trend">⏰ Get Ready</div>
                        </div>
                    </div>
                </div>

                <div class="card mt-12">
                    <h3>Quick Stats</h3>
                    <div class="flex-gap">
                        <div class="stat-card"><h3><?php echo $registered_count; ?></h3><p class="muted">Registered Tournaments</p></div>
                        <div class="stat-card"><h3><?php echo $wins; ?> / <?php echo $losses; ?></h3><p class="muted">Win / Loss</p></div>
                    </div>
                </div>

                <div class="card mt-12">
                    <h3>Upcoming Matches</h3>
                    <?php if ($upcoming_matches): ?>
                        <ul>
                            <?php foreach($upcoming_matches as $m): ?>
                                <li><?php echo htmlspecialchars($m['tournament_name']) . ' — ' . ($m['scheduled_at'] ?? 'TBD'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">No upcoming matches</p>
                    <?php endif; ?>
                </div>

                <div class="card mt-12">
                    <h3>Printables & Reports</h3>
                    <p>
                        <a href="reports.php" class="btn btn-primary" style="margin-bottom:8px">
                            <i class="fas fa-chart-bar"></i> View Reports
                        </a>
                    </p>
                    <p>
                        <a href="print_bracket.php" class="btn btn-primary">
                            <i class="fas fa-print"></i> Print Bracket
                        </a>
                    </p>
                </div>

                <div class="card mt-12">
                    <h3>Recent Activity</h3>
                    <?php if ($activity): ?>
                        <ul>
                            <?php foreach($activity as $a): ?>
                                <li><?php echo htmlspecialchars($a['type']) . ' — ' . date('Y-m-d H:i', strtotime($a['created_at'])); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p class="muted">No recent activity</p>
                    <?php endif; ?>
                </div>
            </div>

            <aside>
                <div class="card">
                    <h3>Quick Actions</h3>
                    <p><a class="btn btn-primary" href="tournaments.php">Browse Tournaments</a></p>
                    <p><a class="btn btn-primary" href="teams.php">Manage Teams</a></p>
                    <p><a class="btn btn-primary" href="account.php">View Account</a></p>
                </div>

                    <?php if ($is_admin): ?>
                    <div class="card mt-12">
                        <h3>Admin Panel</h3>
                        <p>Total registrations: <?php echo $admin_registrations; ?></p>
                        <h4>Popular Games</h4>
                        <ul>
                            <?php foreach($popular_games as $g): ?><li><?php echo htmlspecialchars($g['game']) . ' (' . $g['cnt'] . ')'; ?></li><?php endforeach; ?>
                        </ul>
                        <p><a class="btn btn-primary" href="admin.php">Open Admin</a></p>
                    </div>
                <?php endif; ?>
            </aside>
        </div>
    </div>
    <script src="assets/js/script.js"></script>

    <style>
    .kpi-container {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
        border-radius: 12px;
        padding: 2rem;
        margin-bottom: 1.5rem;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }
    .kpi-header {
        margin-bottom: 2rem;
        color: white;
    }
    .kpi-header h2 {
        margin: 0;
        font-size: 1.8rem;
        margin-bottom: 0.5rem;
    }
    .kpi-header i {
        color: #4facfe;
        margin-right: 0.5rem;
    }
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-top: 0;
    }
    .kpi-card {
        position: relative;
        padding: 1.5rem;
        border-radius: 12px;
        text-align: center;
        color: white;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.15);
        transition: all 0.3s ease;
        overflow: hidden;
    }
    .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
        transition: left 0.5s ease;
    }
    .kpi-card:hover::before {
        left: 100%;
    }
    .kpi-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.25);
        border-color: rgba(255, 255, 255, 0.3);
    }
    .kpi-card-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    }
    .kpi-card-success {
        background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    }
    .kpi-card-info {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    }
    .kpi-icon {
        font-size: 2.5rem;
        margin-bottom: 0.5rem;
        opacity: 0.9;
    }
    .kpi-label {
        font-size: 0.85rem;
        opacity: 0.85;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 0.5rem;
        font-weight: 600;
    }
    .kpi-value {
        font-size: 2.8rem;
        font-weight: 700;
        margin: 0.5rem 0;
        line-height: 1;
        text-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
    }
    .kpi-detail {
        font-size: 0.8rem;
        opacity: 0.8;
        margin-top: 0.5rem;
    }
    .kpi-bar {
        width: 100%;
        height: 6px;
        background: rgba(255, 255, 255, 0.2);
        border-radius: 3px;
        margin-top: 1rem;
        overflow: hidden;
    }
    .kpi-bar-fill {
        height: 100%;
        background: rgba(255, 255, 255, 0.8);
        border-radius: 3px;
        transition: width 0.6s ease;
    }
    .kpi-trend {
        font-size: 0.9rem;
        margin-top: 0.8rem;
        padding: 0.5rem;
        background: rgba(255, 255, 255, 0.1);
        border-radius: 6px;
        display: inline-block;
    }
    @media (max-width: 768px) {
        .kpi-grid {
            grid-template-columns: 1fr;
        }
        .kpi-container {
            padding: 1.5rem;
        }
        .kpi-header h2 {
            font-size: 1.4rem;
        }
    }
    </style>