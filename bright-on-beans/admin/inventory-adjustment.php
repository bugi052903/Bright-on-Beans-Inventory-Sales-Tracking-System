<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Inventory Adjustment';

$preselectId = isset($_GET['ingredient_id']) ? (int)$_GET['ingredient_id'] : 0;
$preselectTx = isset($_GET['transaction_id']) ? (int)$_GET['transaction_id'] : 0;
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'product') || $preselectTx > 0 ? 'product' : 'ingredient';

// Fetch all ingredients for adjustment (including zero-stock items so they can be restocked)
$stmt = $pdo->query("SELECT * FROM ingredients WHERE is_archived = 0 ORDER BY name ASC, current_stock DESC, ingredient_id ASC");
$ingredients = $stmt->fetchAll();

// Fetch all products with recipes
$stmtProds = $pdo->query("SELECT DISTINCT p.* FROM products p JOIN recipe_ingredients ri ON p.product_id = ri.product_id ORDER BY p.name ASC");
$productsWithRecipes = $stmtProds->fetchAll();

// Fetch recent sales transactions for transaction-linked adjustment
$stmtRecentSales = $pdo->query("
    SELECT st.transaction_id, st.transaction_date, st.total_amount, u.first_name, u.last_name,
           GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) SEPARATOR ', ') AS item_summary
    FROM sales_transactions st
    LEFT JOIN users u ON st.user_id = u.user_id
    LEFT JOIN sales_items si ON st.transaction_id = si.transaction_id
    LEFT JOIN products p ON si.product_id = p.product_id
    GROUP BY st.transaction_id
    ORDER BY st.transaction_date DESC
    LIMIT 30
");
$recentSalesList = $stmtRecentSales->fetchAll();

