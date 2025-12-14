// api/update_match.php
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/functions.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Verify CSRF token
if (!verifyCsrfToken($_POST['_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Verify user is logged in and has permission
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$match_id = filter_input(INPUT_POST, 'match_id', FILTER_VALIDATE_INT);
$team1_score = filter_input(INPUT_POST, 'team1_score', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$team2_score = filter_input(INPUT_POST, 'team2_score', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$winner = $_POST['winner'] ?? ''; // 'team1', 'team2', or empty for draw

if (!$match_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
    exit;
}

try {
    // Get match details
    $match = $pdo->prepare("
        SELECT m.*, t.id as tournament_id, 
               m.team1_id, m.team2_id, t.format as tournament_format
        FROM matches m
        JOIN tournaments t ON m.tournament_id = t.id
        WHERE m.id = ?
    ")->execute([$match_id])->fetch();

    if (!$match) {
        throw new Exception('Match not found');
    }

    // Verify user has permission to update this match
    if (!isTournamentAdmin($match['tournament_id']) && !isAdmin()) {
        throw new Exception('You do not have permission to update this match');
    }

    // Determine winner_id
    $winner_id = null;
    if ($winner === 'team1') {
        $winner_id = $match['team1_id'];
    } elseif ($winner === 'team2') {
        $winner_id = $match['team2_id'];
    } // else it's a draw

    // Update match
    $pdo->beginTransaction();

    $update = $pdo->prepare("
        UPDATE matches 
        SET team1_score = ?,
            team2_score = ?,
            winner_id = ?,
            status = 'completed',
            updated_at = NOW()
        WHERE id = ?
    ")->execute([
        $team1_score,
        $team2_score,
        $winner_id,
        $match_id
    ]);

    if (!$update) {
        throw new Exception('Failed to update match');
    }

    // If this is a bracket match, advance the winner to the next round
    if ($winner_id && in_array($match['tournament_format'], ['single_elimination', 'double_elimination'])) {
        advanceToNextRound($match_id, $winner_id, $match['tournament_id']);
    }

    // Log the match update
    logAction('match_updated', [
        'match_id' => $match_id,
        'tournament_id' => $match['tournament_id'],
        'team1_score' => $team1_score,
        'team2_score' => $team2_score,
        'winner_id' => $winner_id
    ]);

    // Notify teams about match result
    notifyMatchResult($match_id);

    $pdo->commit();

    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Match update error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Advance winning team to the next round in the bracket
 */
function advanceToNextRound($match_id, $winner_id, $tournament_id) {
    global $pdo;
    
    // Get current match details
    $match = $pdo->prepare("
        SELECT * FROM matches 
        WHERE id = ?
    ")->execute([$match_id])->fetch();
    
    if (!$match) {
        throw new Exception('Match not found');
    }
    
    // Determine next match position based on bracket structure
    $next_round = $match['round'] + 1;
    $next_match_position = ceil($match['match_order'] / 2);
    
    // Find the next match
    $next_match = $pdo->prepare("
        SELECT * FROM matches 
        WHERE tournament_id = ? 
        AND round = ? 
        AND match_order = ?
        LIMIT 1
    ")->execute([$tournament_id, $next_round, $next_match_position])->fetch();
    
    if ($next_match) {
        // Determine if winner should be team1 or team2 in next match
        $is_team1 = ($match['match_order'] % 2) === 1;
        $field = $is_team1 ? 'team1_id' : 'team2_id';
        
        // Update next match
        $pdo->prepare("
            UPDATE matches 
            SET {$field} = ?,
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$winner_id, $next_match['id']]);
        
        // Notify teams about next match
        notifyNextMatch($next_match['id']);
    }
}

/**
 * Notify teams about match results
 */
function notifyMatchResult($match_id) {
    global $pdo;
    
    $match = $pdo->prepare("
        SELECT m.*, t.name as tournament_name,
               t1.name as team1_name, t2.name as team2_name
        FROM matches m
        JOIN tournaments t ON m.tournament_id = t.id
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.id = ?
    ")->execute([$match_id])->fetch();
    
    if (!$match) {
        return;
    }
    
    // Get team members to notify
    $teams = [];
    if ($match['team1_id']) {
        $teams[] = $match['team1_id'];
    }
    if ($match['team2_id']) {
        $teams[] = $match['team2_id'];
    }
    
    if (empty($teams)) {
        return;
    }
    
    $team_members = $pdo->prepare("
        SELECT u.id, u.email, u.username, u.notification_prefs, tm.team_id
        FROM team_members tm
        JOIN users u ON tm.user_id = u.id
        WHERE tm.team_id IN (" . implode(',', array_fill(0, count($teams), '?')) . ")
    ")->execute($teams)->fetchAll();
    
    foreach ($team_members as $member) {
        $notification_prefs = json_decode($member['notification_prefs'] ?? '{}', true);
        
        // Check if user wants match result notifications
        if (($notification_prefs['match_results'] ?? true) !== false) {
            $is_winner = false;
            $opponent = '';
            
            if ($member['team_id'] == $match['team1_id']) {
                $is_winner = $match['winner_id'] == $match['team1_id'];
                $opponent = $match['team2_name'] ?: 'TBD';
            } elseif ($member['team_id'] == $match['team2_id']) {
                $is_winner = $match['winner_id'] == $match['team2_id'];
                $opponent = $match['team1_name'] ?: 'TBD';
            }
            
            $subject = $is_winner ? 'Match Won!' : 'Match Result';
            $message = sprintf(
                "Your team %s %s against %s with a score of %d-%d in %s.",
                $is_winner ? 'won' : 'lost',
                $opponent,
                $match['team1_name'] . ' ' . $match['team1_score'] . '-' . $match['team2_score'] . ' ' . $match['team2_name'],
                $match['tournament_name']
            );
            
            // Send notification
            createNotification($member['id'], 'match_result', [
                'match_id' => $match_id,
                'tournament_id' => $match['tournament_id'],
                'message' => $message,
                'is_winner' => $is_winner
            ]);
            
            // Send email if enabled
            if (($notification_prefs['email_match_results'] ?? true) !== false) {
                sendEmail($member['email'], $subject, 'match_result', [
                    'username' => $member['username'],
                    'is_winner' => $is_winner,
                    'match' => $match,
                    'message' => $message
                ]);
            }
        }
    }
}