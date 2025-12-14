<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

// Handle team creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_team'])) {
    if (!empty($_SESSION['team_id'])) {
        $error = 'You are already in a team';
    } else {
        $team_name = trim($_POST['team_name'] ?? '');
        if (empty($team_name)) {
            $error = 'Team name is required';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO teams (name, captain_id) VALUES (?, ?)");
                $stmt->execute([$team_name, $_SESSION['user_id']]);
                $team_id = $pdo->lastInsertId();
                $stmt = $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?");
                $stmt->execute([$team_id, $_SESSION['user_id']]);
                $pdo->commit();
                $_SESSION['team_id'] = $team_id;
                $success = 'Team created successfully!';
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = 'Failed to create team: ' . $e->getMessage();
            }
        }
    }
}

// Handle join team (simple action)
if (isset($_GET['join']) && empty($_SESSION['team_id'])) {
    $join_id = intval($_GET['join']);
    try {
        $stmt = $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?");
        $stmt->execute([$join_id, $_SESSION['user_id']]);
        $_SESSION['team_id'] = $join_id;
        $success = 'Joined team successfully!';
    } catch (PDOException $e) {
        $error = 'Failed to join team: ' . $e->getMessage();
    }
}

// Get all teams and their member counts
$stmt = $pdo->query("SELECT t.*, u.username AS captain_name, (SELECT COUNT(*) FROM users WHERE team_id = t.id) AS member_count FROM teams t LEFT JOIN users u ON t.captain_id = u.id ORDER BY t.created_at DESC");
$teams = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams - Tournament Portal</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="breadcrumbs">
            <div class="breadcrumb-item">
                <a href="index.php">Home</a>
            </div>
            <div class="breadcrumb-item active">
                Teams
            </div>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h1>Teams</h1>
                <p class="text-muted">Join or create a team to participate in tournaments</p>
            </div>
            <div class="header-right">
                <?php if (empty($_SESSION['team_id'])): ?>
                    <button onclick="toggleModal('createTeamModal')" class="btn btn-primary">
                        <i class="fas fa-plus"></i>
                        Create Team
                    </button>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if (!empty($success)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="team-grid">
            <?php foreach ($teams as $team): ?>
                <?php
                // fetch members for this team
                $mstmt = $pdo->prepare("SELECT username FROM users WHERE team_id = ?");
                $mstmt->execute([$team['id']]);
                $members = $mstmt->fetchAll();
                ?>
                <div class="team-card hover-lift">
                    <div class="team-header">
                        <div class="team-avatar">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="team-info">
                            <h3><?php echo htmlspecialchars($team['name']); ?></h3>
                            <div class="team-meta">
                                <span>
                                    <i class="fas fa-crown"></i>
                                    <?php echo htmlspecialchars($team['captain_name'] ?? '—'); ?>
                                </span>
                                <span>
                                    <i class="fas fa-user-friends"></i>
                                    <?php echo (int)$team['member_count']; ?> members
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="team-members">
                        <?php foreach ($members as $member): ?>
                            <div class="team-member">
                                <div class="member-avatar">
                                    <i class="fas fa-user"></i>
                                </div>
                                <div class="member-info">
                                    <div class="member-name"><?php echo htmlspecialchars($member['username']); ?></div>
                                    <div class="member-role">
                                        <?php echo ($member['username'] === $team['captain_name']) ? 'Team Captain' : 'Team Member'; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="team-actions">
                        <?php if (empty($_SESSION['team_id'])): ?>
                            <a href="teams.php?join=<?php echo $team['id']; ?>" 
                               class="btn btn-primary"
                               onclick="return confirm('Are you sure you want to join this team?')">
                                <i class="fas fa-sign-in-alt"></i>
                                Join Team
                            </a>
                        <?php elseif ($_SESSION['team_id'] == $team['id']): ?>
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle"></i>
                                Your Team
                            </span>
                        <?php endif; ?>
                        <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $team['captain_id']): ?>
                            <a href="manage_team.php?id=<?php echo $team['id']; ?>" class="btn btn-secondary">Manage</a>
                        <?php endif; ?>

                        <?php // Show "Request Leave" for members who are in this team but not the captain ?>
                        <?php if (isset($_SESSION['user_id']) && isset($_SESSION['team_id']) && $_SESSION['team_id'] == $team['id'] && $_SESSION['user_id'] != $team['captain_id']): ?>
                            <button class="btn btn-warning request-leave-btn" data-team-id="<?php echo $team['id']; ?>">
                                <i class="fas fa-sign-out-alt"></i>
                                Request Leave
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Create Team Modal -->
        <div id="createTeamModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>Create Team</h2>
                    <button onclick="toggleModal('createTeamModal')" class="close-btn">&times;</button>
                </div>
                <form method="POST" class="modal-form" id="createTeamForm">
                    <div class="form-group">
                        <label>Team Name</label>
                        <input type="text" name="team_name" required 
                               placeholder="Team Phoenix" 
                               data-label="Team name"
                               data-tooltip="Choose a unique name for your team">
                    </div>
                    <div class="modal-actions">
                        <button type="submit" name="create_team" class="btn btn-primary" id="createTeamBtn">
                            <i class="fas fa-users"></i>
                            Create Team
                        </button>
                        <button type="button" onclick="toggleModal('createTeamModal')" class="btn btn-secondary">
                            <i class="fas fa-times"></i>
                            Cancel
                        </button>
                    </div>
                </form>
                
                <script src="assets/js/utils.js"></script>
                <script>
                document.getElementById('createTeamForm').addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    if (FormValidator.validateForm(this)) {
                        const teamName = this.querySelector('[name="team_name"]').value;
                        
                        Confirm.show(
                            `Are you sure you want to create team "${teamName}"? You'll be set as the team captain.`,
                            () => {
                                const button = document.getElementById('createTeamBtn');
                                LoadingState.show(button);
                                
                                fetch(window.location.href, {
                                    method: 'POST',
                                    body: new FormData(this)
                                })
                                .then(response => response.text())
                                .then(html => {
                                    if (html.includes('Team created successfully')) {
                                        Toast.success('Team created successfully!');
                                        setTimeout(() => window.location.reload(), 1000);
                                    } else {
                                        const parser = new DOMParser();
                                        const doc = parser.parseFromString(html, 'text/html');
                                        const error = doc.querySelector('.alert-error')?.textContent;
                                        if (error) {
                                            Toast.error(error);
                                        }
                                        LoadingState.hide(button);
                                    }
                                })
                                .catch(error => {
                                    Toast.error('An error occurred. Please try again.');
                                    LoadingState.hide(button);
                                });
                            }
                        );
                    }
                });
                
                // Add confirmations to join team buttons
                document.querySelectorAll('a[href^="teams.php?join="]').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const teamName = this.closest('.team-card').querySelector('h3').textContent;
                        
                        Confirm.show(
                            `Are you sure you want to join team "${teamName}"?`,
                            () => {
                                LoadingState.show(this);
                                window.location.href = this.href;
                            }
                        );
                    });
                });
                </script>
            </div>
            </div>
        </div>

        <script>
        // Request Leave button handler
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.request-leave-btn').forEach(btn => {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const teamId = this.dataset.teamId;
                    const card = this.closest('.team-card');
                    const teamName = card ? card.querySelector('h3')?.textContent : 'your team';

                    Confirm.show(
                        `Request to leave team "${teamName}"?`,
                        () => {
                            LoadingState.show(btn);
                            const fd = new FormData();
                            fd.append('type', 'team');
                            fd.append('team_id', teamId);

                            fetch('tools/request_leave.php', {
                                method: 'POST',
                                body: fd,
                                headers: { 'X-Requested-With': 'XMLHttpRequest' }
                            })
                            .then(resp => resp.json())
                            .then(json => {
                                if (json && json.success) {
                                    Toast.success(json.message || 'Leave request submitted');
                                    setTimeout(() => window.location.reload(), 900);
                                } else {
                                    Toast.error(json.message || 'Failed to submit leave request');
                                    LoadingState.hide(btn);
                                }
                            })
                            .catch(err => {
                                console.error(err);
                                Toast.error('Network error. Please try again.');
                                LoadingState.hide(btn);
                            });
                        }
                    );
                });
            });
        });
        </script>

        <script src="assets/js/script.js"></script>
</body>
</html>
