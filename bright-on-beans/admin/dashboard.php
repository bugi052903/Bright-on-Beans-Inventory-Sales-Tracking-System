<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Admin Dashboard';

// Metrics queries
// Daily Sales (today's total)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales_transactions WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'");
$stmt->execute();
$dailySales = $stmt->fetchColumn() ?: 0;

// Weekly Sales (rolling 7 days)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales_transactions WHERE DATE(transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status = 'completed'");
$stmt->execute();
$weeklySales = $stmt->fetchColumn() ?: 0;

// Monthly Sales (this month)
$stmt = $pdo->prepare("SELECT SUM(total_amount) as total FROM sales_transactions WHERE MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE()) AND status = 'completed'");
$stmt->execute();
$monthlySales = $stmt->fetchColumn() ?: 0;

// Today's Items Sold (total cups/items sold today)
$stmt = $pdo->prepare("
    SELECT SUM(si.quantity) 
    FROM sales_items si 
    JOIN sales_transactions st ON si.transaction_id = st.transaction_id 
    WHERE DATE(st.transaction_date) = CURDATE() AND st.status = 'completed'
");
$stmt->execute();
$todayItemsSold = (int)($stmt->fetchColumn() ?: 0);

// Total Product Count
$stmt = $pdo->query("SELECT COUNT(*) FROM products");
$productCount = $stmt->fetchColumn();

// Total Ingredients Count (Unique ingredient names)
$stmt = $pdo->query("SELECT COUNT(DISTINCT name) FROM ingredients");
$ingredientCount = $stmt->fetchColumn();

// Low Stock Alerts count
$lowStockCount = getLowStockCount($pdo);
$lowStockIngredients = getLowStockIngredients($pdo);

// Expired Ingredients alerts
$expiredCount = getExpiredCount($pdo);
$expiredIngredients = getExpiredIngredients($pdo);

// Expiring Soon Ingredients alerts (7-day heads-up)
$expiringSoonCount = getExpiringSoonCount($pdo);
$expiringSoonIngredients = getExpiringSoonIngredients($pdo);



// Recent Sales (Last 10 transactions)
$stmtRecent = $pdo->query("
    SELECT st.transaction_id, st.transaction_date, st.total_amount, u.first_name, u.last_name,
           (SELECT SUM(si.quantity) FROM sales_items si WHERE si.transaction_id = st.transaction_id) as total_quantity,
           (SELECT GROUP_CONCAT(CONCAT(p.name, ' (x', si.quantity, ')') SEPARATOR ', ') 
            FROM sales_items si JOIN products p ON si.product_id = p.product_id 
            WHERE si.transaction_id = st.transaction_id) as products_summary
    FROM sales_transactions st
    JOIN users u ON st.user_id = u.user_id
    ORDER BY st.transaction_date DESC
    LIMIT 10
");
$recentSales = $stmtRecent->fetchAll();
?>
<?php require_once ROOT_PATH . '/includes/header.php'; ?>

<style>
.dashboard-metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: var(--space-lg);
    margin-bottom: var(--space-xl);
}

.metric-card.metric-sales::before{
    background: 
}

.metric-card.metric-items-sold::before {
    background: #363635;
}
.metric-card.metric-expired::before {
    background: #9E2A2B;
}
.metric-card.metric-expiring-soon::before {
    background: #D97706;
}
.status-badge.status-warning-custom {
    background: #FFF9E6 !important;
    color: #D97706 !important;
    border: 1px solid #FCD34D !important;
}
@media (max-width: 1200px) {
    .dashboard-metrics-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .dashboard-metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 480px) {
    .dashboard-metrics-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem;">
    <h2 style="margin: 0; color: #3C2415; font-size: 1.35rem; font-weight: 700;">Dashboard Overview</h2>
    <a href="<?= BASE_URL ?>/admin/backup-restore.php" style="display: inline-flex; align-items: center; gap: 7px; font-size: 0.8rem; font-weight: 600; color: #3C2415; background: #FDF9F5; padding: 5px 14px; border-radius: 20px; border: 1px solid #D4C4B5; box-shadow: 0 1px 3px rgba(0,0,0,0.05); text-decoration: none;" title="System Maintenance & Database Backup">
        <span>💾</span> System Maintenance
    </a>
</div>

<div class="dashboard-metrics-grid">
    <!-- Row 1: Sales & Volume -->
    <div class="metric-card metric-sales">
        <h3>Daily Sales</h3>
        <p class="metric-value" id="metric-daily-sales"><?= formatCurrency($dailySales) ?></p>
    </div>
    <div class="metric-card metric-weekly">
        <h3>Weekly Sales</h3>
        <p class="metric-value" id="metric-weekly-sales"><?= formatCurrency($weeklySales) ?></p>
    </div>
    <div class="metric-card metric-monthly">
        <h3>Monthly Sales</h3>
        <p class="metric-value" id="metric-monthly-sales"><?= formatCurrency($monthlySales) ?></p>
    </div>
    <div class="metric-card metric-items-sold">
        <h3>Total Sold</h3>
        <p class="metric-value" id="metric-total-sold" style="color: #a80084;"><?= number_format($todayItemsSold) ?> <span style="font-size: 0.85rem; font-weight: normal; color: #6E5343;">sold</span></p>
    </div>

    <!-- Row 2: Inventory & Alerts -->
    <div class="metric-card metric-products">
        <h3>Total Product Count</h3>
        <p class="metric-value" id="metric-products"><?= e($productCount) ?></p>
    </div>
    <div class="metric-card metric-ingredients">
        <h3>Total Ingredients Count</h3>
        <p class="metric-value" id="metric-ingredients"><?= e($ingredientCount) ?></p>
    </div>
    <div class="metric-card metric-alerts">
        <h3>Low Stock Alert</h3>
        <p class="metric-value" id="metric-low-stock"><?= e($lowStockCount) ?></p>
    </div>
    <div class="metric-card metric-expired">
        <h3>Expired Ingredients</h3>
        <p class="metric-value" id="metric-expired" style="color: #9E2A2B;"><?= e($expiredCount) ?></p>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Low Stock Alerts -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.05rem;">⚠️ Low Stock Alerts</h3>
            <a href="<?= BASE_URL ?>/admin/inventory.php?tab=stock&filter=low-stock" style="font-size: 0.82rem; color: #7A6252;">View Inventory →</a>
        </div>
        <div class="card-body">
            <?php if (empty($lowStockIngredients)): ?>
                <div class="empty-state">
                    <p>All ingredient stocks are at healthy levels.</p>
                </div>
            <?php else: ?>
                <ul class="alert-list" style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($lowStockIngredients as $item): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                            <span>⚠️ <strong><?= e($item['name']) ?></strong></span>
                            <div style="display: flex; gap: 8px; align-items: center;">
                                <span style="color: #B45309; font-weight: 600; font-size: 0.88rem;"><?= formatStock($item['current_stock']) ?> <?= e($item['unit']) ?> left</span>
                                <a href="<?= BASE_URL ?>/admin/inventory-adjustment.php?ingredient_id=<?= e($item['ingredient_id']) ?>" class="btn btn-sm" style="padding: 0.2rem 0.55rem; font-size: 0.74rem; background: #C49A82; color: #FFF; border-radius: 5px;">Adjust</a>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- Expiration Alerts -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.05rem;">⏳ Expiration Alerts</h3>
            <a href="<?= BASE_URL ?>/admin/inventory.php?tab=stock&filter=expired" style="font-size: 0.82rem; color: #7A6252;">View Dates →</a>
        </div>
        <div class="card-body">
            <?php if (empty($expiredIngredients) && empty($expiringSoonIngredients)): ?>
                <div class="empty-state">
                    <p>No ingredients expiring soon.</p>
                </div>
            <?php else: ?>
                <ul class="alert-list" style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($expiredIngredients as $exp): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #DC2626;">🚨 <strong><?= e($exp['name']) ?></strong> (Expired)</span>
                            <span style="background: #FCE8E6; color: #DC2626; padding: 0.2rem 0.55rem; border-radius: 5px; font-weight: 600; font-size: 0.76rem; border: 1px solid #FCA5A5;"><?= formatDate($exp['expiration_date']) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($expiringSoonIngredients as $soon): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                            <span style="color: #D97706;">⚠️ <strong><?= e($soon['name']) ?></strong></span>
                            <span style="background: #FFF9E6; color: #D97706; padding: 0.2rem 0.55rem; border-radius: 5px; font-weight: 600; font-size: 0.76rem; border: 1px solid #FCD34D;">Exp: <?= formatDate($soon['expiration_date']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Sales Card (bottom of Low Stock Alerts) -->
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h3>Recent Sales</h3>
        <a href="<?= BASE_URL ?>/admin/sales-history.php" class="btn btn-sm btn-outline" style="font-size: 0.85rem;">View All Sales History →</a>
    </div>
    <div class="card-body p-0" id="recent-sales-card-body">
        <?php if (empty($recentSales)): ?>
            <div class="empty-state p-4 text-center" id="recent-sales-empty">No recent sales recorded yet.</div>
            <div class="table-wrapper" id="recent-sales-table-wrapper" style="display: none;">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Products</th>
                            <th>Quantity</th>
                            <th>Staff</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody id="recent-sales-tbody"></tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrapper" id="recent-sales-table-wrapper">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Products</th>
                            <th>Quantity</th>
                            <th>Staff</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody id="recent-sales-tbody">
                        <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td><small><?= formatDateTime($sale['transaction_date']) ?></small></td>
                            <td><strong><?= e($sale['products_summary'] ?: 'N/A') ?></strong></td>
                            <td><span class="status-badge"><?= e($sale['total_quantity'] ?: 0) ?> item(s)</span></td>
                            <td><?= e($sale['first_name'] . ' ' . $sale['last_name']) ?></td>
                            <td><strong style="color: #3C2415;"><?= formatCurrency($sale['total_amount']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    let lastKnownSalesTotal = <?= (float)$dailySales ?>;
    let lastKnownSold = <?= (int)$todayItemsSold ?>;
    let isPolling = false;

    function pollDashboardLive() {
        if (isPolling) return;
        isPolling = true;

        fetch('<?= BASE_URL ?>/api/get-dashboard-data.php')
            .then(res => res.json())
            .then(response => {
                isPolling = false;
                if (!response.success || !response.data) return;
                const d = response.data;

                // Update Metric Card Values
                const elDaily = document.getElementById('metric-daily-sales');
                const elWeekly = document.getElementById('metric-weekly-sales');
                const elMonthly = document.getElementById('metric-monthly-sales');
                const elSold = document.getElementById('metric-total-sold');
                const elLow = document.getElementById('metric-low-stock');
                const elExp = document.getElementById('metric-expired');
                const elProd = document.getElementById('metric-products');
                const elIng = document.getElementById('metric-ingredients');

                if (elDaily && elDaily.textContent !== d.daily_sales_formatted) {
                    elDaily.textContent = d.daily_sales_formatted;
                }
                if (elWeekly && elWeekly.textContent !== d.weekly_sales_formatted) {
                    elWeekly.textContent = d.weekly_sales_formatted;
                }
                if (elMonthly && elMonthly.textContent !== d.monthly_sales_formatted) {
                    elMonthly.textContent = d.monthly_sales_formatted;
                }
                if (elSold) {
                    elSold.innerHTML = `${Number(d.today_items_sold).toLocaleString()} <span style="font-size: 0.85rem; font-weight: normal; color: #6E5343;">sold</span>`;
                }
                if (elLow && elLow.textContent != d.low_stock_count) {
                    elLow.textContent = d.low_stock_count;
                }
                if (elExp && elExp.textContent != d.expired_count) {
                    elExp.textContent = d.expired_count;
                }
                if (elProd && elProd.textContent != d.product_count) {
                    elProd.textContent = d.product_count;
                }
                if (elIng && elIng.textContent != d.ingredient_count) {
                    elIng.textContent = d.ingredient_count;
                }

                // If a new sale occurred: subtle highlight effect
                if (d.daily_sales !== lastKnownSalesTotal || d.today_items_sold !== lastKnownSold) {
                    if (elDaily) {
                        elDaily.style.transition = 'transform 0.3s ease, color 0.3s ease';
                        elDaily.style.transform = 'scale(1.08)';
                        elDaily.style.color = '#059669';
                        setTimeout(() => {
                            elDaily.style.transform = 'scale(1)';
                            elDaily.style.color = '';
                        }, 1200);
                    }
                    if (elSold) {
                        elSold.style.transition = 'transform 0.3s ease';
                        elSold.style.transform = 'scale(1.08)';
                        setTimeout(() => {
                            elSold.style.transform = 'scale(1)';
                        }, 1200);
                    }
                    lastKnownSalesTotal = d.daily_sales;
                    lastKnownSold = d.today_items_sold;
                }

                // Update Recent Sales Table
                if (d.recent_sales && d.recent_sales.length > 0) {
                    const emptyState = document.getElementById('recent-sales-empty');
                    const wrapper = document.getElementById('recent-sales-table-wrapper');
                    const tbody = document.getElementById('recent-sales-tbody');

                    if (emptyState) emptyState.style.display = 'none';
                    if (wrapper) wrapper.style.display = 'block';

                    if (tbody) {
                        const newHtml = d.recent_sales.map(s => `
                            <tr>
                                <td><small>${s.date_formatted}</small></td>
                                <td><strong>${s.products_summary}</strong></td>
                                <td><span class="status-badge">${s.total_quantity} item(s)</span></td>
                                <td>${s.staff_name}</td>
                                <td><strong style="color: #3C2415;">${s.total_amount_formatted}</strong></td>
                            </tr>
                        `).join('');

                        if (tbody.innerHTML !== newHtml) {
                            tbody.innerHTML = newHtml;
                        }
                    }
                }
            })
            .catch(err => {
                isPolling = false;
            });
    }

    // Poll smoothly every 3 seconds
    setInterval(pollDashboardLive, 3000);
})();
</script>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
