<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$txId = (int)($_GET['id'] ?? 0);
if ($txId <= 0) {
    echo json_encode(['success' => false, 'message' => 'No transaction ID provided']);
    exit;
}

try {
    // Fetch transaction info
    $stmtTx = $pdo->prepare("
        SELECT st.*, u.first_name as staff_first, u.last_name as staff_last,
               uv.first_name as void_first, uv.last_name as void_last
        FROM sales_transactions st
        JOIN users u ON st.user_id = u.user_id
        LEFT JOIN users uv ON st.voided_by = uv.user_id
        WHERE st.transaction_id = ?
    ");
    $stmtTx->execute([$txId]);
    $transaction = $stmtTx->fetch();

    if (!$transaction) {
        throw new Exception("Transaction #{$txId} not found.");
    }

    $stmt = $pdo->prepare(
        "SELECT si.*, p.name as product_name 
         FROM sales_items si 
         JOIN products p ON si.product_id = p.product_id 
         WHERE si.transaction_id = ?
         ORDER BY si.item_id"
    );
    $stmt->execute([$txId]);
    $items = $stmt->fetchAll();

    // Fetch linked product adjustments/spoilage for this transaction (Deduplicated notes)
    $stmtAdj = $pdo->prepare(
        "SELECT DISTINCT notes, created_at FROM inventory_history 
         WHERE (notes LIKE CONCAT('Product Adjustment [Linked to Sale #', ?, ']%') 
            OR notes LIKE CONCAT('Product Spoilage%Sale #', ?, '%'))
         GROUP BY notes
         ORDER BY created_at DESC"
    );
    $stmtAdj->execute([$txId, $txId]);
    $adjustments = $stmtAdj->fetchAll();

    echo json_encode([
        'success' => true,
        'transaction' => $transaction,
        'items' => $items,
        'adjustments' => $adjustments
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
