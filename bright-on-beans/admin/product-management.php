<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();
ensureIngredientStockBatchSchema($pdo);

$pageTitle = 'Product Management';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $id = $_POST['product_id'];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("DELETE FROM recipe_ingredients WHERE product_id = ?");
            $stmt->execute([$id]);
            $stmt = $pdo->prepare("DELETE FROM products WHERE product_id = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            setFlashMessage('success', 'Product deleted successfully.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Error deleting product. It may be linked to past sales.');
        }
        header('Location: ' . BASE_URL . '/admin/product-management.php');
        exit;
    }
    
    if ($action === 'save') {
        $id = $_POST['product_id'] ?? null;
        $name = $_POST['name'];
        $category = $_POST['category'];
        $price = $_POST['price'];
        $status = $_POST['status'];
        
        $ingredientIds = $_POST['ingredient_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        $units = $_POST['unit'] ?? [];
        
        try {
            $pdo->beginTransaction();
            if (empty($id)) {
                $stmt = $pdo->prepare("INSERT INTO products (name, category, price, status) VALUES (?, ?, ?, ?)");
                $stmt->execute([$name, $category, $price, $status]);
                $id = $pdo->lastInsertId();
            } else {
                $stmt = $pdo->prepare("UPDATE products SET name = ?, category = ?, price = ?, status = ? WHERE product_id = ?");
                $stmt->execute([$name, $category, $price, $status, $id]);
                // Delete old recipes
                $stmt = $pdo->prepare("DELETE FROM recipe_ingredients WHERE product_id = ?");
                $stmt->execute([$id]);
            }
            
            // Insert new recipe
            if (!empty($ingredientIds)) {
                $stmtInsert = $pdo->prepare("INSERT INTO recipe_ingredients (product_id, ingredient_id, quantity_per_serving, unit) VALUES (?, ?, ?, ?)");
                for ($i = 0; $i < count($ingredientIds); $i++) {
                    if (!empty($ingredientIds[$i]) && !empty($quantities[$i]) && !empty($units[$i])) {
                        $stmtInsert->execute([$id, $ingredientIds[$i], $quantities[$i], $units[$i]]);
                    }
                }
            }
            
            $pdo->commit();
            setFlashMessage('success', 'Product saved successfully.');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlashMessage('error', 'Failed to save product.');
        }
        header('Location: ' . BASE_URL . '/admin/product-management.php');
        exit;
    }
}

