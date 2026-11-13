<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Sales';

// Fetch Active Master Products
$stmt = $pdo->query("SELECT * FROM products WHERE status = 'available' ORDER BY category, name");
$products = $stmt->fetchAll();

// Group products by category
$categories = [];
foreach ($products as $p) {
    $cat = $p['category'] ?: 'Uncategorized';
    $categories[$cat][] = $p;
}

// Fetch aggregate stock per ingredient name
$stmtStock = $pdo->query("
    SELECT ing_key, SUM(stock_qty) AS total_stock
    FROM (
        SELECT LOWER(TRIM(i.name)) AS ing_key,
               GREATEST(i.current_stock - COALESCE(tracked.tracked_stock, 0), 0) AS stock_qty
        FROM ingredients i
        LEFT JOIN (
            SELECT ingredient_id, SUM(remaining_quantity) AS tracked_stock
            FROM ingredient_stock_batches
            GROUP BY ingredient_id
        ) tracked ON tracked.ingredient_id = i.ingredient_id
        WHERE i.is_archived = 0
          AND (i.expiration_date IS NULL OR i.expiration_date > CURDATE())
        UNION ALL
        SELECT LOWER(TRIM(i.name)) AS ing_key, b.remaining_quantity AS stock_qty
        FROM ingredient_stock_batches b
        JOIN ingredients i ON i.ingredient_id = b.ingredient_id
        WHERE b.remaining_quantity > 0
          AND i.is_archived = 0
          AND (b.expiration_date IS NULL OR b.expiration_date > CURDATE())
    ) available_stock
    GROUP BY ing_key
");
$ingredientStocks = [];
while ($row = $stmtStock->fetch()) {
    $ingredientStocks[$row['ing_key']] = (float)$row['total_stock'];
}

// Fetch all recipes with ingredient details and units
$stmtRecipes = $pdo->query("
    SELECT ri.product_id, ri.quantity_per_serving, i.name as ingredient_name, i.unit
    FROM recipe_ingredients ri
    JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
");
$allRecipeRows = $stmtRecipes->fetchAll();

$productRecipes = [];
foreach ($allRecipeRows as $r) {
    $ingKey = strtolower(trim($r['ingredient_name']));
    $availStock = $ingredientStocks[$ingKey] ?? 0.0;
    $productRecipes[$r['product_id']][] = [
        'name' => $r['ingredient_name'],
        'qty' => (float)$r['quantity_per_serving'],
        'unit' => $r['unit'],
        'stock' => $availStock
    ];
}

require_once ROOT_PATH . '/includes/header.php';
?>

<!-- Custom CSS for Coffee POS layout -->
<style>
.pos-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 992px) {
    .pos-layout {
        grid-template-columns: 1fr;
    }
}
.pos-card {
    background: #FFFFFF;
    border-radius: 12px;
    border: none;
    box-shadow: 0 4px 15px rgba(60,36,21,0.04);
}
.pos-card-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid #F5EFE9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.pos-card-title {
    font-size: 1.1rem;
    font-weight: 700;
    color: #3C2415;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.pos-card-body {
    padding: 1.25rem 1.5rem;
}
.category-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 1.25rem;
}
.product-scroll-container {
    max-height: 520px;
    overflow-y: auto;
    padding-right: 6px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.product-scroll-container::-webkit-scrollbar {
    width: 6px;
}
.product-scroll-container::-webkit-scrollbar-track {
    background: #FAF7F2;
    border-radius: 4px;
}
.product-scroll-container::-webkit-scrollbar-thumb {
    background: #D2C6B9;
    border-radius: 4px;
}
.product-scroll-container::-webkit-scrollbar-thumb:hover {
    background: #B5A494;
}
.product-row-card {
    background: #FFFFFF;
    border: 1px solid #EAE3D9;
    border-radius: 10px;
    padding: 0.85rem 1.15rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: all 0.15s ease;
    box-shadow: 0 1px 3px rgba(60,36,21,0.02);
    width: 100%;
    box-sizing: border-box;
}
.product-row-card:hover {
    border-color: #C49A82;
    background: #FAF8F5;
}
.product-row-card.out-of-stock-item {
    background: #FAF8F5;
    border-color: #E2D9CF;
    opacity: 0.65;
}
.product-row-card.out-of-stock-item:hover {
    border-color: #E2D9CF;
    background: #FAF8F5;
}
.product-row-info {
    display: flex;
    flex-direction: column;
    min-width: 0;
}
.product-row-name {
    font-weight: 600;
    color: #3C2415;
    font-size: 0.95rem;
    line-height: 1.3;
}
.product-row-price {
    font-size: 0.85rem;
    color: #8C7867;
    font-weight: 500;
    margin-top: 3px;
}
.add-btn-square {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: #6B4423;
    color: #FFF;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    font-weight: 600;
    cursor: pointer;
    transition: background 0.15s, transform 0.1s;
    flex-shrink: 0;
}
.add-btn-square:hover:not(:disabled) {
    background: #503118;
    transform: scale(1.04);
}
.add-btn-square:active:not(:disabled) {
    transform: scale(0.96);
}
.add-btn-square:disabled {
    background: #D5CCC1 !important;
    color: #8C7867 !important;
    border: 1px solid #C4B9AD !important;
    cursor: not-allowed;
    opacity: 0.6;
}
.cart-badge {
    background: #3C2415;
    color: #FFF;
    font-size: 0.75rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 12px;
    margin-left: 6px;
}
.cart-item-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #F5EFE9;
}
.remove-cart-item {
    background: transparent;
    border: none;
    color: #C49A82;
    font-size: 1.1rem;
    cursor: pointer;
    padding: 0 4px;
}
.deduction-box {
    background: #FFF8F0;
    border: 1px solid #F5E5D3;
    border-radius: 10px;
    padding: 0.9rem 1.1rem;
    margin-bottom: 1rem;
}
.btn-confirm-sale {
    width: 100%;
    padding: 0.85rem;
    font-size: 1rem;
    font-weight: 700;
    border-radius: 10px;
    background: #3C2415;
    color: #FFF;
    border: none;
    cursor: pointer;
    transition: background 0.2s;
}
.btn-confirm-sale:hover:not(:disabled) {
    background: #5A3A28;
}
.btn-confirm-sale:disabled {
    background: #CCC5BD;
    cursor: not-allowed;
}
.category-filter-btn {
    flex: 0 0 auto;
    padding: 6px 14px;
    font-size: 0.82rem;
    font-weight: 600;
    border-radius: 8px;
    border: 1px solid #D2C6B9;
    background: #FAF7F2;
    color: #6E5343;
    cursor: pointer;
    transition: all 0.15s ease;

}


