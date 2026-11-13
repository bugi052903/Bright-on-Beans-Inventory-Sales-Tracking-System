<?php
/**
 * Bright on Beans Coffee House
 * Utility Functions
 * 
 * Shared helper functions used across the application for
 * sanitization, formatting, data lookups, and validation.
 */

// ─── Input Sanitization ───────────────────────────────────────

/**
 * Sanitize user input against XSS.
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize for output in HTML context.
 */
function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// ─── Formatting Functions ─────────────────────────────────────

/**
 * Format amount with peso sign.
 */
function formatCurrency($amount) {
    return '₱' . number_format((float)$amount, 2);
}

/**
 * Format a number with proper decimal places.
 */
function formatStock($amount) {
    $num = (float)$amount;
    // If the number is a whole number, show no decimals
    if ($num == floor($num)) {
        return number_format($num, 0);
    }
    // Otherwise show up to 2 decimal places, trimming trailing zeros
    return rtrim(rtrim(number_format($num, 3), '0'), '.');
}

/**
 * Format date for display (e.g., "Aug 16, 2026").
 */
function formatDate($date) {
    return date('M d, Y', strtotime($date));
}

/**
 * Format datetime for display (e.g., "Aug 16, 2026 03:30 PM").
 */
function formatDateTime($date) {
    return date('M d, Y h:i A', strtotime($date));
}

/**
 * Format date for date input fields (e.g., "2026-08-16").
 */
function formatDateInput($date) {
    return date('Y-m-d', strtotime($date));
}

// ─── Stock Status Helpers ─────────────────────────────────────

/**
 * Get the stock status label based on current stock vs minimum level.
 */
function getStockStatus($currentStock, $minimumLevel) {
    $cur = (float)$currentStock;
    $min = (float)$minimumLevel;

    if ($cur <= 0) {
        return 'Out of Stock';
    }
    if ($cur <= $min) {
        return 'Low Stock';
    }
    return 'OK';
}

/**
 * Get the CSS class for a stock status badge.
 */
function getStockStatusClass($currentStock, $minimumLevel) {
    $cur = (float)$currentStock;
    $min = (float)$minimumLevel;

    if ($cur <= 0) {
        return 'status-low';
    }
    if ($cur <= $min) {
        return 'status-warning-custom';
    }
    return 'status-ok';
}

// ─── Data Lookup Functions ────────────────────────────────────

/**
 * Get all product categories.
 */
function getCategories($pdo = null) {
    $defaults = [
        'Hot Coffee',
        'Cold Coffee',
        'Non-Coffee',
        'Mocktails',
        'Frappe',
        'Pasta',
        'Burgers',
        'Foods',
        'Dirty Coffee Ice Cream',
        'Specials'
    ];
    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL AND category != ''");
            $dbCats = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $merged = array_unique(array_merge($defaults, $dbCats));
            sort($merged);
            return array_values($merged);
        } catch (Exception $e) {
            return $defaults;
        }
    }
    return $defaults;
}

/**
 * Get available measurement units.
 */
function getUnits() {
    return ['g', 'kg', 'mL', 'L', 'pcs', 'tbsp', 'tsp', 'oz', 'cups'];
}

/**
 * Get the 5 secret questions for password recovery.
 */
function getSecretQuestions() {
    return [
        1 => 'What is your nickname?',
        2 => 'What is your favorite color?',
        3 => 'What is your favorite food?',
        4 => 'What is the name of your best friend?',
        5 => 'What is your birth month?'
    ];
}

// ─── Validation Helpers ───────────────────────────────────────

/**
 * Validate password strength.
 * Returns an array of error messages (empty if valid).
 */
function validatePassword($password) {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number.';
    }
    return $errors;
}

/**
 * Check if a username is available.
 */
