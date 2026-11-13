<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$pageTitle = 'Sales History';

$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to = $_GET['to'] ?? date('Y-m-d');
$staff_id = $_GET['staff_id'] ?? '';
$payment_filter = $_GET['payment_method'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$params = [$from, $to];
$where = "DATE(st.transaction_date) >= ? AND DATE(st.transaction_date) <= ?";

if (!empty($staff_id)) {
    $where .= " AND st.user_id = ?";
    $params[] = $staff_id;
}

if ($payment_filter !== 'all') {
    $where .= " AND st.payment_method = ?";
    $params[] = $payment_filter;
}

if ($status_filter === 'completed') {
    $where .= " AND st.status = 'completed'";
} elseif ($status_filter === 'voided') {
    $where .= " AND st.status = 'voided'";
}

// Compute Summary Metrics (COMPLETED SALES ONLY)
$stmtToday = $pdo->query("SELECT SUM(total_amount) FROM sales_transactions WHERE DATE(transaction_date) = CURDATE() AND status = 'completed'");
$todaySales = $stmtToday->fetchColumn() ?: 0;

$stmtSummary = $pdo->prepare("SELECT COUNT(*) as total_tx, SUM(total_amount) as total_amount FROM sales_transactions st WHERE $where AND st.status = 'completed'");
$stmtSummary->execute($params);
$summaryData = $stmtSummary->fetch();
$totalTx = $summaryData['total_tx'] ?: 0;
$totalSales = $summaryData['total_amount'] ?: 0;
$avgTx = $totalTx > 0 ? ($totalSales / $totalTx) : 0;

$stmtItems = $pdo->prepare("SELECT SUM(si.quantity) FROM sales_items si JOIN sales_transactions st ON si.transaction_id = st.transaction_id WHERE $where AND st.status = 'completed'");
$stmtItems->execute($params);
$totalItemsSold = $stmtItems->fetchColumn() ?: 0;

// Total count for pagination including all matching status
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sales_transactions st WHERE $where");
$stmtCount->execute($params);
$totalRecords = $stmtCount->fetchColumn() ?: 0;
$totalPages = ceil($totalRecords / $limit);

// Fetch Transactions
$sql = "SELECT st.*, u.first_name, u.last_name, 
        uv.first_name as void_first, uv.last_name as void_last,
        (SELECT COUNT(*) FROM sales_items si WHERE si.transaction_id = st.transaction_id AND si.quantity > 0) as items_count,
        (SELECT GROUP_CONCAT(CONCAT(p.name, ' (x', si.quantity, CASE WHEN si.voided_quantity > 0 THEN CONCAT(', ', si.voided_quantity, ' voided') ELSE '' END, ')') SEPARATOR ', ') 
         FROM sales_items si JOIN products p ON si.product_id = p.product_id 
         WHERE si.transaction_id = st.transaction_id) as products_summary,
        (SELECT COUNT(*) FROM inventory_history ih WHERE ih.notes LIKE CONCAT('Product Adjustment [Linked to Sale #', st.transaction_id, ']%') OR ih.notes LIKE CONCAT('Product Spoilage%Sale #', st.transaction_id, '%')) as has_spoilage
        FROM sales_transactions st 
        JOIN users u ON st.user_id = u.user_id 
        LEFT JOIN users uv ON st.voided_by = uv.user_id
        WHERE $where 
        ORDER BY st.transaction_date DESC 
        LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$stmtStaff = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE status = 'active' OR status = 'available'");
$staffList = $stmtStaff->fetchAll();

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Custom CSS for Generous Vertical Downward Spacing -->
<style>
.metrics-grid-spaced {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1.25rem;
    margin-bottom: 2.25rem;
}
@media (max-width: 992px) {
    .metrics-grid-spaced {
        grid-template-columns: repeat(2, 1fr);
    }
}
@media (max-width: 576px) {
    .metrics-grid-spaced {
        grid-template-columns: 1fr;
    }
}
.metrics-grid-spaced .metric-card {
    background: #FFF;
    border-radius: 12px;
    padding: 1.35rem 1.4rem;
    border: none;
    box-shadow: 0 4px 15px rgba(60,36,21,0.04);
}
.metrics-grid-spaced .metric-label {
    font-size: 0.76rem;
    font-weight: 700;
    color: #8C6D58;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    display: block;
    margin-bottom: 6px;
}
.metrics-grid-spaced .metric-value {
    font-size: 1.5rem;
    font-weight: 800;
    color: #3C2415;
}
.void-item-row {
    background: #FFF;
    border: 1px solid #E5D5C5;
    border-radius: 8px;
    padding: 10px 12px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.15s;
}
.void-item-row:hover {
    background: #FAF7F2;
}
</style>

<!-- 1. FILTER CONTROLS -->
<div class="card" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04); margin-bottom: 2.25rem;">
    <div class="card-body" style="padding: 1.25rem 1.5rem;">
        <form method="GET" action="" style="display: flex; flex-wrap: wrap; gap: 1.25rem; align-items: flex-end;">
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #6E5343; margin-bottom: 6px;">From Date</label>
                <input type="date" name="from" value="<?= e($from) ?>" class="form-control" style="border: 1px solid #D2C6B9; border-radius: 8px; font-size: 0.88rem; height: 42px;">
            </div>
            
            <div style="flex: 1; min-width: 140px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #6E5343; margin-bottom: 6px;">To Date</label>
                <input type="date" name="to" value="<?= e($to) ?>" class="form-control" style="border: 1px solid #D2C6B9; border-radius: 8px; font-size: 0.88rem; height: 42px;">
            </div>
            
            <div style="flex: 1.2; min-width: 150px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #6E5343; margin-bottom: 6px;">Staff Member</label>
                <select name="staff_id" class="form-control" style="border: 1px solid #D2C6B9; border-radius: 8px; font-size: 0.88rem; height: 42px;">
                    <option value="">All Staff Members</option>
                    <?php foreach ($staffList as $st): ?>
                        <option value="<?= $st['user_id'] ?>" <?= $staff_id == $st['user_id'] ? 'selected' : '' ?>>
                            <?= e($st['first_name'] . ' ' . $st['last_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div style="flex: 1; min-width: 120px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #6E5343; margin-bottom: 6px;">Payment</label>
                <select name="payment_method" class="form-control" style="border: 1px solid #D2C6B9; border-radius: 8px; font-size: 0.88rem; height: 42px;">
                    <option value="all" <?= $payment_filter === 'all' ? 'selected' : '' ?>>All Payments</option>
                    <option value="cash" <?= $payment_filter === 'cash' ? 'selected' : '' ?>>💵 Cash Only</option>
                    <option value="gcash" <?= $payment_filter === 'gcash' ? 'selected' : '' ?>>📱 GCash Only</option>
                    <option value="maya" <?= $payment_filter === 'maya' ? 'selected' : '' ?>>🟢 Maya Only</option>
                </select>
            </div>

            <div style="flex: 1; min-width: 120px;">
                <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #6E5343; margin-bottom: 6px;">Status</label>
                <select name="status" class="form-control" style="border: 1px solid #D2C6B9; border-radius: 8px; font-size: 0.88rem; height: 42px;">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Sales</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed Only</option>
                    <option value="voided" <?= $status_filter === 'voided' ? 'selected' : '' ?>>Voided Only</option>
                </select>
            </div>
            
            <div style="display: flex; gap: 8px; align-items: flex-end;">
                <button type="submit" class="btn btn-primary" style="background: #3C2415; border: none; height: 42px; padding: 0 1.5rem; border-radius: 8px; font-weight: 700; font-size: 0.88rem;">
                    Apply Filter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 2. METRIC SUMMARY CARDS -->
<div class="metrics-grid-spaced">
    <div class="metric-card">
        <span class="metric-label">Completed Sales</span>
        <div class="metric-value"><?= formatCurrency($totalSales) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Completed Orders</span>
        <div class="metric-value"><?= number_format($totalTx) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Total Items Sold</span>
        <div class="metric-value"><?= number_format($totalItemsSold) ?></div>
    </div>
    <div class="metric-card">
        <span class="metric-label">Average Order</span>
        <div class="metric-value"><?= formatCurrency($avgTx) ?></div>
    </div>
</div>

<!-- 3. TRANSACTIONS TABLE -->
<div class="card" style="border-radius: 12px; border: none; box-shadow: 0 4px 15px rgba(60,36,21,0.04); overflow: hidden;">
    <div class="card-body p-0">
        <div class="table-wrapper">
            <table class="table table-hover align-middle mb-0" style="font-size: 0.86rem;">
                <thead>
                    <tr style="background: #FAF7F2; border-bottom: 2px solid #EAE3D9; color: #8C6D58; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.3px;">
                        <th style="padding: 12px 14px;">Transaction ID</th>
                        <th style="padding: 12px 14px;">Date / Time</th>
                        <th style="padding: 12px 14px;">Products</th>
                        <th style="padding: 12px 14px;">Staff Name</th>
                        <th style="padding: 12px 14px;">Total & Payment</th>
                        <th style="padding: 12px 14px; text-align: center;">Status</th>
                        <th style="padding: 12px 14px; text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $tx): 
                        $isVoided = ($tx['status'] === 'voided');
                        $payMethod = strtolower($tx['payment_method'] ?? 'cash');
                        if ($payMethod === 'gcash') {
                            $payBadge = '<span style="background: #E8F4FD; color: #0056B3; border: 1px solid #BEE5EB; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; display: inline-block; margin-top: 3px;">📱 GCash' . (!empty($tx['payment_reference']) ? ' #' . e($tx['payment_reference']) : '') . '</span>';
                        } elseif ($payMethod === 'maya') {
                            $payBadge = '<span style="background: #E8F8F5; color: #0E6251; border: 1px solid #A2D9CE; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; display: inline-block; margin-top: 3px;">🟢 Maya' . (!empty($tx['payment_reference']) ? ' #' . e($tx['payment_reference']) : '') . '</span>';
                        } else {
                            $payBadge = '<span style="background: #EBF5FB; color: #1B4F72; border: 1px solid #AED6F1; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; display: inline-block; margin-top: 3px;">💵 Cash</span>';
                        }
                    ?>
                    <tr style="border-bottom: 1px solid #F3EDE7; <?= $isVoided ? 'background: #FAF8F6; opacity: 0.85;' : '' ?>">
                        <td style="padding: 12px 14px;">
                            <strong style="color: #3C2415;">#<?= e($tx['transaction_id']) ?></strong>
                        </td>
                        <td style="padding: 12px 14px; white-space: nowrap; color: #5A3A28;">
                            <?= formatDateTime($tx['transaction_date']) ?>
                        </td>
                        <td style="padding: 12px 14px;">
                            <small style="color: <?= $isVoided ? '#9CA3AF' : '#6E5C4F' ?>; font-size: 0.82rem;">
                                <?= e($tx['products_summary'] ?: 'N/A') ?>
                            </small>
                        </td>
                        <td style="padding: 12px 14px; color: #3C2415; font-weight: 600;">
                            <?= e($tx['first_name'] . ' ' . $tx['last_name']) ?>
                        </td>
                        <td style="padding: 12px 14px;">
                            <?php if ($isVoided): ?>
                                <span style="color: #9CA3AF; text-decoration: line-through; font-size: 0.88rem;"><?= formatCurrency($tx['total_amount']) ?></span>
                            <?php else: ?>
                                <strong style="color: #3C2415; font-size: 0.9rem;"><?= formatCurrency($tx['total_amount']) ?></strong>
                            <?php endif; ?>
                            <div><?= $payBadge ?></div>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <?php if ($isVoided): ?>
                                <span style="background: #FCE8E6; color: #DC2626; border: 1px solid #FCA5A5; padding: 0.2rem 0.55rem; border-radius: 5px; font-size: 0.74rem; font-weight: 700; display: inline-block;">🚫 VOIDED</span>
                            <?php else: ?>
                                <span style="background: #E6F4EA; color: #059669; border: 1px solid #A7F3D0; padding: 0.2rem 0.55rem; border-radius: 5px; font-size: 0.74rem; font-weight: 700; display: inline-block;">Completed</span>
                            <?php endif; ?>

                            <?php if (!empty($tx['has_spoilage']) && $tx['has_spoilage'] > 0): ?>
                                <span style="background: #FFF9E6; color: #D97706; border: 1px solid #FCD34D; font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-left: 4px; display: inline-block;">🚨 REMAKE</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px 14px; text-align: center;">
                            <div style="display: inline-flex; gap: 6px; align-items: center; justify-content: center;">
                                <button class="btn btn-sm view-details-btn" 
                                         data-id="<?= e($tx['transaction_id']) ?>"
                                         data-date="<?= formatDateTime($tx['transaction_date']) ?>"
                                         data-staff="<?= e($tx['first_name'] . ' ' . $tx['last_name']) ?>"
                                         data-total="<?= formatCurrency($tx['total_amount']) ?>"
                                         style="padding: 0.3rem 0.65rem; border-radius: 6px; font-size: 0.78rem; font-weight: 600; border: 1px solid #C49A82; color: #3C2415; background: #FFF;">
                                    Details
                                </button>
                                
                                <?php if (!$isVoided): ?>
                                <button class="btn btn-sm void-tx-btn" 
                                         data-id="<?= e($tx['transaction_id']) ?>"
                                         data-total="<?= formatCurrency($tx['total_amount']) ?>"
                                         data-paymethod="<?= e($payMethod) ?>"
                                         style="padding: 0.3rem 0.65rem; border-radius: 6px; font-size: 0.78rem; font-weight: 700; border: 1px solid #FEB2B2; color: #C53030; background: #FFF5F5;"
                                         title="Choose items to void or void entire sale">
                                    🚫 Void
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transactions)): ?>
                    <tr><td colspan="7" class="text-center p-4 text-muted">No transactions found matching your criteria.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="p-3">
            <div class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>&staff_id=<?= urlencode($staff_id) ?>&payment_method=<?= urlencode($payment_filter) ?>&status=<?= urlencode($status_filter) ?>&page=<?= $i ?>" class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-outline' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Transaction Details Modal -->