// Fetch transaction item map for dynamic product dropdown filtering
$stmtTxItems = $pdo->query("
    SELECT si.transaction_id, si.product_id, p.name, p.price 
    FROM sales_items si 
    JOIN products p ON si.product_id = p.product_id
    JOIN recipe_ingredients ri ON p.product_id = ri.product_id
    GROUP BY si.transaction_id, si.product_id
");
$txItemsRaw = $stmtTxItems->fetchAll();

$txProductMap = [];
foreach ($txItemsRaw as $ti) {
    $txProductMap[$ti['transaction_id']][] = [
        'product_id' => $ti['product_id'],
        'name' => $ti['name'],
        'price' => (float)$ti['price']
    ];
}

if ($preselectId <= 0 && !empty($ingredients)) {
    $preselectId = (int)$ingredients[0]['ingredient_id'];
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $actionType = $_POST['action_type'] ?? '';
    $userId = getCurrentUserId();

    try {
        $pdo->beginTransaction();

        if ($actionType === 'product_spoilage') {
            $productId = (int)($_POST['product_id'] ?? 0);
            $transactionId = (int)($_POST['transaction_id'] ?? 0);
            $servings = (float)($_POST['servings'] ?? 1);
            $spoilageReason = trim($_POST['spoilage_reason'] ?? 'Human Error / Spill');
            if (empty($spoilageReason)) {
                $spoilageReason = 'Human Error / Spill';
            }

            if ($productId <= 0 || $servings <= 0) {
                throw new Exception("Please select a valid product and enter a positive serving quantity.");
            }

            $stmtP = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
            $stmtP->execute([$productId]);
            $prod = $stmtP->fetch();
            if (!$prod) throw new Exception("Product not found.");

            // Fetch recipe for THIS SPECIFIC PRODUCT ONLY
            $stmtR = $pdo->prepare("SELECT ri.*, i.name as ing_name FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.ingredient_id WHERE ri.product_id = ?");
            $stmtR->execute([$productId]);
            $recipes = $stmtR->fetchAll();

            if (empty($recipes)) {
                throw new Exception("No recipe ingredients defined for {$prod['name']}.");
            }

            $txTag = $transactionId > 0 ? " [Linked to Sale #{$transactionId}]" : "";

            // Deduct recipe ingredients FOR THIS PRODUCT ONLY & LOG ACCURATE HISTORY PER INGREDIENT
            $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, ?, ?, ?, 'adjustment_deduct', ?)");

            foreach ($recipes as $rec) {
                $requiredQty = (float)$rec['quantity_per_serving'] * $servings;
                $ingName = $rec['ing_name'];

                // Query FIFO batches for this ingredient
                $stmtBatch = $pdo->prepare("SELECT * FROM ingredients WHERE name = ? AND current_stock > 0 ORDER BY expiration_date ASC, ingredient_id ASC FOR UPDATE");
                $stmtBatch->execute([$ingName]);
                $batches = $stmtBatch->fetchAll();

                $needed = $requiredQty;
                foreach ($batches as $b) {
                    if ($needed <= 0) break;
                    $avail = (float)$b['current_stock'];
                    $ded = min($avail, $needed);
                    $newStock = $avail - $ded;
                    $needed -= $ded;

                    // Update stock
                    $stmtUp = $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?");
                    $stmtUp->execute([$newStock, $b['ingredient_id']]);
                    reconcileIngredientStockBatches($pdo, $b['ingredient_id'], $newStock);

                    // Record adjustment
                    $cleanReason = "Product Adjustment{$txTag}: {$prod['name']} ({$servings} serving) - Reason: {$spoilageReason}";
                    
                    $stmtAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, 'deduct', ?, ?, ?)");
                    $stmtAdj->execute([$b['ingredient_id'], $userId, $ded, $cleanReason, $newStock]);

                    // Record accurate inventory_history line
                    $stmtHist->execute([$b['ingredient_id'], $avail, $newStock, $ded, $cleanReason]);
                }
            }

            $pdo->commit();
            setFlashMessage('success', "Recorded Product Adjustment for {$servings} serving(s) of '{$prod['name']}' (Reason: {$spoilageReason}). Recipe ingredients deducted via FIFO.");
            header("Location: " . BASE_URL . "/admin/inventory-adjustment.php?tab=product");
            exit;

        } else {
            $ingredientId = (int)$_POST['ingredient_id'];
            $quantity = floatval($_POST['quantity'] ?? 0);
            $overallPrice = floatval($_POST['overall_price'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $restockDate = !empty($_POST['restock_date']) ? $_POST['restock_date'] : date('Y-m-d');
            $expirationDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;

            if ($quantity <= 0) {
                throw new Exception("Please enter a quantity greater than zero.");
            }

            $stmtIng = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ? FOR UPDATE");
            $stmtIng->execute([$ingredientId]);
            $ing = $stmtIng->fetch();
            if (!$ing) throw new Exception("Ingredient not found.");

            $currentStock = (float)$ing['current_stock'];
            $unit = $ing['unit'];

            if ($overallPrice > 0 && $quantity > 0) {
                $unitCost = $overallPrice / $quantity;
            } else {
                $unitCost = (float)$ing['unit_cost'];
            }

            if ($actionType === 'add') {
                $stmtInsert = $pdo->prepare("INSERT INTO ingredients (name, unit, current_stock, minimum_level, unit_cost, date_added, expiration_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$ing['name'], $unit, $quantity, $ing['minimum_level'], $unitCost, $restockDate, $expirationDate]);
                $newBatchId = $pdo->lastInsertId();

                $cleanReason = $reason ?: "Supplier Delivery";

                $stmtAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, 'add', ?, ?, ?)");
                $stmtAdj->execute([$newBatchId, $userId, $quantity, $cleanReason, $quantity]);

                $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, 0, ?, ?, 'adjustment_add', ?)");
                $stmtHist->execute([$newBatchId, $quantity, $quantity, $cleanReason]);
                
                setFlashMessage('success', "Added stock of " . formatStock($quantity) . " {$unit} for {$ing['name']} (₱" . number_format($unitCost, 2) . "/{$unit}).");

            } elseif ($actionType === 'deduct') {
                if ($quantity > $currentStock) {
                    throw new Exception("Cannot deduct " . formatStock($quantity) . " {$unit}. Current stock is only " . formatStock($currentStock) . " {$unit}.");
                }
                $newStock = $currentStock - $quantity;
                $stmtUp = $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?");
                $stmtUp->execute([$newStock, $ingredientId]);
                reconcileIngredientStockBatches($pdo, $ingredientId, $newStock);

                $cleanReason = $reason ?: "Manual Stock Deduction";

                $stmtAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, 'deduct', ?, ?, ?)");
                $stmtAdj->execute([$ingredientId, $userId, $quantity, $cleanReason, $newStock]);

                $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, ?, ?, ?, 'adjustment_deduct', ?)");
                $stmtHist->execute([$ingredientId, $currentStock, $newStock, $quantity, $cleanReason]);

                setFlashMessage('success', "Deducted " . formatStock($quantity) . " {$unit} of {$ing['name']}. Remaining: " . formatStock($newStock) . " {$unit}.");
            }

            $pdo->commit();
            $redirectId = ($actionType === 'add' && !empty($newBatchId)) ? $newBatchId : $ingredientId;
            header("Location: " . BASE_URL . "/admin/inventory-adjustment.php?tab=ingredient&ingredient_id=" . $redirectId);
            exit;
        }

    } catch (Exception $e) {
        $pdo->rollBack();
        setFlashMessage('error', $e->getMessage());
        header("Location: " . BASE_URL . "/admin/inventory-adjustment.php?tab=" . $activeTab);
        exit;
    }
}

