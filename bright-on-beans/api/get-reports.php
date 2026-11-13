<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();



$period = $_GET['period'] ?? 'daily';
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');

try {
    $whereDate = "DATE(transaction_date) BETWEEN ? AND ? AND status = 'completed'";
    $params = [$from, $to];

    // Total Sales
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM sales_transactions WHERE $whereDate");
    $stmt->execute($params);
    $totalSales = $stmt->fetchColumn() ?: 0;

    // Transaction Count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_transactions WHERE $whereDate");
    $stmt->execute($params);
    $txCount = $stmt->fetchColumn() ?: 0;

    // Items Sold
    $stmt = $pdo->prepare("SELECT SUM(si.quantity) FROM sales_items si JOIN sales_transactions st ON si.transaction_id = st.transaction_id WHERE DATE(st.transaction_date) BETWEEN ? AND ? AND st.status = 'completed'");
    $stmt->execute($params);
    $itemsSold = $stmt->fetchColumn() ?: 0;

    $avgTx = $txCount > 0 ? $totalSales / $txCount : 0;

    // Top Product
    $stmt = $pdo->prepare("SELECT p.name, SUM(si.quantity) as qty FROM sales_items si JOIN sales_transactions st ON si.transaction_id = st.transaction_id JOIN products p ON si.product_id = p.product_id WHERE DATE(st.transaction_date) BETWEEN ? AND ? AND st.status = 'completed' GROUP BY si.product_id ORDER BY qty DESC LIMIT 1");
    $stmt->execute($params);
    $topProduct = $stmt->fetch() ?: ['name' => 'N/A'];

    // Staff Performance
    $stmt = $pdo->prepare("SELECT u.first_name, u.last_name, SUM(st.total_amount) as sales, COUNT(st.transaction_id) as txs FROM sales_transactions st JOIN users u ON st.user_id = u.user_id WHERE DATE(st.transaction_date) BETWEEN ? AND ? AND st.status = 'completed' GROUP BY st.user_id ORDER BY sales DESC");
    $stmt->execute($params);
    $staffPerformance = $stmt->fetchAll();

    // Chart Data (Sales per day)
    $stmt = $pdo->prepare("SELECT DATE(transaction_date) as dt, SUM(total_amount) as sales FROM sales_transactions WHERE $whereDate GROUP BY DATE(transaction_date) ORDER BY dt ASC");
    $stmt->execute($params);
    $chartRows = $stmt->fetchAll();
    
    $labels = [];
    $values = [];
    foreach ($chartRows as $r) {
        $labels[] = $r['dt'];
        $values[] = $r['sales'];
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'total_sales' => $totalSales,
            'transaction_count' => $txCount,
            'items_sold' => $itemsSold,
            'avg_transaction' => $avgTx,
            'top_product' => $topProduct['name'],
            'staff_performance' => $staffPerformance,
            'chart_data' => [
                'labels' => $labels,
                'values' => $values
            ]
        ]
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
