<?php
require_once __DIR__ . '/../config/database.php';

$stmt = $pdo->query("SELECT product_id, name FROM products WHERE name IN ('Caramel Latte', 'Ice Americano')");
$oldProds = $stmt->fetchAll();

foreach ($oldProds as $op) {
    $count = $pdo->prepare("SELECT COUNT(*) FROM sales_items WHERE product_id = ?");
    $count->execute([$op['product_id']]);
    $salesCount = $count->fetchColumn();
    echo "Product {$op['name']} (ID: {$op['product_id']}) is referenced in $salesCount sales_items.\n";
    if ($salesCount == 0) {
        $pdo->prepare("DELETE FROM recipe_ingredients WHERE product_id = ?")->execute([$op['product_id']]);
        $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$op['product_id']]);
        echo "Deleted old sample product: {$op['name']}\n";
    } else {
        $pdo->prepare("UPDATE products SET status = 'unavailable' WHERE product_id = ?")->execute([$op['product_id']]);
        echo "Marked old sample product as unavailable: {$op['name']}\n";
    }
}
