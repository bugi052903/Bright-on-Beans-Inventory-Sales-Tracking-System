<?php
require_once __DIR__ . '/../includes/auth.php';
requireStaff();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Inventory View';

$userId = getCurrentUserId();
$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'portion') ? 'portion' : 'stock';

// Handle Staff Product Spoilage Report Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'staff_spoilage') {
    requireCSRF();
    $productId = (int)($_POST['product_id'] ?? 0);
    $transactionId = (int)($_POST['transaction_id'] ?? 0);
    $servings = (float)($_POST['servings'] ?? 1);
    $spoilageReason = trim($_POST['spoilage_reason'] ?? 'Machine / Human Error');

    try {
        $pdo->beginTransaction();

        if ($productId <= 0 || $servings <= 0) {
            throw new Exception("Please select a valid product and positive serving quantity.");
        }

        $stmtP = $pdo->prepare("SELECT * FROM products WHERE product_id = ?");
        $stmtP->execute([$productId]);
        $prod = $stmtP->fetch();
        if (!$prod) throw new Exception("Product not found.");

        $stmtR = $pdo->prepare("SELECT ri.*, i.name as ing_name FROM recipe_ingredients ri JOIN ingredients i ON ri.ingredient_id = i.ingredient_id WHERE ri.product_id = ?");
        $stmtR->execute([$productId]);
        $recipes = $stmtR->fetchAll();

        if (empty($recipes)) {
            throw new Exception("No recipe ingredients defined for {$prod['name']}.");
        }

        $txTag = $transactionId > 0 ? " [Linked to Sale #{$transactionId}]" : "";
        $deductedSummary = [];

        foreach ($recipes as $rec) {
            $requiredQty = (float)$rec['quantity_per_serving'] * $servings;
            $ingName = $rec['ing_name'];

            $stmtBatch = $pdo->prepare("SELECT * FROM ingredients WHERE name = ? AND current_stock > 0 ORDER BY expiration_date ASC, ingredient_id ASC FOR UPDATE");
            $stmtBatch->execute([$ingName]);
            $batches = $stmtBatch->fetchAll();

            $needed = $requiredQty;
            $stmtHist = $pdo->prepare("INSERT INTO inventory_history (ingredient_id, stock_before, stock_after, change_quantity, change_type, notes) VALUES (?, ?, ?, ?, 'adjustment_deduct', ?)");

            foreach ($batches as $b) {
                if ($needed <= 0) break;
                $avail = (float)$b['current_stock'];
                $ded = min($avail, $needed);
                $newStock = $avail - $ded;
                $needed -= $ded;

                $stmtUp = $pdo->prepare("UPDATE ingredients SET current_stock = ? WHERE ingredient_id = ?");
                $stmtUp->execute([$newStock, $b['ingredient_id']]);
                reconcileIngredientStockBatches($pdo, $b['ingredient_id'], $newStock);

                $noteMsg = "Product Adjustment{$txTag}: {$prod['name']} ({$servings} serving) - Reason: {$spoilageReason}";

                $stmtAdj = $pdo->prepare("INSERT INTO inventory_adjustments (ingredient_id, user_id, adjustment_type, quantity, reason, balance_after) VALUES (?, ?, 'deduct', ?, ?, ?)");
                $stmtAdj->execute([$b['ingredient_id'], $userId, $ded, $noteMsg, $newStock]);

                // Record accurate inventory_history line for THIS SPECIFIC INGREDIENT
                $stmtHist->execute([$b['ingredient_id'], $avail, $newStock, $ded, $noteMsg]);
            }

            $deductedSummary[] = formatStock($requiredQty) . " " . $rec['unit'] . " " . $ingName;
        }

        $pdo->commit();
        setFlashMessage('success', "Reported Product Spoilage / Remake{$txTag} for {$servings} serving(s) of '{$prod['name']}' (Reason: {$spoilageReason}). Recipe ingredients deducted via FIFO.");
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        setFlashMessage('error', $e->getMessage());
    }

    header("Location: " . BASE_URL . "/staff/inventory.php?tab=portion");
    exit;
}

// Fetch Ingredients
$stmt = $pdo->query("SELECT * FROM ingredients WHERE is_archived = 0 ORDER BY name ASC");
$ingredients = $stmt->fetchAll();

// Compute stock status counts for monitoring
$totalIngredientsCount = count($ingredients);
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$expiredCount = 0;
$expiringSoonCount = 0;
$today = date('Y-m-d');

