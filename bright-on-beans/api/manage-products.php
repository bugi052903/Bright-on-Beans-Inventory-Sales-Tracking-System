<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();
ensureIngredientStockBatchSchema($pdo);

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$csrfToken = $input['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$action = $input['action'] ?? '';


try {
    if ($action === 'add' || $action === 'edit') {
        $productId = $input['product_id'] ?? null;
        $name = trim($input['name'] ?? '');
        $category = trim($input['category'] ?? '');
        $price = floatval($input['price'] ?? 0);
        $status = trim($input['status'] ?? 'active');
        $ingredients = $input['ingredients'] ?? []; // [{id, qty, unit}]

        if (!$name || !$category || $price < 0) {
            throw new Exception("Invalid product data.");
        }

        $pdo->beginTransaction();

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO products (name, category, price, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $category, $price, $status]);
            $productId = $pdo->lastInsertId();
        } else {
            $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, price=?, status=? WHERE product_id=?");
            $stmt->execute([$name, $category, $price, $status, $productId]);
            // Clear old recipe
            $pdo->prepare("DELETE FROM recipe_ingredients WHERE product_id=?")->execute([$productId]);
        }

        // Insert new recipe
        $stmtRecipe = $pdo->prepare("INSERT INTO recipe_ingredients (product_id, ingredient_id, quantity_per_serving, unit) VALUES (?, ?, ?, ?)");
        foreach ($ingredients as $ing) {
            $ingId = (int)$ing['id'];
            $qty = floatval($ing['qty']);
            $unit = trim($ing['unit']);
            if ($ingId > 0 && $qty > 0) {
                $stmtActiveIngredient = $pdo->prepare("SELECT is_archived FROM ingredients WHERE ingredient_id = ?");
                $stmtActiveIngredient->execute([$ingId]);
                $isArchived = $stmtActiveIngredient->fetchColumn();
                if ($isArchived === false || (int)$isArchived === 1) {
                    throw new Exception("Archived or missing ingredients cannot be added to a product recipe.");
                }
                $stmtRecipe->execute([$productId, $ingId, $qty, $unit]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Product {$action}ed successfully."]);

    } elseif ($action === 'delete') {
        $productId = $input['product_id'] ?? null;
        if (!$productId) throw new Exception("Product ID required.");

        // cascade delete will handle recipe_ingredients if set, otherwise do it manually
        $pdo->beginTransaction();
        $pdo->prepare("DELETE FROM recipe_ingredients WHERE product_id=?")->execute([$productId]);
        $pdo->prepare("DELETE FROM products WHERE product_id=?")->execute([$productId]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => "Product deleted."]);
    } else {
        throw new Exception("Invalid action.");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