<div class="modal-overlay" id="salesDetailsModal">
    <div class="modal" style="border-radius: 14px; max-width: 580px; width: 92%; border: 1px solid #EAE3D9; box-shadow: 0 10px 30px rgba(60,36,21,0.15);">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #EAE3D9; padding: 1.1rem 1.25rem;">
            <h3 id="modal_tx_title" style="margin: 0; font-size: 1.1rem; color: #3C2415; font-weight: 700;">Transaction Details</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 0.85rem; right: 1.1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #8C6D58;">&times;</button>
        </div>
        <div class="modal-body" id="modal_tx_body" style="padding: 1.25rem;">
            <div class="text-center p-3 text-muted">Loading transaction details...</div>
        </div>
    </div>
</div>

<!-- Partial / Full Item Void Confirmation Modal -->
<div class="modal-overlay" id="voidConfirmModal">
    <div class="modal" style="border-radius: 14px; max-width: 540px; width: 94%; border: 1px solid #FEB2B2; box-shadow: 0 10px 30px rgba(220,38,38,0.12);">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #FEE2E2; background: #FFF5F5; padding: 1.1rem 1.25rem; border-top-left-radius: 13px; border-top-right-radius: 13px;">
            <h3 style="margin: 0; font-size: 1.1rem; color: #991B1B; font-weight: 700; display: flex; align-items: center; gap: 8px;">
                <span>🚫</span> Void / Refund Items
            </h3>
            <button type="button" class="close-modal" style="position: absolute; top: 0.85rem; right: 1.1rem; border: none; background: none; font-size: 1.5rem; cursor: pointer; color: #991B1B;">&times;</button>
        </div>
        <div class="modal-body" style="padding: 1.25rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.9rem;">
                <span id="void_tx_label" style="font-weight: 700; color: #1F2937; font-size: 0.95rem;">Sale #0</span>
                <span id="void_total_label" style="font-weight: 800; color: #991B1B; font-size: 1.1rem;">₱0.00</span>
            </div>

            <!-- Items Selection Box -->
            <div style="margin-bottom: 1rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="font-weight: 700; color: #374151; font-size: 0.86rem; margin: 0;">Select Items & Quantity to Void:</label>
                    <button type="button" id="select_all_void_btn" style="background: none; border: none; color: #991B1B; font-weight: 700; font-size: 0.8rem; cursor: pointer; text-decoration: underline; padding: 0;">
                        Void All Items
                    </button>
                </div>
                <div id="void_items_container" style="max-height: 180px; overflow-y: auto; border: 1px solid #FEE2E2; border-radius: 8px; padding: 8px; background: #FFF9F9;">
                    <div class="text-center p-3 text-muted">Loading items...</div>
                </div>
            </div>

            <!-- Summary Refund / Stock Notice -->
            <div style="background: #FEF3C7; border: 1px solid #FDE68A; border-radius: 8px; padding: 0.75rem 1rem; margin-bottom: 1.15rem; font-size: 0.82rem; color: #92400E; display: flex; justify-content: space-between; align-items: center;">
                <span>⚠️ Stock for selected drinks will be automatically restored.</span>
                <strong id="void_refund_estimate" style="font-size: 0.92rem; color: #B45309;">Refund: ₱0.00</strong>
            </div>

            <!-- Refund Settlement Method -->
            <div class="form-group mb-3">
                <label style="display: block; font-weight: 600; color: #374151; font-size: 0.86rem; margin-bottom: 6px;">Refund Settlement Method:</label>
                <select id="void_refund_method_select" class="form-control" style="font-size: 0.88rem;">
                    <option value="cash">💵 Cash (Handed cash back to customer from drawer)</option>
                    <option value="gcash">📱 GCash (Sent refund via GCash Express Send)</option>
                    <option value="maya">🟢 Maya (Sent refund via Maya digital transfer)</option>
                </select>
            </div>

            <!-- Reason Input -->
            <div class="form-group mb-3">
                <label style="display: block; font-weight: 600; color: #374151; font-size: 0.86rem; margin-bottom: 6px;">Reason for Voiding:</label>
                <select id="void_reason_select" class="form-control mb-2" style="font-size: 0.88rem;">
                    <option value="Customer Cancellation / Changed Mind">Customer Cancellation / Changed Mind</option>
                    <option value="Wrong Item Punched by Cashier">Wrong Item Punched by Cashier</option>
                    <option value="Accidental Duplicate Order">Accidental Duplicate Order</option>
                    <option value="Drink Spilled / Preparation Error">Drink Spilled / Preparation Error</option>
                    <option value="other">Other / Custom Reason...</option>
                </select>
                <input type="text" id="void_reason_custom" class="form-control" placeholder="Type custom reason..." style="display: none; font-size: 0.88rem;">
            </div>

            <div id="void_error_msg" style="display: none; padding: 0.6rem 0.8rem; background: #FEE2E2; color: #991B1B; border-radius: 6px; font-size: 0.84rem; margin-bottom: 1rem;"></div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-outline close-modal" style="border: 1px solid #D1D5DB; color: #4B5563; font-weight: 600; padding: 0.55rem 1.1rem; border-radius: 8px;">Cancel</button>
                <button type="button" id="confirm_void_submit_btn" style="background: #DC2626; color: #FFF; border: none; font-weight: 700; padding: 0.55rem 1.25rem; border-radius: 8px; cursor: pointer; transition: background 0.15s;">
                    Confirm & Void Selection
                </button>
            </div>
        </div>
    </div>
