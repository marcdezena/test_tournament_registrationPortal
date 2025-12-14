<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'All fields are required';
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['is_admin'] = $user['is_admin'];
                $_SESSION['team_id'] = $user['team_id'];
                redirect('index.php');
            } else {
                $error = 'Invalid username or password';
            }
        } catch(PDOException $e) {
            $error = 'Login failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Tournament Portal</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-success" role="status" aria-live="polite"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div role="alert" class="alert alert-error" aria-live="assertive"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="auth-card">
            <div class="auth-header">
                <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <circle cx="12" cy="8" r="3"/>
                </svg>
                <h1>Tournament Portal</h1>
                <p>Sign in to compete</p>
            </div>
            
            <!-- errors and flashes are displayed above the form for better visibility -->
            
            <form method="POST" class="auth-form" novalidate>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" required placeholder="e.g. gamer123" autocomplete="username">
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required placeholder="Your password" autocomplete="current-password">
                </div>
                
                <button type="submit" class="btn btn-primary btn-block">Sign In</button>
            </form>
            
            <div class="auth-footer">
                <a href="register.php">Don't have an account? Register</a>
            </div>
        </div>
    </div>
</body>
</html>