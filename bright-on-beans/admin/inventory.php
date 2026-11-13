<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Inventory';

$activeTab = (isset($_GET['tab']) && $_GET['tab'] === 'portion') ? 'portion' : 'stock';

// Fetch inventory ingredients (FIFO ordered)
$stmt = $pdo->query("SELECT * FROM ingredients WHERE is_archived = 0 ORDER BY name ASC, CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, ingredient_id ASC");
$ingredients = $stmt->fetchAll();

// Fetch products and recipes for portion inventory
$stmt = $pdo->query("
    SELECT p.product_id, p.name as product_name, p.category, p.price, p.status,
           i.name as ingredient_name, ri.quantity_per_serving, ri.unit
    FROM products p
    LEFT JOIN recipe_ingredients ri ON p.product_id = ri.product_id
    LEFT JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
    ORDER BY p.category ASC, p.name ASC, i.name ASC
");
$rawProducts = $stmt->fetchAll();

$products = [];
foreach ($rawProducts as $row) {
    $pid = $row['product_id'];
    if (!isset($products[$pid])) {
        $products[$pid] = [
            'product_id' => $pid,
            'name' => $row['product_name'],
            'category' => $row['category'] ?: 'Uncategorized',
            'price' => (float)$row['price'],
            'status' => $row['status'] ?: 'available',
            'ingredients' => []
        ];
    }
    if (!empty($row['ingredient_name'])) {
        $products[$pid]['ingredients'][] = [
            'name' => $row['ingredient_name'],
            'quantity' => (float)$row['quantity_per_serving'],
            'unit' => $row['unit']
        ];
    }
}
$products = array_values($products);

// Compute stock status counts for monitoring
$totalIngredientsCount = count($ingredients);
$inStockCount = 0;
$lowStockCount = 0;
$outOfStockCount = 0;
$lowStockNames = [];

// Compute expiration status counts
$expiredCount = 0;
$expiringSoonCount = 0;
$expiredNames = [];
$expiringSoonNames = [];
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
        $expiredNames[] = $ing['name'] . ' (Expired: ' . date('M d, Y', strtotime($exp)) . ')';
    } elseif ($isExpSoon) {
        $expiringSoonCount++;
        $expiringSoonNames[] = $ing['name'] . ' (Exp: ' . date('M d, Y', strtotime($exp)) . ')';
    }

    // Stock level tracking
    if ($stk <= 0) {
        $outOfStockCount++;
        $lowStockNames[] = $ing['name'] . ' (Out of Stock)';
    } elseif ($stk <= $m) {
        $lowStockCount++;
        $lowStockNames[] = $ing['name'] . ' (' . formatStock($stk) . ' ' . $ing['unit'] . ' left)';
    } elseif (!$isExp && !$isExpSoon) {
        $inStockCount++;
    }
}

require_once ROOT_PATH . '/includes/header.php';
?>

<div class="tabs mb-4">
    <a href="?tab=stock" class="tab-btn <?= $activeTab === 'stock' ? 'active' : '' ?>" style="text-decoration: none;">Inventory Ingredient</a>
    <a href="?tab=portion" class="tab-btn <?= $activeTab === 'portion' ? 'active' : '' ?>" style="text-decoration: none;">Inventory Portion</a>
</div>

