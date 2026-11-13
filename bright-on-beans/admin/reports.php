<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Reports';

$period = $_GET['period'] ?? 'daily';
$from = $_GET['from'] ?? date('Y-m-d');
$to = $_GET['to'] ?? date('Y-m-d');

$dateCondition = "";
$params = [];

if ($period === 'daily') {
    $dateCondition = "DATE(transaction_date) = CURDATE()";
} elseif ($period === 'weekly') {
    $dateCondition = "DATE(transaction_date) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
} elseif ($period === 'monthly') {
    $dateCondition = "MONTH(transaction_date) = MONTH(CURDATE()) AND YEAR(transaction_date) = YEAR(CURDATE())";
} elseif ($period === 'custom') {
    $dateCondition = "DATE(transaction_date) >= ? AND DATE(transaction_date) <= ?";
    $params = [$from, $to];
}

// Summary Queries
$sqlTotal = "SELECT SUM(total_amount) as total_sales, SUM(total_cost) as total_cogs, COUNT(*) as tx_count FROM sales_transactions WHERE status = 'completed' AND $dateCondition";
$stmt = $pdo->prepare($sqlTotal);
$stmt->execute($params);
$summary = $stmt->fetch();

$totalSales = (float)($summary['total_sales'] ?: 0);
$totalCogs = (float)($summary['total_cogs'] ?: 0);
$netProfit = $totalSales - $totalCogs;

$sqlItems = "SELECT SUM(si.quantity) as total_items FROM sales_items si JOIN sales_transactions st ON si.transaction_id = st.transaction_id WHERE st.status = 'completed' AND $dateCondition";
$stmt = $pdo->prepare($sqlItems);
$stmt->execute($params);
$itemsCount = $stmt->fetchColumn() ?: 0;

$avgTxValue = $summary['tx_count'] > 0 ? $totalSales / $summary['tx_count'] : 0;

// Top Selling Product
$sqlTopProd = "SELECT p.name, SUM(si.quantity) as qty FROM sales_items si JOIN sales_transactions st ON si.transaction_id = st.transaction_id JOIN products p ON si.product_id = p.product_id WHERE st.status = 'completed' AND $dateCondition GROUP BY p.product_id ORDER BY qty DESC LIMIT 1";
$stmt = $pdo->prepare($sqlTopProd);
$stmt->execute($params);
$topProd = $stmt->fetch();
$topProdName = $topProd ? $topProd['name'] . ' (' . $topProd['qty'] . ' sold)' : 'N/A';

// Payment Methods Breakdown
$sqlPayment = "
    SELECT 
        COALESCE(payment_method, 'cash') as method,
        COUNT(*) as tx_count,
        SUM(total_amount) as total_amount
    FROM sales_transactions
    WHERE status = 'completed' AND $dateCondition
    GROUP BY payment_method
";
$stmtPay = $pdo->prepare($sqlPayment);
$stmtPay->execute($params);
$paymentRows = $stmtPay->fetchAll();

$payBreakdown = [
    'cash' => ['count' => 0, 'total' => 0.0],
    'gcash' => ['count' => 0, 'total' => 0.0],
    'maya' => ['count' => 0, 'total' => 0.0]
];
foreach ($paymentRows as $pr) {
    $m = strtolower($pr['method'] ?: 'cash');
    if (isset($payBreakdown[$m])) {
        $payBreakdown[$m]['count'] = (int)$pr['tx_count'];
        $payBreakdown[$m]['total'] = (float)$pr['total_amount'];
    }
}

// Staff Performance
$sqlStaff = "SELECT u.first_name, u.last_name, COUNT(st.transaction_id) as tx_count, SUM(st.total_amount) as total_sales FROM sales_transactions st JOIN users u ON st.user_id = u.user_id WHERE st.status = 'completed' AND $dateCondition GROUP BY u.user_id ORDER BY total_sales DESC";
$stmt = $pdo->prepare($sqlStaff);
$stmt->execute($params);
$staffPerf = $stmt->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<style>
.financial-metrics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 1.75rem;
}
.operational-metrics-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
.payment-metrics-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1.25rem;
    margin-bottom: 2rem;
}
@media (max-width: 992px) {
    .financial-metrics-grid, .operational-metrics-grid, .payment-metrics-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 576px) {
    .financial-metrics-grid, .operational-metrics-grid, .payment-metrics-grid {
        grid-template-columns: 1fr;
    }
}
.metric-card {
    background: #FFF;
    border-radius: 12px;
    padding: 1.35rem 1.4rem;
    border: none;
    box-shadow: 0 4px 15px rgba(60,36,21,0.04);
}
.metric-label {
    font-size: 0.76rem;
    font-weight: 700;
    color: #8C6D58;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: block;
    margin-bottom: 6px;
}
.metric-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #3C2415;
}
.report-period-btn {
    padding: 8px 18px;
    font-size: 0.85rem;
    font-weight: 700;
    border-radius: 8px;
    border: 1px solid #D2C6B9;
    background: #FFF;
    color: #6E5343;
    text-decoration: none;
    transition: all 0.15s ease;
}
.report-period-btn:hover {
    background: #FAF7F2;
    color: #3C2415;
}
.report-period-btn.active {
    background: #3C2415;
    color: #FFF;
    border-color: #3C2415;
}
</style>

