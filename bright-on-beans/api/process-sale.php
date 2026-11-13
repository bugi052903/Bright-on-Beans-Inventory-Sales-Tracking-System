<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$items = $input['items'] ?? [];
if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Cart is empty']);
    exit;
}

$userId = getCurrentUserId();

try {
    ensureIngredientStockBatchSchema($pdo);
    $pdo->beginTransaction();

    $totalAmount = 0;
    $processedItems = [];

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? $item['id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 1);
        if ($productId <= 0 || $quantity <= 0) continue;

        $stmt = $pdo->prepare("SELECT product_id, name, price FROM products WHERE product_id = ? AND status = 'available'");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) {
            throw new Exception("Product not found or unavailable.");
        }

        $subtotal = $product['price'] * $quantity;
        $totalAmount += $subtotal;
        $processedItems[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit_price' => $product['price'],
            'subtotal' => $subtotal,
            'ingredient_usage' => []
        ];

        $stmtRecipe = $pdo->prepare("SELECT ri.ingredient_id, ri.quantity_per_serving, i.name, i.unit FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.ingredient_id WHERE ri.product_id = ?");
        $stmtRecipe->execute([$productId]);
        $recipe = $stmtRecipe->fetchAll();
        if (empty($recipe)) {
            throw new Exception("Product '{$product['name']}' has no ingredients defined in its recipe. Cannot process sale.");
        }
        foreach ($recipe as $ing) {
            $ingKey = strtolower(trim($ing['name']));
            $usageMilli = (int)round((float)$ing['quantity_per_serving'] * $quantity * 1000);
            if (!isset($processedItems[count($processedItems) - 1]['ingredient_usage'][$ingKey])) {
                $processedItems[count($processedItems) - 1]['ingredient_usage'][$ingKey] = [
                    'name' => trim($ing['name']),
                    'amount_milli' => 0,
                    'unit' => $ing['unit']
                ];
            }
            $processedItems[count($processedItems) - 1]['ingredient_usage'][$ingKey]['amount_milli'] += $usageMilli;
        }
    }

    if (empty($processedItems)) {
        throw new Exception("No valid items in the cart.");
    }

    // Load eligible inventory batches while retaining the expiry of existing merged stock.
    $totalCost = 0.00;
    $deductions = [];
    $ingredientUsage = [];
    foreach ($processedItems as $itemIndex => $processedItem) {
        foreach ($processedItem['ingredient_usage'] as $ingKey => $usage) {
            if (!isset($ingredientUsage[$ingKey])) {
                $ingredientUsage[$ingKey] = $usage;
                $ingredientUsage[$ingKey]['amount_milli'] = 0;
            }
            $ingredientUsage[$ingKey]['amount_milli'] += $usage['amount_milli'];
        }
    }
    ksort($ingredientUsage);

    $ingredientPools = [];
    $ingredientAggregateStock = [];
    $stmtIngredients = $pdo->prepare("
        SELECT ingredient_id, name, current_stock, minimum_level, unit_cost, expiration_date,
               COALESCE(date_added, DATE(created_at)) AS received_date
        FROM ingredients
        WHERE LOWER(TRIM(name)) = ? AND is_archived = 0
        ORDER BY ingredient_id
        FOR UPDATE
    ");
    $stmtStockBatches = $pdo->prepare("
        SELECT b.batch_id, b.ingredient_id, b.remaining_quantity, b.expiration_date, b.unit_cost,
               DATE(b.created_at) AS received_date
        FROM ingredient_stock_batches b
        WHERE b.ingredient_id = ? AND b.remaining_quantity > 0
        ORDER BY b.batch_id
        FOR UPDATE
    ");

    foreach ($ingredientUsage as $ingKey => $info) {
        $stmtIngredients->execute([$ingKey]);
        $ingredients = $stmtIngredients->fetchAll();
        $ingredientIds = array_column($ingredients, 'ingredient_id');
        $trackedByIngredient = [];
        $pools = [];

        foreach ($ingredients as $ingredient) {
            $ingredientId = (int)$ingredient['ingredient_id'];
            $aggregateMilli = (int)round((float)$ingredient['current_stock'] * 1000);
            $ingredientAggregateStock[$ingredientId] = $aggregateMilli;

            $stmtStockBatches->execute([$ingredientId]);
            $stockBatches = $stmtStockBatches->fetchAll();
            foreach ($stockBatches as $batch) {
                $quantityMilli = (int)round((float)$batch['remaining_quantity'] * 1000);
                $trackedByIngredient[$ingredientId] = ($trackedByIngredient[$ingredientId] ?? 0) + $quantityMilli;
                if ($batch['expiration_date'] === null || $batch['expiration_date'] > date('Y-m-d')) {
                    $pools[] = [
                        'source' => 'batch',
                        'batch_id' => (int)$batch['batch_id'],
                        'ingredient_id' => $ingredientId,
                        'name' => $ingredient['name'],
                        'minimum_level' => (float)$ingredient['minimum_level'],
                        'unit_cost' => (float)$batch['unit_cost'],
                        'expiration_date' => $batch['expiration_date'],
                        'received_date' => $batch['received_date'],
                        'stock_milli' => $quantityMilli
                    ];
                }
            }
        }

        foreach ($ingredients as $ingredient) {
            $ingredientId = (int)$ingredient['ingredient_id'];
            $baseMilli = max(0, $ingredientAggregateStock[$ingredientId] - ($trackedByIngredient[$ingredientId] ?? 0));
            if ($baseMilli > 0 && ($ingredient['expiration_date'] === null || $ingredient['expiration_date'] > date('Y-m-d'))) {
                $pools[] = [
                    'source' => 'ingredient',
                    'batch_id' => null,
                    'ingredient_id' => $ingredientId,
                    'name' => $ingredient['name'],
                    'minimum_level' => (float)$ingredient['minimum_level'],
                    'unit_cost' => (float)$ingredient['unit_cost'],
                    'expiration_date' => $ingredient['expiration_date'],
                    'received_date' => $ingredient['received_date'],
                    'stock_milli' => $baseMilli
                ];
            }
        }

        usort($pools, function ($a, $b) {
            $receivedOrder = strcmp($a['received_date'] ?? '', $b['received_date'] ?? '');
            if ($receivedOrder !== 0) return $receivedOrder;
            if ($a['expiration_date'] === $b['expiration_date']) {
                return $a['ingredient_id'] <=> $b['ingredient_id'];
            }
            if ($a['expiration_date'] === null) return 1;
            if ($b['expiration_date'] === null) return -1;
            return strcmp($a['expiration_date'], $b['expiration_date']);
        });

        $totalAvailableMilli = array_sum(array_column($pools, 'stock_milli'));
        if ($totalAvailableMilli < $info['amount_milli']) {
            throw new Exception("Insufficient unexpired stock for {$info['name']} (Required: " . number_format($info['amount_milli'] / 1000, 1) . " {$info['unit']}, Available: " . number_format($totalAvailableMilli / 1000, 1) . " {$info['unit']}). Expired stock cannot be used.");
        }
        $ingredientPools[$ingKey] = $pools;
    }

    foreach ($processedItems as $itemIndex => $processedItem) {
        foreach ($processedItem['ingredient_usage'] as $ingKey => $usage) {
            $remainingNeededMilli = $usage['amount_milli'];
            foreach ($ingredientPools[$ingKey] as &$pool) {
                if ($remainingNeededMilli <= 0) break;
                if ($pool['stock_milli'] <= 0) continue;

                $deductionMilli = min($pool['stock_milli'], $remainingNeededMilli);
                $pool['stock_milli'] -= $deductionMilli;
                $remainingNeededMilli -= $deductionMilli;
                $ingredientId = $pool['ingredient_id'];
                $stockBeforeMilli = $ingredientAggregateStock[$ingredientId];
                $ingredientAggregateStock[$ingredientId] -= $deductionMilli;

                $deductionQty = $deductionMilli / 1000;
                $totalCost += $deductionQty * $pool['unit_cost'];
                $deductions[] = [
                    'item_index' => $itemIndex,
                    'ingredient_id' => $ingredientId,
                    'batch_id' => $pool['batch_id'],
                    'stock_before' => $stockBeforeMilli / 1000,
                    'stock_after' => $ingredientAggregateStock[$ingredientId] / 1000,
                    'change_quantity' => $deductionQty,
                    'name' => $pool['name'],
                    'minimum_level' => $pool['minimum_level']
                ];
            }
            unset($pool);
        }
    }

    // Process Payment Method & Change Calculation
    $paymentMethod = strtolower($input['payment_method'] ?? 'cash');
    if (!in_array($paymentMethod, ['cash', 'gcash', 'maya'])) {
        $paymentMethod = 'cash';
    }

    $amountTendered = isset($input['amount_tendered']) ? (float)$input['amount_tendered'] : $totalAmount;
    if ($amountTendered < $totalAmount) {
        throw new Exception("Amount received (₱" . number_format($amountTendered, 2) . ") is less than the total amount (₱" . number_format($totalAmount, 2) . ").");
    }
    $changeAmount = $amountTendered - $totalAmount;
    $paymentRef = !empty($input['payment_reference']) ? trim($input['payment_reference']) : null;

    $stmtTx = $pdo->prepare("INSERT INTO sales_transactions (user_id, total_amount, total_cost, payment_method, amount_tendered, change_amount, payment_reference) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmtTx->execute([$userId, $totalAmount, $totalCost, $paymentMethod, $amountTendered, $changeAmount, $paymentRef]);
    $transactionId = $pdo->lastInsertId();

    $stmtItem = $pdo->prepare("INSERT INTO sales_items (transaction_id, product_id, quantity, unit_price, subtotal) VALUES (?, ?, ?, ?, ?)");
    $salesItemIds = [];
    foreach ($processedItems as $itemIndex => $pi) {
        $stmtItem->execute([$transactionId, $pi['product_id'], $pi['quantity'], $pi['unit_price'], $pi['subtotal']]);
        $salesItemIds[$itemIndex] = (int)$pdo->lastInsertId();
    }

    $lowStockAlerts = [];
    $stmtUpdateStock = $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?");
    $stmtHistory = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, reference_id, notes) VALUES (?, ?, ?, ?, 'sale_deduction', ?, ?)");
    $stmtUpdateBatch = $pdo->prepare("UPDATE ingredient_stock_batches SET remaining_quantity = ? WHERE batch_id = ?");
    $stmtSaleDeduction = $pdo->prepare("
        INSERT INTO sale_stock_deductions (sales_item_id, ingredient_id, batch_id, quantity)
        VALUES (?, ?, ?, ?)
    ");

    foreach ($ingredientPools as $pools) {
        foreach ($pools as $pool) {
            if ($pool['source'] === 'batch') {
                $stmtUpdateBatch->execute([$pool['stock_milli'] / 1000, $pool['batch_id']]);
            }
        }
    }

    foreach ($deductions as $d) {
        $stmtUpdateStock->execute([$d['stock_after'], $d['ingredient_id']]);
        $stmtHistory->execute([$d['ingredient_id'], $d['stock_before'], $d['stock_after'], $d['change_quantity'], $transactionId, "FIFO Deduction from Sale #$transactionId"]);
        $stmtSaleDeduction->execute([
            $salesItemIds[$d['item_index']],
            $d['ingredient_id'],
            $d['batch_id'],
            $d['change_quantity']
        ]);

        if ($d['stock_after'] <= $d['minimum_level']) {
            $lowStockAlerts[] = $d['name'];
        }
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'transaction_id' => $transactionId,
        'total_amount' => $totalAmount,
        'low_stock_alerts' => array_unique($lowStockAlerts)
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
