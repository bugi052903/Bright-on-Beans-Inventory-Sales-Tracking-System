<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();
ensureIngredientStockBatchSchema($pdo);

$role = getCurrentUserRole();
$userId = getCurrentUserId();

$data = [];

try {
    $today = date('Y-m-d');

    if ($role === 'admin') {
        // Daily Sales
        $stmt = $pdo->prepare("SELECT SUM(total_amount) as s FROM sales_transactions WHERE DATE(transaction_date) = ? AND status = 'completed'");
        $stmt->execute([$today]);
        $daily = (float)($stmt->fetchColumn() ?: 0);
        $data['daily_sales'] = $daily;
        $data['daily_sales_formatted'] = formatCurrency($daily);

        // Weekly Sales
        $weekStart = date('Y-m-d', strtotime('-7 days'));
        $stmt = $pdo->prepare("SELECT SUM(total_amount) as s FROM sales_transactions WHERE DATE(transaction_date) >= ? AND status = 'completed'");
        $stmt->execute([$weekStart]);
        $weekly = (float)($stmt->fetchColumn() ?: 0);
        $data['weekly_sales'] = $weekly;
        $data['weekly_sales_formatted'] = formatCurrency($weekly);

        // Monthly Sales
        $monthStart = date('Y-m-01');
        $stmt = $pdo->prepare("SELECT SUM(total_amount) as s FROM sales_transactions WHERE DATE(transaction_date) >= ? AND status = 'completed'");
        $stmt->execute([$monthStart]);
        $monthly = (float)($stmt->fetchColumn() ?: 0);
        $data['monthly_sales'] = $monthly;
        $data['monthly_sales_formatted'] = formatCurrency($monthly);

        // Today's Items Sold
        $stmt = $pdo->prepare("
            SELECT SUM(si.quantity) 
            FROM sales_items si 
            JOIN sales_transactions st ON si.transaction_id = st.transaction_id 
            WHERE DATE(st.transaction_date) = ? AND st.status = 'completed'
        ");
        $stmt->execute([$today]);
        $data['today_items_sold'] = (int)($stmt->fetchColumn() ?: 0);

        // Counts
        $data['product_count'] = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
        $data['ingredient_count'] = (int)$pdo->query("SELECT COUNT(DISTINCT name) FROM ingredients")->fetchColumn();
        $data['low_stock_count'] = (int)getLowStockCount($pdo);
        $data['expired_count'] = (int)getExpiredCount($pdo);

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
        $data['recent_sales'] = array_map(function($sale) {
            return [
                'transaction_id' => (int)$sale['transaction_id'],
                'date_formatted' => formatDateTime($sale['transaction_date']),
                'products_summary' => $sale['products_summary'] ?: 'N/A',
                'total_quantity' => (int)($sale['total_quantity'] ?: 0),
                'staff_name' => trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? '')),
                'total_amount' => (float)$sale['total_amount'],
                'total_amount_formatted' => formatCurrency($sale['total_amount'])
            ];
        }, $recentSales);

    } else {
        // Staff metrics
        $stmt = $pdo->prepare("SELECT COUNT(*) as tx_count, SUM(total_amount) as total_sales FROM sales_transactions WHERE user_id = ? AND DATE(transaction_date) = ? AND status = 'completed'");
        $stmt->execute([$userId, $today]);
        $row = $stmt->fetch();
        $data['transactions'] = (int)($row['tx_count'] ?? 0);
        $data['total_sales'] = (float)($row['total_sales'] ?? 0);
        $data['total_sales_formatted'] = formatCurrency($data['total_sales']);

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
        $data['recent_sales'] = array_map(function($sale) {
            return [
                'transaction_id' => (int)$sale['transaction_id'],
                'date_formatted' => formatDateTime($sale['transaction_date']),
                'products_summary' => $sale['products_summary'] ?: 'N/A',
                'total_quantity' => (int)($sale['total_quantity'] ?: 0),
                'staff_name' => trim(($sale['first_name'] ?? '') . ' ' . ($sale['last_name'] ?? '')),
                'total_amount' => (float)$sale['total_amount'],
                'total_amount_formatted' => formatCurrency($sale['total_amount'])
            ];
        }, $recentSales);
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
