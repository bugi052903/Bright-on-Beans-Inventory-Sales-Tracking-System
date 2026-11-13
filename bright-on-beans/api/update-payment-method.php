<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdmin();

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input.']);
    exit;
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? '';
if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
    echo json_encode(['success' => false, 'message' => 'Invalid or expired CSRF security token.']);
    exit;
}

$transactionId = (int)($input['transaction_id'] ?? 0);
$newMethod = strtolower($input['payment_method'] ?? 'cash');
$adminId = getCurrentUserId();

if ($transactionId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID provided.']);
    exit;
}

if (!in_array($newMethod, ['cash', 'gcash', 'maya'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid payment method selected.']);
    exit;
}

try {
    // 1. Fetch transaction
    $stmtTx = $pdo->prepare("SELECT * FROM sales_transactions WHERE transaction_id = ? FOR UPDATE");
    $stmtTx->execute([$transactionId]);
    $tx = $stmtTx->fetch();

    if (!$tx) {
        throw new Exception("Transaction #{$transactionId} not found.");
    }

    if ($tx['status'] === 'voided') {
        throw new Exception("Cannot change payment method on a voided transaction.");
    }

    $totalAmount = (float)$tx['total_amount'];
    $oldMethod = $tx['payment_method'] ?: 'cash';

    $amountTendered = isset($input['amount_tendered']) ? (float)$input['amount_tendered'] : $totalAmount;
    $changeAmount = 0.00;
    $paymentRef = !empty($input['payment_reference']) ? trim($input['payment_reference']) : null;

    if ($newMethod === 'cash') {
        if ($amountTendered < $totalAmount) {
            $amountTendered = $totalAmount; // Default to exact
        }
        $changeAmount = $amountTendered - $totalAmount;
    } else {
        $amountTendered = $totalAmount;
        $changeAmount = 0.00;
    }

    $stmtUp = $pdo->prepare("
        UPDATE sales_transactions 
        SET payment_method = ?, amount_tendered = ?, change_amount = ?, payment_reference = ?
        WHERE transaction_id = ?
    ");
    $stmtUp->execute([$newMethod, $amountTendered, $changeAmount, $paymentRef, $transactionId]);

    echo json_encode([
        'success' => true,
        'message' => "Payment method for Sale #{$transactionId} switched from " . strtoupper($oldMethod) . " to " . strtoupper($newMethod) . " successfully.",
        'transaction_id' => $transactionId,
        'new_payment_method' => $newMethod
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