<!-- 1. TIME PERIOD SELECTOR -->
<div class="card mb-4" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04);">
    <div class="card-body" style="padding: 1.25rem 1.5rem; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem;">
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <a href="?period=daily" class="report-period-btn <?= $period === 'daily' ? 'active' : '' ?>">Daily (Today)</a>
            <a href="?period=weekly" class="report-period-btn <?= $period === 'weekly' ? 'active' : '' ?>">Weekly (7 Days)</a>
            <a href="?period=monthly" class="report-period-btn <?= $period === 'monthly' ? 'active' : '' ?>">Monthly (This Month)</a>
            <a href="?period=custom" class="report-period-btn <?= $period === 'custom' ? 'active' : '' ?>">Custom Range</a>
        </div>
    </div>
</div>

<?php if ($period === 'custom'): ?>
<div class="card mb-4" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04);">
    <div class="card-body" style="padding: 1.25rem 1.5rem;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 1.25rem; align-items: flex-end;">
            <input type="hidden" name="period" value="custom">
            <div class="form-group mb-0">
                <label class="form-label" style="font-weight: 700; color: #3C2415; font-size: 0.78rem; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px; display: block;">From Date</label>
                <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="font-size: 0.88rem; height: 42px; border-radius: 8px; border: 1px solid #E2D9CF; background: #FAF7F2; padding: 0.45rem 0.85rem; color: #3C2415; min-width: 155px;" required>
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-weight: 700; color: #3C2415; font-size: 0.78rem; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.4px; display: block;">To Date</label>
                <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="font-size: 0.88rem; height: 42px; border-radius: 8px; border: 1px solid #E2D9CF; background: #FAF7F2; padding: 0.45rem 0.85rem; color: #3C2415; min-width: 155px;" required>
            </div>
            <div class="form-group mb-0">
                <label class="form-label" style="font-weight: 700; font-size: 0.78rem; margin-bottom: 6px; display: block; visibility: hidden;">ACTION</label>
                <button type="submit" class="btn" style="background: #3C2415; color: #FFF; font-weight: 700; border: none; height: 42px; padding: 0 1.5rem; border-radius: 8px; font-size: 0.88rem; box-shadow: 0 2px 6px rgba(60,36,21,0.15); display: inline-flex; align-items: center; justify-content: center;">
                    Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- 2. FINANCIAL METRICS (PROFIT & COGS) -->
<h3 style="font-size: 1.05rem; font-weight: 700; color: #3C2415; margin-bottom: 0.75rem;">💰 Financial Overview</h3>
<div class="financial-metrics-grid">
    <div class="metric-card">
        <span class="metric-label">Total Revenue (Gross Sales)</span>
        <div class="metric-value" style="color: #2563EB; font-weight: 700;"><?= formatCurrency($totalSales) ?></div>
        <small style="color: #6B7280; font-size: 0.75rem;">Total money collected from customers</small>
    </div>
    <div class="metric-card">
        <span class="metric-label">Cost of Goods Sold (COGS)</span>
        <div class="metric-value" style="color: #DC2626; font-weight: 700;"><?= formatCurrency($totalCogs) ?></div>
        <small style="color: #6B7280; font-size: 0.75rem;">Raw recipe ingredients cost</small>
    </div>
    <div class="metric-card" style="background: #F0FDF4; border: 1px solid #BBF7D0;">
        <span class="metric-label" style="color: #166534; font-weight: 700;">Net Gross Profit</span>
        <div class="metric-value" style="color: #15803D; font-size: 1.6rem; font-weight: 800;"><?= formatCurrency($netProfit) ?></div>
        <small style="color: #166534; font-size: 0.75rem;">Formula: Revenue minus COGS</small>
    </div>
