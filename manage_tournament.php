<?php
require_once 'config.php';
if (!isLoggedIn()) redirect('login.php');

$tid = intval($_GET['id'] ?? 0);
if (!$tid) redirect('tournaments.php');

// Role check
$is_admin = isAdmin();

$stmt = $pdo->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tid]);
$t = $stmt->fetch();
if (!$t) redirect('tournaments.php');
if (!$is_admin && $t['created_by'] != $_SESSION['user_id']) redirect('tournaments.php');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Handle approve/reject actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash'] = 'Invalid request token';
        redirect("manage_tournament.php?id={$tid}");
    }

    $action = $_POST['action'] ?? '';
    $reg_id = intval($_POST['reg_id'] ?? 0);

    try {
        if ($action === 'approve') {
            $pdo->prepare("UPDATE registrations SET status = 'approved' WHERE id = ?")->execute([$reg_id]);
            $_SESSION['flash'] = 'Participant approved';
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE registrations SET status = 'rejected' WHERE id = ?")->execute([$reg_id]);
            $_SESSION['flash'] = 'Participant rejected';
        }
    } catch (PDOException $e) {
        $_SESSION['flash'] = 'Action failed: ' . $e->getMessage();
    }
    redirect("manage_tournament.php?id={$tid}");
}

// Fetch registrations for this tournament
$regs = [];
try {
    $regsStmt = $pdo->prepare("
        SELECT r.*, u.username, t.name as team_name
        FROM registrations r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN teams t ON r.team_id = t.id
        WHERE r.tournament_id = ?
        ORDER BY r.registered_at ASC
    ");
    $regsStmt->execute([$tid]);
    $regs = $regsStmt->fetchAll();
} catch (PDOException $e) {
    $regs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Tournament - <?php echo htmlspecialchars($t['name']); ?></title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <!-- styles moved to assets/css/styles.css -->
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="breadcrumbs">
            <div class="breadcrumb-item">
                <a href="index.php">Home</a>
            </div>
            <div class="breadcrumb-item">
                <a href="tournaments.php">Tournaments</a>
            </div>
            <div class="breadcrumb-item active">
                Manage <?php echo htmlspecialchars($t['name']); ?>
            </div>
        </div>

        <div class="page-header">
            <div class="header-left">
                <h1>Manage Tournament</h1>
                <h3><?php echo htmlspecialchars($t['name']); ?></h3>
            </div>
            <div class="header-right">
                <a href="bracket.php?id=<?php echo $tid; ?>" class="btn btn-outline-primary">
                    <i class="fas fa-trophy"></i> View Bracket
                </a>
                <?php if ($is_admin || $t['created_by'] == $_SESSION['user_id']): ?>
                <a href="manage_leave_requests.php?tournament_id=<?php echo $tid; ?>" class="btn btn-outline-warning" style="margin-left:8px">
                    <i class="fas fa-flag"></i> Manage Leave Requests
                </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Participants <?php echo "(" . htmlspecialchars($t['type']) . ")"; ?></h3>
            <?php if ($regs): ?>
                <table class="reg-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <?php if ($t['type'] === 'individual'): ?>
                                <th>Player</th>
                            <?php else: ?>
                                <th>Team</th>
                                <th>Team Captain</th>
                            <?php endif; ?>
                            <th>Registered At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($regs as $r): ?>
                        <tr>
                            <td><?php echo (int)$r['id']; ?></td>
                            <?php if ($t['type'] === 'individual'): ?>
                                <td><?php echo htmlspecialchars($r['username'] ?? '—'); ?></td>
                            <?php else: ?>
                                <td><?php echo htmlspecialchars($r['team_name'] ?? '—'); ?></td>
                                <td>
                                    <?php
                                    // If team tournament, fetch captain name for team_id
                                    if ($r['team_id']) {
                                        $stmt2 = $pdo->prepare("SELECT u.username FROM users u JOIN teams t2 ON t2.captain_id = u.id WHERE t2.id = ?");
                                        $stmt2->execute([$r['team_id']]);
                                        $capt = $stmt2->fetch();
                                        echo htmlspecialchars($capt['username'] ?? '—');
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            <?php endif; ?>
                            <td><?php echo htmlspecialchars($r['registered_at'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($r['status'] ?? 'pending'); ?></td>
                            <td class="action-buttons">
                                <?php if (($r['status'] ?? '') !== 'approved'): ?>
                                    <form method="POST" class="action-form" data-loading-text="Approving..." style="display:inline-block">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button class="btn btn-primary" type="submit" 
                                                onclick="return Confirm.show(
                                                    'Approve Participant', 
                                                    'Are you sure you want to approve this participant?',
                                                    () => { LoadingState.start(this); return true; }
                                                )">
                                            <span class="btn-text">
                                                <i class="fas fa-check"></i> Approve
                                            </span>
                                            <span class="loading-spinner"></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (($r['status'] ?? '') !== 'rejected'): ?>
                                    <form method="POST" class="action-form" data-loading-text="Rejecting..." style="display:inline-block;margin-left:6px">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="reg_id" value="<?php echo (int)$r['id']; ?>">
                                        <input type="hidden" name="action" value="reject">
                                        <button class="btn btn-danger" type="submit"
                                                onclick="return Confirm.show(
                                                    'Reject Participant',
                                                    'Are you sure you want to reject this participant? They will need to re-register if you change your mind.',
                                                    () => { LoadingState.start(this); return true; }
                                                )">
                                            <span class="btn-text">
                                                <i class="fas fa-times"></i> Reject
                                            </span>
                                            <span class="loading-spinner"></span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="muted">No participants yet.</p>
            <?php endif; ?>
        </div>

        <div class="section-nav">
            <a class="btn btn-link" href="tournaments.php">
                <i class="fas fa-arrow-left"></i> Back to tournaments
            </a>
        </div>
    </div>
    <script src="assets/js/script.js"></script>
    <script src="assets/js/utils.js"></script>
</body>
</html>