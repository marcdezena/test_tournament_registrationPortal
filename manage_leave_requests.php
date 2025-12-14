<?php
require_once 'config.php';

if (!isLoggedIn()) redirect('login.php');

$tournament_id = isset($_GET['tournament_id']) ? intval($_GET['tournament_id']) : 0;
$is_admin = isAdmin();

// Permission: if filtering by tournament, allow only admins or tournament creator
if ($tournament_id) {
    $stmt = $pdo->prepare("SELECT id, created_by, name FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $tour = $stmt->fetch();
    if (!$tour) redirect('tournaments.php');
    if (!$is_admin && $tour['created_by'] != $_SESSION['user_id']) redirect('tournaments.php');
} else {
    // viewing all requests requires admin
    if (!$is_admin) redirect('index.php');
}

// CSRF
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));

// Handle approve/deny POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['flash'] = 'Invalid request token';
        redirect($_SERVER['REQUEST_URI']);
    }

    $action = $_POST['action'] ?? '';
    $req_id = intval($_POST['request_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM leave_requests WHERE id = ? FOR UPDATE");
        $stmt->execute([$req_id]);
        $req = $stmt->fetch();
        if (!$req) {
            throw new Exception('Request not found');
        }

        if ($action === 'approve') {
            // Mark request approved
            $pdo->prepare("UPDATE leave_requests SET status = 'approved' WHERE id = ?")->execute([$req_id]);

            // For tournament quits, withdraw matching registrations
            if ($req['type'] === 'tournament' && $req['tournament_id']) {
                // Find registrations for this user or team
                $regsStmt = $pdo->prepare("SELECT id FROM registrations WHERE tournament_id = ? AND (user_id = ? OR team_id = ?)");
                $regsStmt->execute([$req['tournament_id'], $req['user_id'], $req['team_id']]);
                $regs = $regsStmt->fetchAll();
                $regIds = array_column($regs, 'id');

                if (!empty($regIds)) {
                    // Withdraw registrations
                    $in = implode(',', array_fill(0, count($regIds), '?'));
                    $updParams = array_merge(['withdrawn'], $regIds);
                    $pdo->prepare("UPDATE registrations SET status = 'withdrawn' WHERE id IN ($in)")->execute($regIds);

                    // Remove participants from matches referencing these registration ids
                    $placeholders = implode(',', array_fill(0, count($regIds), '?'));
                    $params = array_merge($regIds, $regIds, $regIds, [$req['tournament_id']]);
                    $sql = "UPDATE matches SET 
                        participant1_id = CASE WHEN participant1_id IN ($placeholders) THEN NULL ELSE participant1_id END,
                        participant2_id = CASE WHEN participant2_id IN ($placeholders) THEN NULL ELSE participant2_id END,
                        winner_id = CASE WHEN winner_id IN ($placeholders) THEN NULL ELSE winner_id END
                        WHERE tournament_id = ?";
                    $pdo->prepare($sql)->execute($params);
                }
            }

            $_SESSION['flash'] = 'Request approved';

        } elseif ($action === 'deny') {
            $pdo->prepare("UPDATE leave_requests SET status = 'denied' WHERE id = ?")->execute([$req_id]);
            $_SESSION['flash'] = 'Request denied';
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['flash'] = 'Action failed: ' . $e->getMessage();
    }

    // Redirect back to list
    redirect('manage_leave_requests.php' . ($tournament_id ? '?tournament_id=' . $tournament_id : ''));
}

// Fetch requests
$sql = "SELECT lr.*, u.username, t.name as team_name, tour.name as tournament_name
        FROM leave_requests lr
        LEFT JOIN users u ON lr.user_id = u.id
        LEFT JOIN teams t ON lr.team_id = t.id
        LEFT JOIN tournaments tour ON lr.tournament_id = tour.id";
$params = [];
if ($tournament_id) {
    $sql .= " WHERE lr.tournament_id = ?";
    $params[] = $tournament_id;
}
$sql .= " ORDER BY lr.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Manage Leave Requests</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body>
    <?php include 'navbar.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1>Leave / Quit Requests</h1>
            <?php if ($tournament_id): ?>
                <h3>For: <?php echo htmlspecialchars($tour['name']); ?></h3>
            <?php endif; ?>
        </div>

        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
        <?php endif; ?>

        <?php if (empty($requests)): ?>
            <p class="muted">No leave/quit requests found.</p>
        <?php else: ?>
            <table class="reg-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Team</th>
                        <th>Tournament</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo htmlspecialchars($r['username'] ?? ''); ?></td>
                        <td><?php echo htmlspecialchars($r['team_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['tournament_name'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($r['type']); ?></td>
                        <td><?php echo nl2br(htmlspecialchars($r['message'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars($r['status'] ?? 'pending'); ?></td>
                        <td>
                            <?php if (($r['status'] ?? 'pending') === 'pending'): ?>
                                <form method="POST" style="display:inline-block">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <button class="btn btn-primary" type="submit" onclick="return confirm('Approve this request?')">Approve</button>
                                </form>
                                <form method="POST" style="display:inline-block;margin-left:6px">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <input type="hidden" name="action" value="deny">
                                    <button class="btn btn-danger" type="submit" onclick="return confirm('Deny this request?')">Deny</button>
                                </form>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <div class="section-nav" style="margin-top:16px">
            <a class="btn btn-link" href="tournaments.php"><i class="fas fa-arrow-left"></i> Back to tournaments</a>
        </div>
    </div>
    <script src="assets/js/utils.js"></script>
    <script src="assets/js/script.js"></script>
</body>
</html>
