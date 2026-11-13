<?php
/**
 * Automated Verification Script for PIPO (Pallet In / Pallet Out) Flow
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

echo "====================================================\n";
echo "       BRIGHT ON BEANS - PIPO VERIFICATION TEST     \n";
echo "====================================================\n\n";

try {
    // 1. Find or create a test coffee beans ingredient
    $stmt = $pdo->query("SELECT * FROM ingredients WHERE name LIKE '%Bean%' OR name LIKE '%Coffee%' LIMIT 1");
    $ing = $stmt->fetch();
    if (!$ing) {
        $pdo->exec("INSERT INTO ingredients (name, unit, current_stock, minimum_level, unit_cost, expiration_date) VALUES ('Espresso Blend Beans', 'g', 1000.000, 200.000, 0.4500, '2026-10-31')");
        $ingId = $pdo->lastInsertId();
    } else {
        $ingId = $ing['ingredient_id'];
        // Ensure standard initial test state
        $pdo->prepare("UPDATE ingredients SET current_stock = 1000.000, expiration_date = '2026-10-31' WHERE ingredient_id = ?")->execute([$ingId]);
    }

    echo "1. Initial State for Ingredient ID {$ingId}:\n";
    $summaryBefore = getIngredientPalletSummary($pdo, $ingId);
    echo "   - Name: {$summaryBefore['ingredient']['name']}\n";
    echo "   - Active Floor Stock: {$summaryBefore['active_stock']} {$summaryBefore['ingredient']['unit']} (Exp: {$summaryBefore['active_exp']})\n";
    echo "   - Reserved Pallet Stock: {$summaryBefore['reserved_stock']} across {$summaryBefore['pallet_count']} pallets\n";
    echo "   - Total Inventory: {$summaryBefore['total_stock']} {$summaryBefore['ingredient']['unit']}\n\n";

    // 2. Test Pallet In (Inbound Receiving)
    echo "2. Executing Pallet In (5,000 g, Exp: 2026-11-30, Rack A-01)...\n";
    $testPalletCode = generatePalletCode($pdo);
    $batchNo = 'BATCH-NOV2026-' . time();
    $qty = 5000.00;
    $cost = 0.45;
    $expDate = '2026-11-30';
    $location = 'Pallet Rack A-01 (Coffee Beans & Grounds)';
    $supplier = 'Baguio Highland Farms';

    $pdo->beginTransaction();

    $stmtInsert = $pdo->prepare("
        INSERT INTO pallets (
            pallet_code, ingredient_id, batch_number, storage_location, 
            supplier, initial_quantity, remaining_quantity, unit, 
            unit_cost, total_cost, status, received_date, expiration_date, 
            created_by, notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'g', ?, ?, 'stored', CURDATE(), ?, 1, 'Automated Test Batch')
    ");
    $stmtInsert->execute([
        $testPalletCode, $ingId, $batchNo, $location,
        $supplier, $qty, $qty, $cost, ($qty * $cost), $expDate
    ]);
    $newPalletId = $pdo->lastInsertId();

    $stmtMove = $pdo->prepare("
        INSERT INTO pallet_movements (
            pallet_id, movement_type, quantity, quantity_before, quantity_after,
            unit, source_location, destination, purpose_reason, user_id
        ) VALUES (?, 'pallet_in', ?, 0, ?, 'g', 'Supplier Dock', ?, 'Inbound Delivery Test', 1)
    ");
    $stmtMove->execute([$newPalletId, $qty, $qty, $location]);

    $pdo->commit();

    $summaryAfterIn = getIngredientPalletSummary($pdo, $ingId);
    echo "   ✔ Pallet In successful! Pallet ID: {$newPalletId}, Code: {$testPalletCode}\n";
    echo "   - Active Floor Stock (Unchanged): {$summaryAfterIn['active_stock']} g (Exp: {$summaryAfterIn['active_exp']})\n";
    echo "   - Reserved Pallet Stock: {$summaryAfterIn['reserved_stock']} g\n";
    echo "   - Total Inventory: {$summaryAfterIn['total_stock']} g\n\n";

    assert($summaryAfterIn['active_stock'] == 1000.0, "Active stock must remain 1000g!");
    assert($summaryAfterIn['active_exp'] == '2026-10-31', "Active expiration date must remain 2026-10-31!");
    assert($summaryAfterIn['total_stock'] >= 6000.0, "Total stock must reflect active + reserved!");

    // 3. Test Stage to Active (Pallet Out to Bar)
    echo "3. Staging 1,500 g from Pallet {$testPalletCode} to Active Bar...\n";
    $stageQty = 1500.00;

    $pdo->beginTransaction();
    $stmtPallet = $pdo->prepare("SELECT * FROM pallets WHERE pallet_id = ? FOR UPDATE");
    $stmtPallet->execute([$newPalletId]);
    $palletRow = $stmtPallet->fetch();

    $newRemaining = (float)$palletRow['remaining_quantity'] - $stageQty;
    $newStatus = ($newRemaining <= 0) ? 'depleted' : 'in_use';

    $pdo->prepare("UPDATE pallets SET remaining_quantity = ?, status = ? WHERE pallet_id = ?")
        ->execute([$newRemaining, $newStatus, $newPalletId]);

    $stmtIng = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ? FOR UPDATE");
    $stmtIng->execute([$ingId]);
    $ingRow = $stmtIng->fetch();
    $newActive = (float)$ingRow['current_stock'] + $stageQty;

    $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?")
        ->execute([$newActive, $ingId]);

    $stmtMove2 = $pdo->prepare("
        INSERT INTO pallet_movements (
            pallet_id, movement_type, quantity, quantity_before, quantity_after,
            unit, source_location, destination, purpose_reason, user_id
        ) VALUES (?, 'pallet_out', ?, ?, ?, 'g', ?, 'Main Bar / Active Floor', 'Daily Espresso Bar Replenishment', 1)
    ");
    $stmtMove2->execute([$newPalletId, $stageQty, $palletRow['remaining_quantity'], $newRemaining, $palletRow['storage_location']]);

    $pdo->commit();

    $summaryAfterStage = getIngredientPalletSummary($pdo, $ingId);
    echo "   ✔ Stage to Active Bar successful!\n";
    echo "   - Active Floor Stock: {$summaryAfterStage['active_stock']} g\n";
    echo "   - Pallet Remaining Stock: {$newRemaining} g (Status: {$newStatus})\n";
    echo "   - Total Inventory: {$summaryAfterStage['total_stock']} g\n\n";

    assert($summaryAfterStage['active_stock'] == 2500.0, "Active stock should now be 2500g!");
    assert($newRemaining == 3500.0, "Pallet remaining should be 3500g!");

    // 4. Test Relocation
    echo "4. Relocating Pallet {$testPalletCode} to Walk-in Cold Storage...\n";
    $newLoc = 'Walk-in Cold Storage (Dairy & Perishables)';
    $pdo->prepare("UPDATE pallets SET storage_location = ? WHERE pallet_id = ?")->execute([$newLoc, $newPalletId]);
    $pdo->prepare("INSERT INTO pallet_movements (pallet_id, movement_type, quantity, quantity_before, quantity_after, unit, source_location, destination, purpose_reason, user_id) VALUES (?, 'relocation', 0, ?, ?, 'g', ?, ?, 'Rack Optimization', 1)")
        ->execute([$newPalletId, $newRemaining, $newRemaining, $location, $newLoc]);

    $checkLoc = $pdo->query("SELECT storage_location FROM pallets WHERE pallet_id = $newPalletId")->fetchColumn();
    echo "   ✔ Relocation verified! Current Location: {$checkLoc}\n\n";
    assert($checkLoc === $newLoc, "Location should be updated!");

    // 5. Test Expiration Helper
    echo "5. Testing Expiration Badge logic...\n";
    $badgeFresh = getExpirationBadge('2026-12-31');
    $badgeExpired = getExpirationBadge('2026-01-01');
    echo "   - Dec 31, 2026 Badge: {$badgeFresh['label']} ({$badgeFresh['badge_cls']})\n";
    echo "   - Jan 01, 2026 Badge: {$badgeExpired['label']} ({$badgeExpired['badge_cls']})\n\n";

    echo "====================================================\n";
    echo "       ALL PIPO TESTS PASSED SUCCESSFULLY!          \n";
    echo "====================================================\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
