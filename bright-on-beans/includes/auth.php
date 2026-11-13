<?php
/**
 * Bright on Beans Coffee House
 * Authentication & Authorization Middleware
 * 
 * Provides session management, role-based access control,
 * and CSRF token validation for all pages.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/functions.php';

date_default_timezone_set('Asia/Manila');

// ─── Access Control Functions ──────────────────────────────────

/**
 * Require the user to be logged in. Redirects to login if not.
 */
function requireLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
    // Check if the account is still active
    if (isset($_SESSION['status']) && $_SESSION['status'] !== 'active') {
        session_destroy();
        header('Location: ' . BASE_URL . '/index.php?error=inactive');
        exit;
    }
}

/**
 * Require the user to be an Admin. Redirects Staff away.
 */
function requireAdmin() {
    requireLogin();
    if ($_SESSION['role'] !== 'admin') {
        header('Location: ' . BASE_URL . '/staff/dashboard.php');
        exit;
    }
}

/**
 * Require the user to be a Staff member. Redirects Admin away.
 */
function requireStaff() {
    requireLogin();
    if ($_SESSION['role'] !== 'staff') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit;
    }
}

/**
 * Check if the current user is an Admin.
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check if a user is logged in.
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Get the current user's full name.
 */
function getCurrentUserName() {
    return isset($_SESSION['first_name']) 
        ? $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] 
        : 'User';
}

/**
 * Get the current user's role.
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? 'guest';
}

/**
 * Get the current user's ID.
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// ─── CSRF Protection ──────────────────────────────────────────

/**
 * Generate or retrieve the current CSRF token.
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output a hidden CSRF input field for forms.
 */
function csrfField() {
    $token = generateCSRFToken();
    echo '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Validate a submitted CSRF token. Returns true if valid.
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Validate CSRF and die with error if invalid.
 */
function requireCSRF() {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCSRFToken($token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh the page.']));
    }
}

// ─── Session Login/Logout Helpers ─────────────────────────────

/**
 * Set session variables after successful login.
 */
function loginUser($user) {
    $_SESSION['user_id']    = $user['user_id'];
    $_SESSION['username']   = $user['username'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name']  = $user['last_name'];
    $_SESSION['role']       = $user['role'];
    $_SESSION['status']     = $user['status'];
    // Regenerate session ID to prevent session fixation
    session_regenerate_id(true);
}

/**
 * Destroy the current session and redirect to login.
 */
function logoutUser() {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