foreach ($ingredients as $ing) {
    $stk = (float)$ing['current_stock'];
    $m = (float)$ing['minimum_level'];
    $exp = $ing['expiration_date'] ?? null;
    $isExp = $exp && ($exp <= $today);
    $isExpSoon = $exp && !$isExp && ($exp <= date('Y-m-d', strtotime('+7 days')));

    // Expiration tracking
    if ($isExp) {
        $expiredCount++;
    } elseif ($isExpSoon) {
        $expiringSoonCount++;
    }

    // Stock level tracking
    if ($stk <= 0) {
        $outOfStockCount++;
    } elseif ($stk <= $m) {
        $lowStockCount++;
    } elseif (!$isExp) {
        $inStockCount++;
    }
}

// Fetch Products and Recipes
$stmt = $pdo->query("
    SELECT p.product_id, p.name as product_name, p.category, p.price, p.status,
           i.name as ingredient_name, ri.quantity_per_serving, ri.unit
    FROM products p
    LEFT JOIN recipe_ingredients ri ON p.product_id = ri.product_id
    LEFT JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
    ORDER BY p.name, i.name
");
$rawProducts = $stmt->fetchAll();

$products = [];
foreach ($rawProducts as $row) {
    $pid = $row['product_id'];
    if (!isset($products[$pid])) {
        $products[$pid] = [
            'product_id' => $pid,
            'name' => $row['product_name'],
            'category' => $row['category'],
            'price' => $row['price'],
            'status' => $row['status'] ?: 'available',
            'ingredients' => []
        ];
    }
    if ($row['ingredient_name']) {
        $products[$pid]['ingredients'][] = [
            'name' => $row['ingredient_name'],
            'quantity' => $row['quantity_per_serving'],
            'unit' => $row['unit']
        ];
    }
}
$products = array_values($products);

// Fetch recent sales transactions for transaction-linked spoilage modal (THIS STAFF MEMBER ONLY)
$stmtRecentSales = $pdo->prepare("
    SELECT st.transaction_id, st.transaction_date, st.total_amount, u.first_name, u.last_name,
           GROUP_CONCAT(CONCAT(si.quantity, 'x ', p.name) SEPARATOR ', ') AS item_summary
    FROM sales_transactions st
    LEFT JOIN users u ON st.user_id = u.user_id
    LEFT JOIN sales_items si ON st.transaction_id = si.transaction_id
    LEFT JOIN products p ON si.product_id = p.product_id
    WHERE st.user_id = ?
    GROUP BY st.transaction_id
    ORDER BY st.transaction_date DESC
    LIMIT 30
");
$stmtRecentSales->execute([$userId]);
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

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="mb-4" style="display: flex; justify-content: flex-end; align-items: center;">
    <button type="button" class="btn btn-warning" id="staff-spoilage-btn" style="background: #D97706; border: none; color: #FFF; font-weight: 700; border-radius: 8px; padding: 0.65rem 1.25rem; font-size: 0.9rem; box-shadow: 0 2px 8px rgba(217, 119, 6, 0.25);">
        🚨 Report Drink Remake
    </button>
</div>

<!-- Sub-navigation Tabs -->
<div class="tabs mb-4">
    <a href="?tab=stock" class="tab-btn <?= $activeTab === 'stock' ? 'active' : '' ?>" style="text-decoration: none;">Inventory Ingredient</a>
    <a href="?tab=portion" class="tab-btn <?= $activeTab === 'portion' ? 'active' : '' ?>" style="text-decoration: none;">Inventory Portion (Recipes)</a>
</div>

<?php if ($activeTab === 'stock'): ?>
    <!-- ─── TAB 1: INVENTORY STOCK ─── -->
    <div class="card">
        <div class="card-body">
            <!-- Search & Filter Controls -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.25rem;">
                <input type="text" id="stock-search" class="form-control" placeholder="Search ingredients..." style="max-width: 320px; padding: 0.65rem 1rem; border-radius: 10px; border: 1px solid #E2D9CF; background: #FAF7F2; margin: 0;">

                <!-- Status Filter Pills -->
                <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                    <button type="button" class="status-filter-btn active" data-filter="all" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #D2C6B9; background: #3C2415; color: #FFF; transition: all 0.2s;">
                        All (<?= $totalIngredientsCount ?>)
                    </button>
                    <button type="button" class="status-filter-btn" data-filter="in-stock" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #A7F3D0; background: #E6F4EA; color: #059669; transition: all 0.2s;">
                        ✓ In Stock (<?= $inStockCount ?>)
                    </button>
                    <button type="button" class="status-filter-btn" data-filter="low-stock" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #FDE68A; background: #FEF3C7; color: #D97706; transition: all 0.2s;">
                        ⚠️ Low Stock (<?= $lowStockCount ?>)
                    </button>
                    <button type="button" class="status-filter-btn" data-filter="out-of-stock" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #FCA5A5; background: #FCE8E6; color: #DC2626; transition: all 0.2s;">
                        🚨 Out of Stock (<?= $outOfStockCount ?>)
                    </button>
                    <button type="button" class="status-filter-btn" data-filter="expiring-soon" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #FCD34D; background: #FFF9E6; color: #D97706; transition: all 0.2s;">
                        ⏳ Expiring Soon (<?= $expiringSoonCount ?>)
                    </button>
                    <button type="button" class="status-filter-btn" data-filter="expired" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #FCA5A5; background: #FCE8E6; color: #DC2626; transition: all 0.2s;">
                        🚨 Expired (<?= $expiredCount ?>)
                    </button>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="table table-hover align-middle" id="stock-table">
                    <thead>
                        <tr>
                            <th>Ingredient Name</th>
                            <th class="text-center">Current Stock</th>
                            <th class="text-center">Minimum Threshold</th>
                            <th class="text-center">Stock Status</th>
                            <th>Date Added</th>
                            <th>Expiration Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ingredients as $i):
                            $stock = (float)$i['current_stock'];
                            $min = (float)$i['minimum_level'];
                            $expDate = $i['expiration_date'];
                            $isExpired = $expDate && strtotime($expDate) <= strtotime(date('Y-m-d'));
                            $isExpiringSoon = $expDate && !$isExpired && strtotime($expDate) <= strtotime('+7 days');
                            $expStatus = $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : 'ok');
                            
                            $isOutOfStock = $stock <= 0;
                            $isLow = !$isOutOfStock && $stock <= $min;
                            $status = $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : ($isOutOfStock ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock')));
                            $stockCategory = $isOutOfStock ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock');
                        ?>
                            <tr class="stock-row" 
                                data-status="<?= $status ?>" 
                                data-stock="<?= $stockCategory ?>"
                                data-expiration="<?= $expStatus ?>"
                                style="<?= $isExpired ? 'background: #FFF5F5;' : ($isExpiringSoon ? 'background: #FFFDF0;' : ($isLow ? 'background: #FFFDF5;' : ($isOutOfStock ? 'background: #FFF5F5;' : ''))) ?>">
                                <td class="searchable"><strong><?= e($i['name']) ?></strong></td>
                                <td class="text-center">
                                    <strong style="font-size: 1.05rem; color: <?= $isExpired ? '#DC2626' : ($isExpiringSoon ? '#D97706' : (($isOutOfStock || $isLow) ? '#D97706' : '#3C2415')) ?>;">
                                        <?= formatStock($stock) ?> <?= e($i['unit']) ?>
                                    </strong>
                                </td>
                                <td class="text-center text-muted"><?= formatStock($min) ?> <?= e($i['unit']) ?></td>
                                <td class="text-center">
                                    <?php if ($isExpired): ?>
                                        <span class="badge badge-danger">🚨 Expired</span>
                                    <?php elseif ($isExpiringSoon): ?>
                                        <span class="badge badge-warning" style="background: #FFF9E6; color: #D97706; border: 1px solid #FCD34D;">⏳ Expiring Soon</span>
                                    <?php elseif ($isOutOfStock): ?>
                                        <span class="badge badge-danger">🚨 Out of Stock</span>
                                    <?php elseif ($isLow): ?>
                                        <span class="badge badge-warning">⚠️ Low Stock</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">✓ In Stock</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= $i['date_added'] ? e(date('M d, Y', strtotime($i['date_added']))) : '<span class="text-muted">N/A</span>' ?>
                                </td>
                                <td>
                                    <?php if ($expDate): ?>
                                        <?php if ($isExpired): ?>
                                            <span class="badge badge-danger">Expired: <?= formatDate($expDate) ?></span>
                                        <?php elseif ($isExpiringSoon): ?>
                                            <span class="badge badge-warning">Expiring: <?= formatDate($expDate) ?></span>
                                        <?php else: ?>
                                            <span class="text-muted"><?= formatDate($expDate) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($ingredients)): ?>
                            <tr>
                                <td colspan="6" class="text-center p-4">No ingredients found in inventory.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ─── TAB 2: INVENTORY PORTION (RECIPES) ─── -->
    <div class="card">
        <div class="card-body">
            <div class="mb-4">
                <input type="text" id="portion-search" class="form-control" placeholder="Search products or ingredients..." style="max-width: 380px; padding: 0.65rem 1rem; border-radius: 10px; border: 1px solid #E2D9CF; background: #FAF7F2;">
            </div>

            <div class="table-wrapper">
                <table class="table align-middle" id="portion-table">
                    <thead>
                        <tr>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">PRODUCT NAME</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">CATEGORY</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">PRICE</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">RECIPE DETAILS</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px; text-align: center;">STATUS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td><strong style="color: #3C2415; font-size: 0.95rem;"><?= e($p['name']) ?></strong></td>
                                <td><span class="badge badge-secondary"><?= e($p['category']) ?></span></td>
                                <td style="font-weight: 700; color: #3C2415;">₱<?= number_format($p['price'], 2) ?></td>
                                <td>
                                    <?php if (empty($p['ingredients'])): ?>
                                        <span class="text-muted" style="font-size: 0.85rem;">No recipe defined</span>
                                    <?php else: ?>
                                        <ul style="margin: 0; padding-left: 1.2rem; font-size: 0.88rem; color: #5A3A28; line-height: 1.5;">
                                            <?php foreach ($p['ingredients'] as $ing): ?>
                                                <li><strong><?= formatStock($ing['quantity']) ?> <?= e($ing['unit']) ?></strong> of <?= e($ing['name']) ?></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (($p['status'] ?? 'available') === 'available'): ?>
                                        <span class="badge badge-success">✓ Available</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="5" class="text-center p-4" style="color: #A08D7D;">No products found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Staff Product Spoilage Modal -->
