<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Verification of Seeded Menu & Recipes ===\n\n";

$prodCount = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$ingCount = $pdo->query("SELECT COUNT(*) FROM ingredients")->fetchColumn();
$recipeCount = $pdo->query("SELECT COUNT(*) FROM recipe_ingredients")->fetchColumn();

echo "Total Products: $prodCount\n";
echo "Total Ingredients: $ingCount\n";
echo "Total Recipe Ingredient Links: $recipeCount\n\n";

echo "--- All Products in System ---\n";
$stmtAll = $pdo->query("SELECT product_id, name, category, price, status FROM products ORDER BY category, name");
while ($p = $stmtAll->fetch()) {
    echo "ID: {$p['product_id']} | {$p['name']} | {$p['category']} | ₱{$p['price']} | {$p['status']}\n";
}
echo "\n--- Categories Breakdown ---\n";
$stmtCats = $pdo->query("SELECT category, COUNT(*) as count FROM products GROUP BY category ORDER BY category");
while ($c = $stmtCats->fetch()) {
    echo "• {$c['category']}: {$c['count']} items\n";
}

echo "\n--- Sample Recipe Check ---\n";
$sampleProducts = ['Iced Latte', 'Bacon Carbonara', 'Beef Burger', 'Loaded Fries', 'Brighton Whisk'];
foreach ($sampleProducts as $pName) {
    $stmtP = $pdo->prepare("SELECT product_id, name, price FROM products WHERE name = ?");
    $stmtP->execute([$pName]);
    $p = $stmtP->fetch();
    if ($p) {
        echo "\nProduct: {$p['name']} (₱{$p['price']})\n";
        $stmtR = $pdo->prepare("
            SELECT i.name as ing_name, ri.quantity_per_serving, ri.unit, i.current_stock
            FROM recipe_ingredients ri
            JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
            WHERE ri.product_id = ?
        ");
        $stmtR->execute([$p['product_id']]);
        while ($r = $stmtR->fetch()) {
            echo "   - {$r['ing_name']}: {$r['quantity_per_serving']} {$r['unit']} (Available stock: {$r['current_stock']} {$r['unit']})\n";
        }
    }
}