</div>

<form id="csrfHolder" style="display:none;">
    <?= csrfField() ?>
</form>

<?php 
$extraJS = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function() {
    const detailsModal = document.getElementById('salesDetailsModal');
    const modalTitle = document.getElementById('modal_tx_title');
    const modalBody = document.getElementById('modal_tx_body');

    const voidModal = document.getElementById('voidConfirmModal');
    const voidTxLabel = document.getElementById('void_tx_label');
    const voidTotalLabel = document.getElementById('void_total_label');
    const voidItemsContainer = document.getElementById('void_items_container');
    const voidRefundEstimate = document.getElementById('void_refund_estimate');
    const voidRefundMethodSelect = document.getElementById('void_refund_method_select');
    const selectAllVoidBtn = document.getElementById('select_all_void_btn');
    const voidReasonSelect = document.getElementById('void_reason_select');
    const voidReasonCustom = document.getElementById('void_reason_custom');
    const voidSubmitBtn = document.getElementById('confirm_void_submit_btn');
    const voidErrorMsg = document.getElementById('void_error_msg');

    let currentVoidTxId = 0;
    let currentTxItems = [];

    // Reason select toggle custom input
    if (voidReasonSelect) {
        voidReasonSelect.addEventListener('change', function() {
            if (this.value === 'other') {
                voidReasonCustom.style.display = 'block';
                voidReasonCustom.focus();
            } else {
                voidReasonCustom.style.display = 'none';
            }
        });
    }

    function calculateRefundEstimate() {
        let totalRefund = 0;
        document.querySelectorAll('.void-item-checkbox:checked').forEach(cb => {
            const itemId = cb.dataset.id;
            const price = parseFloat(cb.dataset.price);
            const qtyInput = document.getElementById('void_qty_' + itemId);
            const qty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;
            totalRefund += price * qty;
        });
        voidRefundEstimate.textContent = 'Refund: ₱' + totalRefund.toFixed(2);
    }

    // Open Void Confirmation Modal with Item Checklist
    async function openVoidModal(txId, totalStr, payMethod = 'cash') {
        currentVoidTxId = txId;
        voidTxLabel.textContent = 'Sale #' + txId;
        voidTotalLabel.textContent = totalStr;
        voidReasonSelect.value = 'Customer Cancellation / Changed Mind';
        voidReasonCustom.style.display = 'none';
        voidReasonCustom.value = '';
        voidErrorMsg.style.display = 'none';
        voidSubmitBtn.disabled = false;
        voidSubmitBtn.textContent = 'Confirm & Void Selection';
        voidItemsContainer.innerHTML = '<div class="text-center p-3 text-muted">Loading items from order...</div>';
        
        if (voidRefundMethodSelect) {
            voidRefundMethodSelect.value = (payMethod === 'gcash' || payMethod === 'maya') ? payMethod : 'cash';
        }

        if (detailsModal.classList.contains('active')) {
            detailsModal.classList.remove('active');
        }
        voidModal.classList.add('active');

        try {
            const res = await fetch(window.APP.baseUrl + '/api/tx-details.php?id=' + txId);
            const data = await res.json();
            if (data.success && data.items && data.items.length > 0) {
                currentTxItems = data.items;
                let html = '';
                data.items.forEach(it => {
                    const activeQty = parseInt(it.quantity) || 0;
                    if (activeQty <= 0) return;

                    html += `
                        <div class="void-item-row">
                            <label style="display: flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; flex: 1;">
                                <input type="checkbox" class="void-item-checkbox" data-id="\${it.item_id}" data-price="\${it.unit_price}" checked style="width: 16px; height: 16px; cursor: pointer;">
                                <div>
                                    <strong style="color: #3C2415; font-size: 0.88rem;">\${it.product_name}</strong>
                                    <small style="display: block; color: #8C6D58; font-size: 0.76rem;">₱\${parseFloat(it.unit_price).toFixed(2)} each (Active: \${activeQty})</small>
                                </div>
                            </label>
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <button type="button" class="btn btn-sm" onclick="adjustVoidQty(\${it.item_id}, -1, \${activeQty})" style="padding: 2px 7px; font-weight: 800; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px;">−</button>
                                <input type="number" id="void_qty_\${it.item_id}" value="\${activeQty}" min="1" max="\${activeQty}" readonly style="width: 42px; text-align: center; font-weight: 700; border: 1px solid #D2C6B9; border-radius: 4px; padding: 2px 0; font-size: 0.88rem; background: #FAF7F2;">
                                <button type="button" class="btn btn-sm" onclick="adjustVoidQty(\${it.item_id}, 1, \${activeQty})" style="padding: 2px 7px; font-weight: 800; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px;">+</button>
                            </div>
                        </div>
                    `;
                });

                voidItemsContainer.innerHTML = html || '<div class="text-center p-3 text-muted">No active items to void.</div>';

                // Listen to checkbox changes
                document.querySelectorAll('.void-item-checkbox').forEach(cb => {
                    cb.addEventListener('change', calculateRefundEstimate);
                });

                calculateRefundEstimate();
            } else {
                voidItemsContainer.innerHTML = '<div class="text-center p-3 text-danger">Failed to load order items.</div>';
            }
        } catch (err) {
            voidItemsContainer.innerHTML = '<div class="text-center p-3 text-danger">Error loading order items.</div>';
        }
    }

    window.adjustVoidQty = function(itemId, delta, maxQty) {
        const input = document.getElementById('void_qty_' + itemId);
        const cb = document.querySelector('.void-item-checkbox[data-id="' + itemId + '"]');
        if (input) {
            let val = parseInt(input.value) || 1;
            val = Math.max(1, Math.min(maxQty, val + delta));
            input.value = val;
            if (cb) cb.checked = true;
            calculateRefundEstimate();
        }
    };

    if (selectAllVoidBtn) {
        selectAllVoidBtn.addEventListener('click', function() {
            document.querySelectorAll('.void-item-checkbox').forEach(cb => {
                cb.checked = true;
                const itemId = cb.dataset.id;
                const input = document.getElementById('void_qty_' + itemId);
                if (input && input.getAttribute('max')) {
                    input.value = input.getAttribute('max');
                }
            });
            calculateRefundEstimate();
        });
    }

    document.querySelectorAll('.void-tx-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            openVoidModal(this.dataset.id, this.dataset.total, this.dataset.paymethod);
        });
    });

    // Handle Void Submit AJAX
    if (voidSubmitBtn) {
        voidSubmitBtn.addEventListener('click', async function() {
            if (currentVoidTxId <= 0) return;

            const selectedItems = [];
            document.querySelectorAll('.void-item-checkbox:checked').forEach(cb => {
                const itemId = parseInt(cb.dataset.id);
                const qtyInput = document.getElementById('void_qty_' + itemId);
                const vQty = qtyInput ? parseInt(qtyInput.value) || 0 : 0;
                if (itemId > 0 && vQty > 0) {
                    selectedItems.push({
                        item_id: itemId,
                        void_qty: vQty
                    });
                }
            });

            if (selectedItems.length === 0) {
                voidErrorMsg.textContent = 'Please select at least one item to void.';
                voidErrorMsg.style.display = 'block';
                return;
            }

            let finalReason = voidReasonSelect.value;
            if (finalReason === 'other') {
                finalReason = voidReasonCustom.value.trim();
                if (!finalReason) {
                    voidErrorMsg.textContent = 'Please enter a custom reason for voiding.';
                    voidErrorMsg.style.display = 'block';
                    return;
                }
            }

            voidSubmitBtn.disabled = true;
            voidSubmitBtn.textContent = 'Restoring stock & voiding...';
            voidErrorMsg.style.display = 'none';

            const csrfToken = document.querySelector('#csrfHolder input[name="csrf_token"]').value;
            const refundMethodVal = voidRefundMethodSelect ? voidRefundMethodSelect.value : 'cash';

            try {
                const res = await fetch(window.APP.baseUrl + '/api/void-sale.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        transaction_id: currentVoidTxId,
                        void_mode: 'partial',
                        void_items: selectedItems,
                        reason: finalReason,
                        refund_method: refundMethodVal
                    })
                });

                const data = await res.json();
                if (data.success) {
                    voidModal.classList.remove('active');
                    window.location.reload();
                } else {
                    voidErrorMsg.textContent = data.message || 'Failed to void selection.';
                    voidErrorMsg.style.display = 'block';
                    voidSubmitBtn.disabled = false;
                    voidSubmitBtn.textContent = 'Confirm & Void Selection';
                }
            } catch (err) {
                voidErrorMsg.textContent = 'Network or server error. Please try again.';
                voidErrorMsg.style.display = 'block';
                voidSubmitBtn.disabled = false;
                voidSubmitBtn.textContent = 'Confirm & Void Selection';
            }
        });
    }

    // View Details Modal
    document.querySelectorAll('.view-details-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const txId = this.dataset.id;
            const dateStr = this.dataset.date;
            const staffName = this.dataset.staff;
            const totalStr = this.dataset.total;
            
            modalTitle.textContent = 'Transaction Details — Sale #' + txId;
            modalBody.innerHTML = '<div style="text-align: center; padding: 2rem; color: #8C6D58;">Loading transaction details...</div>';
            detailsModal.classList.add('active');

            try {
                const res = await fetch(window.APP.baseUrl + '/api/tx-details.php?id=' + txId);
                const data = await res.json();
                if (data.success) {
                    const tx = data.transaction || {};
                    const isVoided = (tx.status === 'voided');
                    const payMethod = (tx.payment_method || 'cash').toLowerCase();

                    let payInfoStr = '💵 Cash';
                    if (payMethod === 'gcash') {
                        payInfoStr = '📱 GCash' + (tx.payment_reference ? ' (Ref #' + tx.payment_reference + ')' : '');
                    } else if (payMethod === 'maya') {
                        payInfoStr = '🟢 Maya' + (tx.payment_reference ? ' (Ref #' + tx.payment_reference + ')' : '');
                    } else {
                        if (parseFloat(tx.amount_tendered) > 0) {
                            payInfoStr += ` (Tendered: ₱\${parseFloat(tx.amount_tendered).toFixed(2)} | Change: ₱\${parseFloat(tx.change_amount || 0).toFixed(2)})`;
                        }
                    }

                    let html = '';

                    if (isVoided) {
                        const voidedAt = tx.voided_at ? new Date(tx.voided_at).toLocaleString() : '';
                        const voidBy = (tx.void_first ? tx.void_first + ' ' + tx.void_last : 'Admin');
                        const refundMeth = (tx.refund_method === 'gcash' ? '📱 GCash Send Money' : (tx.refund_method === 'maya' ? '🟢 Maya' : '💵 Cash from Drawer'));
                        html += `
                            <div style="background: #FEE2E2; border: 1px solid #FCA5A5; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1rem;">
                                <strong style="color: #991B1B; font-size: 0.9rem; display: block; margin-bottom: 4px;">🚫 TRANSACTION FULLY VOIDED</strong>
                                <div style="color: #7F1D1D; font-size: 0.84rem; line-height: 1.5;">
                                    <strong>Reason:</strong> \${tx.void_reason || 'Order Voided'}<br>
                                    <strong>Refund Settled via:</strong> \${refundMeth}<br>
                                    <strong>Voided By:</strong> \${voidBy} on \${voidedAt}
                                </div>
                            </div>
                        `;
                    } else if (tx.void_reason) {
                        html += `
                            <div style="background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1rem;">
                                <strong style="color: #92400E; font-size: 0.88rem; display: block; margin-bottom: 4px;">⚠️ Partial Void History:</strong>
                                <div style="color: #78350F; font-size: 0.82rem;">\${tx.void_reason}</div>
                            </div>
                        `;
                    }

                    html += `
                        <div style="display: flex; justify-content: space-between; align-items: center; background: #FAF7F2; border: 1px solid #EAE3D9; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 0.75rem; font-size: 0.85rem;">
                            <div><strong>Date/Time:</strong> \${dateStr}</div>
                            <div><span style="background: #E8F0FE; color: #2563EB; border: 1px solid #BFDBFE; padding: 2px 8px; border-radius: 5px; font-weight: 600;">Staff: \${staffName}</span></div>
                        </div>
                        <div style="background: #FDF9F5; border: 1px solid #EFE8DE; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.84rem; color: #5A3A28;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <div><strong>Payment:</strong> \${payInfoStr}</div>
                                \${!isVoided ? `
                                    <button type="button" class="btn btn-sm" onclick="togglePaymentSwitchPanel()" style="padding: 2px 8px; font-size: 0.75rem; border: 1px solid #D2C6B9; background: #FFF; border-radius: 5px; color: #6E5343; font-weight: 700; cursor: pointer;">
                                        ✏️ Switch Method
                                    </button>
                                ` : ''}
                            </div>
                            
                            <!-- Inline Payment Switch Panel -->
                            <div id="switch_payment_panel" style="display: none; background: #FFF; border: 1px solid #E2D9CF; border-radius: 8px; padding: 0.85rem; margin-top: 8px;">
                                <div style="font-weight: 700; font-size: 0.82rem; color: #3C2415; margin-bottom: 6px;">Switch Payment Method:</div>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 6px;">
                                    <select id="new_pay_method_select" class="form-control form-control-sm" style="font-size: 0.85rem; height: 34px; border-radius: 6px; width: 140px;" onchange="handleSwitchMethodChange()">
                                        <option value="cash" \${payMethod === 'cash' ? 'selected' : ''}>💵 Cash</option>
                                        <option value="gcash" \${payMethod === 'gcash' ? 'selected' : ''}>📱 GCash</option>
                                        <option value="maya" \${payMethod === 'maya' ? 'selected' : ''}>🟢 Maya</option>
                                    </select>
                                    <input type="text" id="switch_ref_input" placeholder="Ref # (optional)" class="form-control form-control-sm" style="height: 34px; font-size: 0.85rem; border-radius: 6px; flex: 1; min-width: 120px; display: \${payMethod !== 'cash' ? 'block' : 'none'};">
                                    <button type="button" id="save_switch_btn" class="btn btn-sm btn-primary" onclick="submitPaymentSwitch(\${txId})" style="background: #3C2415; border: none; height: 34px; padding: 0 14px; font-weight: 700; font-size: 0.82rem; border-radius: 6px; cursor: pointer;">
                                        Save
                                    </button>
                                </div>
                                <div id="switch_pay_err" style="display: none; color: #DC2626; font-size: 0.8rem; margin-top: 4px;"></div>
                            </div>
                        </div>
                    `;

                    if (data.adjustments && data.adjustments.length > 0) {
                        html += `
                            <div style="background: #FFF9E6; border: 1px solid #FCD34D; border-radius: 8px; padding: 0.85rem 1rem; margin-bottom: 1rem;">
                                <strong style="color: #D97706; font-size: 0.88rem; display: block; margin-bottom: 4px;">🚨 Product Remake Logged for Sale #\${txId}:</strong>`;
                        data.adjustments.forEach(adj => {
                            let cleanNote = adj.notes.replace(/^Product (?:Adjustment|Spoilage)(?:\s*\[Linked to Sale #\d+\])?:?\s*/i, '');
                            html += `<div style="color: #5A4434; font-size: 0.83rem; margin-top: 3px; font-weight: 600;">• \${cleanNote}</div>`;
                        });
                        html += `</div>`;
                    }

                    html += `
                        <table class="table table-sm align-middle mb-3" style="font-size: 0.88rem;">
                            <thead>
                                <tr style="background: #FAF7F2; color: #8C6D58; font-size: 0.76rem; text-transform: uppercase;">
                                    <th style="padding: 8px 12px;">Purchased Product Item</th>
                                    <th style="text-align: center; padding: 8px 12px;">Active Qty</th>
                                    <th style="text-align: right; padding: 8px 12px;">Unit Price</th>
                                    <th style="text-align: right; padding: 8px 12px;">Subtotal</th>
                                </tr>
                            </thead>
                            <tbody>`;
                    
                    data.items.forEach(item => {
                        const voidedTag = parseInt(item.voided_quantity) > 0 ? `<span style="background: #FEE2E2; color: #991B1B; font-size: 0.72rem; padding: 1px 5px; border-radius: 4px; margin-left: 6px; font-weight: 700;">\${item.voided_quantity} voided</span>` : '';
                        html += `
                            <tr style="border-bottom: 1px solid #F3EDE7;">
                                <td style="padding: 8px 12px; color: #3C2415;">
                                    <strong>\${item.product_name}</strong>\${voidedTag}
                                </td>
                                <td style="text-align: center; padding: 8px 12px; font-weight: 600;">\${item.quantity}</td>
                                <td style="text-align: right; padding: 8px 12px; color: #6E5C4F;">₱\${parseFloat(item.unit_price).toFixed(2)}</td>
                                <td style="text-align: right; padding: 8px 12px; font-weight: 700; color: #3C2415;">₱\${parseFloat(item.subtotal).toFixed(2)}</td>
                            </tr>`;
                    });

                    html += `
                            </tbody>
                        </table>
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 2px solid #EAE3D9; padding-top: 0.75rem;">
                            <div>
                                \${!isVoided ? `
                                    <button type="button" class="btn btn-sm" onclick="openVoidModal(\${txId}, '\${totalStr}', '\${payMethod}')" style="background: #FEE2E2; color: #991B1B; border: 1px solid #FCA5A5; font-weight: 700; padding: 0.4rem 0.9rem; border-radius: 6px; cursor: pointer;">
                                        🚫 Void / Refund Items
                                    </button>
                                ` : ''}
                            </div>
                            <div style="font-size: 1.1rem; font-weight: 800; color: #3C2415;">
                                Active Total: ₱\${parseFloat(tx.total_amount).toFixed(2)}
                            </div>
                        </div>`;

                    modalBody.innerHTML = html;
                } else {
                    modalBody.innerHTML = '<div class="alert alert-danger p-3">' + (data.message || 'Failed to load details') + '</div>';
                }
            } catch (err) {
                modalBody.innerHTML = '<div class="alert alert-danger p-3">Error connecting to server.</div>';
            }
        });
    });

    window.togglePaymentSwitchPanel = function() {
        const panel = document.getElementById('switch_payment_panel');
        if (panel) {
            panel.style.display = (panel.style.display === 'none' ? 'block' : 'none');
        }
    };

    window.handleSwitchMethodChange = function() {
        const select = document.getElementById('new_pay_method_select');
        const refInput = document.getElementById('switch_ref_input');
        if (select && refInput) {
            refInput.style.display = (select.value !== 'cash' ? 'block' : 'none');
        }
    };

    window.submitPaymentSwitch = async function(txId) {
        const select = document.getElementById('new_pay_method_select');
        const refInput = document.getElementById('switch_ref_input');
        const errDiv = document.getElementById('switch_pay_err');
        const saveBtn = document.getElementById('save_switch_btn');

        if (!select) return;

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving...';
        if (errDiv) errDiv.style.display = 'none';

        const csrfToken = document.querySelector('#csrfHolder input[name="csrf_token"]').value;

        try {
            const res = await fetch(window.APP.baseUrl + '/api/update-payment-method.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    transaction_id: txId,
                    payment_method: select.value,
                    payment_reference: refInput ? refInput.value.trim() : ''
                })
            });

            const data = await res.json();
            if (data.success) {
                window.location.reload();
            } else {
                if (errDiv) {
                    errDiv.textContent = data.message || 'Failed to update payment method.';
                    errDiv.style.display = 'block';
                }
                saveBtn.disabled = false;
                saveBtn.textContent = 'Save';
            }
        } catch (err) {
            if (errDiv) {
                errDiv.textContent = 'Network or server error.';
                errDiv.style.display = 'block';
            }
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
        }
    };

    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            detailsModal.classList.remove('active');
            voidModal.classList.remove('active');
        });
    });

    window.openVoidModal = openVoidModal;
});
</script>
HTML;

require_once ROOT_PATH . '/includes/footer.php'; 
?>
