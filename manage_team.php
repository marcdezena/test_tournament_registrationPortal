
<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

$team_id = intval($_GET['id'] ?? 0);
if (!$team_id) redirect('teams.php');

// Verify current user is captain of the team
$stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
$stmt->execute([$team_id]);
$team = $stmt->fetch();
if (!$team) redirect('teams.php');
if ($team['captain_id'] != $_SESSION['user_id']) redirect('teams.php');

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

// Handle remove member action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash'] = 'Invalid request token';
        redirect("manage_team.php?id={$team_id}");
    }
    $remove_id = intval($_POST['remove_id'] ?? 0);
    if ($remove_id && $remove_id != $_SESSION['user_id']) {
        try {
            $pdo->prepare("UPDATE users SET team_id = NULL WHERE id = ?")->execute([$remove_id]);
            $_SESSION['flash'] = 'Member removed';
        } catch (PDOException $e) {
            $_SESSION['flash'] = 'Action failed: ' . $e->getMessage();
        }
    }
    redirect("manage_team.php?id={$team_id}");
}

// Load members
$mstmt = $pdo->prepare("SELECT id, username, email FROM users WHERE team_id = ?");
$mstmt->execute([$team_id]);
$members = $mstmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Team - <?php echo htmlspecialchars($team['name']); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="breadcrumbs">
            <div class="breadcrumb-item">
                <a href="index.php">Home</a>
            </div>
            <div class="breadcrumb-item">
                <a href="teams.php">Teams</a>
            </div>
            <div class="breadcrumb-item active">
                Manage Team
            </div>
        </div>

        <div class="page-header">
            <h1>Manage Team: <?php echo htmlspecialchars($team['name']); ?></h1>
        </div>
        <?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div><?php endif; ?>

        <div class="card">
            <h3>Members</h3>
            <ul>
                <?php foreach ($members as $m): ?>
                    <li>
                        <?php echo htmlspecialchars($m['username']); ?>
                        <?php if ($m['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" class="member-remove-form" style="display:inline;margin-left:8px">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="remove_id" value="<?php echo (int)$m['id']; ?>">
                                <button class="btn btn-danger" type="submit" data-username="<?php echo htmlspecialchars($m['username']); ?>">
                                    <i class="fas fa-user-minus"></i>
                                    Remove
                                </button>
                            </form>
                        <?php else: ?>
                            <span class="muted" data-tooltip="Team captain cannot be removed">
                                <i class="fas fa-crown"></i>
                                Captain
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <p style="margin-top:12px"><a class="btn" href="teams.php">Back to teams</a></p>
    </div>
    <script src="assets/js/script.js"></script>
    <script src="assets/js/utils.js"></script>
    <script>
    // Handle member removal with confirmation
    document.querySelectorAll('.member-remove-form').forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            const username = this.querySelector('button').dataset.username;
            const button = this.querySelector('button');
            
            Confirm.show(
                `Are you sure you want to remove ${username} from the team?<br><br>` +
                `<span style="color: var(--danger)">This action cannot be undone, and they will need to rejoin if removed.</span>`,
                () => {
                    LoadingState.show(button);
                    
                    fetch(window.location.href, {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(response => response.text())
                    .then(html => {
                        if (html.includes('Member removed')) {
                            Toast.success('Team member removed successfully');
                            // Remove the member's li element
                            this.closest('li').remove();
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
        });
    });

    // Add helpful tooltips for team management
    document.querySelectorAll('.btn').forEach(btn => {
        if (btn.classList.contains('btn-danger')) {
            btn.dataset.tooltip = "Remove this member from your team";
        }
    });

    // Initialize tooltips
    Tooltip.init();
    </script>
</body>
</html>
