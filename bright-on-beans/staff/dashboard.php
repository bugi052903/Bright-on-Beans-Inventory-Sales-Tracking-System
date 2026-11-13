<?php
require_once __DIR__ . '/../includes/auth.php';
requireStaff();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Staff Dashboard';

$userId = getCurrentUserId();
$today = date('Y-m-d');

// Get staff's today sales and transactions
$stmt = $pdo->prepare("SELECT COUNT(*) as tx_count, SUM(total_amount) as total_sales FROM sales_transactions WHERE user_id = ? AND DATE(transaction_date) = ? AND status = 'completed'");
$stmt->execute([$userId, $today]);
$salesData = $stmt->fetch();
$txCount = $salesData['tx_count'] ?? 0;
$totalSales = $salesData['total_sales'] ?? 0;

// Get low stock alerts
$lowStockItems = getLowStockIngredients($pdo);

// Get Expired and Expiring Soon ingredients for staff visibility
$expiredIngredients = getExpiredIngredients($pdo);
$expiringSoonIngredients = getExpiringSoonIngredients($pdo);

// Get Staff's Recent Sales (Last 10 transactions)
$stmtRecent = $pdo->prepare("
    SELECT st.transaction_id, st.transaction_date, st.total_amount, u.first_name, u.last_name,
           (SELECT SUM(si.quantity) FROM sales_items si WHERE si.transaction_id = st.transaction_id) as total_quantity,
           (SELECT GROUP_CONCAT(CONCAT(p.name, ' (x', si.quantity, ')') SEPARATOR ', ') 
            FROM sales_items si JOIN products p ON si.product_id = p.product_id 
            WHERE si.transaction_id = st.transaction_id) as products_summary
    FROM sales_transactions st
    JOIN users u ON st.user_id = u.user_id
    WHERE st.user_id = ?
    ORDER BY st.transaction_date DESC
    LIMIT 10
");
$stmtRecent->execute([$userId]);
$recentSales = $stmtRecent->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="section-header">
    <div>
        <h2>Welcome back, <?= e(getCurrentUserName()) ?>!</h2>
        <p>Here is an overview of your activity and store inventory today.</p>
    </div>
</div>

<div class="metrics-grid">
    <div class="metric-card metric-transactions">
        <div class="metric-info">
            <h3>Today's Transactions</h3>
            <div class="metric-value" id="staff-metric-tx"><?= number_format($txCount) ?></div>
        </div>
    </div>
    
    <div class="metric-card metric-sales">
        <div class="metric-info">
            <h3>Today's Total Sales</h3>
            <div class="metric-value" id="staff-metric-sales"><?= formatCurrency($totalSales) ?></div>
        </div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-top: 1.5rem;">
    <!-- Low Stock Alerts -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; font-size: 1.05rem;">⚠️ Low Stock Alerts</h3>
            <a href="<?= BASE_URL ?>/staff/inventory.php" style="font-size: 0.82rem; color: #7A6252;">View Inventory →</a>
        </div>
        <div class="card-body">
            <?php if (empty($lowStockItems)): ?>
                <div class="empty-state">
                    <p>All ingredient stocks are at healthy levels.</p>
                </div>
            <?php else: ?>
                <ul class="alert-list" style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($lowStockItems as $item): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                            <span>⚠️ <strong><?= e($item['name']) ?></strong></span>
                            <span style="color: #B45309; font-weight: 600;"><?= formatStock($item['current_stock']) ?> <?= e($item['unit']) ?> left</span>
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
            <a href="<?= BASE_URL ?>/staff/inventory.php" style="font-size: 0.82rem; color: #7A6252;">View Dates →</a>
        </div>
        <div class="card-body">
            <?php if (empty($expiredIngredients) && empty($expiringSoonIngredients)): ?>
                <div class="empty-state">
                    <p>No ingredients expiring soon.</p>
                </div>
            <?php else: ?>
                <ul class="alert-list" style="list-style: none; padding: 0; margin: 0;">
                    <?php foreach ($expiredIngredients as $exp): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                            <span style="color: #DC2626;">🚨 <strong><?= e($exp['name']) ?></strong> (Expired)</span>
                            <span class="badge-expired"><?= formatDate($exp['expiration_date']) ?></span>
                        </li>
                    <?php endforeach; ?>
                    <?php foreach ($expiringSoonIngredients as $soon): ?>
                        <li style="padding: 0.65rem 0; border-bottom: 1px solid #eee; display: flex; justify-content: space-between;">
                            <span style="color: #D97706;">⚠️ <strong><?= e($soon['name']) ?></strong></span>
                            <span class="badge-warning-custom">Exp: <?= formatDate($soon['expiration_date']) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Sales Card (bottom of Low Stock Alerts) -->
<div class="card mt-4">
    <div class="card-header">
        <h3>Recent Sales</h3>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentSales)): ?>
            <div class="empty-state p-4 text-center" id="staff-recent-empty">No recent sales recorded yet today.</div>
            <div class="table-wrapper" id="staff-recent-wrapper" style="display: none;">
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
                    <tbody id="staff-recent-tbody"></tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrapper" id="staff-recent-wrapper">
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
                    <tbody id="staff-recent-tbody">
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
    let lastKnownTx = <?= (int)$txCount ?>;
    let isPolling = false;

    function pollStaffLive() {
        if (isPolling) return;
        isPolling = true;

        fetch('<?= BASE_URL ?>/api/get-dashboard-data.php')
            .then(res => res.json())
            .then(response => {
                isPolling = false;
                if (!response.success || !response.data) return;
                const d = response.data;

                const elTx = document.getElementById('staff-metric-tx');
                const elSales = document.getElementById('staff-metric-sales');

                if (elTx && elTx.textContent != d.transactions) {
                    elTx.textContent = Number(d.transactions).toLocaleString();
                }
                if (elSales && elSales.textContent !== d.total_sales_formatted) {
                    elSales.textContent = d.total_sales_formatted;
                }

                if (d.transactions !== lastKnownTx) {
                    [elTx, elSales].forEach(el => {
                        if (el) {
                            el.style.transition = 'transform 0.3s ease, color 0.3s ease';
                            el.style.transform = 'scale(1.08)';
                            el.style.color = '#059669';
                            setTimeout(() => {
                                el.style.transform = 'scale(1)';
                                el.style.color = '';
                            }, 1200);
                        }
                    });
                    lastKnownTx = d.transactions;
                }

                // Update Table
                if (d.recent_sales && d.recent_sales.length > 0) {
                    const emptyState = document.getElementById('staff-recent-empty');
                    const wrapper = document.getElementById('staff-recent-wrapper');
                    const tbody = document.getElementById('staff-recent-tbody');

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

    setInterval(pollStaffLive, 3000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
