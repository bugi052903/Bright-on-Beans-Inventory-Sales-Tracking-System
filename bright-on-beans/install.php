<?php
session_start();

$host = 'localhost';
$username = 'root'; // Default XAMPP
$password = ''; // Default XAMPP
$database = 'bright_on_beans';

$message = '';
$installed = false;

try {
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Check if DB exists
    $stmt = $pdo->query("SHOW DATABASES LIKE '$database'");
    if ($stmt->rowCount() > 0) {
        $pdo->query("USE $database");
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($stmt->rowCount() > 0) {
            $user_count_stmt = $pdo->query("SELECT COUNT(*) FROM users");
            if ($user_count_stmt->fetchColumn() > 0) {
                $installed = true;
                $message = "The system is already installed. Please delete this file and <a href='index.php'>Login here</a>.";
            }
        }
    }
} catch (PDOException $e) {
    $message = "Database connection failed: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed) {
    try {
        // Create DB
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$database`");

        // Ensure schema file exists
        $schemaPath = __DIR__ . '/database/schema.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            $pdo->exec($sql);
        } else {
            // Fallback basic schema creation
            $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                user_id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL,
                first_name VARCHAR(50) NOT NULL,
                last_name VARCHAR(50) NOT NULL,
                role ENUM('admin', 'staff') DEFAULT 'staff',
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
        }

        // Insert default admin
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = 'admin'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $hash = password_hash('admin123', PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, status) VALUES ('admin', ?, 'Admin', 'User', 'admin', 'active')");
            $stmt->execute([$hash]);
        }

        $installed = true;
        $message = "<div style='color: green;'>Installation Successful!</div> Default Login:<br>Username: <b>admin</b><br>Password: <b>admin123</b><br><br><a href='index.php'>Proceed to Login</a>";
    } catch (PDOException $e) {
        $message = "Installation failed: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Bright on Beans</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f4f4f4;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            margin: 0;
        }

        .install-card {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 400px;
            width: 100%;
            text-align: center;
        }

        .btn {
            background: #3C2415;
            color: white;
            border: none;
            padding: 10px 20px;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 1rem;
            width: 100%;
        }

        .btn:hover {
            background: #2a190f;
        }

        .msg {
            margin-bottom: 1rem;
            padding: 10px;
            border-radius: 4px;
            background: #eef;
        }
    </style>
</head>

<body>
    <div class="install-card">
        <h2>Bright on Beans Setup</h2>
        <?php if ($message): ?>
            <div class="msg"><?= $message ?></div>
        <?php endif; ?>

        <?php if (!$installed): ?>
            <p>This will create the database and default admin account.</p>
            <form method="POST">
                <button type="submit" class="btn">Install System</button>
            </form>
        <?php else: ?>
            <p style="color: red; font-size: 0.9em; margin-top: 1rem;">IMPORTANT: For security reasons, please delete install.php after setup.</p>
        <?php endif; ?>
    </div>
</body>

</html>