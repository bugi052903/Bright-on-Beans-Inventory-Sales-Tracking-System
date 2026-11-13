<?php
/**
 * Setup Script for Owner & Supervisor Admin Accounts
 * Practice & Setup Tool for Capstone Deployment
 */
require_once __DIR__ . '/../includes/auth.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? 'admin';
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($firstName) || empty($lastName) || empty($username) || empty($password)) {
        $message = '<div style="color:#DC2626; font-weight:bold; padding: 10px; background: #FCE8E6; border-radius: 6px; margin-bottom: 1rem;">All fields are required.</div>';
    } else {
        // Check if username exists
        $stmtCheck = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
        $stmtCheck->execute([$username]);
        if ($stmtCheck->fetch()) {
            // Update existing password
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmtUp = $pdo->prepare("UPDATE users SET password = ?, first_name = ?, last_name = ?, role = ? WHERE username = ?");
            $stmtUp->execute([$hashed, $firstName, $lastName, $role, $username]);
            $message = "<div style='color:#059669; font-weight:bold; padding: 10px; background: #E6F4EA; border-radius: 6px; margin-bottom: 1rem;'>✅ Success: Updated account '{$username}' with new password & details!</div>";
        } else {
            // Insert new admin account
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmtIns = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())");
            $stmtIns->execute([$username, $hashed, $firstName, $lastName, $role]);
            $message = "<div style='color:#059669; font-weight:bold; padding: 10px; background: #E6F4EA; border-radius: 6px; margin-bottom: 1rem;'>✅ Success: Created new " . ucfirst($role) . " account '{$username}' ({$firstName} {$lastName})!</div>";
        }
    }
}

// Fetch all accounts
$users = $pdo->query("SELECT user_id, username, first_name, last_name, role, status, created_at FROM users ORDER BY role ASC, first_name ASC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pre-build Admin Accounts Setup — Bright on Beans</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, sans-serif; background: #FAF7F2; padding: 2rem; color: #3C2415; margin: 0; }
        .box { background: #FFF; border-radius: 14px; padding: 1.75rem; max-width: 520px; margin: 0 auto; box-shadow: 0 4px 20px rgba(60,36,21,0.06); border: 1.5px solid #EAE3D9; }
        h2 { margin-top: 0; color: #3C2415; font-size: 1.3rem; }
        label { display: block; margin-top: 1rem; font-weight: 600; font-size: 0.88rem; color: #3C2415; }
        input, select { width: 100%; padding: 0.65rem 0.8rem; margin-top: 0.3rem; border: 1px solid #D2C6B9; border-radius: 8px; box-sizing: border-box; font-size: 0.9rem; outline: none; }
        input:focus, select:focus { border-color: #00A86B; box-shadow: 0 0 0 3px rgba(0,168,107,0.15); }
        button { background: #00A86B; color: white; padding: 0.75rem; border: none; border-radius: 8px; width: 100%; margin-top: 1.25rem; font-weight: 700; cursor: pointer; font-size: 0.95rem; transition: background 0.15s; }
        button:hover { background: #008F5B; }
        table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; font-size: 0.88rem; }
        th, td { border-bottom: 1px solid #EAE3D9; padding: 10px 8px; text-align: left; }
        th { background: #FAF7F2; color: #8C6D58; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.4px; }
    </style>
</head>
<body>
    <div class="box">
        <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 0.5rem;">
            <span style="font-size: 1.5rem;">☕</span>
            <h2>Pre-build Store Admin Accounts</h2>
        </div>
        <p style="font-size: 0.86rem; color: #6E5343; margin-bottom: 1.25rem;">Use this tool to provision the Owner and Supervisor Admin accounts with their preferred passwords for deployment.</p>
        
        <?= $message ?>

        <form method="POST">
            <label>Account Role</label>
            <select name="role">
                <option value="admin">Admin (Full Access / Owner / Supervisor)</option>
                <option value="staff">Staff (Counter Staff / POS Only)</option>
            </select>

            <label>First Name</label>
            <input type="text" name="first_name" placeholder="e.g. Maria" required>

            <label>Last Name</label>
            <input type="text" name="last_name" placeholder="e.g. Santos" required>

            <label>Username</label>
            <input type="text" name="username" placeholder="e.g. owner_maria" required>

            <label>Preferred Password</label>
            <input type="password" name="password" placeholder="Enter preferred password" required>

            <button type="submit">+ Create / Update Account</button>
        </form>

        <h3 style="margin-top: 2rem; font-size: 0.95rem; color: #3C2415; border-top: 1px solid #EAE3D9; padding-top: 1rem;">Current Accounts in Database:</h3>
        <table>
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name']) ?></strong></td>
                    <td><?= htmlspecialchars($u['username']) ?></td>
                    <td><span style="font-weight: 700; color: <?= $u['role'] === 'admin' ? '#059669' : '#2563EB' ?>; background: <?= $u['role'] === 'admin' ? '#E6F4EA' : '#E8F0FE' ?>; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem;"><?= strtoupper($u['role']) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
