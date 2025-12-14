<?php
require_once 'config.php';
require_once 'lib/bracket.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$tournament_id = intval($_GET['id'] ?? 0);
if (!$tournament_id) {
    redirect('tournaments.php');
}

// Load tournament and verify permissions
$stmt = $pdo->prepare("
    SELECT t.*, u.username as creator_name 
    FROM tournaments t 
    JOIN users u ON t.created_by = u.id 
    WHERE t.id = ?
");
$stmt->execute([$tournament_id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    $_SESSION['flash'] = 'Tournament not found';
    redirect('tournaments.php');
}

// Check if user is tournament creator
if ($tournament['created_by'] != $_SESSION['user_id']) {
    $_SESSION['flash'] = 'Only tournament creators can manage brackets';
    redirect("bracket.php?id={$tournament_id}");
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash'] = 'Invalid security token';
        redirect("manage_bracket.php?id={$tournament_id}");
    }

    try {
        $pdo->beginTransaction();

        switch ($_POST['action']) {
            case 'update_match':
                $match_id = intval($_POST['match_id']);
                $p1_id = intval($_POST['participant1_id']);
                $p2_id = intval($_POST['participant2_id']);
                $score_p1 = trim($_POST['score_p1']);
                $score_p2 = trim($_POST['score_p2']);
                $winner_id = intval($_POST['winner_id']);

                $stmt = $pdo->prepare("
                    UPDATE matches 
                    SET participant1_id = ?, 
                        participant2_id = ?,
                        score_p1 = ?,
                        score_p2 = ?,
                        winner_id = ?,
                        status = 'completed'
                    WHERE id = ? AND tournament_id = ?
                ");
                $stmt->execute([$p1_id, $p2_id, $score_p1, $score_p2, $winner_id, $match_id, $tournament_id]);
                $_SESSION['flash'] = 'Match updated successfully';
                break;

            case 'remove_participant':
                $registration_id = intval($_POST['registration_id']);
                
                // Remove participant from matches
                $stmt = $pdo->prepare("
                    UPDATE matches 
                    SET participant1_id = CASE WHEN participant1_id = ? THEN NULL ELSE participant1_id END,
                        participant2_id = CASE WHEN participant2_id = ? THEN NULL ELSE participant2_id END,
                        winner_id = CASE WHEN winner_id = ? THEN NULL ELSE winner_id END
                    WHERE tournament_id = ?
                ");
                $stmt->execute([$registration_id, $registration_id, $registration_id, $tournament_id]);
                
                // Update registration status
                $stmt = $pdo->prepare("
                    UPDATE registrations 
                    SET status = 'withdrawn' 
                    WHERE id = ? AND tournament_id = ?
                ");
                $stmt->execute([$registration_id, $tournament_id]);
                
                $_SESSION['flash'] = 'Participant removed from bracket';
                break;

            case 'delete_match':
                $match_id = intval($_POST['match_id']);

                $stmt = $pdo->prepare("DELETE FROM matches WHERE id = ? AND tournament_id = ?");
                $stmt->execute([$match_id, $tournament_id]);

                $_SESSION['flash'] = 'Match deleted successfully';
                break;
            case 'regenerate_bracket':
                // Delete existing matches
                $stmt = $pdo->prepare("DELETE FROM matches WHERE tournament_id = ?");
                $stmt->execute([$tournament_id]);
                
                // Delete existing bracket
                $stmt = $pdo->prepare("DELETE FROM brackets WHERE tournament_id = ?");
                $stmt->execute([$tournament_id]);
                
                $_SESSION['flash'] = 'Bracket cleared. Visit bracket page to generate new one.';
                break;
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = 'Error: ' . $e->getMessage();
    }

    redirect("manage_bracket.php?id={$tournament_id}");
}

// Get all matches with participant details
$stmt = $pdo->prepare("
    SELECT m.*, 
           r1.id as p1_reg_id,
           r2.id as p2_reg_id,
           CASE 
               WHEN t.competition_type = 'team' THEN t1.name 
               ELSE u1.username 
           END as p1_name,
           CASE 
               WHEN t.competition_type = 'team' THEN t2.name 
               ELSE u2.username 
           END as p2_name
    FROM matches m
    JOIN tournaments t ON m.tournament_id = t.id
    LEFT JOIN registrations r1 ON m.participant1_id = r1.id
    LEFT JOIN registrations r2 ON m.participant2_id = r2.id
    LEFT JOIN users u1 ON r1.user_id = u1.id
    LEFT JOIN users u2 ON r2.user_id = u2.id
    LEFT JOIN teams t1 ON r1.team_id = t1.id
    LEFT JOIN teams t2 ON r2.team_id = t2.id
    WHERE m.tournament_id = ?
    ORDER BY m.round, m.match_index
");
$stmt->execute([$tournament_id]);
$matches = $stmt->fetchAll();

// Get all active participants
$stmt = $pdo->prepare("
    SELECT r.id, 
           CASE 
               WHEN t.competition_type = 'team' THEN tm.name 
               ELSE u.username 
           END as name,
           r.status
    FROM registrations r
    JOIN tournaments t ON r.tournament_id = t.id
    LEFT JOIN teams tm ON r.team_id = tm.id
    LEFT JOIN users u ON r.user_id = u.id
    WHERE r.tournament_id = ? AND r.status IN ('approved', 'pending')
    ORDER BY r.status, name
");
$stmt->execute([$tournament_id]);
$participants = $stmt->fetchAll();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bracket - <?php echo htmlspecialchars($tournament['name']); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <!-- styles moved to assets/css/styles.css -->
</head>
<body>
    <?php include 'navbar.php'; ?>
    
    <div class="container">
        <div class="page-header">
            <h1>Manage Tournament Bracket</h1>
            <h3><?php echo htmlspecialchars($tournament['name']); ?></h3>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
        <?php endif; ?>

        <div class="tab-container">
            <div class="tab-buttons">
                <button class="tab-btn active" onclick="showTab('matches')">Manage Matches</button>
                <button class="tab-btn" onclick="showTab('participants')">Manage Participants</button>
                <button class="tab-btn" onclick="showTab('admin')">Admin Actions</button>
            </div>

            <div id="matches" class="tab-content active">
                <div class="match-manager">
                    <h2>Tournament Matches</h2>
                    <?php if (empty($matches)): ?>
                        <p>No matches found. Visit the bracket page to generate matches.</p>
                    <?php else: ?>
                        <div class="match-grid">
                            <?php foreach ($matches as $match): ?>
                                <div class="match-card">
                                    <div class="match-header">
                                        Round <?php echo $match['round'] + 1; ?>, Match <?php echo $match['match_index'] + 1; ?>
                                    </div>
                                    <form method="POST" class="score-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="update_match">
                                        <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                        
                                        <div class="form-group">
                                            <label>Participant 1:</label>
                                            <select name="participant1_id" class="form-control" required>
                                                <option value="">Select participant</option>
                                                <?php foreach ($participants as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>" 
                                                            <?php echo $p['id'] == $match['participant1_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p['name']); ?>
                                                        (<?php echo ucfirst($p['status']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="score_p1" class="form-control" 
                                                   value="<?php echo htmlspecialchars($match['score_p1'] ?? ''); ?>" 
                                                   placeholder="Score">
                                        </div>

                                        <div class="form-group">
                                            <label>Participant 2:</label>
                                            <select name="participant2_id" class="form-control" required>
                                                <option value="">Select participant</option>
                                                <?php foreach ($participants as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>" 
                                                            <?php echo $p['id'] == $match['participant2_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($p['name']); ?>
                                                        (<?php echo ucfirst($p['status']); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="text" name="score_p2" class="form-control" 
                                                   value="<?php echo htmlspecialchars($match['score_p2'] ?? ''); ?>" 
                                                   placeholder="Score">
                                        </div>

                                        <div class="form-group">
                                            <label>Winner:</label>
                                            <select name="winner_id" class="form-control" required>
                                                <option value="">Select winner</option>
                                                <option value="<?php echo $match['participant1_id']; ?>" 
                                                        <?php echo $match['winner_id'] == $match['participant1_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($match['p1_name']); ?>
                                                </option>
                                                <option value="<?php echo $match['participant2_id']; ?>" 
                                                        <?php echo $match['winner_id'] == $match['participant2_id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($match['p2_name']); ?>
                                                </option>
                                            </select>
                                        </div>

                                        <button type="submit" class="btn btn-primary">Update Match</button>
                                    </form>
                                    <form method="POST" style="display:inline;margin-left:8px;" onsubmit="return confirm('Are you sure you want to delete this match? This cannot be undone.')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="action" value="delete_match">
                                        <input type="hidden" name="match_id" value="<?php echo $match['id']; ?>">
                                        <button type="submit" class="btn btn-danger">Delete Match</button>
                                    </form>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="participants" class="tab-content">
                <div class="participant-list">
                    <h2>Tournament Participants</h2>
                    <?php foreach ($participants as $participant): ?>
                        <div class="participant-card">
                            <span>
                                <?php echo htmlspecialchars($participant['name']); ?>
                                <span class="status-<?php echo $participant['status']; ?>">
                                    (<?php echo ucfirst($participant['status']); ?>)
                                </span>
                            </span>
                            <form method="POST" style="display:inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="action" value="remove_participant">
                                <input type="hidden" name="registration_id" value="<?php echo $participant['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm" 
                                        onclick="return confirm('Are you sure? This will remove the participant from all matches.')">
                                    Remove from Bracket
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="admin" class="tab-content">
                <div class="admin-actions">
                    <h2>Administrative Actions</h2>
                    <p class="warning-text">Warning: These actions cannot be undone!</p>
                    
                    <form method="POST" onsubmit="return confirm('Are you sure you want to regenerate the entire bracket? All match results will be lost!');">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="action" value="regenerate_bracket">
                        <button type="submit" class="btn btn-danger">Regenerate Entire Bracket</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
    function showTab(tabId) {
        // Hide all tabs
        document.querySelectorAll('.tab-content').forEach(tab => {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('active');
        });
        
        // Show selected tab
        document.getElementById(tabId).classList.add('active');
        document.querySelector(`button[onclick="showTab('${tabId}')"]`).classList.add('active');
    }
    </script>
</body>
</html>