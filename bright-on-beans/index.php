<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Auth functions fallback if needed
if (file_exists(__DIR__ . '/includes/auth.php')) {
    require_once __DIR__ . '/includes/auth.php';
} else {
    function isLoggedIn()
    {
        return isset($_SESSION['user_id']);
    }
    function getCurrentUserRole()
    {
        return $_SESSION['role'] ?? null;
    }
    function generateCSRFToken()
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function csrfField()
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
    }
    function loginUser($user)
    {
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
    }
}

// Redirect if already logged in
if (isLoggedIn()) {
    $role = getCurrentUserRole();
    if ($role === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/staff/dashboard.php');
    }
    exit;
}

$error = '';
$username = '';

// Lockout configuration (matches Flowchart Figure 1: 3 attempts, 3 minutes lockout)
$lockoutDuration = 180; // 3 minutes in seconds
$remainingLockout = 0;

if (isset($_SESSION['lockout_until'])) {
    if (time() < $_SESSION['lockout_until']) {
        $remainingLockout = $_SESSION['lockout_until'] - time();
    } else {
        unset($_SESSION['lockout_until']);
        $_SESSION['login_attempts'] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($remainingLockout > 0) {
        $error = 'You are temporarily blocked for 3 minutes due to multiple failed login attempts.';
    } else {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $csrf = $_POST['csrf_token'] ?? '';

        if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
            $error = 'Invalid request. Please try again.';
        } elseif (empty($username) || empty($password)) {
            $error = 'Both username and password are required.';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] === 'active') {
                    // Reset attempts upon successful login
                    $_SESSION['login_attempts'] = 0;
                    unset($_SESSION['lockout_until']);

                    loginUser($user);
                    if ($user['role'] === 'admin') {
                        header('Location: ' . BASE_URL . '/admin/dashboard.php');
                    } else {
                        header('Location: ' . BASE_URL . '/staff/dashboard.php');
                    }
                    exit;
                } else {
                    $error = 'Your account is inactive. Please contact administrator.';
                }
            } else {
                // Failed attempt tracking
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                $currentAttempts = $_SESSION['login_attempts'];

                if ($currentAttempts >= 3) {
                    $_SESSION['lockout_until'] = time() + $lockoutDuration;
                    $remainingLockout = $lockoutDuration;
                    $error = 'You are temporarily blocked for 3 mins due to 3 failed attempts.';
                } else {
                    $attemptsLeft = 3 - $currentAttempts;
                    $error = "Invalid username or password. ({$attemptsLeft} attempt" . ($attemptsLeft === 1 ? '' : 's') . " remaining)";
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — Bright' on Beans</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <style>
        body.auth-page {
            background: linear-gradient(135deg, #613E28 0%, #462A1A 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Inter', -apple-system, sans-serif;
            margin: 0;
            padding: 1.5rem;
        }

        .auth-card-custom {
            background: #FFFFFF;
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
            padding: 2.25rem 2rem;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.25);
            text-align: center;
        }

        .brand-logo-icon {
            font-size: 2.8rem;
            line-height: 1;
            margin-bottom: 0.5rem;
            display: block;
        }

        .auth-title {
            font-size: 1.4rem;
            font-weight: 700;
            color: #3C2415;
            margin: 0 0 2px 0;
        }

        .auth-subtitle {
            font-size: 0.85rem;
            color: #8C7867;
            margin-bottom: 1.75rem;
        }

        .auth-form-group {
            text-align: left;
            margin-bottom: 1.15rem;
        }

        .auth-label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #3C2415;
            margin-bottom: 0.4rem;
        }

        .auth-input {
            width: 100%;
            padding: 0.7rem 1rem;
            font-size: 0.9rem;
            border: 1px solid #D2C6B9;
            border-radius: 8px;
            background: #FAF7F2;
            outline: none;
            box-sizing: border-box;
            transition: border-color 0.2s;
        }

        .auth-input:focus {
            border-color: #5A3A28;
            background: #FFF;
        }

        .btn-signin {
            width: 100%;
            padding: 0.75rem;
            font-size: 0.95rem;
            font-weight: 600;
            color: #FFFFFF;
            background: #4A2E1C;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 0.5rem;
        }

        .btn-signin:hover {
            background: #382113;
        }

        .auth-links-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 1.25rem;
            font-size: 0.85rem;
        }

        .auth-link-item {
            color: #6E5C4F;
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: color 0.15s;
        }

        .auth-link-item:hover {
            color: #3C2415;
            text-decoration: underline;
        }
    </style>
