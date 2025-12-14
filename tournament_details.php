<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/functions.php';

// Ensure user is logged in
requireLogin();

// Get tournament ID from URL
$tournament_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$tournament_id) {
    setFlash('Invalid tournament ID', 'error');
    redirect('tournaments.php');
}

try {
    // Get tournament details
    $stmt = $pdo->prepare("
        SELECT t.*, 
               u.username as creator_name,
               COUNT(DISTINCT r.id) as registered_count,
               (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.id) as match_count
        FROM tournaments t
        LEFT JOIN users u ON t.created_by = u.id
        LEFT JOIN registrations r ON r.tournament_id = t.id AND r.status = 'approved'
        WHERE t.id = ?
        GROUP BY t.id
    ");
    $stmt->execute([$tournament_id]);
    $tournament = $stmt->fetch();

    if (!$tournament) {
        setFlash('Tournament not found', 'error');
        redirect('tournaments.php');
    }

    // Get tournament organizers
    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, to.role
        FROM tournament_organizers to
        JOIN users u ON to.user_id = u.id
        WHERE to.tournament_id = ?
        ORDER BY to.role, u.username
    ");
    $stmt->execute([$tournament_id]);
    $organizers = $stmt->fetchAll();

    // Get sponsors
    $stmt = $pdo->prepare("SELECT * FROM tournament_sponsors WHERE tournament_id = ? ORDER BY display_order");
    $stmt->execute([$tournament_id]);
    $sponsors = $stmt->fetchAll();

    // Get prize pool
    $stmt = $pdo->prepare("
        SELECT * FROM tournament_prizes 
        WHERE tournament_id = ? 
        ORDER BY placement
    ");
    $stmt->execute([$tournament_id]);
    $prizes = $stmt->fetchAll();

    // Get registered teams/players
    $stmt = $pdo->prepare("
        SELECT r.*, 
               COALESCE(t.name, u.username) as participant_name,
               t.logo as team_logo,
               u.avatar as user_avatar
        FROM registrations r
        LEFT JOIN teams t ON r.team_id = t.id
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.tournament_id = ? AND r.status = 'approved'
        ORDER BY r.registered_at
    ");
    $stmt->execute([$tournament_id]);
    $participants = $stmt->fetchAll();

    // Get upcoming matches
    $stmt = $pdo->prepare("
        SELECT m.*,
               t1.name as team1_name, t1.logo as team1_logo,
               t2.name as team2_name, t2.logo as team2_logo
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.tournament_id = ? 
        AND m.status = 'scheduled'
        AND m.scheduled_at > NOW()
        ORDER BY m.scheduled_at
        LIMIT 5
    ");
    $stmt->execute([$tournament_id]);
    $upcoming_matches = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log('Database error in tournament_details: ' . $e->getMessage());
    setFlash('An error occurred while loading tournament details', 'error');
    redirect('tournaments.php');
}

// Set page title
$page_title = $tournament['name'] . ' - ' . SITE_NAME;

// Include header
include 'includes/header.php';
?>

<div class="tournament-details">
    <!-- Tournament Header -->
    <div class="tournament-header" style="background-image: url('<?= htmlspecialchars($tournament['banner_image'] ?? 'assets/img/default-tournament-banner.jpg') ?>')">
        <div class="tournament-header-overlay">
            <div class="container">
                <div class="tournament-basic-info">
                    <div class="tournament-game-logo">
                        <img src="assets/img/games/<?= htmlspecialchars(strtolower(str_replace(' ', '-', $tournament['game']))) ?>.png" 
                             alt="<?= htmlspecialchars($tournament['game']) ?>" class="game-logo">
                    </div>
                    <div class="tournament-title">
                        <h1><?= htmlspecialchars($tournament['name']) ?></h1>
                        <div class="tournament-meta">
                            <span class="tournament-game"><?= htmlspecialchars($tournament['game']) ?></span>
                            <?php if ($tournament['platform']): ?>
                                <span class="tournament-platform"><?= htmlspecialchars($tournament['platform']) ?></span>
                            <?php endif; ?>
                            <?php if ($tournament['region']): ?>
                                <span class="tournament-region"><?= htmlspecialchars($tournament['region']) ?></span>
                            <?php endif; ?>
                            <span class="tournament-date">
                                <i class="far fa-calendar-alt"></i> 
                                <?= date('F j, Y', strtotime($tournament['date'])) ?>
                                <?php if ($tournament['check_in_time']): ?>
                                    • Check-in: <?= date('g:i A', strtotime($tournament['check_in_time'])) ?>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                    <div class="tournament-actions">
                        <?php if (isTournamentAdmin($tournament['id']) || isAdmin()): ?>
                            <a href="manage_tournament.php?id=<?= $tournament['id'] ?>" class="btn btn-outline-light">
                                <i class="fas fa-cog"></i> Manage
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($tournament['stream_url']): ?>
                            <a href="<?= htmlspecialchars($tournament['stream_url']) ?>" class="btn btn-danger" target="_blank">
                                <i class="fas fa-play"></i> Watch Stream
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($tournament['discord_invite']): ?>
                            <a href="<?= htmlspecialchars($tournament['discord_invite']) ?>" class="btn btn-discord" target="_blank">
                                <i class="fab fa-discord"></i> Join Discord
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="container mt-4">
        <div class="row">
            <!-- Main Content -->
            <div class="col-lg-8">
                <!-- Tournament Tabs -->
                <ul class="nav nav-tabs" id="tournamentTabs" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active" id="overview-tab" data-toggle="tab" href="#overview" role="tab">
                            <i class="fas fa-info-circle"></i> Overview
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="brackets-tab" data-toggle="tab" href="#brackets" role="tab">
                            <i class="fas fa-sitemap"></i> Brackets
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="participants-tab" data-toggle="tab" href="#participants" role="tab">
                            <i class="fas fa-users"></i> Participants (<?= $tournament['registered_count'] ?>)
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="prizes-tab" data-toggle="tab" href="#prizes" role="tab" <?= empty($prizes) ? 'disabled' : '' ?>>
                            <i class="fas fa-trophy"></i> Prizes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" id="rules-tab" data-toggle="tab" href="#rules" role="tab">
                            <i class="fas fa-book"></i> Rules
                        </a>
                    </li>
                </ul>

                <div class="tab-content p-3 border border-top-0 rounded-bottom" id="tournamentTabsContent">
                    <!-- Overview Tab -->
                    <div class="tab-pane fade show active" id="overview" role="tabpanel">
                        <div class="tournament-description">
                            <?= nl2br(htmlspecialchars($tournament['description'] ?? 'No description provided.')) ?>
                        </div>

                        <?php if (!empty($tournament['rules'])): ?>
                        <div class="mt-4">
                            <h5>Tournament Rules</h5>
                            <div class="rules-preview">
                                <?= nl2br(htmlspecialchars(substr($tournament['rules'], 0, 300))) ?>
                                <?php if (strlen($tournament['rules']) > 300): ?>
                                    ... <a href="#rules" class="text-primary">Read more</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($upcoming_matches)): ?>
                        <div class="upcoming-matches mt-4">
                            <h5>Upcoming Matches</h5>
                            <div class="list-group">
                                <?php foreach ($upcoming_matches as $match): ?>
                                <div class="list-group-item">
                                    <div class="match-teams">
                                        <div class="team">
                                            <?php if (!empty($match['team1_logo'])): ?>
                                                <img src="<?= htmlspecialchars($match['team1_logo']) ?>" alt="<?= htmlspecialchars($match['team1_name']) ?>" class="team-logo">
                                            <?php endif; ?>
                                            <span class="team-name"><?= htmlspecialchars($match['team1_name'] ?? 'TBD') ?></span>
                                        </div>
                                        <div class="match-vs">vs</div>
                                        <div class="team">
                                            <span class="team-name"><?= htmlspecialchars($match['team2_name'] ?? 'TBD') ?></span>
                                            <?php if (!empty($match['team2_logo'])): ?>
                                                <img src="<?= htmlspecialchars($match['team2_logo']) ?>" alt="<?= htmlspecialchars($match['team2_name']) ?>" class="team-logo">
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="match-time">
                                        <i class="far fa-clock"></i> 
                                        <?= date('M j, Y g:i A', strtotime($match['scheduled_at'])) ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="text-right mt-2">
                                <a href="bracket.php?tournament=<?= $tournament['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    View All Matches
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Brackets Tab -->
                    <div class="tab-pane fade" id="brackets" role="tabpanel">
                        <div class="bracket-preview">
                            <div class="text-center py-5">
                                <h4>Bracket View</h4>
                                <p>Interactive bracket will be displayed here</p>
                                <a href="bracket.php?tournament=<?= $tournament['id'] ?>" class="btn btn-primary">
                                    View Full Bracket
                                </a>
                            </div>
                        </div>
                    </div>

                    <!-- Participants Tab -->
                    <div class="tab-pane fade" id="participants" role="tabpanel">
                        <?php if (!empty($participants)): ?>
                            <div class="participants-grid">
                                <?php foreach ($participants as $participant): ?>
                                <div class="participant-card">
                                    <div class="participant-avatar">
                                        <?php if (!empty($participant['team_logo'])): ?>
                                            <img src="<?= htmlspecialchars($participant['team_logo']) ?>" alt="Team Logo" class="img-fluid">
                                        <?php elseif (!empty($participant['user_avatar'])): ?>
                                            <img src="<?= htmlspecialchars($participant['user_avatar']) ?>" alt="User Avatar" class="img-fluid">
                                        <?php else: ?>
                                            <div class="avatar-placeholder">
                                                <i class="fas fa-user"></i>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="participant-info">
                                        <h6 class="mb-0"><?= htmlspecialchars($participant['participant_name']) ?></h6>
                                        <small class="text-muted">
                                            Registered on <?= date('M j, Y', strtotime($participant['registered_at'])) ?>
                                        </small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-users fa-3x mb-3"></i>
                                <p>No participants have registered yet.</p>
                                <?php if (isTournamentRegistrationOpen($tournament['id'])): ?>
                                    <a href="register_tournament.php?id=<?= $tournament['id'] ?>" class="btn btn-primary">
                                        Register Now
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Prizes Tab -->
                    <div class="tab-pane fade" id="prizes" role="tabpanel">
                        <?php if (!empty($prizes)): ?>
                            <div class="prizes-list">
                                <?php foreach ($prizes as $prize): ?>
                                <div class="prize-item">
                                    <div class="prize-placement">
                                        <?php if ($prize['placement'] == 1): ?>
                                            <i class="fas fa-trophy gold"></i> 1st Place
                                        <?php elseif ($prize['placement'] == 2): ?>
                                            <i class="fas fa-medal silver"></i> 2nd Place
                                        <?php elseif ($prize['placement'] == 3): ?>
                                            <i class="fas fa-medal bronze"></i> 3rd Place
                                        <?php else: ?>
                                            #<?= $prize['placement'] ?> Place
                                        <?php endif; ?>
                                    </div>
                                    <div class="prize-details">
                                        <div class="prize-amount">
                                            <?php if ($prize['prize_type'] === 'cash' && $prize['amount']): ?>
                                                <?= number_format($prize['amount'], 2) ?> <?= $prize['currency'] ?>
                                            <?php else: ?>
                                                <?= htmlspecialchars($prize['prize']) ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($prize['sponsor_id'])): ?>
                                        <div class="prize-sponsor">
                                            Sponsored by: 
                                            <?php 
                                            $sponsor = array_filter($sponsors, function($s) use ($prize) {
                                                return $s['id'] == $prize['sponsor_id'];
                                            });
                                            $sponsor = reset($sponsor);
                                            if ($sponsor): ?>
                                                <?php if (!empty($sponsor['website_url'])): ?>
                                                    <a href="<?= htmlspecialchars($sponsor['website_url']) ?>" target="_blank">
                                                        <?= htmlspecialchars($sponsor['name']) ?>
                                                    </a>
                                                <?php else: ?>
                                                    <?= htmlspecialchars($sponsor['name']) ?>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <?php if ($tournament['prize_pool'] > 0): ?>
                            <div class="prize-pool-total mt-4 p-3 bg-light rounded text-center">
                                <h5>Total Prize Pool</h5>
                                <div class="display-4 text-primary">
                                    $<?= number_format($tournament['prize_pool'], 2) ?>
                                </div>
                                <small class="text-muted">
                                    <?= count($prizes) ?> prizes across all placements
                                </small>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-trophy fa-3x mb-3"></i>
                                <p>No prize information available yet.</p>
                                <?php if (!empty($sponsors)): ?>
                                    <p>Check out our <a href="#sponsors">sponsors</a> for potential prizes!</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Rules Tab -->
                    <div class="tab-pane fade" id="rules" role="tabpanel">
                        <?php if (!empty($tournament['rules'])): ?>
                            <div class="rules-content">
                                <?= nl2br(htmlspecialchars($tournament['rules'])) ?>
                            </div>
                            
                            <?php if (!empty($tournament['match_rules'])): 
                                $match_rules = json_decode($tournament['match_rules'], true);
                                if ($match_rules): ?>
                                    <h5 class="mt-4">Match Rules</h5>
                                    <div class="table-responsive">
                                        <table class="table table-bordered">
                                            <tbody>
                                                <?php foreach ($match_rules as $rule => $value): ?>
                                                <tr>
                                                    <th width="40%"><?= ucfirst(str_replace('_', ' ', $rule)) ?></th>
                                                    <td><?= is_array($value) ? implode(', ', $value) : $value ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="text-center py-4 text-muted">
                                <i class="fas fa-book fa-3x mb-3"></i>
                                <p>No rules have been specified for this tournament.</p>
                                <p>Please check back later or contact the tournament organizers for more information.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar -->
            <div class="col-lg-4">
                <!-- Tournament Status Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Tournament Status</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $status_class = [
                            'upcoming' => 'primary',
                            'registration_open' => 'info',
                            'in_progress' => 'warning',
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ][$tournament['status']] ?? 'secondary';
                        ?>
                        <div class="tournament-status mb-3">
                            <span class="badge badge-<?= $status_class ?> p-2">
                                <?= ucfirst(str_replace('_', ' ', $tournament['status'])) ?>
                            </span>
                        </div>
                        
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Format</span>
                                <span class="font-weight-bold">
                                    <?= ucfirst(str_replace('_', ' ', $tournament['format'])) ?>
                                </span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Registered</span>
                                <span>
                                    <?= $tournament['registered_count'] ?> / <?= $tournament['max_teams'] ?>
                                    (<?= round(($tournament['registered_count'] / $tournament['max_teams']) * 100) ?>% full)
                                </span>
                            </li>
                            <?php if ($tournament['entry_fee'] > 0): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Entry Fee</span>
                                <span class="font-weight-bold">
                                    $<?= number_format($tournament['entry_fee'], 2) ?>
                                </span>
                            </li>
                            <?php endif; ?>
                            <?php if ($tournament['prize_pool'] > 0): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Prize Pool</span>
                                <span class="font-weight-bold text-success">
                                    $<?= number_format($tournament['prize_pool'], 2) ?>
                                </span>
                            </li>
                            <?php endif; ?>
                            <?php if ($tournament['registration_deadline']): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <span>Registration Deadline</span>
                                <span class="font-weight-bold">
                                    <?= date('M j, Y g:i A', strtotime($tournament['registration_deadline'])) ?>
                                    <small class="d-block text-muted">
                                        <?php 
                                        $now = new DateTime();
                                        $deadline = new DateTime($tournament['registration_deadline']);
                                        $interval = $now->diff($deadline);
                                        $days = $interval->format('%a');
                                        $hours = $interval->format('%h');
                                        $minutes = $interval->format('%i');
                                        
                                        if ($now < $deadline) {
                                            echo "Closes in ";
                                            if ($days > 0) {
                                                echo $days . ' day' . ($days > 1 ? 's' : '');
                                            } elseif ($hours > 0) {
                                                echo $hours . ' hour' . ($hours > 1 ? 's' : '');
                                            } else {
                                                echo $minutes . ' minute' . ($minutes != 1 ? 's' : '');
                                            }
                                        } else {
                                            echo 'Registration closed';
                                        }
                                        ?>
                                    </small>
                                </span>
                            </li>
                            <?php endif; ?>
                        </ul>
                        
                        <?php if (isTournamentRegistrationOpen($tournament['id'])): ?>
                            <div class="mt-3">
                                <a href="register_tournament.php?id=<?= $tournament['id'] ?>" class="btn btn-primary btn-block">
                                    <i class="fas fa-user-plus"></i> Register Now
                                </a>
                                <?php if ($tournament['entry_fee'] > 0): ?>
                                    <small class="text-muted d-block mt-1 text-center">
                                        Entry fee: $<?= number_format($tournament['entry_fee'], 2) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Organizers Card -->
                <?php if (!empty($organizers)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Tournament Organizers</h5>
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($organizers as $organizer): ?>
                            <li class="list-group-item">
                                <div class="d-flex align-items-center">
                                    <div class="avatar-container mr-3">
                                        <img src="<?= getGravatar($organizer['email']) ?>" alt="<?= htmlspecialchars($organizer['username']) ?>" 
                                             class="rounded-circle" width="40" height="40">
                                    </div>
                                    <div>
                                        <h6 class="mb-0"><?= htmlspecialchars($organizer['username']) ?></h6>
                                        <small class="text-muted">
                                            <?= ucfirst(str_replace('_', ' ', $organizer['role'])) ?>
                                        </small>
                                    </div>
                                    <div class="ml-auto">
                                        <a href="profile.php?user=<?= $organizer['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="fas fa-user"></i> Profile
                                        </a>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Sponsors Card -->
                <?php if (!empty($sponsors)): ?>
                <div class="card mb-4" id="sponsors">
                    <div class="card-header">
                        <h5 class="mb-0">Tournament Sponsors</h5>
                    </div>
                    <div class="card-body">
                        <div class="sponsors-grid">
                            <?php foreach ($sponsors as $sponsor): ?>
                            <div class="sponsor-item text-center mb-3">
                                <?php if (!empty($sponsor['logo_url'])): ?>
                                    <?php if (!empty($sponsor['website_url'])): ?>
                                        <a href="<?= htmlspecialchars($sponsor['website_url']) ?>" target="_blank" rel="noopener noreferrer">
                                            <img src="<?= htmlspecialchars($sponsor['logo_url']) ?>" 
                                                 alt="<?= htmlspecialchars($sponsor['name']) ?>" 
                                                 class="img-fluid sponsor-logo" 
                                                 style="max-height: 60px; max-width: 150px;">
                                        </a>
                                    <?php else: ?>
                                        <img src="<?= htmlspecialchars($sponsor['logo_url']) ?>" 
                                             alt="<?= htmlspecialchars($sponsor['name']) ?>" 
                                             class="img-fluid sponsor-logo" 
                                             style="max-height: 60px; max-width: 150px;">
                                    <?php endif; ?>
                                    <?php if (!empty($sponsor['prize_contribution'])): ?>
                                        <div class="sponsor-contribution small text-muted mt-1">
                                            <?= htmlspecialchars($sponsor['prize_contribution']) ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="sponsor-name">
                                        <?php if (!empty($sponsor['website_url'])): ?>
                                            <a href="<?= htmlspecialchars($sponsor['website_url']) ?>" target="_blank" rel="noopener noreferrer">
                                                <?= htmlspecialchars($sponsor['name']) ?>
                                            </a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($sponsor['name']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if (isTournamentAdmin($tournament['id']) || isAdmin()): ?>
                            <div class="text-center mt-3">
                                <a href="manage_tournament.php?id=<?= $tournament['id'] ?>#sponsors" 
                                   class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-plus"></i> Add Sponsor
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Share Tournament -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Share Tournament</h5>
                    </div>
                    <div class="card-body">
                        <div class="input-group mb-3">
                            <input type="text" class="form-control" id="tournament-url" 
                                   value="<?= SITE_URL ?>/tournament_details.php?id=<?= $tournament['id'] ?>" readonly>
                            <div class="input-group-append">
                                <button class="btn btn-outline-secondary" type="button" id="copy-url-btn" 
                                        data-toggle="tooltip" title="Copy to clipboard">
                                    <i class="far fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <div class="social-share-buttons">
                            <a href="https://twitter.com/intent/tweet?url=<?= urlencode(SITE_URL . '/tournament_details.php?id=' . $tournament['id']) ?>&text=<?= urlencode('Check out this tournament: ' . $tournament['name']) ?>" 
                               class="btn btn-sm btn-twitter" target="_blank">
                                <i class="fab fa-twitter"></i> Tweet
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode(SITE_URL . '/tournament_details.php?id=' . $tournament['id']) ?>" 
                               class="btn btn-sm btn-facebook" target="_blank">
                                <i class="fab fa-facebook-f"></i> Share
                            </a>
                            <a href="https://discord.com/channels/" 
                               class="btn btn-sm btn-discord" target="_blank">
                                <i class="fab fa-discord"></i> Share on Discord
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
// Copy tournament URL to clipboard
document.getElementById('copy-url-btn').addEventListener('click', function() {
    const urlInput = document.getElementById('tournament-url');
    urlInput.select();
    document.execCommand('copy');
    
    // Show tooltip
    const tooltip = new bootstrap.Tooltip(this, {
        title: 'Copied!',
        trigger: 'manual'
    });
    tooltip.show();
    
    // Hide tooltip after 2 seconds
    setTimeout(() => {
        tooltip.dispose();
    }, 2000);
});

// Initialize tooltips
$(function () {
    $('[data-toggle="tooltip"]').tooltip();
});

// Handle tab persistence
$('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
    localStorage.setItem('lastTab', $(e.target).attr('href'));
});

// Restore last tab
var lastTab = localStorage.getItem('lastTab');
if (lastTab) {
    $('[href="' + lastTab + '"]').tab('show');
}
</script>
