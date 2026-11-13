<?php

/**
 * Bright on Beans Coffee House
 * Shared Header Template
 * 
 * Includes HTML head, sidebar navigation (role-based), and topbar.
 * Each page must set $pageTitle before including this file.
 */

if (!isset($pageTitle)) $pageTitle = 'Dashboard';
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentDir = basename(dirname($_SERVER['PHP_SELF']));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Bright on Beans Coffee House Inventory Sales Tracking System">
    <title><?= e($pageTitle) ?> — <?= APP_SHORT_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css?v=<?= time() ?>">
    <?php if (isset($extraCSS)) echo $extraCSS; ?>
</head>

<body>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <div class="brand-icon">☕</div>
            <div class="brand-text">
                <h1>Bright on Beans</h1>
                <p>Coffee House</p>
            </div>
        </div>

        <nav class="sidebar-nav">
            <?php if (isAdmin()): ?>
                <!-- ─── Admin Navigation ─── -->
                <div class="nav-section">
                    <span class="nav-section-title">Main Menu</span>
                    <a href="<?= BASE_URL ?>/admin/sales.php" class="nav-link <?= $currentPage === 'sales' ? 'active' : '' ?>">
                        <span class="nav-icon">🧾</span>
                        <span class="nav-text">Sales</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/inventory.php" class="nav-link <?= $currentPage === 'inventory' ? 'active' : '' ?>">
                        <span class="nav-icon">📦</span>
                        <span class="nav-text">Inventory</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text-color">Dashboard</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/reports.php" class="nav-link <?= $currentPage === 'reports' ? 'active' : '' ?>">
                        <span class="nav-icon">📈</span>
                        <span class="nav-text">Reports</span>
                    </a>
                </div>
                <div class="nav-section">
                    <span class="nav-section-title">Admin Tools</span>
                    <a href="<?= BASE_URL ?>/admin/ingredient-management.php" class="nav-link <?= $currentPage === 'ingredient-management' ? 'active' : '' ?>">
                        <span class="nav-icon">🧂</span>
                        <span class="nav-text">Ingredient Management</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/product-management.php" class="nav-link <?= $currentPage === 'product-management' ? 'active' : '' ?>">
                        <span class="nav-icon">☕</span>
                        <span class="nav-text">Product Management</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/inventory-adjustment.php" class="nav-link <?= $currentPage === 'inventory-adjustment' ? 'active' : '' ?>">
                        <span class="nav-icon">⚖️</span>
                        <span class="nav-text">Inventory Adjustment</span>
                    </a>
                    <a href="<?= BASE_URL ?>/admin/sales-history.php" class="nav-link <?= $currentPage === 'sales-history' ? 'active' : '' ?>">
                        <span class="nav-icon">📋</span>
                        <span class="nav-text">Sales History</span>
                    </a>
                </div>

            <?php else: ?>
                <!-- ─── Staff Navigation ─── -->
                <div class="nav-section">
                    <span class="nav-section-title">Main Menu</span>
                    <a href="<?= BASE_URL ?>/staff/sales.php" class="nav-link <?= $currentPage === 'sales' ? 'active' : '' ?>">
                        <span class="nav-icon">🧾</span>
                        <span class="nav-text">Sales</span>
                    </a>
                    <a href="<?= BASE_URL ?>/staff/inventory.php" class="nav-link <?= $currentPage === 'inventory' ? 'active' : '' ?>">
                        <span class="nav-icon">📦</span>
                        <span class="nav-text">View Inventory</span>
                    </a>
                    <a href="<?= BASE_URL ?>/staff/dashboard.php" class="nav-link <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
                        <span class="nav-icon">📊</span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </div>
            <?php endif; ?>

            <!-- ─── Logout (both roles) ─── -->
            <div class="nav-section nav-section-bottom">
                <a href="#" class="nav-link nav-link-logout" id="logoutBtn">
                    <span class="nav-icon">🚪</span>
                    <span class="nav-text">Logout</span>
                </a>
            </div>
        </nav>
    </aside>

    <!-- Main Content Area -->
    <main class="main-content">
        <!-- Top Bar -->
        <header class="topbar">
            <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
                <span></span><span></span><span></span>
            </button>
            <div class="topbar-info">
                <h2 class="topbar-title"><?= e($pageTitle) ?></h2>
            </div>
            <div class="topbar-right">
                <div class="topbar-user">
                    <span class="user-role-badge role-<?= e(getCurrentUserRole()) ?>"><?= ucfirst(e(getCurrentUserRole())) ?></span>
                    <span class="user-name"><?= e(getCurrentUserName()) ?></span>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="content">
            <?php renderFlashMessage(); ?>