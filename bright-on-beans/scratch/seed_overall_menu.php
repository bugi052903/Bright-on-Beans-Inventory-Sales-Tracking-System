<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Seeding Bright'on Coffee Beans Menu & Inventory ===\n\n";

try {
    // $pdo is initialized in database.php
    $pdo->beginTransaction();

    // 1. INGREDIENTS MASTER LIST
    $ingredientsList = [
        ['name' => 'Espresso Shot', 'unit' => 'ml', 'stock' => 5000, 'min' => 500, 'cost' => 0.50],
        ['name' => 'Fresh Milk', 'unit' => 'ml', 'stock' => 10000, 'min' => 1000, 'cost' => 0.08],
        ['name' => 'Ice Cubes', 'unit' => 'pcs', 'stock' => 2000, 'min' => 200, 'cost' => 0.05],
        ['name' => 'Salted Caramel Syrup', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.40],
        ['name' => 'Vanilla Syrup', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.40],
        ['name' => 'Matcha Powder', 'unit' => 'g', 'stock' => 1000, 'min' => 100, 'cost' => 1.20],
        ['name' => 'Dark Chocolate Powder', 'unit' => 'g', 'stock' => 1000, 'min' => 100, 'cost' => 0.80],
        ['name' => 'Strawberry Puree', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.50],
        ['name' => 'Whipped Cream', 'unit' => 'g', 'stock' => 2000, 'min' => 200, 'cost' => 0.60],
        ['name' => 'Oreo Crumbles', 'unit' => 'g', 'stock' => 1500, 'min' => 150, 'cost' => 0.40],
        ['name' => 'Biscoff Paste', 'unit' => 'g', 'stock' => 1500, 'min' => 150, 'cost' => 0.90],
        ['name' => 'Cocoa Powder', 'unit' => 'g', 'stock' => 1000, 'min' => 100, 'cost' => 0.60],
        ['name' => 'Mango Puree', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.50],
        ['name' => 'Blueberry Syrup', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.45],
        ['name' => 'Condensed Milk', 'unit' => 'ml', 'stock' => 3000, 'min' => 300, 'cost' => 0.15],
        ['name' => 'All-Purpose Cream', 'unit' => 'ml', 'stock' => 3000, 'min' => 300, 'cost' => 0.20],
        ['name' => 'Strawberry Jelly', 'unit' => 'g', 'stock' => 1500, 'min' => 150, 'cost' => 0.35],
        ['name' => 'Kiwi Syrup', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.45],
        ['name' => 'Spaghetti Noodles', 'unit' => 'g', 'stock' => 5000, 'min' => 500, 'cost' => 0.12],
        ['name' => 'Bacon Strips', 'unit' => 'pcs', 'stock' => 300, 'min' => 30, 'cost' => 8.00],
        ['name' => 'Egg', 'unit' => 'pcs', 'stock' => 200, 'min' => 20, 'cost' => 7.00],
        ['name' => 'Parmesan Cheese', 'unit' => 'g', 'stock' => 1000, 'min' => 100, 'cost' => 0.85],
        ['name' => 'Heavy Cream', 'unit' => 'ml', 'stock' => 3000, 'min' => 300, 'cost' => 0.30],
        ['name' => 'Shrimp', 'unit' => 'g', 'stock' => 2500, 'min' => 250, 'cost' => 0.65],
        ['name' => 'Pesto Sauce', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.55],
        ['name' => 'Garlic', 'unit' => 'cloves', 'stock' => 200, 'min' => 20, 'cost' => 1.50],
        ['name' => 'Beef Patty', 'unit' => 'pcs', 'stock' => 150, 'min' => 20, 'cost' => 35.00],
        ['name' => 'Burger Bun', 'unit' => 'pcs', 'stock' => 150, 'min' => 20, 'cost' => 12.00],
        ['name' => 'Lettuce', 'unit' => 'g', 'stock' => 1500, 'min' => 150, 'cost' => 0.20],
        ['name' => 'Tomato', 'unit' => 'pcs', 'stock' => 150, 'min' => 20, 'cost' => 5.00],
        ['name' => 'Cheese Slice', 'unit' => 'pcs', 'stock' => 200, 'min' => 20, 'cost' => 8.00],
        ['name' => 'Hungarian Sausage', 'unit' => 'pcs', 'stock' => 150, 'min' => 20, 'cost' => 28.00],
        ['name' => 'Fish Fillet', 'unit' => 'g', 'stock' => 3000, 'min' => 300, 'cost' => 0.45],
        ['name' => 'French Fries', 'unit' => 'g', 'stock' => 8000, 'min' => 800, 'cost' => 0.15],
        ['name' => 'Cheddar Cheese Sauce', 'unit' => 'ml', 'stock' => 3000, 'min' => 300, 'cost' => 0.25],
        ['name' => 'Vanilla Ice Cream', 'unit' => 'scoops', 'stock' => 150, 'min' => 20, 'cost' => 15.00],
        ['name' => 'Dirty Ice Cream (Pink)', 'unit' => 'scoops', 'stock' => 150, 'min' => 20, 'cost' => 15.00],
        ['name' => 'Yogurt Base', 'unit' => 'ml', 'stock' => 5000, 'min' => 500, 'cost' => 0.25],
        ['name' => 'Sugar Syrup', 'unit' => 'ml', 'stock' => 5000, 'min' => 500, 'cost' => 0.08],
        ['name' => 'Calamansi Juice', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.18],
        ['name' => 'Peppermint Tea Bag', 'unit' => 'pcs', 'stock' => 100, 'min' => 15, 'cost' => 12.00],
        ['name' => 'Hot Water', 'unit' => 'ml', 'stock' => 50000, 'min' => 1000, 'cost' => 0.001],
        ['name' => 'Breadcrumbs', 'unit' => 'g', 'stock' => 2000, 'min' => 200, 'cost' => 0.10],
        ['name' => 'Cooking Oil', 'unit' => 'ml', 'stock' => 10000, 'min' => 1000, 'cost' => 0.09],
        ['name' => 'Salt', 'unit' => 'g', 'stock' => 2000, 'min' => 200, 'cost' => 0.04],
        ['name' => 'Pepper', 'unit' => 'g', 'stock' => 1000, 'min' => 100, 'cost' => 0.25],
        ['name' => 'Chicken Breast', 'unit' => 'g', 'stock' => 3000, 'min' => 300, 'cost' => 0.35],
        ['name' => 'Butter', 'unit' => 'g', 'stock' => 2000, 'min' => 200, 'cost' => 0.40],
        ['name' => 'Flat White Milk Foam', 'unit' => 'ml', 'stock' => 3000, 'min' => 300, 'cost' => 0.10],
        ['name' => 'Spanish Latte Syrup', 'unit' => 'ml', 'stock' => 2000, 'min' => 200, 'cost' => 0.35],
    ];

    $ingMap = []; // [lower_name => ingredient_id]
    $stmtFindIng = $pdo->prepare("SELECT ingredient_id, name, unit, current_stock FROM ingredients WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtInsertIng = $pdo->prepare("INSERT INTO ingredients (name, unit, current_stock, minimum_level, unit_cost, date_added, expiration_date) VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))");
    $stmtUpdateIng = $pdo->prepare("UPDATE ingredients SET unit = ?, minimum_level = ?, unit_cost = ? WHERE ingredient_id = ?");

    foreach ($ingredientsList as $ing) {
        $stmtFindIng->execute([$ing['name']]);
        $existing = $stmtFindIng->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $ingId = $existing['ingredient_id'];
            $stmtUpdateIng->execute([$ing['unit'], $ing['min'], $ing['cost'], $ingId]);
            // If current stock is 0, top it up to test stock
            if ((float)$existing['current_stock'] <= 0) {
                $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?")->execute([$ing['stock'], $ingId]);
            }
        } else {
            $stmtInsertIng->execute([$ing['name'], $ing['unit'], $ing['stock'], $ing['min'], $ing['cost']]);
            $ingId = $pdo->lastInsertId();
        }
        $ingMap[strtolower(trim($ing['name']))] = $ingId;
    }
    echo "✓ Processed 50 Master Ingredients.\n";

    // 2. PRODUCTS LIST
    $productsList = [
        ['name' => 'Iced Latte', 'category' => 'Iced Coffee', 'price' => 145.00],
        ['name' => 'Salted Caramel', 'category' => 'Iced Coffee', 'price' => 165.00],
        ['name' => 'Hot Americano', 'category' => 'Hot Coffee', 'price' => 95.00],
        ['name' => 'Biscoff Latte (Hot)', 'category' => 'Hot Coffee', 'price' => 170.00],
        ['name' => 'Matcha Hot', 'category' => 'Non-Coffee', 'price' => 155.00],
        ['name' => 'Dark Chocolate Hot', 'category' => 'Non-Coffee', 'price' => 130.00],
        ['name' => 'Matcha Berry', 'category' => 'Specials', 'price' => 185.00],
        ['name' => 'Chocoberry', 'category' => 'Specials', 'price' => 175.00],
        ['name' => 'Vanilla Frappe', 'category' => 'Frappe', 'price' => 175.00],
        ['name' => 'Cookies & Cream Frappe', 'category' => 'Frappe', 'price' => 185.00],
        ['name' => 'Brighton Whisk', 'category' => 'Dirty Coffee Ice Cream', 'price' => 195.00],
        ['name' => 'Brighton Cream Latte', 'category' => 'Dirty Coffee Ice Cream', 'price' => 195.00],
        ['name' => 'Strawberry Yogurt', 'category' => 'Yogurt', 'price' => 155.00],
        ['name' => 'Mango Yogurt', 'category' => 'Yogurt', 'price' => 155.00],
        ['name' => 'Mango Mocktail', 'category' => 'Mocktails', 'price' => 130.00],
        ['name' => 'Blueberry Mocktail', 'category' => 'Mocktails', 'price' => 140.00],
        ['name' => 'Bacon Carbonara', 'category' => 'Pasta', 'price' => 185.00],
        ['name' => 'Creamy Shrimp Pesto', 'category' => 'Pasta', 'price' => 210.00],
        ['name' => 'Beef Burger', 'category' => 'Burgers', 'price' => 200.00],
        ['name' => 'Bacon Burger', 'category' => 'Burgers', 'price' => 220.00],
        ['name' => 'Hungarian Sausage', 'category' => 'Foods', 'price' => 155.00],
        ['name' => 'Fish with Fries', 'category' => 'Foods', 'price' => 200.00],
        ['name' => 'Regular Fries (Cheese)', 'category' => 'Fries', 'price' => 100.00],
        ['name' => 'Loaded Fries', 'category' => 'Fries', 'price' => 180.00],
    ];

    $prodMap = []; // [lower_name => product_id]
    $stmtFindProd = $pdo->prepare("SELECT product_id, name FROM products WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
    $stmtInsertProd = $pdo->prepare("INSERT INTO products (name, category, price, status) VALUES (?, ?, ?, 'available')");
    $stmtUpdateProd = $pdo->prepare("UPDATE products SET category = ?, price = ?, status = 'available' WHERE product_id = ?");

    foreach ($productsList as $p) {
        $stmtFindProd->execute([$p['name']]);
        $existing = $stmtFindProd->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            $pId = $existing['product_id'];
            $stmtUpdateProd->execute([$p['category'], $p['price'], $pId]);
        } else {
            $stmtInsertProd->execute([$p['name'], $p['category'], $p['price']]);
            $pId = $pdo->lastInsertId();
        }
        $prodMap[strtolower(trim($p['name']))] = $pId;
    }
    echo "✓ Processed 24 Menu Products across categories.\n";

    // 3. RECIPES LIST (88 mappings)
    $recipesData = [
        // Iced Latte
        ['Iced Latte', 'Espresso Shot', 60, 'ml'],
        ['Iced Latte', 'Fresh Milk', 150, 'ml'],
        ['Iced Latte', 'Ice Cubes', 6, 'pcs'],
        ['Iced Latte', 'Sugar Syrup', 15, 'ml'],

        // Salted Caramel
        ['Salted Caramel', 'Espresso Shot', 60, 'ml'],
        ['Salted Caramel', 'Fresh Milk', 150, 'ml'],
        ['Salted Caramel', 'Salted Caramel Syrup', 30, 'ml'],
        ['Salted Caramel', 'Ice Cubes', 6, 'pcs'],

        // Hot Americano
        ['Hot Americano', 'Espresso Shot', 30, 'ml'],
        ['Hot Americano', 'Hot Water', 180, 'ml'],

        // Biscoff Latte (Hot)
        ['Biscoff Latte (Hot)', 'Espresso Shot', 60, 'ml'],
        ['Biscoff Latte (Hot)', 'Fresh Milk', 150, 'ml'],
        ['Biscoff Latte (Hot)', 'Biscoff Paste', 20, 'g'],

        // Matcha Hot
        ['Matcha Hot', 'Matcha Powder', 8, 'g'],
        ['Matcha Hot', 'Fresh Milk', 200, 'ml'],
        ['Matcha Hot', 'Sugar Syrup', 15, 'ml'],

        // Dark Chocolate Hot
        ['Dark Chocolate Hot', 'Dark Chocolate Powder', 15, 'g'],
        ['Dark Chocolate Hot', 'Fresh Milk', 200, 'ml'],
        ['Dark Chocolate Hot', 'Sugar Syrup', 10, 'ml'],

        // Matcha Berry
        ['Matcha Berry', 'Matcha Powder', 8, 'g'],
        ['Matcha Berry', 'Fresh Milk', 150, 'ml'],
        ['Matcha Berry', 'Strawberry Puree', 40, 'ml'],
        ['Matcha Berry', 'Ice Cubes', 6, 'pcs'],

        // Chocoberry
        ['Chocoberry', 'Cocoa Powder', 12, 'g'],
        ['Chocoberry', 'Fresh Milk', 150, 'ml'],
        ['Chocoberry', 'Strawberry Puree', 40, 'ml'],
        ['Chocoberry', 'Ice Cubes', 6, 'pcs'],

        // Vanilla Frappe
        ['Vanilla Frappe', 'Vanilla Syrup', 30, 'ml'],
        ['Vanilla Frappe', 'Fresh Milk', 120, 'ml'],
        ['Vanilla Frappe', 'Ice Cubes', 8, 'pcs'],
        ['Vanilla Frappe', 'Whipped Cream', 20, 'g'],

        // Cookies & Cream Frappe
        ['Cookies & Cream Frappe', 'Oreo Crumbles', 25, 'g'],
        ['Cookies & Cream Frappe', 'Fresh Milk', 120, 'ml'],
        ['Cookies & Cream Frappe', 'Ice Cubes', 8, 'pcs'],
        ['Cookies & Cream Frappe', 'Whipped Cream', 20, 'g'],
        ['Cookies & Cream Frappe', 'Condensed Milk', 20, 'ml'],

        // Brighton Whisk
        ['Brighton Whisk', 'Espresso Shot', 30, 'ml'],
        ['Brighton Whisk', 'Vanilla Ice Cream', 2, 'scoops'],
        ['Brighton Whisk', 'Dirty Ice Cream (Pink)', 1, 'scoops'],
        ['Brighton Whisk', 'Oreo Crumbles', 15, 'g'],

        // Brighton Cream Latte
        ['Brighton Cream Latte', 'Espresso Shot', 60, 'ml'],
        ['Brighton Cream Latte', 'Fresh Milk', 150, 'ml'],
        ['Brighton Cream Latte', 'All-Purpose Cream', 30, 'ml'],
        ['Brighton Cream Latte', 'Ice Cubes', 6, 'pcs'],

        // Strawberry Yogurt
        ['Strawberry Yogurt', 'Yogurt Base', 180, 'ml'],
        ['Strawberry Yogurt', 'Strawberry Puree', 40, 'ml'],
        ['Strawberry Yogurt', 'Sugar Syrup', 15, 'ml'],
        ['Strawberry Yogurt', 'Strawberry Jelly', 20, 'g'],

        // Mango Yogurt
        ['Mango Yogurt', 'Yogurt Base', 180, 'ml'],
        ['Mango Yogurt', 'Mango Puree', 40, 'ml'],
        ['Mango Yogurt', 'Sugar Syrup', 15, 'ml'],

        // Mango Mocktail
        ['Mango Mocktail', 'Mango Puree', 60, 'ml'],
        ['Mango Mocktail', 'Sugar Syrup', 15, 'ml'],
        ['Mango Mocktail', 'Ice Cubes', 6, 'pcs'],
        ['Mango Mocktail', 'Hot Water', 80, 'ml'],

        // Blueberry Mocktail
        ['Blueberry Mocktail', 'Blueberry Syrup', 40, 'ml'],
        ['Blueberry Mocktail', 'Sugar Syrup', 15, 'ml'],
        ['Blueberry Mocktail', 'Ice Cubes', 6, 'pcs'],
        ['Blueberry Mocktail', 'Fresh Milk', 80, 'ml'],

        // Bacon Carbonara
        ['Bacon Carbonara', 'Spaghetti Noodles', 150, 'g'],
        ['Bacon Carbonara', 'Bacon Strips', 3, 'pcs'],
        ['Bacon Carbonara', 'Egg', 1, 'pcs'],
        ['Bacon Carbonara', 'Parmesan Cheese', 20, 'g'],
        ['Bacon Carbonara', 'Heavy Cream', 50, 'ml'],
        ['Bacon Carbonara', 'Butter', 10, 'g'],

        // Creamy Shrimp Pesto
        ['Creamy Shrimp Pesto', 'Spaghetti Noodles', 150, 'g'],
        ['Creamy Shrimp Pesto', 'Shrimp', 80, 'g'],
        ['Creamy Shrimp Pesto', 'Pesto Sauce', 40, 'ml'],
        ['Creamy Shrimp Pesto', 'Heavy Cream', 50, 'ml'],
        ['Creamy Shrimp Pesto', 'Garlic', 2, 'cloves'],
        ['Creamy Shrimp Pesto', 'Butter', 10, 'g'],

        // Beef Burger
        ['Beef Burger', 'Beef Patty', 1, 'pcs'],
        ['Beef Burger', 'Burger Bun', 1, 'pcs'],
        ['Beef Burger', 'Lettuce', 15, 'g'],
        ['Beef Burger', 'Tomato', 1, 'pcs'],
        ['Beef Burger', 'Cheese Slice', 1, 'pcs'],

        // Bacon Burger
        ['Bacon Burger', 'Beef Patty', 1, 'pcs'],
        ['Bacon Burger', 'Burger Bun', 1, 'pcs'],
        ['Bacon Burger', 'Bacon Strips', 2, 'pcs'],
        ['Bacon Burger', 'Lettuce', 15, 'g'],
        ['Bacon Burger', 'Cheese Slice', 1, 'pcs'],

        // Hungarian Sausage
        ['Hungarian Sausage', 'Hungarian Sausage', 1, 'pcs'],
        ['Hungarian Sausage', 'French Fries', 100, 'g'],
        ['Hungarian Sausage', 'Cooking Oil', 30, 'ml'],

        // Fish with Fries
        ['Fish with Fries', 'Fish Fillet', 150, 'g'],
        ['Fish with Fries', 'French Fries', 100, 'g'],
        ['Fish with Fries', 'Breadcrumbs', 30, 'g'],
        ['Fish with Fries', 'Cooking Oil', 50, 'ml'],

        // Regular Fries (Cheese)
        ['Regular Fries (Cheese)', 'French Fries', 150, 'g'],
        ['Regular Fries (Cheese)', 'Cheddar Cheese Sauce', 40, 'ml'],
        ['Regular Fries (Cheese)', 'Cooking Oil', 50, 'ml'],

        // Loaded Fries
        ['Loaded Fries', 'French Fries', 200, 'g'],
        ['Loaded Fries', 'Cheddar Cheese Sauce', 60, 'ml'],
        ['Loaded Fries', 'Bacon Strips', 2, 'pcs'],
        ['Loaded Fries', 'Cooking Oil', 50, 'ml'],
    ];

    $stmtInsertRecipe = $pdo->prepare("
        INSERT INTO recipe_ingredients (product_id, ingredient_id, quantity_per_serving, unit) 
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity_per_serving = VALUES(quantity_per_serving), unit = VALUES(unit)
    ");

    $linkedCount = 0;
    foreach ($recipesData as $r) {
        $pKey = strtolower(trim($r[0]));
        $iKey = strtolower(trim($r[1]));
        $qty = (float)$r[2];
        $unit = $r[3];

        if (!isset($prodMap[$pKey])) {
            echo "Warning: Product not found: {$r[0]}\n";
            continue;
        }
        if (!isset($ingMap[$iKey])) {
            echo "Warning: Ingredient not found: {$r[1]}\n";
            continue;
        }

        $pId = $prodMap[$pKey];
        $iId = $ingMap[$iKey];

        $stmtInsertRecipe->execute([$pId, $iId, $qty, $unit]);
        $linkedCount++;
    }

    echo "✓ Successfully linked $linkedCount Recipe Ingredient mappings.\n";

    $pdo->commit();
    echo "\n=== Database Successfully Seeded! ===\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error seeding database: " . $e->getMessage() . "\n";
}