</div>

<!-- 3. PAYMENT METHOD BREAKDOWN (CASH VS GCASH VS MAYA) -->
<h3 style="font-size: 1.05rem; font-weight: 700; color: #3C2415; margin-bottom: 0.75rem;">💳 Payment Collections Breakdown</h3>
<div class="payment-metrics-grid">
    <div class="metric-card" style="border-left: 4px solid #1B4F72;">
        <span class="metric-label">💵 Physical Cash in Drawer</span>
        <div class="metric-value" style="color: #1B4F72;"><?= formatCurrency($payBreakdown['cash']['total']) ?></div>
        <small style="color: #6B7280; font-size: 0.75rem;"><?= number_format($payBreakdown['cash']['count']) ?> cash transactions</small>
    </div>
    <div class="metric-card" style="border-left: 4px solid #0056B3;">
        <span class="metric-label">📱 GCash E-Wallet Collections</span>
        <div class="metric-value" style="color: #0056B3;"><?= formatCurrency($payBreakdown['gcash']['total']) ?></div>
        <small style="color: #6B7280; font-size: 0.75rem;"><?= number_format($payBreakdown['gcash']['count']) ?> GCash transactions</small>
    </div>
    <div class="metric-card" style="border-left: 4px solid #0E6251;">
        <span class="metric-label">🟢 Maya E-Wallet Collections</span>
        <div class="metric-value" style="color: #0E6251;"><?= formatCurrency($payBreakdown['maya']['total']) ?></div>
        <small style="color: #6B7280; font-size: 0.75rem;"><?= number_format($payBreakdown['maya']['count']) ?> Maya transactions</small>
    </div>
</div>

<!-- 4. OPERATIONAL METRICS -->
<h3 style="font-size: 1.05rem; font-weight: 700; color: #3C2415; margin-bottom: 0.75rem;">📊 Operational Insights</h3>
<div class="operational-metrics-grid">
    <div class="metric-card">
        <span class="metric-label">Total Transactions</span>
        <div class="metric-value"><?= number_format($summary['tx_count'] ?: 0) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Total Items Sold</span>
        <div class="metric-value"><?= number_format($itemsCount) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Avg Transaction Value</span>
        <div class="metric-value"><?= formatCurrency($avgTxValue) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Top Selling Product</span>
        <div class="metric-value" style="font-size: 1.1rem; line-height: 1.3; color: #3C2415; font-weight: 700; margin-top: 4px;"><?= e($topProdName) ?></div>
    </div>
</div>

<!-- 5. STAFF PERFORMANCE TABLE (FULL WIDTH CARD) -->
<div class="card mb-4" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04); overflow: hidden;">
    <div class="card-header" style="background: #FDF9F5; border-bottom: 1px solid #EFE8DE; padding: 1.1rem 1.25rem;">
        <h3 style="margin: 0; font-size: 0.95rem; color: #3C2415; font-weight: 700;">👥 Staff Performance & Order Breakdown</h3>
    </div>
    <div class="card-body p-0">
        <div style="overflow-x: auto; width: 100%;">
            <table class="table mb-0" style="font-size: 0.85rem; width: 100%;">
                <thead>
                    <tr style="background: #FAF7F2; border-bottom: 2px solid #EAE3D9; color: #8C6D58; font-size: 0.75rem; text-transform: uppercase;">
                        <th style="padding: 12px 14px;">Staff Name</th>
                        <th style="text-align: center; padding: 12px 14px;">Completed Orders</th>
                        <th style="text-align: right; padding: 12px 14px;">Total Generated Sales</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staffPerf as $sp): ?>
                    <tr style="border-bottom: 1px solid #F3EDE7;">
                        <td style="padding: 12px 14px;"><strong style="color: #3C2415;"><?= e($sp['first_name'] . ' ' . $sp['last_name']) ?></strong></td>
                        <td style="text-align: center; padding: 12px 14px; font-weight: 700; color: #3C2415;"><?= number_format($sp['tx_count']) ?></td>
                        <td style="text-align: right; padding: 12px 14px; font-weight: 700; color: #059669;"><?= formatCurrency($sp['total_sales']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($staffPerf)): ?>
                    <tr><td colspan="3" class="text-center p-4 text-muted">No sales recorded for this period.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
