<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF security token.']);
    exit;
}

$transactionId = (int)($input['transaction_id'] ?? 0);
$voidMode = $input['void_mode'] ?? 'all'; // 'all' or 'partial'
$voidItemsInput = $input['void_items'] ?? []; // Array of { item_id, void_qty }
$reason = trim($input['reason'] ?? 'Customer Cancellation / Order Void');
$adminId = getCurrentUserId();

if ($transactionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID provided.']);
    exit;
}

if (empty($reason)) {
    $reason = 'Customer Cancellation / Order Void';
}

try {
    ensureIngredientStockBatchSchema($pdo);
    $pdo->beginTransaction();

    // 1. Check if transaction exists and is completed
    $stmtTx = $pdo->prepare("SELECT * FROM sales_transactions WHERE transaction_id = ? FOR UPDATE");
    $stmtTx->execute([$transactionId]);
    $tx = $stmtTx->fetch();

    if (!$tx) {
        throw new Exception("Transaction #{$transactionId} not found.");
    }

    if ($tx['status'] === 'voided') {
        throw new Exception("Transaction #{$transactionId} is already fully voided.");
    }

    // 2. Fetch all sales items for this transaction
    $stmtItems = $pdo->prepare("
        SELECT si.*, p.name as product_name 
        FROM sales_items si 
        JOIN products p ON si.product_id = p.product_id 
        WHERE si.transaction_id = ? 
        FOR UPDATE
    ");
    $stmtItems->execute([$transactionId]);
    $existingItems = $stmtItems->fetchAll();

    if (empty($existingItems)) {
        throw new Exception("No purchased items found for Transaction #{$transactionId}.");
    }

    $itemsToVoid = []; // Map of item_id => void_qty
    $itemSummaryList = [];

    if ($voidMode === 'all') {
        foreach ($existingItems as $it) {
            $qty = (int)$it['quantity'];
            if ($qty > 0) {
                $itemsToVoid[$it['item_id']] = [
                    'item' => $it,
                    'void_qty' => $qty
                ];
                $itemSummaryList[] = "{$qty}x {$it['product_name']}";
            }
        }
    } else {
        // Partial mode: map input void items
        $inputMap = [];
        foreach ($voidItemsInput as $vi) {
            $itemId = (int)($vi['item_id'] ?? 0);
            $vQty = (int)($vi['void_qty'] ?? 0);
            if ($itemId > 0 && $vQty > 0) {
                $inputMap[$itemId] = $vQty;
            }
        }

        if (empty($inputMap)) {
            throw new Exception("Please select at least one item and quantity to void.");
        }

        foreach ($existingItems as $it) {
            $itemId = (int)$it['item_id'];
            if (isset($inputMap[$itemId])) {
                $vQty = $inputMap[$itemId];
                $currQty = (int)$it['quantity'];
                if ($vQty > $currQty) {
                    throw new Exception("Cannot void {$vQty}x {$it['product_name']}. Only {$currQty} active in this order.");
                }
                $itemsToVoid[$itemId] = [
                    'item' => $it,
                    'void_qty' => $vQty
                ];
                $itemSummaryList[] = "{$vQty}x {$it['product_name']}";
            }
        }
    }

    if (empty($itemsToVoid)) {
        throw new Exception("No active items available to void in this transaction.");
    }

    // 3. Compute ingredient restorations per item
    $restoreIngredientMap = [];

    $stmtRecipe = $pdo->prepare("
        SELECT ri.*, i.name as ing_name, i.unit, i.current_stock 
        FROM recipe_ingredients ri 
        JOIN ingredients i ON ri.ingredient_id = i.ingredient_id 
        WHERE ri.product_id = ?
    ");
    $stmtSaleDeductions = $pdo->prepare("
        SELECT d.ingredient_id, d.batch_id, d.quantity, i.name AS ing_name, i.unit
        FROM sale_stock_deductions d
        JOIN ingredients i ON i.ingredient_id = d.ingredient_id
        WHERE d.sales_item_id = ?
        FOR UPDATE
    ");

    $stmtUpItem = $pdo->prepare("
        UPDATE sales_items 
        SET quantity = ?, subtotal = ?, voided_quantity = ? 
        WHERE item_id = ?
    ");

    foreach ($itemsToVoid as $itemId => $data) {
        $it = $data['item'];
        $vQty = $data['void_qty'];
        $currQty = (int)$it['quantity'];
        $unitPrice = (float)$it['unit_price'];

        $newQty = $currQty - $vQty;
        $newSubtotal = $newQty * $unitPrice;
        $newVoidedQty = (int)$it['voided_quantity'] + $vQty;

        $stmtUpItem->execute([$newQty, $newSubtotal, $newVoidedQty, $itemId]);

        $stmtSaleDeductions->execute([$itemId]);
        $saleDeductions = $stmtSaleDeductions->fetchAll();

        if (!empty($saleDeductions)) {
            $originalQty = $currQty + (int)$it['voided_quantity'];
            foreach ($saleDeductions as $deduction) {
                $sourceKey = $deduction['batch_id'] === null
                    ? 'ingredient:' . $deduction['ingredient_id']
                    : 'batch:' . $deduction['batch_id'];
                $restoreQty = round((float)$deduction['quantity'] * $vQty / $originalQty, 3);

                if (!isset($restoreIngredientMap[$sourceKey])) {
                    $restoreIngredientMap[$sourceKey] = [
                        'ing_id' => (int)$deduction['ingredient_id'],
                        'batch_id' => $deduction['batch_id'] === null ? null : (int)$deduction['batch_id'],
                        'name' => $deduction['ing_name'],
                        'unit' => $deduction['unit'],
                        'qty' => 0.0
                    ];
                }
                $restoreIngredientMap[$sourceKey]['qty'] += $restoreQty;
            }
        } else {
            // Older sales have no batch-level deduction records.
            $stmtRecipe->execute([$it['product_id']]);
            $recipes = $stmtRecipe->fetchAll();
            foreach ($recipes as $r) {
                $ingId = (int)$r['ingredient_id'];
                $sourceKey = 'ingredient:' . $ingId;
                $totRestore = (float)$r['quantity_per_serving'] * $vQty;

                if (!isset($restoreIngredientMap[$sourceKey])) {
                    $restoreIngredientMap[$sourceKey] = [
                        'ing_id' => $ingId,
                        'batch_id' => null,
                        'name' => $r['ing_name'],
                        'unit' => $r['unit'],
                        'qty' => 0.0
                    ];
                }
                $restoreIngredientMap[$sourceKey]['qty'] += $totRestore;
            }
        }
    }

    // Sort ingredients alphabetically for clean audit logging
    usort($restoreIngredientMap, function ($a, $b) {
        return strcmp($a['name'], $b['name']);
    });

    // 4. Restore ingredients to stock and record in audit trails
    $stmtUpStock = $pdo->prepare("UPDATE ingredients SET current_stock = current_stock + ? WHERE ingredient_id = ?");
    $stmtUpBatch = $pdo->prepare("UPDATE ingredient_stock_batches SET remaining_quantity = remaining_quantity + ? WHERE batch_id = ?");
    $stmtInsertHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, reference_id, notes) VALUES (?, ?, ?, ?, 'adjustment_add', ?, ?)");
    $stmtInsertAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, 'add', ?, ?, ?)");

    $summaryStr = implode(', ', $itemSummaryList);
    $restoredSummaryList = [];

    foreach ($restoreIngredientMap as $ing) {
        $ingId = $ing['ing_id'];
        $qtyToRestore = (float)$ing['qty'];
        if ($qtyToRestore <= 0) continue;

        // Fetch live current stock
        $stmtCurr = $pdo->prepare("SELECT current_stock FROM ingredients WHERE ingredient_id = ? FOR UPDATE");
        $stmtCurr->execute([$ingId]);
        $currStock = (float)$stmtCurr->fetchColumn();

        $newStock = $currStock + $qtyToRestore;
        $stmtUpStock->execute([$qtyToRestore, $ingId]);
        if ($ing['batch_id'] !== null) {
            $stmtUpBatch->execute([$qtyToRestore, $ing['batch_id']]);
        }

        $auditNote = "Restored stock from Voided Sale #{$transactionId} [{$summaryStr}] (Reason: {$reason})";
        $stmtInsertHist->execute([$ingId, $currStock, $newStock, $qtyToRestore, $transactionId, $auditNote]);
        $stmtInsertAdj->execute([$ingId, $adminId, $qtyToRestore, "Voided Sale #{$transactionId} [{$summaryStr}]: {$reason}", $newStock]);

        $restoredSummaryList[] = formatStock($qtyToRestore) . " " . $ing['unit'] . " " . $ing['name'];
    }

    // 5. Check remaining active quantities for the transaction
    $stmtRemain = $pdo->prepare("SELECT SUM(quantity) as tot_qty, SUM(subtotal) as new_total FROM sales_items WHERE transaction_id = ?");
    $stmtRemain->execute([$transactionId]);
    $remainRow = $stmtRemain->fetch();

    $totRemainingQty = (int)($remainRow['tot_qty'] ?: 0);
    $newTotalAmount = (float)($remainRow['new_total'] ?: 0.0);

    $refundMethod = $input['refund_method'] ?? 'cash';

    if ($totRemainingQty === 0) {
        // Fully voided
        $stmtVoidTx = $pdo->prepare("
            UPDATE sales_transactions 
            SET status = 'voided', total_amount = ?, void_reason = ?, voided_at = NOW(), voided_by = ?, refund_method = ? 
            WHERE transaction_id = ?
        ");
        $stmtVoidTx->execute([$newTotalAmount, $reason, $adminId, $refundMethod, $transactionId]);
        $statusMsg = "Sale #{$transactionId} is now fully voided.";
    } else {
        // Partially voided
        $stmtVoidTx = $pdo->prepare("
            UPDATE sales_transactions 
            SET total_amount = ?, void_reason = CONCAT(COALESCE(CONCAT(void_reason, ' | '), ''), ?) 
            WHERE transaction_id = ?
        ");
        $partialNote = "Partially voided [{$summaryStr}] (Reason: {$reason})";
        $stmtVoidTx->execute([$newTotalAmount, $partialNote, $transactionId]);
        $statusMsg = "Voided [{$summaryStr}] from Sale #{$transactionId}. Remaining sale total: ₱" . number_format($newTotalAmount, 2);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $statusMsg,
        'transaction_id' => $transactionId,
        'new_total' => $newTotalAmount,
        'is_fully_voided' => ($totRemainingQty === 0),
        'restored_summary' => $restoredSummaryList
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