$stmt = $pdo->query("
    SELECT 
        p.*, 
        (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.product_id = p.product_id) as ingredients_count,
        COALESCE(
            (SELECT SUM(ri.quantity_per_serving * i.unit_cost) 
             FROM recipe_ingredients ri 
             JOIN ingredients i ON ri.ingredient_id = i.ingredient_id 
             WHERE ri.product_id = p.product_id), 
            0.00
        ) as recipe_cost
    FROM products p 
    ORDER BY p.category, p.name
");
$products = $stmt->fetchAll();

$stmtAllIngs = $pdo->query("SELECT * FROM ingredients ORDER BY name ASC, CASE WHEN expiration_date IS NULL THEN 1 ELSE 0 END, expiration_date ASC, ingredient_id ASC");
$allIngredientsList = $stmtAllIngs->fetchAll();

$ingredientLookup = [];
$uniqueIngredients = [];
$seenNames = [];

foreach ($allIngredientsList as $ing) {
    $ingredientLookup[$ing['ingredient_id']] = $ing['name'];
    if ((int)$ing['is_archived'] === 1) {
        continue;
    }
    
    $nameLower = strtolower(trim($ing['name']));
    if (!in_array($nameLower, $seenNames)) {
        $seenNames[] = $nameLower;
        $uniqueIngredients[] = $ing;
    }
}

$ingredients = $uniqueIngredients;

$stmt = $pdo->query("
    SELECT ri.*, i.unit_cost 
    FROM recipe_ingredients ri 
    JOIN ingredients i ON ri.ingredient_id = i.ingredient_id
");
$allRecipes = $stmt->fetchAll();
$recipesByProduct = [];
foreach ($allRecipes as $r) {
    $costPerServing = $r['quantity_per_serving'] * $r['unit_cost'];
    $recipesByProduct[$r['product_id']][] = [
        'ingredient_id' => $r['ingredient_id'],
        'ingredient_name' => $ingredientLookup[$r['ingredient_id']] ?? 'Unknown',
        'quantity_per_serving' => $r['quantity_per_serving'],
        'unit' => $r['unit'],
        'unit_cost' => $r['unit_cost'],
        'cost_per_serving' => $costPerServing
    ];
}

require_once ROOT_PATH . '/includes/header.php';
?>

<?php renderFlashMessage(); ?>

<div class="card" style="border-radius: 14px; border: 1px solid #EAE3D9; box-shadow: 0 2px 10px rgba(60,36,21,0.03);">
    <div class="card-body" style="padding: 1.5rem;">
        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap" style="gap: 1rem;">
            <input type="text" id="search-input" class="form-control mb-0" placeholder="Search product name or category..." style="max-width: 380px; padding: 0.65rem 1rem; border-radius: 10px; border: 1px solid #E2D9CF; background: #FAF7F2; font-size: 0.9rem;">
            <button type="button" class="btn" id="add-product-btn" style="background: #3C2415; border: none; border-radius: 8px; font-weight: 700; padding: 0.65rem 1.35rem; color: #FFF; margin-left: auto; box-shadow: 0 2px 6px rgba(60,36,21,0.15);">
                + Add New Product
            </button>
        </div>
        <div class="table-wrapper">
            <table class="table align-middle" id="products-table">
                <thead>
                    <tr>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">PRODUCT NAME</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">CATEGORY</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">COST TO MAKE</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">SELLING PRICE</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">EST. PROFIT</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">STATUS</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px;">RECIPE INGREDIENTS</th>
                        <th style="font-weight: 700; color: #8C6D58; font-size: 0.8rem; letter-spacing: 0.5px; text-align: center;">ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($products as $prod): 
                        $estProfit = $prod['price'] - $prod['recipe_cost'];
                    ?>
                    <tr>
                        <td class="searchable"><strong style="color: #3C2415; font-size: 0.95rem;"><?= e($prod['name']) ?></strong></td>
                        <td class="searchable" style="color: #6E5C4F; font-size: 0.9rem;"><?= e($prod['category']) ?></td>
                        <td style="color: #C0392B; font-weight: 600;">₱<?= number_format($prod['recipe_cost'], 2) ?></td>
                        <td style="font-weight: 700; color: #3C2415;">₱<?= number_format($prod['price'], 2) ?></td>
                        <td style="font-weight: 700; color: #27AE60;">₱<?= number_format($estProfit, 2) ?></td>
                        <td>
                            <span class="badge <?= $prod['status'] === 'available' ? 'badge-success' : 'badge-danger' ?>" style="padding: 5px 12px; border-radius: 12px; font-weight: 600; font-size: 0.75rem;">
                                <?= strtoupper(e($prod['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge" style="background: #FAF3EC; color: #7A5338; border: 1px solid #EFE4D8; padding: 5px 10px; border-radius: 8px; font-weight: 600; font-size: 0.8rem;">
                                <?= e($prod['ingredients_count']) ?> INGREDIENT(S)
                            </span>
                        </td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 8px; align-items: center; justify-content: center;">
                                <button type="button" class="btn btn-sm view-product-btn" data-product="<?= htmlspecialchars(json_encode($prod)) ?>" style="padding: 0.35rem 0.8rem; border-radius: 6px; font-weight: 600; background: #FFF; border: 1px solid #D4A574; color: #5A3A28; font-size: 0.82rem;">
                                    View
                                </button>
                                <button type="button" class="btn btn-sm edit-btn" data-product="<?= htmlspecialchars(json_encode($prod)) ?>" style="padding: 0.35rem 0.8rem; border-radius: 6px; font-weight: 700; background: #3C2415; color: #FFF; border: none; font-size: 0.82rem;">
                                    Edit
                                </button>
                                <form method="POST" class="d-inline" style="margin:0;" onsubmit="return confirm('Delete this product?');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="product_id" value="<?= e($prod['product_id']) ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 0.35rem 0.8rem; border-radius: 6px; font-weight: 600; font-size: 0.82rem;">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr id="no-products-row"><td colspan="6" class="text-center p-4" style="color: #A08D7D;">No products found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal-overlay" id="productModal">
    <div class="modal modal-lg" style="border-radius: 14px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="modalTitle" style="margin: 0; padding-right: 2rem; color: #3C2415; font-weight: 700;">Add Product</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 1rem; right: 1.25rem;">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" action="">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="product_id" id="modal_id">
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="form-label">Product Name</label>
                        <input type="text" name="name" id="modal_name" class="form-control" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="form-label">Category</label>
                        <input type="text" name="category" id="modal_category" class="form-control" list="category_list" placeholder="Select or type category..." required autocomplete="off">
                        <datalist id="category_list">
                            <?php foreach (getCategories($pdo) as $cat): ?>
                            <option value="<?= e($cat) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="form-label">Price (₱)</label>
                        <input type="number" name="price" id="modal_price" class="form-control" step="0.01" required>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" id="modal_status" class="form-control">
                            <option value="available">Available</option>
                            <option value="unavailable">Unavailable</option>
                        </select>
                    </div>
                </div>

                <hr>
                <h4 style="font-size: 1rem; font-weight: 700; color: #3C2415;" class="mb-3">Add Ingredients Per Serving</h4>
                <div id="recipe-container"></div>
                <div class="mt-2">
                    <button type="button" class="btn btn-sm" id="add-recipe-row" style="background: #5A3A28; color: #FFF; border-radius: 8px; font-weight: 600;">+ Add Ingredient</button>
                </div>

                <div class="form-actions mt-4" style="display: flex; justify-content: flex-end; align-items: center; gap: 10px; width: 100%;">
                    <button type="button" class="btn btn-secondary close-modal" style="border-radius: 8px; padding: 0.55rem 1.25rem; font-weight: 600;">Cancel</button>
                    <button type="submit" class="btn btn-primary" style="background: #3C2415; border: none; border-radius: 8px; font-weight: 700; padding: 0.55rem 1.4rem;">Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Product Details Modal -->
<div class="modal-overlay" id="viewProductModal">
    <div class="modal" style="border-radius: 14px; max-width: 550px;">
        <div class="modal-header" style="position: relative; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="viewModalTitle" style="margin: 0; padding-right: 2rem; color: #3C2415; font-weight: 700;">Product Details</h3>
            <button type="button" class="close-modal" style="position: absolute; top: 1rem; right: 1.25rem;">&times;</button>
        </div>
        <div class="modal-body">
            <div id="viewModalContent"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary close-modal" style="border-radius: 8px;">Close</button>
        </div>
    </div>
</div>

<?php 
$jsonRecipes = json_encode($recipesByProduct);
$jsonIngredients = json_encode($ingredients);
$jsonUnits = json_encode(getUnits());

$extraJS = <<<HTML
<script>
const recipesByProduct = {$jsonRecipes};
const allIngredients = {$jsonIngredients};
const availableUnits = {$jsonUnits};

document.addEventListener('DOMContentLoaded', function() {
    const productModal = document.getElementById('productModal');
    const viewModal = document.getElementById('viewProductModal');
    
    // Search
    const searchInput = document.getElementById('search-input');
    if (searchInput) {
        searchInput.addEventListener('keyup', function() {
            const val = this.value.toLowerCase();
            let hasMatch = false;
            document.querySelectorAll('#products-table tbody tr:not(#no-products-row)').forEach(tr => {
                const text = tr.textContent.toLowerCase();
                if (text.includes(val)) {
                    tr.style.display = '';
                    hasMatch = true;
                } else {
                    tr.style.display = 'none';
                }
            });
            const noRow = document.getElementById('no-products-row');
            if (noRow) noRow.style.display = hasMatch ? 'none' : '';
        });
    }

    // Add Product
    document.getElementById('add-product-btn').addEventListener('click', function() {
        document.getElementById('modalTitle').textContent = 'Add New Product';
        document.getElementById('modal_id').value = '';
        document.getElementById('modal_name').value = '';
        document.getElementById('modal_category').value = '';
        document.getElementById('modal_price').value = '';
        document.getElementById('modal_status').value = 'available';
        document.getElementById('recipe-container').innerHTML = '';
        addRecipeRow();
        productModal.classList.add('active');
    });

    // Edit Product
    document.querySelectorAll('.edit-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const p = JSON.parse(this.dataset.product);
            document.getElementById('modalTitle').textContent = 'Edit Product: ' + p.name;
            document.getElementById('modal_id').value = p.product_id;
            document.getElementById('modal_name').value = p.name;
            document.getElementById('modal_category').value = p.category;
            document.getElementById('modal_price').value = p.price;
            document.getElementById('modal_status').value = p.status;
            
            const container = document.getElementById('recipe-container');
            container.innerHTML = '';
            
            const recipes = recipesByProduct[p.product_id] || [];
            if (recipes.length > 0) {
                recipes.forEach(r => {
                    addRecipeRow(r.ingredient_id, r.quantity_per_serving, r.unit);
                });
            } else {
                addRecipeRow();
            }
            
            productModal.classList.add('active');
        });
    });

    // View Details Modal
    document.querySelectorAll('.view-product-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const p = JSON.parse(this.dataset.product);
            document.getElementById('viewModalTitle').textContent = p.name + ' Details';
            
            const recipes = recipesByProduct[p.product_id] || [];
            let recipeHtml = '';
            if (recipes.length > 0) {
                recipeHtml = `
                    <table class="table table-bordered table-sm mt-3">
                        <thead style="background:#F5EFE9;">
                            <tr>
                                <th>Ingredient</th>
                                <th>Quantity</th>
                                <th>Price / Unit</th>
                                <th>Total Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                `;
                recipes.forEach(r => {
                    recipeHtml += `
                        <tr>
                            <td>\${r.ingredient_name}</td>
                            <td>\${r.quantity_per_serving} \${r.unit}</td>
                            <td>₱\${parseFloat(r.unit_cost).toFixed(2)}</td>
                            <td><strong>₱\${parseFloat(r.cost_per_serving).toFixed(2)}</strong></td>
                        </tr>
                    `;
                });
                recipeHtml += '</tbody></table>';
            } else {
                recipeHtml = '<p class="text-muted mt-2">No recipe ingredients added for this product.</p>';
            }
            
            const costToMake = parseFloat(p.recipe_cost || 0);
            const sellingPrice = parseFloat(p.price || 0);
            const estProfit = sellingPrice - costToMake;
            
            document.getElementById('viewModalContent').innerHTML = `
                <div class="mb-3" style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; background: #FAF7F2; padding: 12px; border-radius: 10px; border: 1px solid #EAE3D9;">
                    <div style="font-size:0.9rem;"><strong>Category:</strong> \${p.category}</div>
                    <div style="font-size:0.9rem;"><strong>Status:</strong> <span class="badge \${p.status === 'available' ? 'badge-success' : 'badge-danger'}">\${p.status.toUpperCase()}</span></div>
                    <div style="font-size:0.9rem; color: #C0392B;"><strong>Cost to Make:</strong> ₱\${costToMake.toFixed(2)}</div>
                    <div style="font-size:0.9rem; color: #3C2415;"><strong>Selling Price:</strong> ₱\${sellingPrice.toFixed(2)}</div>
                    <div style="font-size:0.9rem; color: #27AE60; grid-column: span 2; border-top: 1px dashed #E2D9CF; padding-top: 6px; margin-top: 4px;"><strong>Estimated Profit:</strong> ₱\${estProfit.toFixed(2)}</div>
                </div>
                <h4 style="font-size:0.95rem; font-weight:700; color:#3C2415; border-top:1px solid #EFEAE3; padding-top:0.75rem;">Recipe Ingredients per Serving</h4>
                \${recipeHtml}
            `;
            
            viewModal.classList.add('active');
        });
    });

    // Close Modals
    document.querySelectorAll('.close-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            productModal.classList.remove('active');
            viewModal.classList.remove('active');
        });
    });

    // Add Recipe Row Button
    document.getElementById('add-recipe-row').addEventListener('click', function() {
        addRecipeRow();
    });

    function addRecipeRow(ingId = '', qty = '', unit = '') {
        const container = document.getElementById('recipe-container');
        const div = document.createElement('div');
        div.className = 'form-row align-items-center mb-2 recipe-row';
        
        let ingOptions = '<option value="">-- Select Ingredient --</option>';
        allIngredients.forEach(i => {
            const sel = (i.ingredient_id == ingId) ? 'selected' : '';
            ingOptions += `<option value="\${i.ingredient_id}" data-unit="\${i.unit || ''}" \${sel}>\${i.name}</option>`;
        });
        
        let unitOptions = '';
        availableUnits.forEach(u => {
            const sel = (u === unit) ? 'selected' : '';
            unitOptions += `<option value="\${u}" \${sel}>\${u}</option>`;
        });
        
        div.innerHTML = `
            <div class="col-md-5">
                <select name="ingredient_id[]" class="form-control ingredient-select" required>\${ingOptions}</select>
            </div>
            <div class="col-md-3">
                <input type="number" name="quantity[]" class="form-control" placeholder="Qty" step="0.001" value="\${qty}" required>
            </div>
            <div class="col-md-3">
                <select name="unit[]" class="form-control unit-select" required>\${unitOptions}</select>
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-danger remove-row" style="border-radius:6px;">&times;</button>
            </div>
        `;

        const ingSelect = div.querySelector('.ingredient-select');
        const unitSelect = div.querySelector('.unit-select');

        // Automatically set the unit when ingredient is selected
        ingSelect.addEventListener('change', function() {
            const selectedOpt = this.options[this.selectedIndex];
            const defaultUnit = selectedOpt.getAttribute('data-unit');
            if (defaultUnit) {
                for (let j = 0; j < unitSelect.options.length; j++) {
                    if (unitSelect.options[j].value.toLowerCase() === defaultUnit.toLowerCase()) {
                        unitSelect.selectedIndex = j;
                        break;
                    }
                }
            }
        });
        
        div.querySelector('.remove-row').addEventListener('click', function() {
            div.remove();
        });
        
        container.appendChild(div);
    }
});
</script>
HTML;
?>
<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
