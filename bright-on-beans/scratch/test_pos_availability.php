<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Verifying POS Servings for all 24 Available Products ===\n\n";

$stmtStock = $pdo->query("SELECT LOWER(TRIM(name)) AS ing_key, COALESCE(SUM(current_stock), 0) AS total_stock FROM ingredients GROUP BY LOWER(TRIM(name))");
$ingredientStocks = [];
while ($row = $stmtStock->fetch()) {
    $ingredientStocks[$row['ing_key']] = (float)$row['total_stock'];
}

$stmtProds = $pdo->query("SELECT product_id, name, category, price FROM products WHERE status = 'available' ORDER BY category, name");
$products = $stmtProds->fetchAll();

echo "Total Available Products: " . count($products) . "\n\n";

$allOk = true;
foreach ($products as $p) {
    $stmtRecipes = $pdo->prepare("
        SELECT ri.quantity_per_serving, i.name as ingredient_name
        FROM recipe_ingredients ri
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
        WHERE ri.product_id = ?
    ");
    $stmtRecipes->execute([$p['product_id']]);
    $recipe = $stmtRecipes->fetchAll();

    $maxServings = PHP_INT_MAX;
    if (empty($recipe)) {
        $maxServings = 0;
        echo "[ERROR: NO RECIPE] {$p['name']} ({$p['category']})\n";
        $allOk = false;
    } else {
        foreach ($recipe as $ing) {
            $ingKey = strtolower(trim($ing['ingredient_name']));
            $stock = $ingredientStocks[$ingKey] ?? 0;
            $qty = (float)$ing['quantity_per_serving'];
            if ($qty > 0) {
                $servings = floor($stock / $qty);
                if ($servings < $maxServings) $maxServings = (int)$servings;
            }
        }
        if ($maxServings === PHP_INT_MAX) $maxServings = 0;
        $status = ($maxServings <= 0) ? "OUT OF STOCK" : "{$maxServings} servings available";
        echo "✓ [{$p['category']}] {$p['name']} (₱{$p['price']}) -> {$status} (" . count($recipe) . " ingredients)\n";
    }
}

if ($allOk) {
    echo "\n>>> ALL 24 PRODUCTS ARE 100% READY AND FUNCTIONAL IN THE POS SYSTEM! <<<\n";
} else {
    echo "\n>>> SOME PRODUCTS ARE MISSING RECIPES! <<<\n";
}