<?php if ($activeTab === 'stock'): ?>
    <!-- ─── TAB 1: INVENTORY INGREDIENT ─── -->

    <!-- Expired Ingredients Alert Warning Banner -->
    <?php if ($expiredCount > 0): ?>
        <div style="background: #FEF2F2; border: 1px solid #FCA5A5; border-left: 5px solid #DC2626; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 2px 6px rgba(220, 38, 38, 0.08);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.6rem; line-height: 1;">🚨</span>
                <div>
                    <strong style="color: #991B1B; font-size: 0.96rem;">Expired Ingredients Alert</strong>
                    <div style="color: #B91C1C; font-size: 0.85rem; margin-top: 3px;">
                        <strong><?= $expiredCount ?></strong> ingredient(s) have passed their expiration date:
                        <strong><?= implode(', ', array_slice($expiredNames, 0, 4)) ?><?= count($expiredNames) > 4 ? ', ...' : '' ?></strong>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/inventory-adjustment.php" class="btn btn-sm" style="background: #DC2626; color: #FFF; font-weight: 700; padding: 0.45rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.82rem;">Adjust Stock →</a>
        </div>
    <?php endif; ?>

    <!-- Expiring Soon Warning Banner (7-Day Buffer) -->
    <?php if ($expiringSoonCount > 0): ?>
        <div style="background: #FFFDF0; border: 1px solid #FCD34D; border-left: 5px solid #D97706; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 2px 6px rgba(217, 119, 6, 0.08);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.6rem; line-height: 1;">⏳</span>
                <div>
                    <strong style="color: #92400E; font-size: 0.96rem;">Expiring Soon Warning (Within 7 Days)</strong>
                    <div style="color: #B45309; font-size: 0.85rem; margin-top: 3px;">
                        <strong><?= $expiringSoonCount ?></strong> ingredient(s) are near expiration:
                        <strong><?= implode(', ', array_slice($expiringSoonNames, 0, 4)) ?><?= count($expiringSoonNames) > 4 ? ', ...' : '' ?></strong>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/inventory-adjustment.php" class="btn btn-sm" style="background: #D97706; color: #FFF; font-weight: 700; padding: 0.45rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.82rem;">Adjust Stock →</a>
        </div>
    <?php endif; ?>

    <!-- Low-Stock Alert Warning Banner -->
    <?php if ($lowStockCount > 0 || $outOfStockCount > 0): ?>
        <div style="background: #FFFBEB; border: 1px solid #FCD34D; border-left: 5px solid #F59E0B; padding: 1rem 1.25rem; border-radius: 10px; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; box-shadow: 0 2px 6px rgba(245, 158, 11, 0.08);">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span style="font-size: 1.6rem; line-height: 1;">⚠️</span>
                <div>
                    <strong style="color: #92400E; font-size: 0.96rem;">Low-Stock Threshold Alert</strong>
                    <div style="color: #B45309; font-size: 0.85rem; margin-top: 3px;">
                        <strong><?= ($lowStockCount + $outOfStockCount) ?></strong> ingredient(s) are at or below minimum threshold:
                        <strong><?= implode(', ', array_slice($lowStockNames, 0, 4)) ?><?= count($lowStockNames) > 4 ? ', ...' : '' ?></strong>
                    </div>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/inventory-adjustment.php" class="btn btn-sm" style="background: #92400E; color: #FFF; font-weight: 700; padding: 0.45rem 1rem; border-radius: 6px; text-decoration: none; font-size: 0.82rem;">Adjust Stock →</a>
        </div>
    <?php endif; ?>

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
                    <button type="button" class="status-filter-btn" data-filter="low-stock" style="padding: 0.4rem 0.85rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600; cursor: pointer; border: 1px solid #FDE68A; background:#FFF9E6; color:#D97706; transition: all 0.2s;">
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
                            <th class="text-center">Unit Cost</th>
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
                            $unitCost = (float)$i['unit_cost'];
                            $isOutOfStock = $stock <= 0;
                            $isLow = !$isOutOfStock && $stock <= $min;
                            $expDate = $i['expiration_date'];
                            $isExpired = $expDate && strtotime($expDate) <= strtotime(date('Y-m-d'));
                            $isExpiringSoon = $expDate && !$isExpired && strtotime($expDate) <= strtotime('+7 days');
                            $expCategory = $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : 'ok');
                            $statusCategory = $isExpired ? 'expired' : ($isExpiringSoon ? 'expiring-soon' : ($isOutOfStock ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock')));
                            $stockCategory = $isOutOfStock ? 'out-of-stock' : ($isLow ? 'low-stock' : 'in-stock');
                        ?>
                            <tr data-status="<?= $statusCategory ?>" data-stock="<?= $stockCategory ?>" data-exp="<?= $expCategory ?>" style="<?= $isExpired ? 'background: #FFF5F5;' : ($isExpiringSoon ? 'background: #FFFDF0;' : ($isLow ? 'background: #FFFDF5;' : ($isOutOfStock ? 'background: #FFF5F5;' : ''))) ?>">
                                <td class="searchable">
                                    <strong style="color: #3C2415; font-size: 0.95rem;"><?= e($i['name']) ?></strong>
                                </td>
                                <td class="text-center" style="font-weight: 700; color: <?= $isExpired ? '#DC2626' : ($isExpiringSoon ? '#D97706' : ($isOutOfStock ? '#DC2626' : ($isLow ? '#D97706' : '#3C2415'))) ?>;">
                                    <?= formatStock($stock) ?> <?= e($i['unit']) ?>
                                </td>
                                <td class="text-center" style="color: #6E5C4F; font-weight: 600;">
                                    <?= formatCurrency($unitCost) ?>
                                </td>
                                <td class="text-center" style="color: #8C6D58;">
                                    <?= formatStock($min) ?> <?= e($i['unit']) ?>
                                </td>
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
                                <td><?= formatDate($i['date_added']) ?></td>
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
                                <td colspan="7" class="text-center p-4 text-muted">No ingredients found in inventory.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ─── TAB 2: INVENTORY PORTION ─── -->
    <div class="card">
        <div class="card-body">
            <div class="mb-4">
                <input type="text" id="portion-search" class="form-control" placeholder="Search products or categories..." style="max-width: 380px; padding: 0.65rem 1rem; border-radius: 10px; border: 1px solid #E2D9CF; background: #FAF7F2;">
            </div>

            <div class="table-wrapper">
                <table class="table align-middle" id="portion-table">
                    <thead>
                        <tr>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">PRODUCT NAME</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">CATEGORY</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">PRICE</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">RECIPE INGREDIENTS</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px; text-align: center;">STATUS</th>
                            <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px; text-align: center;">ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p):
                            $ingCount = count($p['ingredients']);
                        ?>
                            <tr>
                                <td class="searchable"><strong style="color: #3C2415; font-size: 0.95rem;"><?= e($p['name']) ?></strong></td>
                                <td><span class="badge badge-secondary"><?= e($p['category']) ?></span></td>
                                <td style="font-weight: 700; color: #3C2415;">₱<?= number_format($p['price'], 2) ?></td>
                                <td>
                                    <span class="badge" style="background: #FAF3EC; color: #7A5338; border: 1px solid #EFE4D8; padding: 4px 8px; border-radius: 6px; font-weight: 600; font-size: 0.8rem;">
                                        <?= $ingCount ?> Ingredient(s)
                                    </span>
                                </td>
                                <td style="text-align: center;">
                                    <?php if (($p['status'] ?? 'available') === 'available'): ?>
                                        <span class="badge badge-success">✓ Available</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                                <td style="text-align: center;">
                                    <button type="button" class="btn btn-sm btn-outline view-portion-btn"
                                        data-product="<?= htmlspecialchars(json_encode($p)) ?>">
                                        View
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="6" class="text-center p-4 text-muted">No products found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- ─── RECIPE PORTION DETAILS POPUP MODAL ─── -->
<div class="modal-overlay" id="portionModal">
    <div class="modal" style="border-radius: 14px; max-width: 480px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #EAE3D9; padding: 1.25rem 1.5rem;">
            <h3 id="portionModalTitle" style="margin: 0; padding-right: 2rem; color: #3C2415; font-weight: 700;">Recipe Details</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 1rem; right: 1.25rem; background: none; border: none; font-size: 1.5rem; color: #A08D7D; cursor: pointer;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.5rem;">
            <!-- Product Header Summary -->
            <div style="background: #FAF7F2; border: 1px solid #EAE3D9; border-radius: 8px; padding: 0.9rem 1.1rem; margin-bottom: 1.25rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: #8C6D58; text-transform: uppercase;">Category:</span>
                    <strong id="portionCategory" style="color: #3C2415; font-size: 0.88rem;"></strong>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="font-size: 0.8rem; font-weight: 600; color: #8C6D58; text-transform: uppercase;">Selling Price:</span>
                    <strong id="portionPrice" style="color: #059669; font-size: 1.1rem; font-weight: 800;"></strong>
                </div>
            </div>

            <!-- Ingredients List -->
            <h4 style="font-size: 0.85rem; font-weight: 700; color: #6E5343; margin-bottom: 0.6rem; text-transform: uppercase; letter-spacing: 0.4px;">
                🧪 Portion Ingredients:
            </h4>
            <div id="portionIngredientsList" style="background: #FFF; border: 1px solid #EAE3D9; border-radius: 8px; padding: 0.85rem 1.1rem; margin-bottom: 1.25rem; min-height: 80px;">
                <!-- Filled dynamically by JS -->
            </div>

            <div style="display: flex; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary close-modal" style="padding: 0.5rem 1.4rem; font-weight: 600; border-radius: 8px;">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$extraJS = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Status Filter Pills Functionality
    const filterBtns = document.querySelectorAll('.status-filter-btn');
    let currentFilter = 'all';

    function applyFilters() {
        const searchTerm = (document.getElementById('stock-search')?.value || '').toLowerCase();
        const rows = document.querySelectorAll('#stock-table tbody tr');

        rows.forEach(row => {
            const status = row.getAttribute('data-status');
            const exp = row.getAttribute('data-exp');
            const searchEl = row.querySelector('.searchable');
            const matchesSearch = !searchTerm || (searchEl && searchEl.textContent.toLowerCase().includes(searchTerm));
            
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

            // Apply selected pill highlight
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

    // Check URL parameters for preset filter (e.g. ?tab=stock&filter=expired)
    const urlParams = new URLSearchParams(window.location.search);
    const filterParam = urlParams.get('filter');
    if (filterParam) {
        const targetBtn = document.querySelector(`.status-filter-btn[data-filter="\${filterParam}"]`);
        if (targetBtn) {
            targetBtn.click();
        }
    }

    const stockSearch = document.getElementById('stock-search');
    if (stockSearch) {
        stockSearch.addEventListener('keyup', applyFilters);
    }

    // Portion Search functionality
    function setupSearch(inputId, tableId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        input.addEventListener('keyup', function() {
            const term = this.value.toLowerCase();
            const rows = document.querySelectorAll('#' + tableId + ' tbody tr');
            rows.forEach(row => {
                const searchEl = row.querySelector('.searchable');
                if (!searchEl) return;
                const text = searchEl.textContent.toLowerCase();
                row.style.display = text.includes(term) ? '' : 'none';
            });
        });
    }
    setupSearch('portion-search', 'portion-table');

    // View Recipe Portion Modal
    const portionModal = document.getElementById('portionModal');
    const modalTitle = document.getElementById('portionModalTitle');
    const modalCat = document.getElementById('portionCategory');
    const modalPrice = document.getElementById('portionPrice');
    const modalList = document.getElementById('portionIngredientsList');

    document.querySelectorAll('.view-portion-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const prod = JSON.parse(this.getAttribute('data-product') || '{}');
            const ingredients = prod.ingredients || [];

            modalTitle.textContent = 'Recipe Portion: ' + (prod.name || 'Product');
            modalCat.textContent = prod.category || 'N/A';
            modalPrice.textContent = '₱' + parseFloat(prod.price || 0).toFixed(2);

            if (ingredients && ingredients.length > 0) {
                let html = '<ul style="margin: 0; padding-left: 1.2rem; font-size: 0.9rem; color: #5A3A28; line-height: 1.7;">';
                ingredients.forEach(r => {
                    html += `<li><strong>\${parseFloat(r.quantity).toFixed(1)} \${r.unit}</strong> of \${r.name}</li>`;
                });
                html += '</ul>';
                modalList.innerHTML = html;
            } else {
                modalList.innerHTML = '<p class="text-muted mb-0" style="font-size: 0.85rem;">No recipe ingredients assigned to this product.</p>';
            }

            portionModal.classList.add('active');
        });
    });

    // Close Modal handlers
    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            portionModal.classList.remove('active');
        });
    });

    window.addEventListener('click', function(e) {
        if (e.target === portionModal) {
            portionModal.classList.remove('active');
        }
    });
});
</script>
HTML;
?>
<?php require_once ROOT_PATH . '/includes/footer.php'; ?>