</head>

<body class="auth-page">
    <div class="auth-card-custom">
        <!-- Logo -->
        <div class="brand-logo-icon">☕</div>

        <!-- Header -->
        <h2 class="auth-title">Bright on Beans</h2>
        <div class="auth-subtitle">Inventory Sales Tracking System</div>

        <!-- Error & Flash Alerts -->
        <?php if ($remainingLockout > 0): ?>
            <div id="lockout-banner" class="alert alert-error" style="text-align: center; margin-bottom: 1.25rem; border-radius: 8px; font-size: 0.9rem; padding: 0.85rem 1rem; background: #FDECEE; color: #C62828; border: 1px solid #F5C2C7; font-weight: 500;">
                <div style="font-weight: 700; margin-bottom: 4px;">⚠️ Temporarily Blocked</div>
                <div>You are temporarily blocked for 3 mins.</div>
                <div style="margin-top: 6px; font-size: 1.1rem; font-weight: 700; color: #8C1D18;">
                    Remaining Time: <span id="timer-countdown">--:--</span>
                </div>
            </div>
        <?php elseif ($error): ?>
            <div class="alert alert-error" style="text-align: left; margin-bottom: 1.25rem; border-radius: 8px; font-size: 0.85rem; padding: 0.65rem 0.9rem; background: #FDECEE; color: #E53935; border: 1px solid #F5C2C7;">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php renderFlashMessage(); ?>

        <!-- Form -->
        <form method="POST" action="">
            <?= csrfField() ?>

            <div class="auth-form-group">
                <label class="auth-label" for="username">Username</label>
                <input type="text" id="username" name="username" class="auth-input" placeholder="Enter your username" value="<?= e($username) ?>" required <?= $remainingLockout > 0 ? 'disabled' : '' ?>>
            </div>

            <div class="auth-form-group">
                <label class="auth-label" for="password">Password</label>
                <input type="password" id="password" name="password" class="auth-input" placeholder="Enter your password" required <?= $remainingLockout > 0 ? 'disabled' : '' ?>>
            </div>

            <button type="submit" id="submit-btn" class="btn-signin" <?= $remainingLockout > 0 ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : '' ?>>Sign In</button>
        </form>

        <!-- Bottom Links -->
        <div class="auth-links-row">
            <a href="<?= BASE_URL ?>/auth/forgot-password.php" class="auth-link-item">
                🔑 Forgot Password?
            </a>
            <a href="<?= BASE_URL ?>/auth/register.php" class="auth-link-item">
                📝 Register
            </a>
        </div>
    </div>

    <?php if ($remainingLockout > 0): ?>
    <script>
        let secondsLeft = <?= (int)$remainingLockout ?>;
        const timerDisplay = document.getElementById('timer-countdown');
        const submitBtn = document.getElementById('submit-btn');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const lockoutBox = document.getElementById('lockout-banner');

        function updateTimer() {
            const mins = Math.floor(secondsLeft / 60);
            const secs = secondsLeft % 60;
            if (timerDisplay) {
                timerDisplay.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
            }
            if (secondsLeft <= 0) {
                clearInterval(timerInterval);
                if (lockoutBox) {
                    lockoutBox.style.background = '#E8F5E9';
                    lockoutBox.style.color = '#2E7D32';
                    lockoutBox.style.borderColor = '#A5D6A7';
                    lockoutBox.innerHTML = '<div style="font-weight:700;">✅ Block Expired</div><div>You may now sign in.</div>';
                }
                if (usernameInput) usernameInput.disabled = false;
                if (passwordInput) passwordInput.disabled = false;
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.style.opacity = '1';
                    submitBtn.style.cursor = 'pointer';
                }
            } else {
                secondsLeft--;
            }
        }
        updateTimer();
        const timerInterval = setInterval(updateTimer, 1000);
    </script>
    <?php endif; ?>
</body>

</html>