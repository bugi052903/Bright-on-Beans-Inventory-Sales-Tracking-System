<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (file_exists(__DIR__ . '/../includes/auth.php')) {
    require_once __DIR__ . '/../includes/auth.php';
} else {
    function isLoggedIn() { return isset($_SESSION['user_id']); }
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

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$error = '';
$success = '';
$first_name = $_POST['first_name'] ?? '';
$last_name = $_POST['last_name'] ?? '';
$username = $_POST['username'] ?? '';
$secret_answers = $_POST['secret_answers'] ?? [];

$secret_questions = getSecretQuestions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $csrf = $_POST['csrf_token'] ?? '';
    $pwdErrors = validatePassword($password);
    
    if (empty($csrf) || !validateCSRFToken($csrf)) {
        $error = 'Invalid security token. Please try again.';
    } elseif (empty($first_name) || empty($last_name) || empty($username) || empty($password) || empty($confirm_password)) {
        $error = 'All primary fields are required.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!empty($pwdErrors)) {
        $error = implode(' ', $pwdErrors);
    } elseif (!isUsernameAvailable($pdo, $username)) {
        $error = 'Username is already taken.';
    } else {
        // Validate secret questions
        $answers_valid = true;
        foreach ($secret_questions as $q_num => $q_text) {
            if (empty($secret_answers[$q_num])) {
                $answers_valid = false;
                break;
            }
        }
        
        if (!$answers_valid) {
            $error = 'All secret questions must be answered.';
        } else {
            try {
                $pdo->beginTransaction();
                
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, status, created_at) VALUES (?, ?, ?, ?, 'staff', 'active', NOW())");
                $stmt->execute([$username, $hashed_password, $first_name, $last_name]);
                $user_id = $pdo->lastInsertId();
                
                $stmt_sq = $pdo->prepare("INSERT INTO secret_questions (user_id, question_number, question, answer) VALUES (?, ?, ?, ?)");
                foreach ($secret_questions as $q_num => $q_text) {
                    $hashed_answer = password_hash(strtolower(trim($secret_answers[$q_num])), PASSWORD_DEFAULT);
                    $stmt_sq->execute([$user_id, $q_num, $q_text, $hashed_answer]);
                }
                
                $pdo->commit();
                
                setFlashMessage('success', 'Registration successful! You can now login.');
                header('Location: ' . BASE_URL . '/index.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'An error occurred during registration. Please try again.';
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
    <title>Staff Registration - Bright on Beans Coffee House</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
    <style>
        .auth-card { max-width: 600px; }
        .password-reqs { font-size: 0.85em; color: #666; margin-top: 5px; }

        .form-control.is-valid {
            border: 2px solid #10B981 !important;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%2310B981'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M5 13l4 4L19 7'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 10px center !important;
            background-size: 18px 18px !important;
            padding-right: 35px !important;
        }

        .form-control.is-invalid {
            border: 2px solid #EF4444 !important;
            box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.15) !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='%23EF4444'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='3' d='M6 18L18 6M6 6l12 12'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 10px center !important;
            background-size: 18px 18px !important;
            padding-right: 35px !important;
        }

        .val-hint {
            font-size: 0.78rem;
            margin-top: 4px;
            font-weight: 600;
            display: block;
        }
        .val-hint.valid { color: #059669; }
        .val-hint.invalid { color: #DC2626; }
    </style>
</head>
<body class="auth-page">
    <div class="auth-card">
        <div class="auth-header">
            <h2>Staff Registration</h2>
            <p>Create a new staff account</p>
        </div>
        <div class="auth-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <?= csrfField() ?>
                
                <div class="form-row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="first_name">First Name</label>
                        <input type="text" id="first_name" name="first_name" class="form-control" value="<?= e($first_name) ?>" required>
                        <span class="val-hint" id="hint_first_name"></span>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="last_name">Last Name</label>
                        <input type="text" id="last_name" name="last_name" class="form-control" value="<?= e($last_name) ?>" required>
                        <span class="val-hint" id="hint_last_name"></span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" id="username" name="username" class="form-control" value="<?= e($username) ?>" required>
                    <span class="val-hint" id="hint_username"></span>
                </div>
                
                <div class="form-row" style="display: flex; gap: 15px;">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="password">Password</label>
                        <input type="password" id="password" name="password" class="form-control" required>
                        <span class="val-hint" id="hint_password">Min 8 chars, 1 uppercase, 1 lowercase, 1 number</span>
                    </div>
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label" for="confirm_password">Confirm Password</label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                        <span class="val-hint" id="hint_confirm_password"></span>
                    </div>
                </div>
                
                <div style="margin-top: 20px; margin-bottom: 15px; border-top: 1px solid #ddd; padding-top: 15px;">
                    <h3 style="margin-bottom: 15px;">Security Questions</h3>
                    <p style="font-size: 0.9em; margin-bottom: 15px; color: #666;">These will be used if you forget your password.</p>
                    
                    <?php foreach ($secret_questions as $q_num => $q_text): ?>
                        <div class="form-group">
                            <label class="form-label"><?= e($q_text) ?></label>
                            <input type="text" name="secret_answers[<?= $q_num ?>]" class="form-control sec-ans" data-num="<?= $q_num ?>"
                                value="<?= e($secret_answers[$q_num] ?? '') ?>" required>
                            <span class="val-hint" id="hint_sec_<?= $q_num ?>"></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" style="width: 100%;">Register</button>
                </div>
            </form>
        </div>
        <div class="auth-footer" style="text-align: center; margin-top: 20px;">
            <p>Already have an account? <a href="<?= BASE_URL ?>/index.php">Login</a></p>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const firstName = document.getElementById('first_name');
    const lastName = document.getElementById('last_name');
    const username = document.getElementById('username');
    const password = document.getElementById('password');
    const confirmPassword = document.getElementById('confirm_password');
    const secAnswers = document.querySelectorAll('.sec-ans');

    function setFieldState(input, hintElem, isValid, message) {
        if (!input) return;
        if (isValid) {
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
            if (hintElem) {
                hintElem.textContent = message || '✓ Looks good!';
                hintElem.className = 'val-hint valid';
            }
        } else {
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
            if (hintElem) {
                hintElem.textContent = message || '✕ Required field';
                hintElem.className = 'val-hint invalid';
            }
        }
    }

    function validateFirstName() {
        const val = firstName.value.trim();
        if (val.length >= 2) {
            setFieldState(firstName, document.getElementById('hint_first_name'), true, '✓ Valid first name');
        } else {
            setFieldState(firstName, document.getElementById('hint_first_name'), false, '✕ Minimum 2 characters required');
        }
    }

    function validateLastName() {
        const val = lastName.value.trim();
        if (val.length >= 2) {
            setFieldState(lastName, document.getElementById('hint_last_name'), true, '✓ Valid last name');
        } else {
            setFieldState(lastName, document.getElementById('hint_last_name'), false, '✕ Minimum 2 characters required');
        }
    }

    function validateUsername() {
        const val = username.value.trim();
        if (val.length >= 3 && /^[a-zA-Z0-9_]+$/.test(val)) {
            setFieldState(username, document.getElementById('hint_username'), true, '✓ Valid username');
        } else {
            setFieldState(username, document.getElementById('hint_username'), false, '✕ Min 3 characters (letters, numbers, underscore)');
        }
    }

    function validatePassword() {
        const val = password.value;
        const hint = document.getElementById('hint_password');
        if (val.length < 8) {
            setFieldState(password, hint, false, '✕ Minimum 8 characters required');
        } else if (!/[A-Z]/.test(val)) {
            setFieldState(password, hint, false, '✕ Must contain at least 1 uppercase letter (A-Z)');
        } else if (!/[a-z]/.test(val)) {
            setFieldState(password, hint, false, '✕ Must contain at least 1 lowercase letter (a-z)');
        } else if (!/[0-9]/.test(val)) {
            setFieldState(password, hint, false, '✕ Must contain at least 1 number (0-9)');
        } else {
            setFieldState(password, hint, true, '✓ Strong password requirement met!');
        }
        if (confirmPassword.value) validateConfirmPassword();
    }

    function validateConfirmPassword() {
        const val = confirmPassword.value;
        const hint = document.getElementById('hint_confirm_password');
        if (val && val === password.value && password.classList.contains('is-valid')) {
            setFieldState(confirmPassword, hint, true, '✓ Passwords match perfectly!');
        } else {
            setFieldState(confirmPassword, hint, false, '✕ Passwords do not match');
        }
    }

    firstName?.addEventListener('input', validateFirstName);
    lastName?.addEventListener('input', validateLastName);
    username?.addEventListener('input', validateUsername);
    password?.addEventListener('input', validatePassword);
    confirmPassword?.addEventListener('input', validateConfirmPassword);

    secAnswers.forEach(ansInput => {
        const num = ansInput.dataset.num;
        const hint = document.getElementById('hint_sec_' + num);
        ansInput.addEventListener('input', function() {
            if (this.value.trim().length >= 2) {
                setFieldState(this, hint, true, '✓ Security answer entered');
            } else {
                setFieldState(this, hint, false, '✕ Please provide a security answer');
            }
        });
    });
});
</script>
</body>
</html>
