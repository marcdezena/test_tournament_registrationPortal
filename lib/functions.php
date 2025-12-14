<?php
// Auth helpers
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function isAdmin(): bool {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
}

function redirect(string $path): void {
    // Whitelist allowed paths
    $allowed = ['login.php', 'index.php', 'dashboard.php', 'tournaments.php', 'teams.php', 'account.php', 'logout.php', 'register.php', 'bracket.php', 'reports.php', 'manage_tournament.php', 'manage_bracket.php', 'manage_leave_requests.php', 'manage_team.php', 'print_bracket.php', 'print_match.php', 'setup_database.php'];
    $file = basename($path);
    if (!in_array($file, $allowed, true)) {
        $path = 'index.php';
    }
    header("Location: $path");
    exit;
}

function csrf(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function logAction(string $action, array $data = []): void {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, payload) VALUES (?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'] ?? null, $action, json_encode($data)]);
    } catch (Throwable $e) {
        error_log("Log error: " . $e->getMessage());
    }
}

function canManageTournament(int $tournament_id): bool {
    global $pdo;
    $stmt = $pdo->prepare("SELECT created_by FROM tournaments WHERE id = ?");
    $stmt->execute([$tournament_id]);
    $t = $stmt->fetch();
    return $t && ((int)$t['created_by'] === ($_SESSION['user_id'] ?? -1));
}
