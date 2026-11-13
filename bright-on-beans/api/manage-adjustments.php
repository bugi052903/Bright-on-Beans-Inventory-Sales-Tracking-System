<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$csrfToken = $input['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$userId = getCurrentUserId();

$ingId = $input['ingredient_id'] ?? null;
$type = $input['adjustment_type'] ?? ''; // add, deduct
$qty = floatval($input['quantity'] ?? 0);
$reason = trim($input['reason'] ?? '');

try {
    if (!$ingId || !in_array($type, ['add', 'deduct']) || $qty <= 0 || !$reason) {
        throw new Exception("Invalid adjustment data.");
    }

    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT current_stock FROM ingredients WHERE ingredient_id=? FOR UPDATE");
    $stmt->execute([$ingId]);
    $ing = $stmt->fetch();
    if (!$ing) throw new Exception("Ingredient not found.");

    $before = (float)$ing['current_stock'];
    $after = $type === 'add' ? $before + $qty : $before - $qty;

    if ($after < 0) {
        throw new Exception("Cannot deduct more than current stock.");
    }

    // Update stock
    $pdo->prepare("UPDATE ingredients SET current_stock=? WHERE ingredient_id=?")->execute([$after, $ingId]);
    reconcileIngredientStockBatches($pdo, $ingId, $after);

    // Insert adjustment record
    $stmtAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, ?, ?, ?, ?)");
    $stmtAdj->execute([$ingId, $userId, $type, $qty, $reason, $after]);
    $adjId = $pdo->lastInsertId();

    // Log history
    $changeType = $type === 'add' ? 'adjustment_add' : 'adjustment_deduct';
    $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, reference_id, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtHist->execute([$ingId, $before, $after, $qty, $changeType, $adjId, $reason]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => "Stock adjusted successfully."]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