.category-filter-btn:hover {
    background: #EAE3D9;
    color: #3C2415;
}
.category-filter-btn.active {
    background: #3C2415;
    color: #FFF;
    border-color: #3C2415;
}

.pay-method-btn {
    transition: all 0.15s ease;
}
.pay-method-btn.active {
    background: #3C2415 !important;
    color: #FFF !important;
    border-color: #3C2415 !important;
}
</style>

<div class="pos-layout">
    <!-- LEFT PANEL: PRODUCT CATALOG -->
    <div class="pos-card">
        <div class="pos-card-header">
            <h3 class="pos-card-title">
                <span>☕</span> Products
            </h3>
            <span style="font-size: 0.82rem; color: #8C7867; font-weight: 500;">Select items to punch sale</span>
        </div>

        <div class="pos-card-body">
            <!-- Category Pills Filter -->
            <div class="category-pills" id="categoryPills">
                <button type="button" class="category-filter-btn active" data-category="all">All Items</button>
                <?php foreach (array_keys($categories) as $catName): ?>
                    <button type="button" class="category-filter-btn" data-category="<?= htmlspecialchars($catName) ?>">
                        <?= e($catName) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <!-- Products List (Single-Column Scrollable, No Category Headers) -->
            <div id="productCatalog" class="product-scroll-container">
                <?php foreach ($products as $prod): 
                    $pId = $prod['product_id'];
                    $catName = $prod['category'] ?: 'Uncategorized';
                    $recipe = $productRecipes[$pId] ?? [];
                    $maxServings = PHP_INT_MAX;
                    if (empty($recipe)) {
                        $maxServings = 0;
                    } else {
                        foreach ($recipe as $ing) {
                            if ($ing['qty'] > 0) {
                                $stockMilli = (int)round($ing['stock'] * 1000);
                                $quantityMilli = (int)round($ing['qty'] * 1000);
                                $servings = $quantityMilli > 0 ? intdiv($stockMilli, $quantityMilli) : 0;
                                if ($servings < $maxServings) {
                                    $maxServings = $servings;
                                }
                            }
                        }
                    }
                    if ($maxServings === PHP_INT_MAX) $maxServings = 0;
                    $isOutOfStock = ($maxServings <= 0);
                ?>
                    <div class="product-row-card <?= $isOutOfStock ? 'out-of-stock-item' : '' ?>" data-category="<?= htmlspecialchars($catName) ?>">
                        <div class="product-row-info">
                            <div class="product-row-name"><?= e($prod['name']) ?></div>
                            <div class="product-row-price">₱<?= number_format($prod['price'], 0) ?></div>
                            <?php if ($isOutOfStock): ?>
                                <span style="display: inline-block; background: #DC2626; color: #FFF; font-size: 0.65rem; font-weight: 700; padding: 1px 5px; border-radius: 4px; margin-top: 3px; width: fit-content;">OUT OF STOCK</span>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; align-items: center; gap: 6px;">
                            <button type="button" class="add-btn-square" title="Decrease quantity in cart" onclick="removeFromCartByProductId(<?= $prod['product_id'] ?>)">
                                −
                            </button>
                            <button type="button" class="add-btn-square" title="<?= $isOutOfStock ? 'Out of stock' : 'Add to cart' ?>" onclick="addToCart(<?= $prod['product_id'] ?>, '<?= addslashes($prod['name']) ?>', <?= $prod['price'] ?>, <?= $maxServings ?>)" <?= $isOutOfStock ? 'disabled' : '' ?>>
                                +
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($products)): ?>
                    <div class="text-center p-4" style="color: #A08D7D;">No available products found.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: CART -->
    <div class="pos-card">
        <div class="pos-card-header">
            <h3 class="pos-card-title">
                <span>🛒</span> Cart <span class="cart-badge" id="cart-badge">0</span>
            </h3>
            <button type="button" class="btn btn-sm btn-link text-danger p-0" id="clear-cart-btn" style="display:none; text-decoration:none; font-size:0.85rem; font-weight:600;">Clear Cart</button>
        </div>

        <div class="pos-card-body">
            <!-- Cart Items List -->
            <div id="cart-items-list" style="min-height: 100px; max-height: 220px; overflow-y: auto; margin-bottom: 0.75rem;">
                <div class="text-center p-4" style="color: #A08D7D; font-size: 0.9rem;">Cart is empty</div>
            </div>

            <hr style="border-color: #F5EFE9; margin: 0.75rem 0 1rem;">

            <!-- Summary Lines -->
            <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; color: #7A6654; margin-bottom: 0.5rem;">
                <span>Total Items</span>
                <span id="cart-items-count" style="font-weight: 600; color: #3C2415;">0 items</span>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.15rem;">
                <span style="font-size: 1.1rem; font-weight: 700; color: #3C2415;">Total</span>
                <span id="cart-total-price" style="font-size: 1.8rem; font-weight: 800; color: #3C2415;">₱0</span>
            </div>

            <!-- PAYMENT METHOD & CASH/DIGITAL RECEIVED SECTION -->
            <div style="background: #FAF7F2; border: 1px solid #EAE3D9; border-radius: 10px; padding: 0.85rem 1rem; margin-bottom: 1.15rem;">
                <div style="font-weight: 700; color: #6E5343; font-size: 0.8rem; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.4px;">
                    💳 Payment Method
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; margin-bottom: 10px;">
                    <button type="button" id="pay-cash-btn" class="pay-method-btn active" onclick="selectPaymentMethod('cash')" style="padding: 6px 4px; font-size: 0.82rem; font-weight: 700; border-radius: 6px; border: 1px solid #ceacac; background: #3C2415; color: #FFF; cursor: pointer;">
                        💵 Cash
                    </button>
                    <button type="button" id="pay-gcash-btn" class="pay-method-btn" onclick="selectPaymentMethod('gcash')" style="padding: 6px 4px; font-size: 0.82rem; font-weight: 700; border-radius: 6px; border: 1px solid #D2C6B9; background: #FFF; color: #3C2415; cursor: pointer;">
                        📱 GCash
                    </button>
                    <button type="button" id="pay-maya-btn" class="pay-method-btn" onclick="selectPaymentMethod('maya')" style="padding: 6px 4px; font-size: 0.82rem; font-weight: 700; border-radius: 6px; border: 1px solid #D2C6B9; background: #FFF; color: #3C2415; cursor: pointer;">
                        🟢 Maya
                    </button>
                </div>

                <!-- Received Amount & Change Calculator (For All Methods) -->
                <div>
                    <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 6px;">
                        <label id="received-amount-label" style="font-size: 0.82rem; font-weight: 600; color: #5A3A28; margin: 0; min-width: 125px;">Cash Received (₱):</label>
                        <input type="number" id="cash-tendered-input" step="1" min="0" placeholder="0" class="form-control" style="height: 36px; font-size: 0.95rem; font-weight: 700; text-align: right; border-radius: 6px; border: 1px solid #D2C6B9;" oninput="calculateChange()">
                    </div>
                    <!-- Quick Amount Denominations -->
                    <div style="display: flex; gap: 4px; justify-content: flex-end; margin-bottom: 6px;">
                        <button type="button" class="btn btn-sm" onclick="setExactCash()" style="font-size: 0.72rem; padding: 2px 6px; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px; color: #6E5343;">Exact</button>
                        <button type="button" class="btn btn-sm" onclick="addTendered(100)" style="font-size: 0.72rem; padding: 2px 6px; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px; color: #6E5343;">+₱100</button>
                        <button type="button" class="btn btn-sm" onclick="addTendered(500)" style="font-size: 0.72rem; padding: 2px 6px; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px; color: #6E5343;">+₱500</button>
                        <button type="button" class="btn btn-sm" onclick="addTendered(1000)" style="font-size: 0.72rem; padding: 2px 6px; border: 1px solid #D2C6B9; background: #FFF; border-radius: 4px; color: #6E5343;">+₱1000</button>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #FFF; border: 1px solid #EAE3D9; border-radius: 6px; padding: 6px 10px; margin-bottom: 6px;">
                        <span id="change-due-label" style="font-size: 0.82rem; font-weight: 600; color: #6E5343;">Change Due:</span>
                        <strong id="cash-change-display" style="font-size: 1.05rem; color: #059669;">₱0.00</strong>
                    </div>

                    <!-- Digital Wallet Reference Number -->
                    <div id="digital-ref-container" style="display: none; margin-top: 8px;">
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <label style="font-size: 0.8rem; font-weight: 600; color: #5A3A28; margin: 0; min-width: 125px;">Ref # (Optional):</label>
                            <input type="text" id="payment-reference-input" placeholder="Optional reference / trace #" class="form-control" style="height: 34px; font-size: 0.85rem; border-radius: 6px; border: 1px solid #D2C6B9;">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ingredients to be Deducted Box -->
            <div id="deductions-box" class="deduction-box" style="display: none;">
                <div style="font-weight: 700; color: #8C532B; font-size: 0.85rem; margin-bottom: 0.4rem; display: flex; align-items: center; gap: 6px;">
                    <span>🧪</span> Ingredients to be deducted:
                </div>
                <ul id="deductions-ul" style="margin: 0; padding-left: 1.2rem; font-size: 0.85rem; color: #5A3A28; line-height: 1.5;"></ul>
            </div>

            <!-- Confirm Sale Button -->
            <button type="button" class="btn-confirm-sale" id="confirm-sale-btn" disabled onclick="submitSale()">
                Confirm Sale & Record
            </button>
        </div>
    </div>
