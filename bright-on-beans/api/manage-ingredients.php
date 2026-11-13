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
        $id = $input['ingredient_id'] ?? null;
        $name = trim($input['name'] ?? '');
        $unit = trim($input['unit'] ?? '');
        $stock = floatval($input['current_stock'] ?? 0);
        $min = floatval($input['minimum_level'] ?? 0);
        $unitCost = floatval($input['unit_cost'] ?? 0);
        $expirationDate = !empty($input['expiration_date']) ? $input['expiration_date'] : null;
        $dateAdded = !empty($input['date_added']) ? $input['date_added'] : date('Y-m-d');

        if (!$name || !$unit) {
            throw new Exception("Name and unit required.");
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO ingredients (name, unit, current_stock, minimum_level, date_added, unit_cost, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $unit, $stock, $min, $dateAdded, $unitCost, $expirationDate]);
        } else {
            $stmt = $pdo->prepare("UPDATE ingredients SET name=?, unit=?, current_stock=?, minimum_level=?, date_added=?, unit_cost=?, expiration_date=? WHERE ingredient_id=?");
            $stmt->execute([$name, $unit, $stock, $min, $dateAdded, $unitCost, $expirationDate, $id]);
            reconcileIngredientStockBatches($pdo, $id, $stock);
        }
        echo json_encode(['success' => true, 'message' => "Ingredient {$action}ed."]);

    } elseif ($action === 'delete') {
        $id = $input['ingredient_id'] ?? null;
        if (!$id) throw new Exception("Ingredient ID required.");

        $stmt = $pdo->prepare("SELECT current_stock FROM ingredients WHERE ingredient_id = ?");
        $stmt->execute([$id]);
        $currentStock = $stmt->fetchColumn();
        if ($currentStock === false) throw new Exception("Ingredient not found.");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM recipe_ingredients WHERE ingredient_id=?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            if ((float)$currentStock > 0) {
                throw new Exception("Ingredient still has stock and cannot be deleted.");
            }
            $pdo->prepare("UPDATE ingredients SET is_archived = 1 WHERE ingredient_id = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Ingredient archived; product recipes were kept."]);
        } else {
            $pdo->prepare("DELETE FROM ingredient_stock_batches WHERE ingredient_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM ingredients WHERE ingredient_id=?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Ingredient deleted."]);
        }
    } else {
        throw new Exception("Invalid action.");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
