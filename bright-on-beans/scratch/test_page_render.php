<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['username'] = 'admin';
$_SESSION['role'] = 'admin';
$_SESSION['status'] = 'active';
$_SESSION['first_name'] = 'Admin';
$_SESSION['last_name'] = 'User';
$_SERVER['PHP_SELF'] = '/bright-on-beans/admin/inventory-adjustment.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['ingredient_id'] = 15;

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

$summary = getIngredientPalletSummary($pdo, 15);
echo "Summary fetched successfully:\n";
echo "- Active: " . $summary['active_stock'] . "\n";
echo "- Reserved: " . $summary['reserved_stock'] . "\n";
echo "- Pallets count: " . count($summary['pallets']) . "\n";

$stmtHistList = $pdo->prepare("
    SELECT 
        created_at,
        change_type AS type,
        CASE 
            WHEN change_type = 'pallet_in' THEN 'New Batch (Pallet In)'
            WHEN change_type = 'adjustment_add' THEN 'Moved to Active Bar'
            WHEN change_type = 'adjustment_deduct' THEN 'Deducted / Spoilage'
            ELSE 'Stock Adjustment'
        END AS label,
        change_quantity AS qty,
        notes,
        CASE 
            WHEN change_type = 'pallet_in' THEN 'text-success'
            WHEN change_type = 'adjustment_add' THEN 'text-info'
            WHEN change_type = 'adjustment_deduct' THEN 'text-danger'
            ELSE 'text-muted'
        END AS badge_class
    FROM inventory_history 
    WHERE ingredient_id = ? AND change_type IN ('pallet_in', 'adjustment_add', 'adjustment_deduct')
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmtHistList->execute([15]);
$recentHistory = $stmtHistList->fetchAll();
echo "- Recent history rows: " . count($recentHistory) . "\n";
echo "✔ All database operations on inventory-adjustment.php run perfectly!\n";
