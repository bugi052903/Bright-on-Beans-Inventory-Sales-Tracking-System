<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Ingredient Management';

$activeTab = isset($_GET['tab']) ? $_GET['tab'] : 'catalog';
if (!in_array($activeTab, ['catalog', 'dispose', 'audit'])) {
    $activeTab = 'catalog';
}

// Handle Add / Edit / Delete / Dispose POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    $action = $_POST['action'];

    try {
        if ($action === 'save') {
            $id = (int)($_POST['ingredient_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $unit = trim($_POST['unit'] ?? '');
            $currentStock = (float)($_POST['current_stock'] ?? 0);
            $minimumLevel = (float)($_POST['minimum_level'] ?? 0);
            $totalPrice = (float)($_POST['total_price'] ?? 0);
            $unitCost = (float)($_POST['unit_cost'] ?? 0);
            
            // If total price was provided and current stock > 0, compute unit cost if not provided
            if ($totalPrice > 0 && $currentStock > 0 && $unitCost <= 0) {
                $unitCost = $totalPrice / $currentStock;
            }

            $dateAdded = !empty($_POST['date_added']) ? $_POST['date_added'] : date('Y-m-d');
            $expirationDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;

            if (empty($name) || empty($unit)) {
                throw new Exception("Ingredient name and unit are required.");
            }

            if ($id > 0) {
                // Fetch old stock for audit history log
                $stmtOld = $pdo->prepare("SELECT current_stock FROM ingredients WHERE ingredient_id = ?");
                $stmtOld->execute([$id]);
                $oldStock = (float)$stmtOld->fetchColumn();

                $stmt = $pdo->prepare("UPDATE ingredients SET name = ?, unit = ?, current_stock = ?, minimum_level = ?, unit_cost = ?, date_added = ?, expiration_date = ? WHERE ingredient_id = ?");
                $stmt->execute([$name, $unit, $currentStock, $minimumLevel, $unitCost, $dateAdded, $expirationDate, $id]);
                reconcileIngredientStockBatches($pdo, $id, $currentStock);

                // Log audit if stock changed manually
                if ($oldStock != $currentStock) {
                    $diff = $currentStock - $oldStock;
                    $changeType = $diff > 0 ? 'adjustment_add' : 'adjustment_deduct';
                    $note = "Manual Stock Adjustment: Modified stock level from {$oldStock} to {$currentStock}";
                    $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmtHist->execute([$id, $oldStock, $currentStock, abs($diff), $changeType, $note]);
                }

                setFlashMessage('success', "Ingredient '{$name}' updated successfully.");
            } else {
                $stmt = $pdo->prepare("INSERT INTO ingredients (name, unit, current_stock, minimum_level, unit_cost, date_added, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $unit, $currentStock, $minimumLevel, $unitCost, $dateAdded, $expirationDate]);
                $newId = $pdo->lastInsertId();

                // Log audit for new ingredient creation
                $note = "Initial Stock Creation: Added {$currentStock} {$unit} of {$name}";
                $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, 0, ?, ?, 'addition', ?)");
                $stmtHist->execute([$newId, $currentStock, $currentStock, $note]);

                setFlashMessage('success', "New ingredient '{$name}' added successfully.");
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['ingredient_id'] ?? 0);
            if ($id > 0) {
                $pdo->beginTransaction();

                $stmtIngredient = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ? FOR UPDATE");
                $stmtIngredient->execute([$id]);
                $ingredient = $stmtIngredient->fetch();
                if (!$ingredient) {
                    throw new Exception("Ingredient not found.");
                }

                $stmtRecipes = $pdo->prepare("SELECT COUNT(*) FROM recipe_ingredients WHERE ingredient_id = ?");
                $stmtRecipes->execute([$id]);
                $isInRecipe = (int)$stmtRecipes->fetchColumn() > 0;
                $isExpired = !empty($ingredient['expiration_date']) && $ingredient['expiration_date'] < date('Y-m-d');
                $stmtTrackedStock = $pdo->prepare("
                    SELECT COALESCE(SUM(remaining_quantity), 0)
                    FROM ingredient_stock_batches
                    WHERE ingredient_id = ? AND remaining_quantity > 0
                ");
                $stmtTrackedStock->execute([$id]);
                $trackedStock = (float)$stmtTrackedStock->fetchColumn();

                $stmtPalletTable = $pdo->query("
                    SELECT COUNT(*)
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE() AND table_name = 'pallets'
                ");
                $hasPalletTable = (int)$stmtPalletTable->fetchColumn() > 0;
                $reservedStock = 0.0;
                if ($hasPalletTable) {
                    $stmtReservedStock = $pdo->prepare("
                        SELECT COALESCE(SUM(remaining_quantity), 0)
                        FROM pallets
                        WHERE ingredient_id = ? AND status IN ('stored', 'in_use')
                    ");
                    $stmtReservedStock->execute([$id]);
                    $reservedStock = (float)$stmtReservedStock->fetchColumn();
                }

                $isOutOfStock = (float)$ingredient['current_stock'] <= 0
                    && $trackedStock <= 0
                    && $reservedStock <= 0;

                if ($isOutOfStock) {
                    if ($isInRecipe) {
                        $pdo->prepare("UPDATE ingredients SET is_archived = 1 WHERE ingredient_id = ?")->execute([$id]);
                        $pdo->commit();
                        setFlashMessage('success', "Ingredient '{$ingredient['name']}' was archived. Its product recipe links were kept.");
                    } else {
                        $pdo->prepare("DELETE FROM sale_stock_deductions WHERE ingredient_id = ?")->execute([$id]);

                        if ($hasPalletTable) {
                            $stmtMovementTable = $pdo->query("
                                SELECT COUNT(*)
                                FROM information_schema.tables
                                WHERE table_schema = DATABASE() AND table_name = 'pallet_movements'
                            ");
                            if ((int)$stmtMovementTable->fetchColumn() > 0) {
                                $pdo->prepare("
                                    DELETE FROM pallet_movements
                                    WHERE pallet_id IN (SELECT pallet_id FROM pallets WHERE ingredient_id = ?)
                                ")->execute([$id]);
                            }
                            $pdo->prepare("DELETE FROM pallets WHERE ingredient_id = ?")->execute([$id]);
                        }

                        $pdo->prepare("DELETE FROM ingredient_stock_batches WHERE ingredient_id = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM inventory_adjustments WHERE ingredient_id = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM inventory_history WHERE ingredient_id = ?")->execute([$id]);
                        $pdo->prepare("DELETE FROM ingredients WHERE ingredient_id = ?")->execute([$id]);
                        $pdo->commit();
                        setFlashMessage('success', "Out-of-stock ingredient '{$ingredient['name']}' was deleted.");
                    }
                } elseif ($isInRecipe && $isExpired) {
                    $pdo->prepare("
                        UPDATE ingredient_stock_batches
                        SET remaining_quantity = 0
                        WHERE ingredient_id = ?
                          AND expiration_date IS NOT NULL
                          AND expiration_date < CURDATE()
                    ")->execute([$id]);

                    $stmtFreshStock = $pdo->prepare("
                        SELECT COALESCE(SUM(remaining_quantity), 0)
                        FROM ingredient_stock_batches
                        WHERE ingredient_id = ?
                          AND remaining_quantity > 0
                          AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                    ");
                    $stmtFreshStock->execute([$id]);
                    $freshStock = (float)$stmtFreshStock->fetchColumn();

                    $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?")
                        ->execute([$freshStock, $id]);
                    $pdo->commit();
                    setFlashMessage('success', "Expired stock of '{$ingredient['name']}' was disposed. The ingredient and product recipes were kept, and unexpired batch stock remains available.");
                } else {
                    throw new Exception("This ingredient still has active or reserved stock. Deduct or dispose of its stock before deleting it.");
                }
            }
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        setFlashMessage('error', $e->getMessage());
    }

    header("Location: " . BASE_URL . "/admin/ingredient-management.php?tab=" . $activeTab);
    exit;
}

// Fetch all ingredients for Catalog tab & modals
$stmt = $pdo->query("SELECT * FROM ingredients WHERE is_archived = 0 ORDER BY name ASC, expiration_date ASC");
$rawIngredients = $stmt->fetchAll();

// Fetch all disposal / waste deduction records for the Disposal tab
$stmtDisposal = $pdo->query("
    SELECT 
        ia.adjustment_id,
        ia.created_at,
        ia.quantity,
        ia.reason,
        ia.balance_after,
        ia.user_id,
        i.ingredient_id,
        i.name as ingredient_name,
        i.unit,
        i.unit_cost,
        (ia.quantity * i.unit_cost) as cost_loss,
        u.first_name,
        u.last_name,
        u.role
    FROM inventory_adjustments ia
    JOIN ingredients i ON ia.ingredient_id = i.ingredient_id
    LEFT JOIN users u ON ia.user_id = u.user_id
    WHERE ia.adjustment_type = 'deduct'
    ORDER BY ia.created_at DESC, ia.adjustment_id DESC
");
$disposalRecords = $stmtDisposal->fetchAll();

// Group product adjustments so all recipe ingredients of a single drink incident are unified into 1 entry
$displayDisposalRecords = [];
$productGroups = [];

foreach ($disposalRecords as $dr) {
    $rawReason = $dr['reason'] ?? '';
    $isProduct = (stripos($rawReason, 'Product Adjustment') !== false || stripos($rawReason, 'Product Disposal') !== false);

    if ($isProduct) {
        $prodName = $dr['ingredient_name'];
        $servings = '1 serving';
        $reasonText = 'Product Spoilage / Remake';

        if (preg_match('/^(?:Product Adjustment|Product Disposal)[^:]*:\s*([^(]+?)\s*\(([^)]+)\)\s*-\s*Reason:\s*(.*)$/i', $rawReason, $matches)) {
            $prodName = trim($matches[1]);
            $servings = trim($matches[2]);
            $reasonText = trim($matches[3]);
        } elseif (preg_match('/(?:Product Adjustment|Product Disposal)[^:]*:\s*([^(]+)/i', $rawReason, $mFallback)) {
            $prodName = trim($mFallback[1]);
            if (preg_match('/\(([^)]+)\)/', $rawReason, $sMatch)) {
                $servings = trim($sMatch[1]);
            }
            if (preg_match('/Reason:\s*(.*)$/i', $rawReason, $rMatch)) {
                $reasonText = trim($rMatch[1]);
            }
        }

        // Group by minute of created_at + full reason string + user_id
        $timeKey = date('Y-m-d H:i', strtotime($dr['created_at']));
        $groupKey = $timeKey . '_' . $rawReason . '_' . ($dr['user_id'] ?? 0);

        if (!isset($productGroups[$groupKey])) {
            $productGroups[$groupKey] = [
                'type' => 'product',
                'created_at' => $dr['created_at'],
                'item_name' => $prodName,
                'type_label' => 'Drink Product',
                'quantity_display' => $servings,
                'reason' => $reasonText,
                'cost_loss' => (float)$dr['cost_loss'],
                'user_name' => ($dr['first_name'] ? ($dr['first_name'] . ' ' . $dr['last_name']) : 'Admin'),
                'user_role' => ucfirst($dr['role'] ?? 'Admin'),
            ];
        } else {
            $productGroups[$groupKey]['cost_loss'] += (float)$dr['cost_loss'];
        }
    } else {
        // Raw ingredient deduction
        $cleanReason = $rawReason;
        if (stripos($rawReason, 'Disposal: ') === 0) {
            $cleanReason = substr($rawReason, 10);
        } elseif (preg_match('/Reason:\s*([^]]+)/i', $rawReason, $rMatches)) {
            $cleanReason = trim($rMatches[1]);
        }

        $displayDisposalRecords[] = [
            'type' => 'ingredient',
            'created_at' => $dr['created_at'],
            'item_name' => $dr['ingredient_name'],
            'type_label' => 'Raw Ingredient',
            'quantity_display' => formatStock($dr['quantity']) . ' ' . $dr['unit'],
            'reason' => $cleanReason,
            'cost_loss' => (float)$dr['cost_loss'],
            'user_name' => ($dr['first_name'] ? ($dr['first_name'] . ' ' . $dr['last_name']) : 'Admin'),
            'user_role' => ucfirst($dr['role'] ?? 'Admin'),
        ];
    }
}

// Merge product groups
foreach ($productGroups as $pg) {
    $displayDisposalRecords[] = $pg;
}

// Sort all combined records by created_at DESC
usort($displayDisposalRecords, function($a, $b) {
    return strtotime($b['created_at']) <=> strtotime($a['created_at']);
});

$totalDisposalCount = count($displayDisposalRecords);
$totalCostLoss = 0.0;
foreach ($displayDisposalRecords as $row) {
    $totalCostLoss += (float)$row['cost_loss'];
}

// Fetch complete inventory activity and movement logs for Activity Log tab
$stmtActivity = $pdo->query("
    SELECT 
        ih.history_id,
        ih.created_at,
        ih.change_type,
        ih.change_quantity,
        ih.stock_before,
        ih.stock_after,
        ih.notes,
        i.name AS ingredient_name,
        i.unit
    FROM inventory_history ih
    JOIN ingredients i ON ih.ingredient_id = i.ingredient_id
    ORDER BY ih.created_at DESC, i.name ASC, ih.history_id DESC
");
$activityLogs = $stmtActivity->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="tabs mb-4" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #EAE3D9; padding-bottom: 0.5rem;">
    <div style="display: flex; gap: 8px;">
        <a href="?tab=catalog" class="tab-btn <?= $activeTab === 'catalog' ? 'active' : '' ?>" style="text-decoration: none;">
            🧂 Ingredient Catalog
        </a>
        <a href="?tab=dispose" class="tab-btn <?= $activeTab === 'dispose' ? 'active' : '' ?>" style="text-decoration: none;">
            🗑️ Waste & Disposal
        </a>
        <a href="?tab=audit" class="tab-btn <?= $activeTab === 'audit' ? 'active' : '' ?>" style="text-decoration: none;">
            📜 Ingredient Movement & Usage Log
        </a>
    </div>

    <?php if ($activeTab === 'catalog'): ?>
    <button type="button" class="btn" id="openAddModal" style="background: #3C2415; border: none; border-radius: 8px; font-weight: 700; padding: 0.65rem 1.35rem; font-size: 0.9rem; color: #FFF; margin-left: auto; box-shadow: 0 2px 6px rgba(60,36,21,0.15); display: inline-flex; align-items: center; justify-content: center; white-space: nowrap;">
        + Add New Ingredient
    </button>
    <?php endif; ?>
</div>

<?php if ($activeTab === 'catalog'): ?>
<!-- ─── TAB 1: INGREDIENT CATALOG ─── -->
<div class="card" style="border-radius: 14px; border: 1px solid #EAE3D9; box-shadow: 0 2px 10px rgba(60,36,21,0.03);">
    <div class="card-body" style="padding: 1.5rem;">
        <!-- Search Bar -->
        <div class="mb-4">
            <input type="text" id="ingredient-search-input" class="form-control mb-0" placeholder="Search ingredient name..." style="max-width: 380px; height: 42px; padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #E2D9CF; background: #FAF7F2; font-size: 0.88rem; color: #3C2415;">
        </div>

        <div class="table-wrapper">
            <table class="table align-middle mb-0" id="ingredients-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FAF7F2; border-bottom: 2px solid #EAE3D9; color: #8C6D58; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <th style="padding: 12px 14px; width: 15%;">INGREDIENT NAME</th>
                        <th style="padding: 12px 14px; width: 6%;">UNIT</th>
                        <th style="padding: 12px 14px; width: 11%;">CURRENT STOCK</th>
                        <th style="padding: 12px 14px; width: 10%;">MIN LEVEL</th>
                        <th style="padding: 12px 14px; width: 11%;">TOTAL PRICE (₱)</th>
                        <th style="padding: 12px 14px; width: 11%;">UNIT COST (₱)</th>
                        <th style="padding: 12px 14px; width: 10%;">DATE ADDED</th>
                        <th style="padding: 12px 14px; width: 10%;">EXPIRATION</th>
                        <th style="padding: 12px 14px; width: 6%;">STATUS</th>
                        <th style="padding: 12px 14px; width: 16%; text-align: center;">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rawIngredients as $ing): 
                        $curStock = (float)$ing['current_stock'];
                        $minLevel = (float)$ing['minimum_level'];
                        $unitCost = (float)$ing['unit_cost'];
                        $totalPrice = $curStock * $unitCost;
                        $isOutOfStock = $curStock <= 0;
                        $isLow = !$isOutOfStock && $curStock <= $minLevel;
                        
                        $expDate = $ing['expiration_date'];
                        $isExpired = $expDate && strtotime($expDate) <= strtotime(date('Y-m-d'));
                        $isExpiringSoon = $expDate && !$isExpired && strtotime($expDate) <= strtotime('+7 days');

                        if ($isExpired) {
                            $statusBadge = '<span style="background: #FCE8E6; color: #C53030; border: 1px solid #FEB2B2; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">EXPIRED</span>';
                        } elseif ($isOutOfStock) {
                            $statusBadge = '<span style="background: #FCE8E6; color: #C53030; border: 1px solid #FEB2B2; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">OUT OF STOCK</span>';
                        } elseif ($isLow) {
                            $statusBadge = '<span style="background: #FFF9E6; color: #D97706; border: 1px solid #FCD34D; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">LOW STOCK</span>';
                        } elseif ($isExpiringSoon) {
                            $statusBadge = '<span style="background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">EXPIRING</span>';
                        } else {
                            $statusBadge = '<span style="background: #E6F4EA; color: #059669; border: 1px solid #A7F3D0; padding: 3px 8px; border-radius: 6px; font-weight: 700; font-size: 0.74rem;">OK</span>';
                        }
                    ?>
                    <tr style="border-bottom: 1px solid #F3EDE7;">
                        <td style="padding: 12px 14px;" class="searchable">
                            <strong style="color: #3C2415; font-size: 0.92rem;"><?= e($ing['name']) ?></strong>
                        </td>
                        <td style="padding: 12px 14px; color: #5A3A28; font-weight: 600; font-size: 0.88rem;">
                            <?= e($ing['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px;">
                            <strong style="color: #3C2415; font-size: 0.92rem;"><?= formatStock($curStock) ?></strong>
                        </td>
                        <td style="padding: 12px 14px; color: #6E5C4F; font-size: 0.88rem;">
                            <?= formatStock($minLevel) ?>
                        </td>
                        <td style="padding: 12px 14px; font-weight: 700; color: #1B4F72; font-size: 0.9rem;">
                            ₱<?= number_format($totalPrice, 2) ?>
                        </td>
                        <td style="padding: 12px 14px; font-weight: 700; color: #059669; font-size: 0.9rem;">
                            ₱<?= number_format($unitCost, 2) ?> / <?= e($ing['unit']) ?>
                        </td>
                        <td style="padding: 12px 14px; color: #5A3A28; font-size: 0.84rem;">
                            <?= $ing['date_added'] ? date('M d, Y', strtotime($ing['date_added'])) : '—' ?>
                        </td>
                        <td style="padding: 12px 14px; font-size: 0.84rem; <?= $isExpired ? 'color: #DC2626; font-weight: 700;' : 'color: #5A3A28;' ?>">
                            <?= $expDate ? date('M d, Y', strtotime($expDate)) : '—' ?>
                        </td>
                        <td style="padding: 12px 14px;">
                            <?= $statusBadge ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <div style="display: flex; gap: 6px; justify-content: center; flex-wrap: nowrap;">
                                <button type="button" class="btn btn-sm btn-outline view-ing-btn" 
                                        data-id="<?= $ing['ingredient_id'] ?>"
                                        data-name="<?= e($ing['name']) ?>"
                                        data-unit="<?= e($ing['unit']) ?>"
                                        data-stock="<?= formatStock($curStock) ?>"
                                        data-min="<?= formatStock($minLevel) ?>"
                                        data-price="<?= number_format($totalPrice, 2) ?>"
                                        data-cost="<?= number_format($unitCost, 2) ?>"
                                        data-added="<?= $ing['date_added'] ? date('M d, Y', strtotime($ing['date_added'])) : 'None' ?>"
                                        data-exp="<?= $expDate ? date('M d, Y', strtotime($expDate)) : 'None' ?>"
                                        style="padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; border: 1px solid #C49A82; color: #3C2415; white-space: nowrap;">
                                    View
                                </button>
                                <button type="button" class="btn btn-sm btn-primary edit-ing-btn"
                                        data-id="<?= $ing['ingredient_id'] ?>"
                                        data-name="<?= e($ing['name']) ?>"
                                        data-unit="<?= e($ing['unit']) ?>"
                                        data-stock="<?= $curStock ?>"
                                        data-min="<?= $minLevel ?>"
                                        data-price="<?= $totalPrice ?>"
                                        data-cost="<?= $unitCost ?>"
                                        data-added="<?= $ing['date_added'] ?>"
                                        data-exp="<?= $ing['expiration_date'] ?>"
                                        style="padding: 0.3rem 0.65rem; border-radius: 6px; font-size: 0.78rem; font-weight: 700; background: #3C2415; border: none; color: #FFF; white-space: nowrap;">
                                    Edit
                                </button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this out-of-stock ingredient? If a product recipe uses it, it will be archived and the recipe/history will be kept. Otherwise it will be permanently deleted.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="ingredient_id" value="<?= $ing['ingredient_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 0.3rem 0.6rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; background: #DC2626; border: none; color: #FFF; white-space: nowrap;">
                                     Archive
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($rawIngredients)): ?>
                    <tr><td colspan="10" class="text-center p-4 text-muted">No ingredients found in catalog. Click "+ Add New Ingredient" to create one.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php elseif ($activeTab === 'dispose'): ?>
<!-- ─── TAB 2: WASTE & DISPOSAL MANAGEMENT ─── -->

<!-- Summary Metrics Cards -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
    <div class="card" style="border-radius: 12px; border: 1px solid #EAE3D9; padding: 1.25rem; background: #FFFFFF; box-shadow: 0 2px 8px rgba(60,36,21,0.03);">
        <div style="font-size: 0.78rem; text-transform: uppercase; color: #8C6D58; font-weight: 700; letter-spacing: 0.5px;">Total Disposals Recorded</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #3C2415; margin-top: 4px;">
            <?= $totalDisposalCount ?>
        </div>
        <div style="font-size: 0.78rem; color: #A08D7D; margin-top: 2px;">Incidents of waste or spoilage</div>
    </div>
    <div class="card" style="border-radius: 12px; border: 1px solid #EAE3D9; padding: 1.25rem; background: #FFFFFF; box-shadow: 0 2px 8px rgba(60,36,21,0.03);">
        <div style="font-size: 0.78rem; text-transform: uppercase; color: #8C6D58; font-weight: 700; letter-spacing: 0.5px;">Total Cost Loss from Waste</div>
        <div style="font-size: 1.6rem; font-weight: 800; color: #DC2626; margin-top: 4px;">
            <?= formatCurrency($totalCostLoss) ?>
        </div>
        <div style="font-size: 0.78rem; color: #A08D7D; margin-top: 2px;">Estimated financial inventory loss</div>
    </div>
</div>

<div class="card" style="border-radius: 14px; border: 1px solid #EAE3D9; box-shadow: 0 2px 10px rgba(60,36,21,0.03);">
    <div class="card-body" style="padding: 1.5rem;">
        <!-- Search Bar -->
        <div class="mb-4">
            <input type="text" id="disposal-search-input" class="form-control mb-0" placeholder="Search disposed item, reason, or staff..." style="max-width: 380px; height: 42px; padding: 0.5rem 1rem; border-radius: 8px; border: 1px solid #E2D9CF; background: #FAF7F2; font-size: 0.88rem; color: #3C2415;">
        </div>

        <div class="table-wrapper">
            <table class="table align-middle mb-0" id="disposal-table" style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FAF7F2; border-bottom: 2px solid #EAE3D9; color: #8C6D58; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px;">
                        <th style="padding: 12px 14px; width: 18%;">DATE & TIME</th>
                        <th style="padding: 12px 14px; width: 22%;">ITEM NAME</th>
                        <th style="padding: 12px 14px; width: 12%;">TYPE</th>
                        <th style="padding: 12px 14px; width: 14%;">QUANTITY DISPOSED</th>
                        <th style="padding: 12px 14px; width: 14%;">REASON</th>
                        <th style="padding: 12px 14px; width: 10%;">COST LOSS (₱)</th>
                        <th style="padding: 12px 14px; width: 10%;">RECORDED BY</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($displayDisposalRecords as $row): 
                        $dateTime = formatDateTime($row['created_at']);
                        $isProduct = ($row['type'] === 'product');
                    ?>
                    <tr style="border-bottom: 1px solid #F3EDE7;">
                        <td style="padding: 12px 14px; color: #5A3A28; font-size: 0.84rem; white-space: nowrap;">
                            <strong><?= e($dateTime) ?></strong>
                        </td>
                        <td style="padding: 12px 14px;" class="searchable-item">
                            <strong style="color: #3C2415; font-size: 0.92rem;"><?= e($row['item_name']) ?></strong>
                        </td>
                        <td style="padding: 12px 14px;">
                            <span style="background: <?= $isProduct ? '#EFF6FF' : '#F3EDE7' ?>; color: <?= $isProduct ? '#1D4ED8' : '#5A3A28' ?>; padding: 3px 8px; border-radius: 6px; font-weight: 600; font-size: 0.74rem;">
                                <?= e($row['type_label']) ?>
                            </span>
                        </td>
                        <td style="padding: 12px 14px;">
                            <strong style="color: #991B1B; font-size: 0.9rem;"><?= e($row['quantity_display']) ?></strong>
                        </td>
                        <td style="padding: 12px 14px;" class="searchable-reason">
                            <span style="background: #FEE2E2; color: #991B1B; padding: 3px 8px; border-radius: 4px; font-weight: 600; font-size: 0.76rem;">
                                <?= e($row['reason']) ?>
                            </span>
                        </td>
                        <td style="padding: 12px 14px; font-weight: 700; color: #DC2626; font-size: 0.88rem;">
                            <?= formatCurrency($row['cost_loss']) ?>
                        </td>
                        <td style="padding: 12px 14px; color: #6E5C4F; font-size: 0.84rem;" class="searchable-user">
                            <?= e($row['user_name']) ?> <span style="font-size: 0.76rem; color: #A08D7D;">(<?= e($row['user_role']) ?>)</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($displayDisposalRecords)): ?>
                    <tr>
                        <td colspan="7" class="text-center p-4" style="color: #A08D7D; font-size: 0.9rem;">
                            No disposal records recorded yet.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ─── TAB 3: INGREDIENT MOVEMENT & AUDIT LOG ─── -->

<div class="card" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04); overflow: hidden;">
    <div class="card-header" style="background: #FDF9F5; border-bottom: 1px solid #EFE8DE; padding: 1.1rem 1.25rem;">
        <h3 style="margin: 0; font-size: 0.98rem; color: #3C2415; font-weight: 700;">📜 Complete Ingredient Activity & Movement Audit Trail</h3>
    </div>
    <div class="card-body p-0">
        <div style="overflow-x: auto; width: 100%;">
            <table class="table mb-0" style="font-size: 0.85rem; width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #FAF7F2; border-bottom: 2px solid #EAE3D9; color: #8C6D58; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.3px;">
                        <th style="padding: 12px 14px;">Timestamp</th>
                        <th style="padding: 12px 14px;">Ingredient Name</th>
                        <th style="padding: 12px 14px; text-align: center;">Action Type</th>
                        <th style="padding: 12px 14px; text-align: center;">Change Qty</th>
                        <th style="padding: 12px 14px; text-align: center;">Balance After</th>
                        <th style="padding: 12px 14px;">Audit Trail Notes & Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($activityLogs as $log): 
                        $dt = date('M d, Y h:i A', strtotime($log['created_at']));
                        $ingName = $log['ingredient_name'];
                        $unit = $log['unit'];
                        $changeQty = (float)$log['change_quantity'];
                        $stockBefore = (float)$log['stock_before'];
                        $stockAfter = (float)$log['stock_after'];
                        $notes = $log['notes'] ?: '';
                        $type = $log['change_type'];

                        if (strpos($notes, 'Restored stock') !== false || strpos($notes, 'Voided Sale') !== false || strpos($notes, 'Restored') !== false) {
                            $badge = '<span style="background: #ECFDF5; color: #059669; border: 1px solid #A7F3D0; padding: 3px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">🔄 Stock Restored</span>';
                            $qtyDisplay = '<strong style="color: #059669;">+' . formatStock($changeQty) . ' ' . e($unit) . '</strong>';
                        } elseif (strpos($notes, 'Customer Sale') !== false || strpos($notes, 'FIFO Deduction') !== false) {
                            $badge = '<span style="background: #E8F0FE; color: #2563EB; border: 1px solid #BFDBFE; padding: 3px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">🛒 Customer Sale</span>';
                            $qtyDisplay = '<strong style="color: #2563EB;">-' . formatStock($changeQty) . ' ' . e($unit) . '</strong>';
                        } elseif (strpos($notes, 'Product Adjustment') !== false || strpos($notes, 'Product Spoilage') !== false) {
                            $badge = '<span style="background: #FEF3C7; color: #D97706; border: 1px solid #FDE68A; padding: 3px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">🚨 Remake</span>';
                            $qtyDisplay = '<strong style="color: #DC2626;">-' . formatStock($changeQty) . ' ' . e($unit) . '</strong>';
                        } elseif ($type === 'addition' || $type === 'adjustment_add' || strpos($notes, 'Initial Stock') !== false || strpos($notes, 'Restock') !== false) {
                            $badge = '<span style="background: #E6F4EA; color: #059669; border: 1px solid #A7F3D0; padding: 3px 8px; border-radius: 5px; font-weight: 700; font-size: 0.78rem;">📦 Stock Restock</span>';
                            $qtyDisplay = '<strong style="color: #059669;">+' . formatStock($changeQty) . ' ' . e($unit) . '</strong>';
                        } else {
                            $sign = ($type === 'deduct' || $type === 'adjustment_deduct') ? '-' : '+';
                            $color = ($sign === '-') ? '#DC2626' : '#059669';
                            $badge = '<span style="background: #F3F4F6; color: #4B5563; border: 1px solid #E5E7EB; padding: 3px 8px; border-radius: 5px; font-weight: 600; font-size: 0.78rem;">⚙️ System Adjustment</span>';
                            $qtyDisplay = '<strong style="color: ' . $color . ';">' . $sign . formatStock($changeQty) . ' ' . e($unit) . '</strong>';
                        }
                    ?>
                    <tr style="border-bottom: 1px solid #F3EDE7;">
                        <td style="padding: 12px 14px; white-space: nowrap; color: #5A3A28;">
                            <strong><?= $dt ?></strong>
                        </td>
                        <td style="padding: 12px 14px;">
                            <strong style="color: #3C2415; font-size: 0.9rem;"><?= e($ingName) ?></strong>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?= $badge ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?= $qtyDisplay ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <strong style="color: #3C2415; font-size: 0.88rem;"><?= formatStock($stockAfter) ?> <?= e($unit) ?></strong>
                        </td>
                        <td style="padding: 12px 14px; color: #6E5C4F; font-size: 0.82rem;">
                            <?= e($notes) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($activityLogs)): ?>
                    <tr><td colspan="6" class="text-center p-4 text-muted">No inventory movement logs recorded yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Add / Edit Modal with Automatic Unit Cost Calculation -->
<div class="modal-overlay" id="modal">
    <div class="modal" style="border-radius: 14px; max-width: 480px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; padding-right: 2rem; color: #3C2415; font-weight: 700;">Add New Ingredient</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 1rem; right: 1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #8C6D58;">&times;</button>
        </div>
        <form method="POST" id="ingForm" style="padding: 1.25rem;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="ingredient_id" id="ing_id">
            
            <div class="form-group mb-3">
                <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Ingredient Name</label>
                <input type="text" name="name" id="ing_name" class="form-control" required placeholder="e.g. Coffee Beans" style="font-size: 0.9rem;">
            </div>

            <div class="row" style="display: flex; gap: 12px; margin: 0;">
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Unit</label>
                    <input type="text" name="unit" id="ing_unit" class="form-control" required placeholder="e.g. g, mL, pcs, kg" style="font-size: 0.9rem;" oninput="recalcUnitCost()">
                </div>
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Stock Quantity</label>
                    <input type="number" step="0.001" min="0" name="current_stock" id="ing_stock" class="form-control" required placeholder="e.g. 500" style="font-size: 0.9rem;" oninput="recalcUnitCost()">
                </div>
            </div>

            <div class="row" style="display: flex; gap: 12px; margin: 0;">
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Total Purchase Price (₱)</label>
                    <input type="number" step="0.01" min="0" name="total_price" id="ing_price" class="form-control" placeholder="e.g. 1000.00" style="font-size: 0.9rem;" oninput="recalcUnitCost()">
                </div>
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Unit Cost (₱)</label>
                    <input type="number" step="0.01" min="0" name="unit_cost" id="ing_cost" class="form-control" required placeholder="e.g. 2.00" style="font-size: 0.9rem; background: #FAF7F2;" oninput="recalcTotalPrice()">
                </div>
            </div>

            <div style="background: #F0FDF4; border: 1px solid #BBF7D0; border-radius: 6px; padding: 6px 10px; margin-bottom: 12px; font-size: 0.82rem; color: #166534;">
                💡 <strong>Auto-Calculator:</strong> <span id="auto_calc_hint">Enter Stock & Total Price to compute Unit Cost automatically.</span>
            </div>

            <div class="row" style="display: flex; gap: 12px; margin: 0;">
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Min Alert Level</label>
                    <input type="number" step="0.01" min="0" name="minimum_level" id="ing_min" class="form-control" required placeholder="10" style="font-size: 0.9rem;">
                </div>
                <div class="form-group mb-3" style="flex: 1; padding: 0;">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Date Added</label>
                    <input type="date" name="date_added" id="ing_added" class="form-control" value="<?= date('Y-m-d') ?>" style="font-size: 0.9rem;">
                </div>
            </div>

            <div class="form-group mb-3">
                <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Expiration Date</label>
                <input type="date" name="expiration_date" id="ing_exp" class="form-control" style="font-size: 0.9rem;">
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 1rem;">
                <button type="button" class="btn btn-outline close-modal" style="border-radius: 8px;">Cancel</button>
                <button type="submit" class="btn btn-primary" style="background: #3C2415; border: none; border-radius: 8px; font-weight: 700; padding: 0.55rem 1.4rem;">Save Ingredient</button>
            </div>
        </form>
    </div>
</div>

<!-- View Details Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal" style="border-radius: 14px; max-width: 440px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #EAE3D9; padding: 1rem 1.25rem;">
            <h3 style="margin: 0; font-size: 1.05rem; color: #3C2415; font-weight: 700;">Ingredient Details</h3>
            <button type="button" class="close-view-modal" style="position: absolute; top: 0.85rem; right: 1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #8C6D58;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.25rem;">
            <div style="background: #FAF7F2; padding: 1rem; border-radius: 10px; border: 1px solid #EAE3D9; margin-bottom: 1rem;">
                <div style="font-size: 0.8rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Ingredient Name</div>
                <div id="v_name" style="font-size: 1.2rem; font-weight: 800; color: #3C2415; margin-top: 2px;"></div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Current Stock</div>
                    <div id="v_stock" style="font-size: 1.05rem; font-weight: 700; color: #3C2415; margin-top: 2px;"></div>
                </div>
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Min Alert Level</div>
                    <div id="v_min" style="font-size: 1.05rem; font-weight: 700; color: #6E5C4F; margin-top: 2px;"></div>
                </div>
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Total Value / Price</div>
                    <div id="v_price" style="font-size: 1.05rem; font-weight: 700; color: #1B4F72; margin-top: 2px;"></div>
                </div>
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Unit Cost</div>
                    <div id="v_cost" style="font-size: 1.05rem; font-weight: 700; color: #059669; margin-top: 2px;"></div>
                </div>
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Date Added</div>
                    <div id="v_added" style="font-size: 0.95rem; font-weight: 600; color: #3C2415; margin-top: 2px;"></div>
                </div>
                <div style="background: #FFF; padding: 0.85rem; border-radius: 8px; border: 1px solid #E2D9CF;">
                    <div style="font-size: 0.75rem; color: #8C6D58; text-transform: uppercase; font-weight: 700;">Expiration</div>
                    <div id="v_exp" style="font-size: 0.95rem; font-weight: 600; color: #3C2415; margin-top: 2px;"></div>
                </div>
            </div>

            <div style="margin-top: 1.25rem; text-align: right;">
                <button type="button" class="btn btn-outline close-view-modal" style="border-radius: 8px; font-weight: 600;">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<HTML
<script>
function recalcUnitCost() {
    const stock = parseFloat(document.getElementById('ing_stock').value) || 0;
    const price = parseFloat(document.getElementById('ing_price').value) || 0;
    const unit = document.getElementById('ing_unit').value.trim() || 'unit';
    const costInput = document.getElementById('ing_cost');
    const hint = document.getElementById('auto_calc_hint');

    if (stock > 0 && price > 0) {
        const unitCost = price / stock;
        costInput.value = unitCost.toFixed(2);
        if (hint) {
            hint.innerHTML = `<strong>₱\${price.toFixed(2)}</strong> ÷ <strong>\${stock} \${unit}</strong> = <strong>₱\${unitCost.toFixed(2)}</strong> per \${unit}`;
        }
    }
}

function recalcTotalPrice() {
    const stock = parseFloat(document.getElementById('ing_stock').value) || 0;
    const cost = parseFloat(document.getElementById('ing_cost').value) || 0;
    const priceInput = document.getElementById('ing_price');
    if (stock > 0 && cost > 0) {
        priceInput.value = (stock * cost).toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('modal');
    const viewModal = document.getElementById('viewModal');
    const searchInput = document.getElementById('ingredient-search-input');
    const dispSearchInput = document.getElementById('disposal-search-input');

    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#ingredients-table tbody tr');
            rows.forEach(row => {
                const nameCell = row.querySelector('.searchable');
                if (!nameCell) return;
                const name = nameCell.textContent.toLowerCase();
                row.style.display = name.includes(query) ? '' : 'none';
            });
        });
    }

    if (dispSearchInput) {
        dispSearchInput.addEventListener('keyup', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#disposal-table tbody tr');
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    }

    const addBtn = document.getElementById('openAddModal');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            document.getElementById('modalTitle').textContent = 'Add New Ingredient';
            document.getElementById('ing_id').value = '';
            document.getElementById('ing_name').value = '';
            document.getElementById('ing_unit').value = '';
            document.getElementById('ing_stock').value = '';
            document.getElementById('ing_price').value = '';
            document.getElementById('ing_cost').value = '';
            document.getElementById('ing_min').value = '10';
            document.getElementById('ing_added').value = new Date().toISOString().split('T')[0];
            document.getElementById('ing_exp').value = '';
            document.getElementById('auto_calc_hint').textContent = 'Enter Stock & Total Price to compute Unit Cost automatically.';
            modal.classList.add('active');
        });
    }

    document.querySelectorAll('.edit-ing-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('modalTitle').textContent = 'Edit Ingredient';
            document.getElementById('ing_id').value = this.dataset.id;
            document.getElementById('ing_name').value = this.dataset.name;
            document.getElementById('ing_unit').value = this.dataset.unit;
            document.getElementById('ing_stock').value = this.dataset.stock;
            document.getElementById('ing_price').value = parseFloat(this.dataset.price || 0).toFixed(2);
            document.getElementById('ing_cost').value = parseFloat(this.dataset.cost || 0).toFixed(2);
            document.getElementById('ing_min').value = this.dataset.min;
            document.getElementById('ing_added').value = this.dataset.added || '';
            document.getElementById('ing_exp').value = this.dataset.exp || '';
            recalcUnitCost();
            modal.classList.add('active');
        });
    });

    document.querySelectorAll('.view-ing-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.getElementById('v_name').textContent = this.dataset.name;
            document.getElementById('v_stock').textContent = this.dataset.stock + ' ' + this.dataset.unit;
            document.getElementById('v_min').textContent = this.dataset.min + ' ' + this.dataset.unit;
            document.getElementById('v_price').textContent = '₱' + this.dataset.price;
            document.getElementById('v_cost').textContent = '₱' + parseFloat(this.dataset.cost || 0).toFixed(2) + ' / ' + this.dataset.unit;
            document.getElementById('v_added').textContent = this.dataset.added;
            document.getElementById('v_exp').textContent = this.dataset.exp;
            viewModal.classList.add('active');
        });
    });

    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            if (modal) modal.classList.remove('active');
        });
    });

    document.querySelectorAll('.close-view-modal').forEach(btn => {
        btn.addEventListener('click', () => {
            if (viewModal) viewModal.classList.remove('active');
        });
    });

    window.addEventListener('click', (e) => {
        if (e.target === modal) modal.classList.remove('active');
        if (e.target === viewModal) viewModal.classList.remove('active');
    });
});
</script>
HTML;

require_once ROOT_PATH . '/includes/footer.php';
?>
