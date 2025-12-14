// bracket.php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';

$tournament_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tournament_id) {
    setFlash('Invalid tournament ID', 'error');
    redirect('tournaments.php');
}

// Get tournament details
$tournament = $pdo->prepare("
    SELECT t.*, u.username as creator_name 
    FROM tournaments t 
    JOIN users u ON t.created_by = u.id 
    WHERE t.id = ?
")->execute([$tournament_id])->fetch();

if (!$tournament) {
    setFlash('Tournament not found', 'error');
    redirect('tournaments.php');
}

// Get matches
$matches = $pdo->prepare("
    SELECT m.*, 
           t1.name as team1_name, t1.logo as team1_logo,
           t2.name as team2_name, t2.logo as team2_logo
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.tournament_id = ?
    ORDER BY m.round, m.match_order
")->execute([$tournament_id])->fetchAll();

// Group matches by round
$rounds = [];
foreach ($matches as $match) {
    $rounds[$match['round']][] = $match;
}

// Include header
include 'includes/header.php';
?>

<div class="bracket-container">
    <h1><?= htmlspecialchars($tournament['name']) ?> - Bracket</h1>
    
    <div class="bracket-actions mb-4">
        <div class="btn-group">
            <button class="btn btn-outline-secondary" id="zoom-in">
                <i class="fas fa-search-plus"></i> Zoom In
            </button>
            <button class="btn btn-outline-secondary" id="zoom-out">
                <i class="fas fa-search-minus"></i> Zoom Out
            </button>
            <button class="btn btn-outline-secondary" id="reset-view">
                <i class="fas fa-sync-alt"></i> Reset View
            </button>
        </div>
    </div>

    <div class="bracket-wrapper" id="bracket-wrapper">
        <?php foreach ($rounds as $round => $round_matches): ?>
        <div class="round" data-round="<?= $round ?>">
            <h3>Round <?= $round ?></h3>
            <div class="matches">
                <?php foreach ($round_matches as $match): ?>
                <div class="match" data-match-id="<?= $match['id'] ?>">
                    <div class="match-teams">
                        <div class="team team1 <?= $match['winner_id'] == $match['team1_id'] ? 'winner' : '' ?>">
                            <div class="team-logo">
                                <?php if (!empty($match['team1_logo'])): ?>
                                    <img src="<?= htmlspecialchars($match['team1_logo']) ?>" alt="<?= htmlspecialchars($match['team1_name']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="team-name"><?= htmlspecialchars($match['team1_name'] ?? 'TBD') ?></div>
                            <div class="team-score"><?= $match['team1_score'] ?? '' ?></div>
                        </div>
                        <div class="team team2 <?= $match['winner_id'] == $match['team2_id'] ? 'winner' : '' ?>">
                            <div class="team-logo">
                                <?php if (!empty($match['team2_logo'])): ?>
                                    <img src="<?= htmlspecialchars($match['team2_logo']) ?>" alt="<?= htmlspecialchars($match['team2_name']) ?>">
                                <?php endif; ?>
                            </div>
                            <div class="team-name"><?= htmlspecialchars($match['team2_name'] ?? 'TBD') ?></div>
                            <div class="team-score"><?= $match['team2_score'] ?? '' ?></div>
                        </div>
                    </div>
                    <div class="match-info">
                        <span class="match-time"><?= $match['scheduled_at'] ? date('M j, g:i A', strtotime($match['scheduled_at'])) : 'TBD' ?></span>
                        <?php if (isTournamentAdmin($tournament_id)): ?>
                            <button class="btn btn-sm btn-outline-primary edit-match" data-match-id="<?= $match['id'] ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Match Edit Modal -->
<div class="modal fade" id="matchModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Match</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span>&times;</span>
                </button>
            </div>
            <form id="matchForm">
                <div class="modal-body">
                    <input type="hidden" name="match_id" id="match_id">
                    <div class="form-group">
                        <label>Team 1 Score</label>
                        <input type="number" class="form-control" name="team1_score" id="team1_score" min="0">
                    </div>
                    <div class="form-group">
                        <label>Team 2 Score</label>
                        <input type="number" class="form-control" name="team2_score" id="team2_score" min="0">
                    </div>
                    <div class="form-group">
                        <label>Winner</label>
                        <select class="form-control" name="winner_id" id="winner_id">
                            <option value="">Select Winner</option>
                            <option value="team1">Team 1</option>
                            <option value="team2">Team 2</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Bracket.js and custom scripts -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bracket-js@1.0.0/dist/bracket.min.css">
<script src="https://cdn.jsdelivr.net/npm/bracket-js@1.0.0/dist/bracket.min.js"></script>
<script>
$(document).ready(function() {
    // Initialize bracket with data
    const matches = <?= json_encode($matches) ?>;
    const bracketData = {
        teams: [],
        results: []
    };

    // Process matches into bracket format
    // ... (bracket data processing logic)

    // Initialize bracket
    const container = $('#bracket-container');
    container.bracket({
        init: bracketData,
        teamWidth: 150,
        scoreWidth: 30,
        matchMargin: 30,
        roundMargin: 50,
        centerConnectors: true
    });

    // Zoom functionality
    let zoomLevel = 1;
    const bracketWrapper = $('#bracket-wrapper');
    
    $('#zoom-in').click(function() {
        if (zoomLevel < 2) {
            zoomLevel += 0.1;
            updateZoom();
        }
    });
    
    $('#zoom-out').click(function() {
        if (zoomLevel > 0.5) {
            zoomLevel -= 0.1;
            updateZoom();
        }
    });
    
    $('#reset-view').click(function() {
        zoomLevel = 1;
        updateZoom();
        bracketWrapper.scrollLeft(bracketWrapper.width() / 2 - bracketWrapper.width() / 2);
    });
    
    function updateZoom() {
        bracketWrapper.css('transform', `scale(${zoomLevel})`);
        bracketWrapper.css('transform-origin', 'center top');
    }

    // Match editing
    $('.edit-match').click(function() {
        const matchId = $(this).data('match-id');
        const match = matches.find(m => m.id == matchId);
        
        if (match) {
            $('#match_id').val(match.id);
            $('#team1_score').val(match.team1_score || '');
            $('#team2_score').val(match.team2_score || '');
            $('#winner_id').val(match.winner_id ? 
                (match.winner_id == match.team1_id ? 'team1' : 'team2') : '');
            
            $('#matchModal').modal('show');
        }
    });

    // Save match results
    $('#matchForm').submit(function(e) {
        e.preventDefault();
        
        const formData = {
            match_id: $('#match_id').val(),
            team1_score: $('#team1_score').val(),
            team2_score: $('#team2_score').val(),
            winner: $('#winner_id').val(),
            _token: '<?= $_SESSION['csrf_token'] ?>'
        };

        $.post('api/update_match.php', formData)
            .done(function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error: ' + (response.message || 'Failed to update match'));
                }
            })
            .fail(function() {
                alert('Failed to update match. Please try again.');
            });
    });
});
</script>

<?php include 'includes/footer.php'; ?>