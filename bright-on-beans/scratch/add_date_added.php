<?php
require_once __DIR__ . '/../config/database.php';

try {
    // Check if column already exists
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredients LIKE 'date_added'");
    if ($stmt->rowCount() == 0) {
        // Add date_added column
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN date_added DATE DEFAULT NULL AFTER minimum_level");
        echo "Successfully added 'date_added' column to 'ingredients' table.\n";
        
        // Update existing records to use their created_at date
        $pdo->exec("UPDATE ingredients SET date_added = DATE(created_at) WHERE date_added IS NULL");
        echo "Updated existing records' 'date_added' to match their 'created_at' date.\n";
    } else {
        echo "'date_added' column already exists in 'ingredients' table.\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
