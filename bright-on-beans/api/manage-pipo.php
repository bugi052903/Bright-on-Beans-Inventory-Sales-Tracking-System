<?php
/**
 * Bright on Beans Coffee House
 * PIPO (Pallet In / Pallet Out) Management API Handler
 */

require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = $input['action'] ?? $_GET['action'] ?? '';

// CSRF validation for mutating actions
if (in_array($action, ['pallet_in', 'stage_to_active', 'pallet_out', 'relocate', 'update_pallet'])) {
    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        jsonResponse(['success' => false, 'message' => 'Invalid security token (CSRF). Please refresh the page.'], 403);
    }
}

$userId = getCurrentUserId();

try {
    ensureIngredientStockBatchSchema($pdo);
    switch ($action) {

        // ─────────────────────────────────────────────────────────────
        // 1. PALLET IN (Inbound Bulk Receiving)
        // ─────────────────────────────────────────────────────────────
        case 'pallet_in':
            $ingredientId = (int)($input['ingredient_id'] ?? 0);
            $palletCode = trim($input['pallet_code'] ?? '');
            $batchNumber = trim($input['batch_number'] ?? '');
            $supplier = trim($input['supplier'] ?? '');
            $storageLocation = trim($input['storage_location'] ?? 'Pallet Rack A-01 (Coffee Beans & Grounds)');
            $quantity = floatval($input['quantity'] ?? 0);
            $unitCost = floatval($input['unit_cost'] ?? 0);
            $receivedDate = !empty($input['received_date']) ? $input['received_date'] : date('Y-m-d');
            $expirationDate = !empty($input['expiration_date']) ? $input['expiration_date'] : null;
            $notes = trim($input['notes'] ?? '');

            if ($ingredientId <= 0) {
                throw new Exception("Please select a valid ingredient.");
            }
            if ($quantity <= 0) {
                throw new Exception("Received quantity must be greater than zero.");
            }
            if (empty($palletCode)) {
                $palletCode = generatePalletCode($pdo);
            }

            $pdo->beginTransaction();

            // Fetch ingredient to verify and get standard unit
            $stmtIng = $pdo->prepare("SELECT ingredient_id, name, unit, unit_cost FROM ingredients WHERE ingredient_id = ?");
            $stmtIng->execute([$ingredientId]);
            $ingredient = $stmtIng->fetch();
            if (!$ingredient) {
                throw new Exception("Selected ingredient was not found.");
            }

            $unit = $ingredient['unit'];
            if ($unitCost <= 0) {
                $unitCost = (float)$ingredient['unit_cost'];
            }
            $totalCost = $quantity * $unitCost;

            // Ensure pallet code uniqueness
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM pallets WHERE pallet_code = ?");
            $stmtCheck->execute([$palletCode]);
            if ($stmtCheck->fetchColumn() > 0) {
                $palletCode = generatePalletCode($pdo);
            }

            // Insert new pallet (stored as reserved stock)
            $stmtInsert = $pdo->prepare("
                INSERT INTO pallets (
                    pallet_code, ingredient_id, batch_number, storage_location, 
                    supplier, initial_quantity, remaining_quantity, unit, 
                    unit_cost, total_cost, status, received_date, expiration_date, 
                    created_by, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'stored', ?, ?, ?, ?)
            ");
            $stmtInsert->execute([
                $palletCode, $ingredientId, $batchNumber, $storageLocation,
                $supplier, $quantity, $quantity, $unit,
                $unitCost, $totalCost, $receivedDate, $expirationDate,
                $userId, $notes
            ]);
            $palletId = $pdo->lastInsertId();

            // Log movement in pallet_movements
            $stmtMove = $pdo->prepare("
                INSERT INTO pallet_movements (
                    pallet_id, movement_type, quantity, quantity_before, quantity_after,
                    unit, source_location, destination, purpose_reason, user_id
                ) VALUES (?, 'pallet_in', ?, 0, ?, ?, 'Receiving Dock / Supplier', ?, ?, ?)
            ");
            $purpose = $notes ?: "Inbound Pallet Received (Batch: " . ($batchNumber ?: 'N/A') . ")";
            $stmtMove->execute([
                $palletId, $quantity, $quantity,
                $unit, $storageLocation, $purpose, $userId
            ]);

            // Log in inventory_history
            $stmtHist = $pdo->prepare("
                INSERT INTO inventory_history (
                    ingredient_id, stock_before, stock_after, change_quantity, 
                    change_type, reference_id, notes
                ) VALUES (?, 0, ?, ?, 'pallet_in', ?, ?)
            ");
            $histNotes = "Pallet In #{$palletCode}: " . formatStock($quantity) . " {$unit} placed in {$storageLocation}" . ($expirationDate ? " (Exp: " . formatDate($expirationDate) . ")" : "");
            $stmtHist->execute([
                $ingredientId, $quantity, $quantity, $palletId, $histNotes
            ]);

            $pdo->commit();

            $summary = getIngredientPalletSummary($pdo, $ingredientId);
            jsonResponse([
                'success'     => true,
                'message'     => "Pallet {$palletCode} successfully received and added to Reserved Stock.",
                'pallet_id'   => $palletId,
                'pallet_code' => $palletCode,
                'summary'     => $summary
            ]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 2. STAGE TO ACTIVE (Pallet Out -> Move to Coffee Bar Floor)
        // ─────────────────────────────────────────────────────────────
        case 'stage_to_active':
            $palletId = (int)($input['pallet_id'] ?? 0);
            $stageQty = floatval($input['quantity'] ?? 0);
            $notes = trim($input['notes'] ?? '');

            if ($palletId <= 0 || $stageQty <= 0) {
                throw new Exception("Invalid pallet ID or stage quantity.");
            }

            $pdo->beginTransaction();

            $stmtPallet = $pdo->prepare("SELECT * FROM pallets WHERE pallet_id = ? FOR UPDATE");
            $stmtPallet->execute([$palletId]);
            $pallet = $stmtPallet->fetch();
            if (!$pallet) {
                throw new Exception("Pallet not found.");
            }

            $remaining = (float)$pallet['remaining_quantity'];
            if ($stageQty > $remaining) {
                throw new Exception("Cannot stage " . formatStock($stageQty) . " {$pallet['unit']}. Only " . formatStock($remaining) . " {$pallet['unit']} remaining on this pallet.");
            }

            $newRemaining = $remaining - $stageQty;
            $newStatus = ($newRemaining <= 0) ? 'depleted' : 'in_use';

            // Update pallet remaining quantity and status
            $stmtUpPallet = $pdo->prepare("UPDATE pallets SET remaining_quantity = ?, status = ? WHERE pallet_id = ?");
            $stmtUpPallet->execute([$newRemaining, $newStatus, $palletId]);

            // Fetch and update active ingredient stock
            $stmtIng = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ? FOR UPDATE");
            $stmtIng->execute([$pallet['ingredient_id']]);
            $ing = $stmtIng->fetch();
            if (!$ing) {
                throw new Exception("Associated ingredient not found.");
            }

            $activeBefore = (float)$ing['current_stock'];
            $activeAfter = $activeBefore + $stageQty;

            $stmtBatch = $pdo->prepare("
                INSERT INTO ingredient_stock_batches
                    (ingredient_id, pallet_id, remaining_quantity, expiration_date, unit_cost)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmtBatch->execute([
                $pallet['ingredient_id'],
                $palletId,
                $stageQty,
                $pallet['expiration_date'],
                $pallet['unit_cost']
            ]);

            $stmtUpIng = $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?");
            $stmtUpIng->execute([$activeAfter, $pallet['ingredient_id']]);

            // Log movement
            $stmtMove = $pdo->prepare("
                INSERT INTO pallet_movements (
                    pallet_id, movement_type, quantity, quantity_before, quantity_after,
                    unit, source_location, destination, purpose_reason, user_id
                ) VALUES (?, 'pallet_out', ?, ?, ?, ?, ?, 'Main Bar / Active Floor', ?, ?)
            ");
            $purpose = $notes ?: "Staged to Active Coffee Bar Floor (Pallet {$pallet['pallet_code']})";
            $stmtMove->execute([
                $palletId, $stageQty, $remaining, $newRemaining,
                $pallet['unit'], $pallet['storage_location'], $purpose, $userId
            ]);

            // Log in inventory_adjustments
            $stmtAdj = $pdo->prepare("
                INSERT INTO inventory_adjustments (
                    ingredient_id, user_id, adjustment_type, quantity, reason, balance_after
                ) VALUES (?, ?, 'add', ?, ?, ?)
            ");
            $adjReason = "Staged from Pallet #{$pallet['pallet_code']} (Batch: " . ($pallet['batch_number'] ?: 'N/A') . ")";
            $stmtAdj->execute([
                $pallet['ingredient_id'], $userId, $stageQty, $adjReason, $activeAfter
            ]);
            $adjId = $pdo->lastInsertId();

            // Log in inventory_history
            $stmtHist = $pdo->prepare("
                INSERT INTO inventory_history (
                    ingredient_id, stock_before, stock_after, change_quantity, 
                    change_type, reference_id, notes
                ) VALUES (?, ?, ?, ?, 'adjustment_add', ?, ?)
            ");
            $stmtHist->execute([
                $pallet['ingredient_id'], $activeBefore, $activeAfter, $stageQty, $adjId, $adjReason
            ]);

            $pdo->commit();

            $summary = getIngredientPalletSummary($pdo, $pallet['ingredient_id']);
            jsonResponse([
                'success'   => true,
                'message'   => "Successfully staged " . formatStock($stageQty) . " {$pallet['unit']} to Active Bar Stock.",
                'summary'   => $summary
            ]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 3. PALLET OUT (Outbound Dispatch / External Transfer / Spoilage)
        // ─────────────────────────────────────────────────────────────
        case 'pallet_out':
            $palletId = (int)($input['pallet_id'] ?? 0);
            $dispatchQty = floatval($input['quantity'] ?? 0);
            $destination = trim($input['destination'] ?? 'Outbound Dispatch');
            $reason = trim($input['reason'] ?? 'Pallet Out Dispatch');

            if ($palletId <= 0 || $dispatchQty <= 0) {
                throw new Exception("Invalid pallet ID or dispatch quantity.");
            }

            $pdo->beginTransaction();

            $stmtPallet = $pdo->prepare("SELECT * FROM pallets WHERE pallet_id = ? FOR UPDATE");
            $stmtPallet->execute([$palletId]);
            $pallet = $stmtPallet->fetch();
            if (!$pallet) {
                throw new Exception("Pallet not found.");
            }

            $remaining = (float)$pallet['remaining_quantity'];
            if ($dispatchQty > $remaining) {
                throw new Exception("Cannot dispatch " . formatStock($dispatchQty) . " {$pallet['unit']}. Only " . formatStock($remaining) . " {$pallet['unit']} available.");
            }

            $newRemaining = $remaining - $dispatchQty;
            $newStatus = ($newRemaining <= 0) ? 'depleted' : 'in_use';

            $stmtUp = $pdo->prepare("UPDATE pallets SET remaining_quantity = ?, status = ? WHERE pallet_id = ?");
            $stmtUp->execute([$newRemaining, $newStatus, $palletId]);

            // Log movement
            $stmtMove = $pdo->prepare("
                INSERT INTO pallet_movements (
                    pallet_id, movement_type, quantity, quantity_before, quantity_after,
                    unit, source_location, destination, purpose_reason, user_id
                ) VALUES (?, 'pallet_out', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtMove->execute([
                $palletId, $dispatchQty, $remaining, $newRemaining,
                $pallet['unit'], $pallet['storage_location'], $destination, $reason, $userId
            ]);

            // Log inventory history
            $stmtHist = $pdo->prepare("
                INSERT INTO inventory_history (
                    ingredient_id, stock_before, stock_after, change_quantity, 
                    change_type, reference_id, notes
                ) VALUES (?, ?, ?, ?, 'pallet_out', ?, ?)
            ");
            $histNotes = "Pallet Out #{$pallet['pallet_code']} dispatched to {$destination}: {$reason}";
            $stmtHist->execute([
                $pallet['ingredient_id'], $remaining, $newRemaining, $dispatchQty, $palletId, $histNotes
            ]);

            $pdo->commit();

            $summary = getIngredientPalletSummary($pdo, $pallet['ingredient_id']);
            jsonResponse([
                'success' => true,
                'message' => "Successfully dispatched " . formatStock($dispatchQty) . " {$pallet['unit']} to {$destination}.",
                'summary' => $summary
            ]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 4. RELOCATE PALLET (Move Rack Bay)
        // ─────────────────────────────────────────────────────────────
        case 'relocate':
            $palletId = (int)($input['pallet_id'] ?? 0);
            $newLocation = trim($input['new_location'] ?? '');

            if ($palletId <= 0 || empty($newLocation)) {
                throw new Exception("Please provide a valid pallet ID and new storage location.");
            }

            $pdo->beginTransaction();

            $stmtPallet = $pdo->prepare("SELECT * FROM pallets WHERE pallet_id = ? FOR UPDATE");
            $stmtPallet->execute([$palletId]);
            $pallet = $stmtPallet->fetch();
            if (!$pallet) {
                throw new Exception("Pallet not found.");
            }

            $oldLocation = $pallet['storage_location'];
            if ($oldLocation === $newLocation) {
                throw new Exception("Pallet is already stored in {$newLocation}.");
            }

            $stmtUp = $pdo->prepare("UPDATE pallets SET storage_location = ? WHERE pallet_id = ?");
            $stmtUp->execute([$newLocation, $palletId]);

            // Log movement
            $stmtMove = $pdo->prepare("
                INSERT INTO pallet_movements (
                    pallet_id, movement_type, quantity, quantity_before, quantity_after,
                    unit, source_location, destination, purpose_reason, user_id
                ) VALUES (?, 'relocation', 0, ?, ?, ?, ?, ?, 'Storage Rack Bay Relocation', ?)
            ");
            $stmtMove->execute([
                $palletId, $pallet['remaining_quantity'], $pallet['remaining_quantity'],
                $pallet['unit'], $oldLocation, $newLocation, $userId
            ]);

            $pdo->commit();

            $summary = getIngredientPalletSummary($pdo, $pallet['ingredient_id']);
            jsonResponse([
                'success' => true,
                'message' => "Pallet {$pallet['pallet_code']} successfully relocated to {$newLocation}.",
                'summary' => $summary
            ]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 5. GET INGREDIENT SUMMARY (AJAX Refresh)
        // ─────────────────────────────────────────────────────────────
        case 'get_ingredient_summary':
            $ingredientId = (int)($input['ingredient_id'] ?? $_GET['ingredient_id'] ?? 0);
            if ($ingredientId <= 0) {
                throw new Exception("Invalid ingredient ID.");
            }
            $summary = getIngredientPalletSummary($pdo, $ingredientId);
            if (!$summary) {
                throw new Exception("Ingredient not found.");
            }
            jsonResponse(['success' => true, 'summary' => $summary]);
            break;

        // ─────────────────────────────────────────────────────────────
        // 6. GET PALLET DETAILS (For Printable Label / Slips)
        // ─────────────────────────────────────────────────────────────
        case 'get_pallet_details':
            $palletId = (int)($input['pallet_id'] ?? $_GET['pallet_id'] ?? 0);
            if ($palletId <= 0) {
                throw new Exception("Invalid pallet ID.");
            }
            $stmt = $pdo->prepare("
                SELECT p.*, i.name as ingredient_name, i.current_stock as active_stock,
                       u.first_name, u.last_name
                FROM pallets p
                JOIN ingredients i ON p.ingredient_id = i.ingredient_id
                LEFT JOIN users u ON p.created_by = u.user_id
                WHERE p.pallet_id = ?
            ");
            $stmt->execute([$palletId]);
            $pallet = $stmt->fetch();
            if (!$pallet) {
                throw new Exception("Pallet not found.");
            }

            // Fetch movement history for this pallet
            $stmtMove = $pdo->prepare("
                SELECT m.*, u.first_name, u.last_name
                FROM pallet_movements m
                LEFT JOIN users u ON m.user_id = u.user_id
                WHERE m.pallet_id = ?
                ORDER BY m.created_at DESC
            ");
            $stmtMove->execute([$palletId]);
            $movements = $stmtMove->fetchAll();

            $expInfo = getExpirationBadge($pallet['expiration_date']);
            $statusInfo = getPalletStatusBadge($pallet['status']);

            jsonResponse([
                'success'   => true,
                'pallet'    => $pallet,
                'movements' => $movements,
                'exp_info'  => $expInfo,
                'status_info' => $statusInfo
            ]);
            break;

        default:
            throw new Exception("Invalid or unsupported PIPO action.");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
}