</div>

<!-- Sale Success Modal -->
<div class="modal-overlay" id="saleSuccessModal">
    <div class="modal text-center" style="max-width: 440px; border-radius: 16px; background: #FFF; padding: 2.5rem 2rem; box-shadow: 0 10px 30px rgba(0,0,0,0.15); box-sizing: border-box;">
        <div style="margin: 0 auto 1.5rem auto; width: 64px; height: 64px; background: #E6F4EA; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #137333;">
            <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>
        
        <h3 style="color: #3C2415; font-weight: 700; margin: 0 0 0.75rem 0; font-size: 1.35rem;">Sales Recorded Successfully!</h3>
        <p class="text-muted" id="successModalText" style="font-size: 0.9rem; margin: 0 0 2rem 0; line-height: 1.5;">Automatic Inventory Deduction (AID) completed.</p>
        
        <div style="display: flex; gap: 12px; justify-content: center; width: 100%;">
            <a href="<?= BASE_URL ?>/<?= (isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin') ? 'admin' : 'staff' ?>/dashboard.php" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; background: #3C2415; color: #FFF; border: none; border-radius: 8px; font-weight: 600; font-size: 0.9rem; text-decoration: none; height: 42px; transition: background 0.15s;">Return to Dashboard</a>
            <button type="button" id="new-sale-btn" style="flex: 1; display: inline-flex; align-items: center; justify-content: center; background: #FAF7F2; color: #3C2415; border: 1px solid #D2C6B9; border-radius: 8px; font-weight: 600; font-size: 0.9rem; height: 42px; cursor: pointer; transition: background 0.15s;">Record Another Sale</button>
        </div>
    </div>