<div class="modal-overlay" id="staffSpoilageModal">
    <div class="modal" style="border-radius: 14px; max-width: 480px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #EAE3D9; padding-bottom: 0.75rem;">
            <h3 style="margin: 0; font-size: 1.1rem; color: #3C2415; font-weight: 700;">Report Drink Spoilage / Remake</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 0.85rem; right: 1.1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #8C6D58;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.25rem 0.25rem 0.5rem;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="staff_spoilage">

                <!-- 1. Select Recorded Sale Transaction (Optional) -->
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Select Recorded Sale Transaction</label>
                    <select name="transaction_id" id="modal_spoil_tx_id" class="form-control" style="font-size: 0.86rem; font-weight: 600; background: #FAF7F2;">
                        <?php if (!empty($recentSalesList)): ?>
                            <option value="0">-- General Product Spoilage (No Sale Linked) --</option>
                            <?php foreach ($recentSalesList as $s):
                                $dateStr = date('M d, g:i A', strtotime($s['transaction_date']));
                            ?>
                                <option value="<?= $s['transaction_id'] ?>">
                                    <?= $dateStr ?> — Sale #<?= $s['transaction_id'] ?> (<?= e($s['item_summary'] ?: 'Items') ?> — ₱<?= number_format($s['total_amount'], 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <option value="0">-- General Product Spoilage (No Sales Yet) --</option>
                        <?php endif; ?>
                    </select>
                    <small style="color: #8C6D58; font-size: 0.8rem; margin-top: 4px; display: block;">Select the specific past sale where the drink error occurred.</small>
                </div>

                <!-- 2. Select Drink Product -->
                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Select Specific Drink to Adjust</label>
                    <select name="product_id" id="modal_spoil_product_id" class="form-control" style="font-weight: 600; font-size: 0.9rem;" required>
                        <?php foreach ($products as $p):
                            if (empty($p['ingredients'])) continue;
                        ?>
                            <option value="<?= $p['product_id'] ?>"><?= e($p['name']) ?> (₱<?= number_format($p['price'], 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #059669; font-size: 0.8rem; margin-top: 4px; display: block; font-weight: 600;" id="staff_product_filter_hint">Filters to items in selected sale.</small>
                </div>

                <div class="form-group mb-3">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Quantity Wasted / Remade (Servings)</label>
                    <input type="number" name="servings" value="1" step="1" min="1" class="form-control" required style="font-size: 0.95rem; font-weight: 600;">
                </div>

                <div class="form-group mb-4">
                    <label class="form-label" style="font-weight: 600; color: #3C2415; font-size: 0.88rem;">Reason</label>
                    <input type="text" name="spoilage_reason" class="form-control" placeholder="e.g. Machine error on Caramel Latte" style="font-size: 0.9rem;" required>
                </div>

                <button type="submit" class="btn btn-warning w-100" style="background: #D97706; border: none; padding: 0.7rem; font-weight: 700; font-size: 0.95rem; border-radius: 8px; color: #FFF;">
                    🚨 Submit Spoilage Report
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const txMap = <?= json_encode($txProductMap) ?>;
        const allProds = <?= json_encode($products) ?>;

        const modal = document.getElementById('staffSpoilageModal');
        const spoilBtn = document.getElementById('staff-spoilage-btn');
        const txSelect = document.getElementById('modal_spoil_tx_id');
        const prodSelect = document.getElementById('modal_spoil_product_id');
        const filterHint = document.getElementById('staff_product_filter_hint');

        function updateStaffProductOptions() {
            if (!txSelect || !prodSelect) return;
            const txId = txSelect.value;
            prodSelect.innerHTML = '';

            let itemsToDisplay = [];
            if (txId > 0 && txMap[txId] && txMap[txId].length > 0) {
                itemsToDisplay = txMap[txId];
                filterHint.textContent = 'Showing only items from Sale #' + txId;
            } else {
                itemsToDisplay = allProds.filter(p => p.ingredients && p.ingredients.length > 0);
                filterHint.textContent = 'Showing all available products with recipes.';
            }

            itemsToDisplay.forEach((p, idx) => {
                const opt = document.createElement('option');
                opt.value = p.product_id;
                opt.textContent = (p.name || p.product_name) + ' (₱' + (parseFloat(p.price) || 0).toFixed(2) + ')';
                if (idx === 0) opt.selected = true;
                prodSelect.appendChild(opt);
            });
        }

        if (txSelect) {
            txSelect.addEventListener('change', updateStaffProductOptions);
        }

        spoilBtn?.addEventListener('click', function() {
            updateStaffProductOptions();
            modal.classList.add('active');
        });

        document.querySelectorAll('.spoil-product-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const pid = this.dataset.id;
                updateStaffProductOptions();
                if (prodSelect) prodSelect.value = pid;
                modal.classList.add('active');
            });
        });

        document.querySelectorAll('.close-modal').forEach(btn => {
            btn.addEventListener('click', function() {
                modal.classList.remove('active');
            });
        });

        // Stock Filter and Search
        const filterBtns = document.querySelectorAll('.status-filter-btn');
        let currentFilter = 'all';

        function applyFilters() {
            const searchInput = document.getElementById('stock-search');
            const searchTerm = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const rows = document.querySelectorAll('#stock-table tbody tr.stock-row');

            rows.forEach(row => {
                const nameCell = row.querySelector('.searchable');
                const name = nameCell ? nameCell.textContent.toLowerCase() : '';
                const status = row.getAttribute('data-status');
                const exp = row.getAttribute('data-expiration');

                const matchesSearch = !searchTerm || name.includes(searchTerm);
                let matchesStatus = false;

                if (currentFilter === 'all') {
                    matchesStatus = true;
                } else if (currentFilter === 'expiring-soon') {
                    matchesStatus = (exp === 'expiring-soon');
                } else if (currentFilter === 'expired') {
                    matchesStatus = (exp === 'expired');
                } else if (currentFilter === 'out-of-stock') {
                    matchesStatus = (row.getAttribute('data-stock') === 'out-of-stock');
                } else {
                    matchesStatus = (status === currentFilter);
                }

                row.style.display = (matchesSearch && matchesStatus) ? '' : 'none';
            });
        }

        filterBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                filterBtns.forEach(b => {
                    b.style.background = '';
                    b.style.color = '';
                    b.classList.remove('active');
                });
                this.classList.add('active');
                currentFilter = this.getAttribute('data-filter');

                if (currentFilter === 'all') {
                    this.style.background = '#3C2415';
                    this.style.color = '#FFF';
                } else if (currentFilter === 'in-stock') {
                    this.style.background = '#059669';
                    this.style.color = '#FFF';
                } else if (currentFilter === 'low-stock') {
                    this.style.background = '#D97706';
                    this.style.color = '#FFF';
                } else if (currentFilter === 'out-of-stock') {
                    this.style.background = '#DC2626';
                    this.style.color = '#FFF';
                } else if (currentFilter === 'expiring-soon') {
                    this.style.background = '#D97706';
                    this.style.color = '#FFF';
                } else if (currentFilter === 'expired') {
                    this.style.background = '#DC2626';
                    this.style.color = '#FFF';
                }

                applyFilters();
            });
        });

        const stockSearch = document.getElementById('stock-search');
        if (stockSearch) {
            stockSearch.addEventListener('keyup', applyFilters);
        }

        // Portion Search
        const searchInput = document.getElementById('portion-search');
        searchInput?.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const rows = document.querySelectorAll('#portion-table tbody tr');

            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    });
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>