function isUsernameAvailable($pdo, $username, $excludeUserId = null) {
    $sql = "SELECT COUNT(*) FROM users WHERE username = ?";
    $params = [$username];
    if ($excludeUserId) {
        $sql .= " AND user_id != ?";
        $params[] = $excludeUserId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn() == 0;
}

// ─── Alert / Flash Message Functions ─────────────────────────

/**
 * Set a flash message to display on the next page load.
 */
function setFlashMessage($type, $message) {
    $_SESSION['flash'] = [
        'type'    => $type,    // 'success', 'error', 'warning', 'info'
        'message' => $message
    ];
}

/**
 * Get and clear the flash message.
 */
function getFlashMessage() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Render flash message HTML if one exists.
 */
function renderFlashMessage() {
    $flash = getFlashMessage();
    if ($flash) {
        $type = e($flash['type']);
        $message = e($flash['message']);
        echo "<div class=\"alert alert-{$type}\" id=\"flashMessage\">";
        echo "<span>{$message}</span>";
        echo "<button class=\"alert-close\" onclick=\"this.parentElement.remove()\">×</button>";
        echo "</div>";
    }
}

// ─── Low Stock Alert Functions ────────────────────────────────

/**
 * Get ingredient names whose combined active stock is at or below the minimum level.
 */
function getLowStockIngredients($pdo) {
    $stmt = $pdo->query(
        "SELECT MIN(ingredient_id) AS ingredient_id,
                MIN(name) AS name,
                MIN(unit) AS unit,
                SUM(current_stock) AS current_stock,
                MAX(minimum_level) AS minimum_level
         FROM ingredients
         WHERE is_archived = 0
         GROUP BY LOWER(TRIM(name)), LOWER(TRIM(unit))
         HAVING SUM(current_stock) <= MAX(minimum_level)
         ORDER BY (SUM(current_stock) / NULLIF(MAX(minimum_level), 0)) ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Count the number of ingredient names at or below their minimum stock level.
 */
function getLowStockCount($pdo) {
    $stmt = $pdo->query(
        "SELECT COUNT(*)
         FROM (
             SELECT LOWER(TRIM(name)), LOWER(TRIM(unit))
             FROM ingredients
             WHERE is_archived = 0
             GROUP BY LOWER(TRIM(name)), LOWER(TRIM(unit))
             HAVING SUM(current_stock) <= MAX(minimum_level)
         ) AS low_stock_ingredients"
    );
    return $stmt->fetchColumn();
}

/**
 * Count the number of expired ingredients.
 */
function getExpiredCount($pdo) {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM ingredients WHERE expiration_date IS NOT NULL AND expiration_date <= CURDATE()"
    );
    return $stmt->fetchColumn();
}

/**
 * Count the number of ingredients expiring soon (within 7 days, excluding already expired).
 */
function getExpiringSoonCount($pdo) {
    $stmt = $pdo->query(
        "SELECT COUNT(*) FROM ingredients WHERE expiration_date IS NOT NULL AND expiration_date > CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)"
    );
    return $stmt->fetchColumn();
}

/**
 * Get all expired ingredients.
 */
function getExpiredIngredients($pdo) {
    $stmt = $pdo->query(
        "SELECT ingredient_id, name, unit, current_stock, expiration_date 
         FROM ingredients 
         WHERE expiration_date IS NOT NULL AND expiration_date <= CURDATE() 
         ORDER BY expiration_date ASC"
    );
    return $stmt->fetchAll();
}

/**
 * Get all ingredients expiring soon (within 7 days, excluding already expired).
 */
function getExpiringSoonIngredients($pdo) {
    $stmt = $pdo->query(
        "SELECT ingredient_id, name, unit, current_stock, expiration_date 
         FROM ingredients 
         WHERE expiration_date IS NOT NULL AND expiration_date > CURDATE() AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
         ORDER BY expiration_date ASC"
    );
    return $stmt->fetchAll();
}

// ─── PIPO (Pallet In / Pallet Out) Helpers ────────────────────

/**
 * Generate next sequential Pallet Code (e.g. PLT-20260821-001).
 */
function generatePalletCode($pdo) {
    $prefix = 'PLT-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT pallet_code FROM pallets WHERE pallet_code LIKE ? ORDER BY pallet_id DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $lastCode = $stmt->fetchColumn();

    if ($lastCode) {
        $lastSeq = (int)substr($lastCode, strlen($prefix));
        $nextSeq = str_pad($lastSeq + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $nextSeq = '001';
    }
    return $prefix . $nextSeq;
}

/**
 * Predefined coffee house storage locations / pallet rack bays.
 */
function getStorageLocations() {
    return [
        'Pallet Rack A-01 (Coffee Beans & Grounds)',
        'Pallet Rack A-02 (Syrups & Flavorings)',
        'Pallet Rack B-01 (Cups, Lids & Packaging)',
        'Pallet Rack B-02 (Paper Goods & Supplies)',
        'Walk-in Cold Storage (Dairy & Perishables)',
        'Dry Goods Storage Bay',
        'Staging & Receiving Dock'
    ];
}

/**
 * Ensure the tables used for expiration-aware active stock are available.
 */
function ensureIngredientStockBatchSchema($pdo) {
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name IN ('ingredient_stock_batches', 'sale_stock_deductions')
    ");
    if ((int)$stmt->fetchColumn() < 2) {
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS ingredient_stock_batches (
            batch_id INT NOT NULL AUTO_INCREMENT,
            ingredient_id INT NOT NULL,
            pallet_id INT DEFAULT NULL,
            remaining_quantity DECIMAL(12,3) NOT NULL DEFAULT 0.000,
            expiration_date DATE DEFAULT NULL,
            unit_cost DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (batch_id),
            KEY idx_active_batches_ingredient (ingredient_id),
            KEY idx_active_batches_expiration (expiration_date),
            CONSTRAINT active_batches_ingredient_fk
                FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $pdo->exec("
        CREATE TABLE IF NOT EXISTS sale_stock_deductions (
            deduction_id INT NOT NULL AUTO_INCREMENT,
            sales_item_id INT NOT NULL,
            ingredient_id INT NOT NULL,
            batch_id INT DEFAULT NULL,
            quantity DECIMAL(12,3) NOT NULL,
            PRIMARY KEY (deduction_id),
            KEY idx_sale_deductions_item (sales_item_id),
            KEY idx_sale_deductions_batch (batch_id),
            CONSTRAINT sale_deductions_item_fk
                FOREIGN KEY (sales_item_id) REFERENCES sales_items (item_id) ON DELETE CASCADE,
            CONSTRAINT sale_deductions_ingredient_fk
                FOREIGN KEY (ingredient_id) REFERENCES ingredients (ingredient_id),
            CONSTRAINT sale_deductions_batch_fk
                FOREIGN KEY (batch_id) REFERENCES ingredient_stock_batches (batch_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'ingredients'
          AND column_name = 'is_archived'
    ");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/**
 * Keep staged batch quantities within the ingredient's aggregate active stock.
 */
function reconcileIngredientStockBatches($pdo, $ingredientId, $currentStock) {
    $currentStockMilli = max(0, (int)round((float)$currentStock * 1000));
    $stmt = $pdo->prepare("
        SELECT batch_id, remaining_quantity
        FROM ingredient_stock_batches
        WHERE ingredient_id = ? AND remaining_quantity > 0
        ORDER BY expiration_date IS NULL, expiration_date ASC, batch_id ASC
        FOR UPDATE
    ");
    $stmt->execute([$ingredientId]);
    $batches = $stmt->fetchAll();

    $trackedMilli = 0;
    foreach ($batches as $batch) {
        $trackedMilli += (int)round((float)$batch['remaining_quantity'] * 1000);
    }

    $excessMilli = $trackedMilli - $currentStockMilli;
    if ($excessMilli <= 0) return;

    $stmtUpdate = $pdo->prepare("UPDATE ingredient_stock_batches SET remaining_quantity = ? WHERE batch_id = ?");
    foreach ($batches as $batch) {
        if ($excessMilli <= 0) break;
        $batchMilli = (int)round((float)$batch['remaining_quantity'] * 1000);
        $reductionMilli = min($batchMilli, $excessMilli);
        $stmtUpdate->execute([($batchMilli - $reductionMilli) / 1000, $batch['batch_id']]);
        $excessMilli -= $reductionMilli;
    }
}

/**
 * Get comprehensive stock summary for an ingredient (Active vs. Reserved Pallets).
 */
function getIngredientPalletSummary($pdo, $ingredientId) {
    $stmt = $pdo->prepare("SELECT * FROM ingredients WHERE ingredient_id = ?");
    $stmt->execute([$ingredientId]);
    $ingredient = $stmt->fetch();
    if (!$ingredient) return null;

    $activeStock = (float)$ingredient['current_stock'];
    $activeExp = $ingredient['expiration_date'];

    // Get reserved pallets (stored or partially in-use with remaining quantity)
    $stmtPallets = $pdo->prepare("
        SELECT p.*, u.first_name, u.last_name 
        FROM pallets p 
        LEFT JOIN users u ON p.created_by = u.user_id 
        WHERE p.ingredient_id = ? AND p.status IN ('stored', 'in_use') AND p.remaining_quantity > 0 
        ORDER BY p.expiration_date IS NULL, p.expiration_date ASC, p.pallet_id ASC
    ");
    $stmtPallets->execute([$ingredientId]);
    $reservedPallets = $stmtPallets->fetchAll();

    $reservedStock = 0.0;
    $totalPalletValue = 0.0;
    foreach ($reservedPallets as $p) {
        $reservedStock += (float)$p['remaining_quantity'];
        $totalPalletValue += (float)$p['remaining_quantity'] * (float)$p['unit_cost'];
    }

    $totalStock = $activeStock + $reservedStock;
    $activeValue = $activeStock * (float)$ingredient['unit_cost'];
    $totalValue = $activeValue + $totalPalletValue;

    return [
        'ingredient'       => $ingredient,
        'active_stock'     => $activeStock,
        'active_exp'       => $activeExp,
        'reserved_stock'   => $reservedStock,
        'pallet_count'     => count($reservedPallets),
        'total_stock'      => $totalStock,
        'active_value'     => $activeValue,
        'total_value'      => $totalValue,
        'pallets'          => $reservedPallets
    ];
}

/**
 * Get expiration badge metadata for a given date.
 */
function getExpirationBadge($expirationDate) {
    if (empty($expirationDate)) {
        return [
            'label'     => 'No Expiry Set',
            'badge_cls' => 'badge-neutral',
            'days_left' => null,
            'status'    => 'none'
        ];
    }
    
    $today = new DateTime('today');
    $expDate = new DateTime($expirationDate);
    $interval = $today->diff($expDate);
    $days = (int)$interval->format('%r%a');

    if ($days < 0) {
        return [
            'label'     => 'Expired (' . abs($days) . 'd ago)',
            'badge_cls' => 'badge-expired',
            'days_left' => $days,
            'status'    => 'expired'
        ];
    } elseif ($days <= 7) {
        return [
            'label'     => "Expiring Soon ({$days}d)",
            'badge_cls' => 'badge-warning-custom',
            'days_left' => $days,
            'status'    => 'expiring_soon'
        ];
    } elseif ($days <= 30) {
        return [
            'label'     => "Good ({$days}d left)",
            'badge_cls' => 'badge-good',
            'days_left' => $days,
            'status'    => 'good'
        ];
    } else {
        return [
            'label'     => "Fresh ({$days}d left)",
            'badge_cls' => 'badge-fresh',
            'days_left' => $days,
            'status'    => 'fresh'
        ];
    }
}

/**
 * Get status badge info for a Pallet.
 */
function getPalletStatusBadge($status) {
    switch ($status) {
        case 'stored':
            return ['label' => 'Reserved (Stored)', 'class' => 'status-stored', 'icon' => '📦'];
        case 'in_use':
            return ['label' => 'Active (In-Use)', 'class' => 'status-inuse', 'icon' => '⚡'];
        case 'depleted':
            return ['label' => 'Depleted', 'class' => 'status-depleted', 'icon' => '✅'];
        case 'quarantined':
            return ['label' => 'Quarantined', 'class' => 'status-quarantine', 'icon' => '⚠️'];
        default:
            return ['label' => ucfirst($status), 'class' => 'status-stored', 'icon' => '📦'];
    }
}

/**
 * Get movement type display badge info.
 */
function getMovementTypeBadge($type) {
    switch ($type) {
        case 'pallet_in':
            return ['label' => 'Pallet In (Inbound)', 'class' => 'move-in', 'icon' => '📥'];
        case 'pallet_out':
            return ['label' => 'Pallet Out (Dispatched)', 'class' => 'move-out', 'icon' => '📤'];
        case 'relocation':
            return ['label' => 'Rack Relocation', 'class' => 'move-relocate', 'icon' => '🔄'];
        case 'adjustment':
            return ['label' => 'Pallet Adjustment', 'class' => 'move-adj', 'icon' => '⚖️'];
        default:
            return ['label' => ucfirst($type), 'class' => 'move-in', 'icon' => '📦'];
    }
}

/**
 * Get total number of active/stored pallets across all ingredients.
 */
function getActivePalletsCount($pdo) {
    $stmt = $pdo->query("SELECT COUNT(*) FROM pallets WHERE status IN ('stored', 'in_use') AND remaining_quantity > 0");
    return (int)$stmt->fetchColumn();
}

/**
 * Get total count of pallets expiring within 7 days.
 */
function getExpiringPalletsCount($pdo) {
    $stmt = $pdo->query("
        SELECT COUNT(*) FROM pallets 
        WHERE status IN ('stored', 'in_use') AND remaining_quantity > 0 
        AND expiration_date IS NOT NULL AND expiration_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ");
    return (int)$stmt->fetchColumn();
}

// ─── JSON Response Helper ─────────────────────────────────────

/**
 * Send a JSON response and exit.
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