// Fetch currently selected ingredient details
$selectedIng = null;
if ($preselectId > 0) {
    $stmtSel = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ?");
    $stmtSel->execute([$preselectId]);
    $selectedIng = $stmtSel->fetch();
}

$ingMap = [];
foreach ($ingredients as $ing) {
    $ingMap[strtolower(trim($ing['name']))] = [
        'id' => (int)$ing['ingredient_id'],
        'name' => $ing['name'],
        'unit' => $ing['unit'],
        'stock' => formatStock((float)$ing['current_stock']),
        'raw_stock' => (float)$ing['current_stock'],
        'unit_cost' => (float)$ing['unit_cost']
    ];
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="tabs mb-4" style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #EAE3D9; padding-bottom: 0.5rem;">
    <div style="display: flex; gap: 8px;">
        <a href="?tab=ingredient" class="tab-btn <?= $activeTab === 'ingredient' ? 'active' : '' ?>" style="text-decoration: none;">📦 Ingredient Adjustment</a>
        <a href="?tab=product" class="tab-btn <?= $activeTab === 'product' ? 'active' : '' ?>" style="text-decoration: none;">🚨 Product Adjustment</a>
    </div>
    <a href="<?= BASE_URL ?>/admin/ingredient-management.php?tab=audit" class="btn btn-outline btn-sm" style="border: 1px solid #C49A82; color: #3C2415; border-radius: 8px; font-weight: 600; padding: 0.45rem 1rem; text-decoration: none; font-size: 0.82rem;">
        📜 View Movement & Audit Logs ➔
    </a>
</div>

<?php renderFlashMessage(); ?>

<?php if ($activeTab === 'ingredient'): ?>
<!-- ─── TAB 1: INGREDIENT ADJUSTMENT ─── -->
<div style="max-width: 580px; margin: 0 auto;">
    <div class="card" style="border-radius: 14px; border: 1px solid #EAE3D9; box-shadow: 0 4px 15px rgba(60,36,21,0.04);">
        <div class="card-header" style="background: #FDF9F5; border-bottom: 1px solid #EFE8DE; padding: 1.1rem 1.4rem;">
            <h3 style="margin: 0; font-size: 1rem; color: #3C2415; font-weight: 700;">Record Stock Adjustment</h3>
        </div>
        <div class="card-body" style="padding: 1.5rem;">
            <form method="POST" action="">
                <?= csrfField() ?>
                
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Select Ingredient</label>
                    <input type="text" list="ingredient_datalist" id="ingredient_search_input" class="form-control" placeholder="Type to search or select an ingredient..." value="<?= e($selectedIng['name'] ?? '') ?>" autocomplete="off" required style="font-size: 0.9rem;" oninput="onIngredientSelect(this)">
                    <datalist id="ingredient_datalist">
                        <?php foreach ($ingredients as $ing): 
                            $stk = (float)$ing['current_stock'];
                            $stockText = $stk <= 0 ? '0 ' . $ing['unit'] . ' - Out of Stock' : formatStock($stk) . ' ' . $ing['unit'];
                            $expText = !empty($ing['expiration_date']) ? ' | Exp: ' . date('M d, Y', strtotime($ing['expiration_date'])) : '';
                        ?>
                        <option value="<?= e($ing['name']) ?>"><?= $stockText ?><?= $expText ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" name="ingredient_id" id="ingredient_id_hidden" value="<?= (int)($selectedIng['ingredient_id'] ?? $preselectId) ?>">
                    <small id="ingredient_stock_hint" style="color: #8C6D58; display: block; margin-top: 4px; font-size: 0.82rem;">
                        Current Stock: <strong id="stock_display"><?= formatStock((float)($selectedIng['current_stock'] ?? 0)) ?> <?= e($selectedIng['unit'] ?? '') ?></strong>
                    </small>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Adjustment Type</label>
                    <select name="action_type" id="action_type" class="form-control" required style="font-size: 0.9rem;">
                        <option value="add">+ Add Stock (Restock)</option>
                        <option value="deduct">- Deduct Stock (Spoilage / Waste)</option>
                    </select>
                </div>

                <div class="form-group mb-3" id="restock-date-group">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Restock Date</label>
                    <input type="date" name="restock_date" class="form-control" value="<?= date('Y-m-d') ?>" style="font-size: 0.9rem;" required>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Quantity <span id="unit_label">(in <?= e($selectedIng['unit'] ?? 'units') ?>)</span></label>
                    <input type="number" name="quantity" id="adj_qty" step="0.01" min="0.01" class="form-control" required placeholder="Enter quantity" style="font-size: 0.95rem; font-weight: 600;" oninput="calcUnitCostFromPrice()">
                </div>

                <div class="form-group mb-3" id="price-group">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Total Price (₱)</label>
                    <input type="number" name="overall_price" id="overall_price" step="0.01" min="0" class="form-control" placeholder="e.g. 1000.00" style="font-size: 0.9rem;" oninput="calcUnitCostFromPrice()">
                    <small id="unit_cost_hint" style="color: #059669; font-weight: 600; display: block; margin-top: 4px; font-size: 0.8rem;"></small>
                </div>

                <div class="form-group mb-3" id="exp-group">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Expiration Date</label>
                    <input type="date" name="expiration_date" class="form-control" style="font-size: 0.9rem;">
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Reason</label>
                    <input type="text" name="reason" class="form-control" placeholder="e.g. Supplier delivery, Spilled milk container, etc." style="font-size: 0.9rem;" required>
                </div>

                <button type="submit" class="btn btn-primary w-100" style="background: #3C2415; border: none; padding: 0.75rem; font-weight: 700; font-size: 0.95rem; border-radius: 8px; box-shadow: 0 2px 8px rgba(60,36,21,0.2);">
                    Confirm Stock Adjustment
                </button>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ─── TAB 2: PRODUCT ADJUSTMENT (DRINK REMAKE / SPOILAGE) ─── -->
<div style="max-width: 580px; margin: 0 auto;">
    <div class="card" style="border-radius: 14px; border: 1px solid #EAE3D9; box-shadow: 0 4px 15px rgba(60,36,21,0.04);">
        <div class="card-header" style="background: #FDF9F5; border-bottom: 1px solid #EFE8DE; padding: 1.1rem 1.4rem;">
            <h3 style="margin: 0; font-size: 1rem; color: #3C2415; font-weight: 700;">🚨 Record Drink Remake / Spoilage</h3>
        </div>
        <div class="card-body" style="padding: 1.5rem;">
            <form method="POST" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action_type" value="product_spoilage">

                <!-- Linked Transaction Dropdown -->
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Linked Sale (Optional)</label>
                    <select name="transaction_id" id="transaction_id" class="form-control" style="font-size: 0.88rem;">
                        <option value="0">-- General Remake (Not Linked to Specific Sale) --</option>
                        <?php foreach ($recentSalesList as $sal): 
                            $salDate = date('M d, g:i A', strtotime($sal['transaction_date']));
                            $cashier = $sal['first_name'] ? " ({$sal['first_name']})" : "";
                        ?>
                        <option value="<?= $sal['transaction_id'] ?>" <?= $sal['transaction_id'] == $preselectTx ? 'selected' : '' ?>>
                            Sale #<?= $sal['transaction_id'] ?> - ₱<?= number_format($sal['total_amount'], 2) ?> [<?= $salDate ?>]<?= $cashier ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Product Selection -->
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Select Product</label>
                    <select name="product_id" id="product_id" class="form-control" required style="font-size: 0.9rem;">
                        <option value="">-- Choose Product --</option>
                        <?php foreach ($productsWithRecipes as $p): ?>
                        <option value="<?= $p['product_id'] ?>">
                            <?= e($p['name']) ?> (₱<?= number_format($p['price'], 2) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Servings Remade</label>
                    <input type="number" name="servings" value="1" min="1" step="1" class="form-control" required style="font-size: 0.95rem; font-weight: 600;">
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Reason</label>
                    <input type="text" list="spoilage_reasons_list" name="spoilage_reason" class="form-control" placeholder="Select a preset or type your own reason..." required autocomplete="off" style="font-size: 0.9rem;">
                    <datalist id="spoilage_reasons_list">
                        <option value="Spill / Accidental Drop">
                        <option value="Incorrect Formulation / Wrong Order">
                        <option value="Customer Complaint / Remake">
                        <option value="Machine Malfunction">
                        <option value="Taste / Quality Rejection">
                        <option value="Other Spoilage">
                    </datalist>
                </div>

                <button type="submit" class="btn btn-warning w-100" style="background: #D97706; border: none; font-weight: 700; padding: 0.75rem; border-radius: 8px; font-size: 0.95rem; color: #FFF; box-shadow: 0 2px 8px rgba(217,119,6,0.25);">
                    🚨 Deduct Recipe Ingredients
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const ingData = <?= json_encode($ingMap) ?>;

function onIngredientSelect(input) {
    const val = input.value.trim().toLowerCase();
    const hiddenId = document.getElementById('ingredient_id_hidden');
    const unitLabel = document.getElementById('unit_label');
    const stockDisplay = document.getElementById('stock_display');

    if (ingData[val]) {
        const item = ingData[val];
        if (hiddenId) hiddenId.value = item.id;
        if (unitLabel) unitLabel.textContent = '(in ' + item.unit + ')';
        if (stockDisplay) stockDisplay.textContent = item.stock + ' ' + item.unit;
    } else {
        if (val === '') {
            if (hiddenId) hiddenId.value = '';
            if (stockDisplay) stockDisplay.textContent = 'None';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const ingInput = document.getElementById('ingredient_search_input');
    const hiddenId = document.getElementById('ingredient_id_hidden');
    if (ingInput && hiddenId) {
        const parentForm = ingInput.closest('form');
        if (parentForm) {
            parentForm.addEventListener('submit', function(e) {
                const val = ingInput.value.trim().toLowerCase();
                if (ingData[val]) {
                    hiddenId.value = ingData[val].id;
                }
                if (!hiddenId.value || parseInt(hiddenId.value) <= 0) {
                    e.preventDefault();
                    alert('Please select or type a valid ingredient from the list.');
                    ingInput.focus();
                    return false;
                }
            });
        }
    }

    const txMap = <?= json_encode($txProductMap) ?>;
    const txSelect = document.getElementById('transaction_id');
    const prodSelect = document.getElementById('product_id');

    if (txSelect && prodSelect) {
        txSelect.addEventListener('change', function() {
            const txId = parseInt(this.value);
            prodSelect.innerHTML = '<option value="">-- Choose Product --</option>';

            if (txId > 0 && txMap[txId]) {
                txMap[txId].forEach(p => {
                    const opt = document.createElement('option');
                    opt.value = p.product_id;
                    opt.textContent = p.name + ' (₱' + p.price.toFixed(2) + ')';
                    prodSelect.appendChild(opt);
                });
            } else {
                <?php foreach ($productsWithRecipes as $p): ?>
                const opt<?= $p['product_id'] ?> = document.createElement('option');
                opt<?= $p['product_id'] ?>.value = '<?= $p['product_id'] ?>';
                opt<?= $p['product_id'] ?>.textContent = '<?= e(addslashes($p['name'])) ?> (₱<?= number_format($p['price'], 2) ?>)';
                prodSelect.appendChild(opt<?= $p['product_id'] ?>);
                <?php endforeach; ?>
            }
        });
    }

    const actionSelect = document.getElementById('action_type');
    const priceGroup = document.getElementById('price-group');
    const expGroup = document.getElementById('exp-group');
    const restockDateGroup = document.getElementById('restock-date-group');

    if (actionSelect) {
        actionSelect.addEventListener('change', function() {
            if (this.value === 'add') {
                if (priceGroup) priceGroup.style.display = 'block';
                if (expGroup) expGroup.style.display = 'block';
                if (restockDateGroup) restockDateGroup.style.display = 'block';
            } else {
                if (priceGroup) priceGroup.style.display = 'none';
                if (expGroup) expGroup.style.display = 'none';
                if (restockDateGroup) restockDateGroup.style.display = 'none';
                document.getElementById('overall_price').value = '';
                document.getElementById('unit_cost_hint').textContent = '';
            }
        });
    }
});

function calcUnitCostFromPrice() {
    const qty = parseFloat(document.getElementById('adj_qty').value) || 0;
    const price = parseFloat(document.getElementById('overall_price').value) || 0;
    const hint = document.getElementById('unit_cost_hint');
    if (qty > 0 && price > 0) {
        const unitCost = price / qty;
        hint.textContent = 'Computed Unit Cost: ₱' + unitCost.toFixed(2) + ' per unit';
    } else {
        hint.textContent = '';
    }
}
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
