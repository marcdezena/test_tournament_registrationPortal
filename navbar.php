<?php
// navbar with notifications
require_once __DIR__ . '/config.php';
$unreadCount = 0;
$notifications = [];
if (isLoggedIn()) {
    try {
        // prefer read_flag column name if present
        $colStmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications'");
        $colStmt->execute();
        $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);
        $readCol = in_array('read_flag', $cols) ? 'read_flag' : (in_array('is_read', $cols) ? 'is_read' : 'read_flag');

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND $readCol = 0");
        $countStmt->execute([$_SESSION['user_id']]);
        $unreadCount = (int)$countStmt->fetchColumn();

        $notifStmt = $pdo->prepare("SELECT id, type, payload, $readCol as is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
        $notifStmt->execute([$_SESSION['user_id']]);
        $notifications = $notifStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $unreadCount = 0;
        $notifications = [];
    }
}
?>

<nav class="navbar" aria-label="Main navigation">
    <div class="nav-container">
        <a href="index.php" class="nav-brand">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24">
                <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            <span>Tournament Portal</span>
        </a>

        <button class="nav-toggle" aria-expanded="false" aria-controls="primary-navigation" aria-label="Toggle navigation">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
        </button>

        <div id="primary-navigation" class="nav-links" role="navigation" aria-label="Primary">
            <a href="index.php" class="<?= basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '' ?>">Home</a>
            <a href="tournaments.php" class="<?= basename($_SERVER['PHP_SELF']) === 'tournaments.php' ? 'active' : '' ?>">Tournaments</a>
            <a href="teams.php" class="<?= basename($_SERVER['PHP_SELF']) === 'teams.php' ? 'active' : '' ?>">Teams</a>
            <a href="dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="account.php" class="<?= basename($_SERVER['PHP_SELF']) === 'account.php' ? 'active' : '' ?>">Account</a>
            <?php if (isLoggedIn()): ?>
                <div class="nav-notifications">
                    <button id="notifToggle" class="btn btn-icon" aria-haspopup="true" aria-expanded="false" onclick="toggleNotifs()">
                        <i class="fas fa-bell"></i>
                        <?php if ($unreadCount > 0): ?>
                            <span class="badge"><?php echo $unreadCount; ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notifDropdown" class="notif-dropdown" role="menu" aria-hidden="true" style="display:none">
                        <div class="notif-header">Notifications</div>
                        <div class="notif-list">
                            <?php if (empty($notifications)): ?>
                                <div class="notif-empty">No notifications</div>
                            <?php else: ?>
                                <?php foreach ($notifications as $n): ?>
                                    <?php
                                        $payload = json_decode($n['payload'], true);
                                        $msg = $payload['message'] ?? ($n['type'] . ' event');
                                        $link = 'reports.php';
                                        if (!empty($payload['report_id'])) $link = 'reports.php?type=overview&highlight=' . (int)$payload['report_id'];
                                    ?>
                                    <a href="<?php echo $link; ?>" class="notif-item <?php echo $n['is_read'] ? 'read' : 'unread'; ?>" data-id="<?php echo $n['id']; ?>">
                                        <div class="notif-msg"><?php echo htmlspecialchars($msg); ?></div>
                                        <div class="notif-time"><?php echo date('Y-m-d H:i', strtotime($n['created_at'])); ?></div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="notif-actions">
                            <button class="btn btn-link" onclick="markAllNotifsRead()">Mark all read</button>
                            <a class="btn btn-link" href="manage_leave_requests.php">View requests</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <a href="logout.php" class="btn btn-outline">Logout</a>
        </div>
    </div>
</nav>

<style>
.nav-notifications { position: relative; display:inline-block; }
.notif-dropdown { position: absolute; right: 0; top: 40px; width: 320px; background: white; border: 1px solid var(--border); border-radius:6px; box-shadow: 0 6px 24px rgba(0,0,0,0.12); z-index:2000; }
.notif-list { max-height: 320px; overflow:auto; }
.notif-item { display:block; padding:10px; border-bottom:1px solid var(--border); text-decoration:none; color:inherit; }
.notif-item.unread { background: rgba(0,123,255,0.04); }
.badge { background: var(--accent); color:white; border-radius:12px; padding:2px 6px; font-size:12px; vertical-align:top; margin-left:6px; }
</style>

<script>
function toggleNotifs(){
    const d = document.getElementById('notifDropdown');
    if (d.style.display === 'none' || d.style.display === ''){
        d.style.display = 'block';
        document.getElementById('notifToggle').setAttribute('aria-expanded','true');
    } else {
        d.style.display = 'none';
        document.getElementById('notifToggle').setAttribute('aria-expanded','false');
    }
}

function markAllNotifsRead(){
    fetch('tools/mark_notifications_read.php', { method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'} })
    .then(r => r.json()).then(j => {
        if (j.success) {
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            const b = document.querySelector('.badge'); if (b) b.remove();
        } else {
            alert('Failed to mark notifications read');
        }
    }).catch(() => alert('Network error'));
}
</script>
