<?php
require_once 'config.php';

// Initialize
$error = '';
$success = '';
$username = '';
$email = '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Populate old values for re-render
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    // CSRF check
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid request token. Please refresh the page and try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validation
        if (empty($username) || empty($email) || empty($password)) {
            $error = 'All fields are required';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format';
        } else {
            try {
                // Check if username exists
                $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $error = 'Username already exists';
                } else {
                    // Check if email exists
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = 'Email already registered';
                    } else {
                        // Insert new user
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
                        $stmt->execute([$username, $email, $hashed_password]);

                        // Success handling: JSON for AJAX, otherwise redirect with flash
                        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'message' => 'Registration successful']);
                            exit();
                        } else {
                            $_SESSION['flash'] = 'Registration successful! Please login.';
                            redirect('login.php');
                        }
                    }
                }
            } catch (PDOException $e) {
                // In dev you might log $e->getMessage() to a file; return friendly message to user
                $error = 'Registration failed. Please try again later.';
            }
        }
    }

    // If AJAX and error, return JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        if (!empty($error)) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Tournament Portal</title>
    <link rel="stylesheet" href="assets/css/styles.css?v=<?php echo filemtime(__DIR__ . '/assets/css/styles.css'); ?>">
</head>
<body class="auth-page">
    <div class="auth-container">
        <?php if (!empty($_SESSION['flash'])): ?>
            <div class="alert alert-success" role="status" aria-live="polite"><?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?></div>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error" role="alert" aria-live="assertive"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="auth-card">
            <div class="auth-header">
                <svg class="auth-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <h1>Create Account</h1>
                <p>Join the competition</p>
            </div>

            <form method="POST" class="auth-form" novalidate id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <div class="form-group">
                    <label for="username">Username</label>
                    <input id="username" type="text" name="username" required 
                           data-label="Username" placeholder="Choose a username" 
                           autocomplete="username"
                           data-tooltip="Choose a unique username for your profile"
                           value="<?php echo htmlspecialchars($username); ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input id="email" type="email" name="email" required 
                           data-label="Email" placeholder="you@example.com" 
                           autocomplete="email"
                           data-tooltip="We'll send important tournament updates here"
                           value="<?php echo htmlspecialchars($email); ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required 
                           data-label="Password" minlength="8" 
                           placeholder="Minimum 8 characters" 
                           autocomplete="new-password"
                           data-tooltip="Use at least 8 characters with letters and numbers">
                    <div class="password-strength" aria-hidden="true"></div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <input id="confirm_password" type="password" name="confirm_password" 
                           required data-label="Password confirmation" 
                           placeholder="Re-enter password" 
                           autocomplete="new-password">
                </div>

                <button type="submit" class="btn btn-primary btn-block" id="registerButton">Register</button>
            </form>

            <script src="assets/js/utils.js"></script>
            <script>
                (function(){
                    const form = document.getElementById('registerForm');
                    const button = document.getElementById('registerButton');

                    form.addEventListener('submit', function(e) {
                        e.preventDefault();

                        // Client-side validation
                        if (!FormValidator.validateForm(this)) {
                            // focus first invalid field
                            const firstInvalid = this.querySelector('.field-error, :invalid');
                            if (firstInvalid) firstInvalid.focus();
                            return;
                        }

                        LoadingState.show(button);

                        // Submit via AJAX for better UX; server will also handle non-AJAX
                        fetch(window.location.href, {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: new FormData(this)
                        })
                        .then(response => {
                            const ct = response.headers.get('content-type') || '';
                            if (ct.includes('application/json')) return response.json();
                            return response.text();
                        })
                        .then(result => {
                            if (typeof result === 'object') {
                                if (result.success) {
                                    Toast.success(result.message + ' Redirecting...');
                                    setTimeout(() => window.location.href = 'login.php', 1200);
                                    return;
                                } else {
                                    Toast.error(result.message || 'Registration failed');
                                    // focus first input for convenience
                                    form.querySelector('input[type="text"], input[type="email"], input[type="password"]').focus();
                                    LoadingState.hide(button);
                                    return;
                                }
                            }

                            // Fallback: server returned HTML - check for success string
                            if (typeof result === 'string' && result.indexOf('Registration successful') !== -1) {
                                Toast.success('Registration successful! Redirecting to login...');
                                setTimeout(() => window.location.href = 'login.php', 1200);
                                return;
                            }

                            // Otherwise, try to extract error text from returned HTML
                            if (typeof result === 'string') {
                                const parser = new DOMParser();
                                const doc = parser.parseFromString(result, 'text/html');
                                const errorText = doc.querySelector('.alert-error')?.textContent;
                                if (errorText) Toast.error(errorText);
                                else Toast.error('Registration failed.');
                            }

                            LoadingState.hide(button);
                        })
                        .catch(err => {
                            Toast.error('An error occurred. Please try again.');
                            console.error(err);
                            LoadingState.hide(button);
                        });
                    });

                    // Password strength indicator
                    const passwordInput = document.getElementById('password');
                    const strengthDiv = document.querySelector('.password-strength');

                    if (passwordInput) {
                        passwordInput.addEventListener('input', function() {
                            const password = this.value;
                            let strength = 0;
                            if (password.length >= 8) strength++;
                            if (/[A-Z]/.test(password)) strength++;
                            if (/[a-z]/.test(password)) strength++;
                            if (/[0-9]/.test(password)) strength++;
                            if (/[^A-Za-z0-9]/.test(password)) strength++;

                            let message = '';
                            switch(strength) {
                                case 0:
                                case 1:
                                    message = '<span style="color: var(--danger)">Weak</span>';
                                    break;
                                case 2:
                                case 3:
                                    message = '<span style="color: var(--accent-2)">Medium</span>';
                                    break;
                                case 4:
                                case 5:
                                    message = '<span style="color: var(--success)">Strong</span>';
                                    break;
                            }

                            strengthDiv.innerHTML = message ? `Password strength: ${message}` : '';
                        });
                    }
                })();
            </script>

            <div class="auth-footer">
                <a href="login.php">Already have an account? Sign in</a>
            </div>
        </div>
    </div>
</body>
</html>