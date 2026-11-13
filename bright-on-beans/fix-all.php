<?php
// Fix everything in the database
try {
    $pdo = new PDO("mysql:host=localhost;dbname=bright_on_beans;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "<h2>Database Fix Script</h2>";

    // 1. Fix products with empty/wrong status
    $fixed = $pdo->exec("UPDATE products SET status = 'available' WHERE status = '' OR status IS NULL OR status = 'active'");
    echo "<p>✅ Fixed $fixed products with wrong status → set to 'available'</p>";

    // 2. Fix any 'inactive' products to 'unavailable'
    $fixed2 = $pdo->exec("UPDATE products SET status = 'unavailable' WHERE status = 'inactive'");
    echo "<p>✅ Fixed $fixed2 products 'inactive' → 'unavailable'</p>";

    // 3. Ensure admin account exists
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE username = 'admin'");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (username, password, first_name, last_name, role, status) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute(['admin', $hash, 'Admin', 'Admin', 'admin', 'active']);
        echo "<p>✅ Admin account created (admin / admin123)</p>";
    } else {
        echo "<p>✅ Admin account already exists</p>";
    }

    // 4. Show current state
    $products = $pdo->query("SELECT product_id, name, category, price, status FROM products")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Products in database: " . count($products) . "</h3>";
    if (!empty($products)) {
        echo "<table border='1' cellpadding='5'><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Status</th></tr>";
        foreach ($products as $p) {
            echo "<tr><td>{$p['product_id']}</td><td>{$p['name']}</td><td>{$p['category']}</td><td>{$p['price']}</td><td>{$p['status']}</td></tr>";
        }
        echo "</table>";
    }

    $users = $pdo->query("SELECT user_id, username, role, status FROM users")->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Users: " . count($users) . "</h3>";
    foreach ($users as $u) {
        echo "<p>ID:{$u['user_id']} | {$u['username']} | {$u['role']} | {$u['status']}</p>";
    }

    echo "<br><hr>";
    echo "<h3>✅ All fixes applied! <a href='/bright-on-beans/'>Go to Login →</a></h3>";
    echo "<p style='color:red;'>Delete this file (fix-all.php) after you're done!</p>";

} catch (PDOException $e) {
    echo "<h2 style='color:red;'>Error: " . $e->getMessage() . "</h2>";
}
