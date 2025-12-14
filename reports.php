<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Get report type and filters
$report_type = $_GET['type'] ?? 'overview';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : null;

// Prepare data based on report type
$data = [];
$error = null;

try {
    switch ($report_type) {
        case 'overview':
            // Platform overview statistics
            $stmt = $pdo->query("
                SELECT 
                    (SELECT COUNT(*) FROM users) as total_users,
                    (SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as new_users_30d,
                    (SELECT COUNT(*) FROM teams) as total_teams,
                    (SELECT COUNT(*) FROM tournaments) as total_tournaments,
                    (SELECT COUNT(*) FROM tournaments WHERE status = 'upcoming') as upcoming_tournaments,
                    (SELECT COUNT(*) FROM tournaments WHERE status = 'in_progress') as active_tournaments,
                    (SELECT COUNT(*) FROM tournaments WHERE status = 'completed') as completed_tournaments,
                    (SELECT COUNT(*) FROM registrations) as total_registrations,
                    (SELECT COUNT(*) FROM matches) as total_matches,
                    (SELECT COUNT(*) FROM matches WHERE status = 'completed') as completed_matches
            ");
            $data['overview'] = $stmt->fetch();
            
            // Tournament growth over time
            $stmt = $pdo->query("
                SELECT 
                    DATE_FORMAT(created_at, '%Y-%m') as month,
                    COUNT(*) as count
                FROM tournaments
                GROUP BY month
                ORDER BY month DESC
                LIMIT 12
            ");
            $data['tournament_growth'] = $stmt->fetchAll();
            
            // Most popular games
            $stmt = $pdo->query("
                SELECT 
                    t.game,
                    COUNT(*) as tournament_count,
                    COUNT(DISTINCT r.id) as total_participants
                FROM tournaments t
                LEFT JOIN registrations r ON r.tournament_id = t.id
                GROUP BY t.game
                ORDER BY tournament_count DESC
                LIMIT 10
            ");
            $data['popular_games'] = $stmt->fetchAll();
            
            // Most active users (by tournament participation)
            $stmt = $pdo->query("
                SELECT 
                    u.username,
                    COUNT(DISTINCT r.tournament_id) as tournaments_joined,
                    COUNT(DISTINCT CASE 
                        WHEN m.winner_id = r.id AND m.status = 'completed' 
                        THEN m.id 
                    END) as matches_won
                FROM users u
                INNER JOIN registrations r ON r.user_id = u.id
                LEFT JOIN matches m ON (m.participant1_id = r.id OR m.participant2_id = r.id)
                GROUP BY u.id, u.username
                HAVING tournaments_joined > 0
                ORDER BY tournaments_joined DESC, matches_won DESC
                LIMIT 10
            ");
            $data['active_users'] = $stmt->fetchAll();
            break;
            
        case 'tournament':
            if (!$tournament_id) {
                throw new Exception('Tournament ID required');
            }
            
            // Verify access
            if (!canManageTournament($tournament_id) && !isAdmin()) {
                throw new Exception('Access denied');
            }
            
            // Tournament details
            $stmt = $pdo->prepare("
                SELECT t.*, u.username as creator_name
                FROM tournaments t
                JOIN users u ON t.created_by = u.id
                WHERE t.id = ?
            ");
            $stmt->execute([$tournament_id]);
            $data['tournament'] = $stmt->fetch();
            
            if (!$data['tournament']) {
                throw new Exception('Tournament not found');
            }
            
            // Participants
            $stmt = $pdo->prepare("
                SELECT 
                    r.id,
                    r.status,
                    r.registered_at,
                    COALESCE(tm.name, u.username) as participant_name,
                    CASE 
                        WHEN r.team_id IS NOT NULL THEN 'team'
                        ELSE 'individual'
                    END as participant_type
                FROM registrations r
                LEFT JOIN users u ON r.user_id = u.id
                LEFT JOIN teams tm ON r.team_id = tm.id
                WHERE r.tournament_id = ?
                ORDER BY r.registered_at ASC
            ");
            $stmt->execute([$tournament_id]);
            $data['participants'] = $stmt->fetchAll();
            
            // Matches
            $stmt = $pdo->prepare("
                SELECT 
                    m.*,
                    COALESCE(tm1.name, u1.username) as p1_name,
                    COALESCE(tm2.name, u2.username) as p2_name
                FROM matches m
                LEFT JOIN registrations r1 ON m.participant1_id = r1.id
                LEFT JOIN registrations r2 ON m.participant2_id = r2.id
                LEFT JOIN users u1 ON r1.user_id = u1.id
                LEFT JOIN users u2 ON r2.user_id = u2.id
                LEFT JOIN teams tm1 ON r1.team_id = tm1.id
                LEFT JOIN teams tm2 ON r2.team_id = tm2.id
                WHERE m.tournament_id = ?
                ORDER BY m.round, m.match_index
            ");
            $stmt->execute([$tournament_id]);
            $data['matches'] = $stmt->fetchAll();
            break;
            
        case 'user':
            // User's own statistics
            $user_id = $_SESSION['user_id'];
            $team_id = $_SESSION['team_id'] ?? null;
            
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(DISTINCT r.tournament_id) as tournaments_joined,
                    COUNT(DISTINCT CASE WHEN t.status = 'completed' THEN r.tournament_id END) as tournaments_completed,
                    COUNT(DISTINCT CASE WHEN m.status = 'completed' THEN m.id END) as total_matches,
                    COUNT(DISTINCT CASE 
                        WHEN m.winner_id = r.id AND m.status = 'completed' 
                        THEN m.id 
                    END) as matches_won,
                    COUNT(DISTINCT CASE 
                        WHEN m.status = 'completed' 
                        AND m.winner_id IS NOT NULL 
                        AND m.winner_id != r.id 
                        AND (m.participant1_id = r.id OR m.participant2_id = r.id)
                        THEN m.id 
                    END) as matches_lost
                FROM registrations r
                LEFT JOIN tournaments t ON r.tournament_id = t.id
                LEFT JOIN matches m ON (m.participant1_id = r.id OR m.participant2_id = r.id)
                WHERE r.user_id = ? OR (r.team_id IS NOT NULL AND r.team_id = ?)
            ");
            $stmt->execute([$user_id, $team_id]);
            $data['user_stats'] = $stmt->fetch();
            
            // Tournament history
            $stmt = $pdo->prepare("
                SELECT 
                    t.id,
                    t.name,
                    t.game,
                    t.date,
                    t.status,
                    r.status as registration_status,
                    r.registered_at
                FROM registrations r
                JOIN tournaments t ON r.tournament_id = t.id
                WHERE r.user_id = ? OR (r.team_id IS NOT NULL AND r.team_id = ?)
                ORDER BY t.date DESC
            ");
            $stmt->execute([$user_id, $team_id]);
            $data['tournament_history'] = $stmt->fetchAll();
            break;
            
        case 'team':
            $team_id = $_SESSION['team_id'] ?? null;
            
            if (!$team_id) {
                throw new Exception('You are not in a team');
            }
            
            // Team statistics
            $stmt = $pdo->prepare("
                SELECT 
                    t.id,
                    t.name,
                    t.captain_id,
                    u.username as captain_name,
                    COUNT(DISTINCT tm.id) as member_count,
                    COUNT(DISTINCT r.tournament_id) as tournaments_joined,
                    COUNT(DISTINCT CASE 
                        WHEN m.winner_id = r.id AND m.status = 'completed' 
                        THEN m.id 
                    END) as team_wins
                FROM teams t
                JOIN users u ON t.captain_id = u.id
                LEFT JOIN users tm ON tm.team_id = t.id
                LEFT JOIN registrations r ON r.team_id = t.id
                LEFT JOIN matches m ON (m.participant1_id = r.id OR m.participant2_id = r.id)
                WHERE t.id = ?
                GROUP BY t.id, t.name, t.captain_id, u.username
            ");
            $stmt->execute([$team_id]);
            $data['team_stats'] = $stmt->fetch();
            
            if (!$data['team_stats']) {
                throw new Exception('Team not found');
            }
            
            // Team members
            $stmt = $pdo->prepare("
                SELECT 
                    u.id,
                    u.username,
                    u.created_at,
                    COUNT(DISTINCT r.tournament_id) as tournaments_with_team
                FROM users u
                LEFT JOIN registrations r ON r.user_id = u.id AND r.team_id = ?
                WHERE u.team_id = ?
                GROUP BY u.id, u.username, u.created_at
                ORDER BY tournaments_with_team DESC
            ");
            $stmt->execute([$team_id, $team_id]);
            $data['team_members'] = $stmt->fetchAll();
            break;
            
        default:
            throw new Exception('Invalid report type');
    }
} catch (Exception $e) {
    error_log('Report generation error: ' . $e->getMessage());
    $error = 'Failed to generate report: ' . $e->getMessage();
}

// Get list of tournaments for filter dropdown
$tournaments_list = [];
try {
    if (isAdmin()) {
        $stmt = $pdo->query("SELECT id, name, date FROM tournaments ORDER BY date DESC LIMIT 50");
        $tournaments_list = $stmt->fetchAll();
    } else {
        $stmt = $pdo->prepare("
            SELECT DISTINCT t.id, t.name, t.date
            FROM tournaments t
            WHERE t.created_by = ?
            ORDER BY t.date DESC
            LIMIT 50
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $tournaments_list = $stmt->fetchAll();
    }
} catch (Exception $e) {
    error_log('Error fetching tournaments list: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo htmlspecialchars(SITE_NAME); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" 
          integrity="sha512-9usAa10IRO0HhonpyAIVpjrylPvoDwiPUiKdWk5t3PyolY1cOd4DSE0Ga+ri4AuTroPR5aQvXU9xC6qOPnzFeg==" 
          crossorigin="anonymous" 
          referrerpolicy="no-referrer">
    <style>
        @media print {
            .no-print { display: none !important; }
            .page-header { border-bottom: 2px solid #000; padding-bottom: 10px; }
            .report-card { page-break-inside: avoid; }
            body { font-size: 12pt; }
        }
        
        .report-filters {
            background: var(--surface);
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--accent) 0%, var(--accent-2) 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 0.5rem 0;
        }
        
        .stat-label {
            font-size: 0.875rem;
            opacity: 0.9;
        }
        
        .report-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .report-table th {
            background: var(--surface);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border);
        }
        
        .report-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--border);
        }
        
        .report-table tr:last-child td {
            border-bottom: none;
        }
        
        .report-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 2rem;
        }
        
        .chart-container {
            height: 300px;
            margin: 1rem 0;
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #666;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container">
        <div class="page-header no-print">
            <div class="header-left">
                <h1><i class="fas fa-chart-bar"></i> Reports & Analytics</h1>
                <p class="text-muted">View statistics and generate reports</p>
            </div>
            <div class="header-right">
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print Report
                </button>
                <button onclick="exportToPDF()" class="btn btn-primary">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error no-print"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- Report Type Selection -->
        <div class="report-filters no-print">
            <form method="GET" id="reportForm">
                <div class="filter-grid">
                    <div class="form-group">
                        <label>Report Type</label>
                        <select name="type" onchange="this.form.submit()">
                            <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Platform Overview</option>
                            <option value="tournament" <?php echo $report_type === 'tournament' ? 'selected' : ''; ?>>Tournament Report</option>
                            <option value="user" <?php echo $report_type === 'user' ? 'selected' : ''; ?>>My Statistics</option>
                            <option value="team" <?php echo $report_type === 'team' ? 'selected' : ''; ?>>Team Statistics</option>
                        </select>
                    </div>
                    
                    <?php if ($report_type === 'tournament' && !empty($tournaments_list)): ?>
                    <div class="form-group">
                        <label>Tournament</label>
                        <select name="tournament_id" onchange="this.form.submit()">
                            <option value="">Select Tournament</option>
                            <?php foreach ($tournaments_list as $t): ?>
                                <option value="<?php echo $t['id']; ?>" <?php echo $tournament_id == $t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($t['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Report Content -->
        <?php if ($report_type === 'overview' && isset($data['overview'])): ?>
            <div class="report-section">
                <h2>Platform Overview</h2>
                <p class="text-muted">Current statistics as of <?php echo date('F j, Y g:i A'); ?></p>
                
                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Total Users</div>
                        <div class="stat-value"><?php echo number_format($data['overview']['total_users']); ?></div>
                        <div class="stat-label">+<?php echo $data['overview']['new_users_30d']; ?> this month</div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="stat-label">Total Teams</div>
                        <div class="stat-value"><?php echo number_format($data['overview']['total_teams']); ?></div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stat-label">Tournaments</div>
                        <div class="stat-value"><?php echo number_format($data['overview']['total_tournaments']); ?></div>
                        <div class="stat-label"><?php echo $data['overview']['completed_tournaments']; ?> completed</div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                        <div class="stat-label">Total Matches</div>
                        <div class="stat-value"><?php echo number_format($data['overview']['total_matches']); ?></div>
                        <div class="stat-label"><?php echo $data['overview']['completed_matches']; ?> completed</div>
                    </div>
                </div>

                <?php if (!empty($data['popular_games'])): ?>
                <div class="report-card">
                    <h3>Most Popular Games</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Game</th>
                                <th>Tournaments</th>
                                <th>Total Participants</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['popular_games'] as $index => $game): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($game['game']); ?></td>
                                <td><?php echo $game['tournament_count']; ?></td>
                                <td><?php echo $game['total_participants']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['active_users'])): ?>
                <div class="report-card">
                    <h3>Most Active Users</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Username</th>
                                <th>Tournaments Joined</th>
                                <th>Matches Won</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['active_users'] as $index => $user): ?>
                            <tr>
                                <td><?php echo $index + 1; ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td><?php echo $user['tournaments_joined']; ?></td>
                                <td><?php echo $user['matches_won']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type === 'tournament' && isset($data['tournament'])): ?>
            <div class="report-section">
                <h2>Tournament Report: <?php echo htmlspecialchars($data['tournament']['name']); ?></h2>
                <p class="text-muted">
                    Created by: <?php echo htmlspecialchars($data['tournament']['creator_name']); ?> | 
                    Date: <?php echo date('F j, Y', strtotime($data['tournament']['date'])); ?> | 
                    Status: <?php echo ucfirst($data['tournament']['status']); ?>
                </p>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Participants</div>
                        <div class="stat-value"><?php echo count($data['participants']); ?></div>
                        <div class="stat-label">of <?php echo $data['tournament']['max_teams']; ?> max</div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="stat-label">Total Matches</div>
                        <div class="stat-value"><?php echo count($data['matches']); ?></div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stat-label">Completed</div>
                        <div class="stat-value">
                            <?php echo count(array_filter($data['matches'], fn($m) => $m['status'] === 'completed')); ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($data['participants'])): ?>
                <div class="report-card">
                    <h3>Participants</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['participants'] as $p): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($p['participant_name']); ?></td>
                                <td><?php echo ucfirst($p['participant_type']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $p['status']; ?>">
                                        <?php echo ucfirst($p['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($p['registered_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['matches'])): ?>
                <div class="report-card">
                    <h3>Match Results</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Round</th>
                                <th>Match</th>
                                <th>Participant 1</th>
                                <th>Score</th>
                                <th>Participant 2</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['matches'] as $m): ?>
                            <tr>
                                <td>Round <?php echo $m['round'] + 1; ?></td>
                                <td>Match <?php echo $m['match_index'] + 1; ?></td>
                                <td><?php echo htmlspecialchars($m['p1_name'] ?? 'TBD'); ?></td>
                                <td>
                                    <?php if ($m['score_p1'] !== null && $m['score_p2'] !== null): ?>
                                        <?php echo $m['score_p1']; ?> - <?php echo $m['score_p2']; ?>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($m['p2_name'] ?? 'TBD'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $m['status']; ?>">
                                        <?php echo ucfirst($m['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type === 'user' && isset($data['user_stats'])): ?>
            <div class="report-section">
                <h2>My Statistics</h2>
                <p class="text-muted">Your performance across all tournaments</p>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Tournaments Joined</div>
                        <div class="stat-value"><?php echo $data['user_stats']['tournaments_joined']; ?></div>
                        <div class="stat-label"><?php echo $data['user_stats']['tournaments_completed']; ?> completed</div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="stat-label">Matches Won</div>
                        <div class="stat-value"><?php echo $data['user_stats']['matches_won']; ?></div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stat-label">Win Rate</div>
                        <div class="stat-value">
                            <?php 
                            $total = $data['user_stats']['matches_won'] + $data['user_stats']['matches_lost'];
                            echo $total > 0 ? round(($data['user_stats']['matches_won'] / $total) * 100, 1) . '%' : 'N/A';
                            ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($data['tournament_history'])): ?>
                <div class="report-card">
                    <h3>Tournament History</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Tournament</th>
                                <th>Game</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['tournament_history'] as $t): ?>
                            <tr>
                                <td>
                                    <a href="bracket.php?id=<?php echo $t['id']; ?>">
                                        <?php echo htmlspecialchars($t['name']); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($t['game']); ?></td>
                                <td><?php echo date('M j, Y', strtotime($t['date'])); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $t['status']; ?>">
                                        <?php echo ucfirst($t['status']); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-trophy"></i>
                    <p>You haven't joined any tournaments yet.</p>
                </div>
                <?php endif; ?>
            </div>

        <?php elseif ($report_type === 'team' && isset($data['team_stats'])): ?>
            <div class="report-section">
                <h2>Team Statistics: <?php echo htmlspecialchars($data['team_stats']['name']); ?></h2>
                <p class="text-muted">Captain: <?php echo htmlspecialchars($data['team_stats']['captain_name']); ?></p>

                <div class="stat-grid">
                    <div class="stat-card">
                        <div class="stat-label">Team Members</div>
                        <div class="stat-value"><?php echo $data['team_stats']['member_count']; ?></div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);">
                        <div class="stat-label">Tournaments</div>
                        <div class="stat-value"><?php echo $data['team_stats']['tournaments_joined']; ?></div>
                    </div>
                    
                    <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                        <div class="stat-label">Team Wins</div>
                        <div class="stat-value"><?php echo $data['team_stats']['team_wins']; ?></div>
                    </div>
                </div>

                <?php if (!empty($data['team_members'])): ?>
                <div class="report-card">
                    <h3>Team Members</h3>
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Member Since</th>
                                <th>Tournaments With Team</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['team_members'] as $member): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($member['username']); ?>
                                    <?php if ($member['id'] == $data['team_stats']['captain_id']): ?>
                                        <i class="fas fa-crown" style="color: gold;" title="Captain"></i>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M j, Y', strtotime($member['created_at'])); ?></td>
                                <td><?php echo $member['tournaments_with_team']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Print footer -->
        <div class="report-footer" style="display: none;">
            <p style="text-align: center; margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #ddd;">
                Generated on <?php echo date('F j, Y g:i A'); ?> | <?php echo htmlspecialchars(SITE_NAME); ?>
            </p>
        </div>
    </div>

    <script src="assets/js/utils.js"></script>
    <script src="assets/js/script.js"></script>
    <script>
        // Show footer only when printing
        window.addEventListener('beforeprint', function() {
            document.querySelector('.report-footer').style.display = 'block';
        });
        
        window.addEventListener('afterprint', function() {
            document.querySelector('.report-footer').style.display = 'none';
        });

        // Export to PDF (simplified - requires browser print to PDF)
        function exportToPDF() {
            window.print();
        }
    </script>
</body>
</html>