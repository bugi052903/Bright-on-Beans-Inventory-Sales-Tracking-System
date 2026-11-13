<?php
require_once __DIR__ . '/../config/database.php';

try {
    $stmt = $pdo->query("DESCRIBE ingredients");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Columns in ingredients table:\n";
    foreach ($columns as $col) {
        echo "- {$col['Field']} ({$col['Type']})\n";
    }

    $stmt2 = $pdo->query("SELECT ingredient_id, name, date_added FROM ingredients LIMIT 5");
    $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "\nSample rows:\n";
    foreach ($rows as $row) {
        echo "ID: {$row['ingredient_id']} | Name: {$row['name']} | Date Added: {$row['date_added']}\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
