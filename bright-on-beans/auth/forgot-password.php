<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
} else {
    function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    function csrfField() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';
    }
}

$error = '';
$success = '';
$step = $_SESSION['fp_step'] ?? 1;
$username = $_SESSION['fp_username'] ?? '';
$user_id = $_SESSION['fp_user_id'] ?? null;

$secret_questions = getSecretQuestions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (empty($csrf) || $csrf !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        if (isset($_POST['action']) && $_POST['action'] === 'restart') {
            unset($_SESSION['fp_step'], $_SESSION['fp_username'], $_SESSION['fp_user_id']);
            $step = 1;
            $username = '';
        } elseif ($step === 1) {
            $input_username = $_POST['username'] ?? '';
            if (empty($input_username)) {
                $error = 'Username is required.';
            } else {
                $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE username = ?");
                $stmt->execute([$input_username]);
                $user = $stmt->fetch();
                
                if ($user) {
                    // Check if user has secret questions
                    $stmt_sq = $pdo->prepare("SELECT COUNT(*) FROM secret_questions WHERE user_id = ?");
                    $stmt_sq->execute([$user['user_id']]);
                    if ($stmt_sq->fetchColumn() == 5) {
                        $_SESSION['fp_step'] = 2;
                        $_SESSION['fp_username'] = $user['username'];
                        $_SESSION['fp_user_id'] = $user['user_id'];
                        $step = 2;
                        $username = $user['username'];
                        $user_id = $user['user_id'];
                    } else {
                        $error = 'This account does not have security questions set up. Please contact an administrator.';
                    }
                } else {
                    $error = 'User Not Found.';
                }
            }
        } elseif ($step === 2) {
            $answers = $_POST['secret_answers'] ?? [];
            $all_filled = true;
            foreach ($secret_questions as $q_num => $q_text) {
                if (empty($answers[$q_num])) {
                    $all_filled = false;
                    break;
                }
            }
            
            if (!$all_filled) {
                $error = 'All security questions must be answered.';
            } else {
                $stmt = $pdo->prepare("SELECT question_number, answer FROM secret_questions WHERE user_id = ?");
                $stmt->execute([$user_id]);
                $db_answers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                $all_correct = true;
                foreach ($answers as $q_num => $answer) {
                    $normalized_answer = strtolower(trim($answer));
                    if (!isset($db_answers[$q_num]) || !password_verify($normalized_answer, $db_answers[$q_num])) {
                        $all_correct = false;
                        break;
                    }
                }
                
                if ($all_correct) {
                    $_SESSION['fp_step'] = 3;
                    $step = 3;
                } else {
                    $error = 'Answers do not match our records.';
                }
            }
        } elseif ($step === 3) {
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            $pwdErrors = validatePassword($password);
            if (empty($password) || empty($confirm_password)) {
                $error = 'Both password fields are required.';
            } elseif ($password !== $confirm_password) {
                $error = 'Passwords do not match.';
            } elseif (!empty($pwdErrors)) {
                $error = implode(' ', $pwdErrors);
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                if ($stmt->execute([$hashed_password, $user_id])) {
                    unset($_SESSION['fp_step'], $_SESSION['fp_username'], $_SESSION['fp_user_id']);
                    setFlashMessage('success', 'Password Successfully Changed. Please login with your new password.');
                    header('Location: ' . BASE_URL . '/index.php');
                    exit;
                } else {
                    $error = 'Failed to update password. Please try again.';
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
    <title>Forgot Password - Bright on Beans Coffee House</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .auth-card { max-width: 600px; }
        .password-reqs { font-size: 0.85em; color: #666; margin-top: 5px; }
    </style>
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>Reset Password</h2>
            <p>Recover access to your account</p>
        </div>
        <div class="auth-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>
            
            <?php if ($step > 1): ?>
                <div style="margin-bottom: 20px; font-size: 0.9em; text-align: right;">
                    <form method="POST" action="" style="display:inline;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="restart">
                        <button type="submit" class="btn btn-sm btn-outline">Start Over</button>
                    </form>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?= csrfField() ?>
                
                <?php if ($step === 1): ?>
                    <p style="margin-bottom: 15px;">Enter your username to begin the password recovery process.</p>
                    <div class="form-group">
                        <label class="form-label" for="username">Username</label>
                        <input type="text" id="username" name="username" class="form-control" value="<?= e($username) ?>" required>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Continue</button>
                    </div>
                    
                <?php elseif ($step === 2): ?>
                    <p style="margin-bottom: 15px;">Please answer all 5 security questions for the account: <strong><?= e($username) ?></strong>.</p>
                    
                    <?php foreach ($secret_questions as $q_num => $q_text): ?>
                        <div class="form-group">
                            <label class="form-label"><?= e($q_text) ?></label>
                            <input type="text" name="secret_answers[<?= $q_num ?>]" class="form-control" required>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Verify Answers</button>
                    </div>
                    
                <?php elseif ($step === 3): ?>
                    <p style="margin-bottom: 15px;">Security answers verified. Please enter your new password.</p>
                    
                    <div class="form-group">
                        <label class="form-label" for="password">New Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                        <div class="password-reqs">Min 8 chars, 1 uppercase, 1 lowercase, 1 number</div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirm_password">Confirm New Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" style="width: 100%;">Reset Password</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
        <div class="auth-footer" style="text-align: center; margin-top: 20px;">
            <p><a href="<?= BASE_URL ?>/index.php">Back to Login</a></p>
        </div>
    </div>
</body>
</html>