</div>

<form id="saleForm" style="display:none;">
    <?= csrfField() ?>
</form>

<?php
$productRecipesJson = json_encode($productRecipes);

$extraJS = <<<HTML
<script>
let cart = [];
const productRecipes = $productRecipesJson;
let selectedPaymentMethod = 'cash';
let currentCartTotal = 0;
let isCustomTendered = false;

document.addEventListener('DOMContentLoaded', function() {
    const categoryButtons = document.querySelectorAll('.category-filter-btn');
    const productCards = document.querySelectorAll('.product-row-card');

    categoryButtons.forEach(button => {
        button.addEventListener('click', function() {
            categoryButtons.forEach(btn => btn.classList.remove('active'));
            this.classList.add('active');

            const selectedCategory = this.getAttribute('data-category');

            productCards.forEach(card => {
                if (selectedCategory === 'all' || card.getAttribute('data-category') === selectedCategory) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    const tenderedInput = document.getElementById('cash-tendered-input');
    if (tenderedInput) {
        tenderedInput.addEventListener('input', function() {
            isCustomTendered = true;
            calculateChange();
        });
    }

    document.getElementById('clear-cart-btn').addEventListener('click', function() {
        cart = [];
        isCustomTendered = false;
        renderCart();
    });

    document.getElementById('new-sale-btn').addEventListener('click', function() {
        document.getElementById('saleSuccessModal').classList.remove('active');
        isCustomTendered = false;
        document.getElementById('cash-tendered-input').value = '';
        document.getElementById('payment-reference-input').value = '';
        document.getElementById('cash-change-display').textContent = '₱0.00';
    });
});

function selectPaymentMethod(method) {
    selectedPaymentMethod = method;
    document.querySelectorAll('.pay-method-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.background = '#FFF';
        btn.style.color = '#3C2415';
        btn.style.borderColor = '#D2C6B9';
    });

    const activeBtn = document.getElementById('pay-' + method + '-btn');
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.background = '3c2415#';
        activeBtn.style.color = '#FFF';
        activeBtn.style.borderColor = '#3C2415';
    }

    const label = document.getElementById('received-amount-label');
    const changeLabel = document.getElementById('change-due-label');
    const refContainer = document.getElementById('digital-ref-container');

    if (method === 'cash') {
        if (label) label.textContent = 'Cash Received (₱):';
        if (changeLabel) changeLabel.textContent = 'Change Due:';
        if (refContainer) refContainer.style.display = 'none';
    } else if (method === 'gcash') {
        if (label) label.textContent = 'GCash Received (₱):';
        if (changeLabel) changeLabel.textContent = 'Cash to Return:';
        if (refContainer) refContainer.style.display = 'block';
    } else if (method === 'maya') {
        if (label) label.textContent = 'Maya Received (₱):';
        if (changeLabel) changeLabel.textContent = 'Cash to Return:';
        if (refContainer) refContainer.style.display = 'block';
    }

    calculateChange();
}

function calculateChange() {
    const input = document.getElementById('cash-tendered-input');
    const changeDisplay = document.getElementById('cash-change-display');
    const tendered = parseFloat(input.value) || 0;
    
    if (tendered >= currentCartTotal && currentCartTotal > 0) {
        const change = tendered - currentCartTotal;
        changeDisplay.textContent = '₱' + change.toFixed(2);
        changeDisplay.style.color = '#059669';
    } else if (currentCartTotal > 0 && tendered > 0) {
        const short = currentCartTotal - tendered;
        changeDisplay.textContent = '-₱' + short.toFixed(2) + ' (Short)';
        changeDisplay.style.color = '#DC2626';
    } else {
        changeDisplay.textContent = '₱0.00';
        changeDisplay.style.color = '#059669';
    }
}

function setExactCash() {
    isCustomTendered = false;
    document.getElementById('cash-tendered-input').value = currentCartTotal;
    calculateChange();
}

function addTendered(amount) {
    isCustomTendered = true;
    const input = document.getElementById('cash-tendered-input');
    let curr = parseFloat(input.value) || 0;
    input.value = curr + amount;
    calculateChange();
}

function addToCart(productId, name, price, maxServings) {
    if (maxServings <= 0) {
        alert('"' + name + '" is OUT OF STOCK and cannot be added.');
        return;
    }
    const existingIndex = cart.findIndex(item => item.id === productId);
    const currentQty = existingIndex > -1 ? cart[existingIndex].quantity : 0;
    if (currentQty + 1 > maxServings) {
        alert('Cannot add more "' + name + '". Only ' + maxServings + ' serving(s) available based on ingredient stock.');
        return;
    }
    if (existingIndex > -1) {
        cart[existingIndex].quantity += 1;
    } else {
        cart.push({ id: productId, name: name, price: price, quantity: 1, maxServings: maxServings });
    }
    renderCart();
}

function removeFromCartByProductId(productId) {
    const existingIndex = cart.findIndex(item => item.id === productId);
    if (existingIndex > -1) {
        if (cart[existingIndex].quantity > 1) {
            cart[existingIndex].quantity -= 1;
        } else {
            cart.splice(existingIndex, 1);
        }
        renderCart();
    }
}

function updateCartQuantity(index, delta) {
    if (!cart[index]) return;
    if (delta > 0 && (cart[index].quantity + delta) > cart[index].maxServings) {
        alert('Cannot add more "' + cart[index].name + '". Maximum available stock reached (' + cart[index].maxServings + ' servings).');
        return;
    }
    cart[index].quantity += delta;
    if (cart[index].quantity <= 0) {
        cart.splice(index, 1);
    }
    renderCart();
}

function removeCartItem(index) {
    if (!cart[index]) return;
    cart.splice(index, 1);
    renderCart();
}

function renderCart() {
    const cartContainer = document.getElementById('cart-items-list');
    const badge = document.getElementById('cart-badge');
    const itemsCountEl = document.getElementById('cart-items-count');
    const totalPriceEl = document.getElementById('cart-total-price');
    const confirmBtn = document.getElementById('confirm-sale-btn');
    const clearBtn = document.getElementById('clear-cart-btn');
    const deductionsBox = document.getElementById('deductions-box');
    const deductionsUl = document.getElementById('deductions-ul');

    if (cart.length === 0) {
        cartContainer.innerHTML = '<div class="text-center p-4" style="color: #A08D7D; font-size: 0.9rem;">Cart is empty</div>';
        badge.textContent = '0';
        itemsCountEl.textContent = '0 items';
        totalPriceEl.textContent = '₱0';
        currentCartTotal = 0;
        isCustomTendered = false;
        confirmBtn.disabled = true;
        clearBtn.style.display = 'none';
        deductionsBox.style.display = 'none';
        const tenderedInput = document.getElementById('cash-tendered-input');
        if (tenderedInput) tenderedInput.value = '';
        calculateChange();
        return;
    }

    clearBtn.style.display = 'inline-block';
    let html = '';
    let itemsTotal = 0;
    let itemsCount = 0;
    let ingredientMap = {};

    cart.forEach((item, index) => {
        const subtotal = item.price * item.quantity;
        itemsTotal += subtotal;
        itemsCount += item.quantity;

        const recipe = productRecipes[item.id] || [];
        recipe.forEach(ing => {
            const key = ing.name;
            const unit = ing.unit;
            const totalUsed = ing.qty * item.quantity;
            const mapKey = key + '|' + unit;
            if (!ingredientMap[mapKey]) {
                ingredientMap[mapKey] = { name: key, unit: unit, qty: 0 };
            }
            ingredientMap[mapKey].qty += totalUsed;
        });

        html += `
            <div class="cart-item-row" style="display: flex; justify-content: space-between; align-items: center; padding: 9px 12px; background: #FAF7F2; border-radius: 10px; margin-bottom: 8px; border: 1px solid #EAE3D9;">
                <div style="flex: 1; min-width: 0; padding-right: 8px;">
                    <div style="font-weight: 700; color: #3C2415; font-size: 0.88rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">\${item.name}</div>
                    <div style="font-size: 0.78rem; color: #8C7867; margin-top: 2px;">₱\${parseFloat(item.price).toFixed(2)}</div>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="display: inline-flex; align-items: center; background: #FFFFFF; border: 1px solid #D2C6B9; border-radius: 6px; overflow: hidden; height: 28px;">
                        <button type="button" onclick="updateCartQuantity(\${index}, -1)" title="Decrease Quantity" style="width: 26px; height: 100%; border: none; background: transparent; color: #3C2415; font-weight: 800; font-size: 0.95rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s;">−</button>
                        <span style="padding: 0 6px; font-weight: 700; color: #3C2415; font-size: 0.85rem; min-width: 20px; text-align: center;">\${item.quantity}</span>
                        <button type="button" onclick="updateCartQuantity(\${index}, 1)" title="Increase Quantity" style="width: 26px; height: 100%; border: none; background: transparent; color: #3C2415; font-weight: 800; font-size: 0.95rem; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: background 0.15s;">+</button>
                    </div>
                    <span style="font-weight: 700; color: #3C2415; font-size: 0.9rem; min-width: 55px; text-align: right;">₱\${subtotal.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</span>
                    <button type="button" class="remove-cart-item" title="Remove Entire Item" onclick="removeCartItem(\${index})" style="background: none; border: none; color: #C53030; font-size: 0.95rem; cursor: pointer; padding: 2px 4px; line-height: 1;">✕</button>
                </div>
            </div>
        `;
    });

    cartContainer.innerHTML = html;

    currentCartTotal = itemsTotal;
    badge.textContent = itemsCount;
    itemsCountEl.textContent = `\${itemsCount} items`;
    totalPriceEl.textContent = `₱\${currentCartTotal.toLocaleString()}`;
    confirmBtn.disabled = false;

    // Calculate change if amount was entered
    calculateChange();

    const deductionKeys = Object.keys(ingredientMap);
    if (deductionKeys.length > 0) {
        let dedHtml = '';
        deductionKeys.forEach(k => {
            const item = ingredientMap[k];
            dedHtml += `<li><strong>\${item.name}:</strong> \${item.qty.toFixed(1)}\${item.unit}</li>`;
        });
        deductionsUl.innerHTML = dedHtml;
        deductionsBox.style.display = 'block';
    } else {
        deductionsBox.style.display = 'none';
    }
}

async function submitSale() {
    if (cart.length === 0) return;

    const tenderedInput = document.getElementById('cash-tendered-input');
    const refInput = document.getElementById('payment-reference-input');
    const rawVal = tenderedInput ? tenderedInput.value.trim() : '';
    let tenderedVal = parseFloat(rawVal) || 0;

    if (selectedPaymentMethod === 'cash') {
        if (rawVal === '' || tenderedVal <= 0) {
            alert('Please enter the Cash Received from customer (or click "Exact").');
            tenderedInput.focus();
            return;
        } else if (tenderedVal < currentCartTotal) {
            alert('Cash received (₱' + tenderedVal.toFixed(2) + ') is less than total amount (₱' + currentCartTotal.toFixed(2) + ').');
            tenderedInput.focus();
            return;
        }
    } else {
        // Digital wallet (GCash / Maya) defaults to exact total if left empty
        if (rawVal === '' || tenderedVal <= 0) {
            tenderedVal = currentCartTotal;
        } else if (tenderedVal < currentCartTotal) {
            alert('Amount received (₱' + tenderedVal.toFixed(2) + ') is less than total amount (₱' + currentCartTotal.toFixed(2) + ').');
            tenderedInput.focus();
            return;
        }
    }

    const btn = document.getElementById('confirm-sale-btn');
    btn.disabled = true;
    btn.textContent = 'Processing Sale...';

    const csrfToken = document.querySelector('input[name="csrf_token"]').value;

    try {
        const response = await fetch('../api/process-sale.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({ 
                items: cart,
                payment_method: selectedPaymentMethod,
                amount_tendered: (selectedPaymentMethod === 'cash') ? tenderedVal : currentCartTotal,
                payment_reference: refInput ? refInput.value.trim() : ''
            })
        });

        const result = await response.json();
        
        if (result.success) {
            const paidMethodName = (selectedPaymentMethod === 'cash' ? '💵 Cash' : (selectedPaymentMethod === 'gcash' ? '📱 GCash' : '🟢 Maya'));
            const changeVal = (selectedPaymentMethod === 'cash') ? (tenderedVal - currentCartTotal) : 0;

            cart = [];
            renderCart();
            
            let detailText = `<strong>Sale #\${result.transaction_id}</strong> — Total: <strong>₱\${parseFloat(result.total_amount).toFixed(2)}</strong><br>`;
            detailText += `<span style="display:inline-block; margin-top:6px; background:#FAF7F2; border:1px solid #EAE3D9; padding:4px 10px; border-radius:6px; font-size:0.85rem;">`;
            detailText += `Payment: <strong>\${paidMethodName}</strong>`;
            if (selectedPaymentMethod === 'cash' && changeVal > 0) {
                detailText += ` (Change: <strong>₱\${changeVal.toFixed(2)}</strong>)`;
            }
            detailText += `</span>`;

            if (result.low_stock_alerts && result.low_stock_alerts.length > 0) {
                detailText += `<br><br><strong class="text-danger">⚠️ Low Stock Warning for:</strong> \${result.low_stock_alerts.join(', ')}`;
            }
            document.getElementById('successModalText').innerHTML = detailText;
            document.getElementById('saleSuccessModal').classList.add('active');
        } else {
            alert('Error: ' + (result.message || 'Failed to process sale.'));
        }
    } catch (err) {
        console.error(err);
        alert('An error occurred while processing the sale.');
    } finally {
        btn.textContent = 'Confirm Sale & Record';
        btn.disabled = cart.length === 0;
    }
}
</script>
HTML;

require_once ROOT_PATH . '/includes/footer.php'; 
?>
