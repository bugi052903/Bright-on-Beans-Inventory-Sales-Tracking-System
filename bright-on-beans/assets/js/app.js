// Core Javascript for Bright on Beans
document.addEventListener('DOMContentLoaded', () => {

    // Sidebar Toggle
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.querySelector('.sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && sidebar && overlay) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }

    // Flash Messages Auto-dismiss
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }, 5000);
    });

    // Logout Modal
    const logoutBtn = document.getElementById('logoutBtn');
    const logoutModal = document.getElementById('logoutModal');
    if (logoutBtn && logoutModal) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            logoutModal.style.display = 'flex';
        });
        
        logoutModal.querySelector('.btn-secondary').addEventListener('click', () => {
            logoutModal.style.display = 'none';
        });
    }

    // Tabs
    const tabBtns = document.querySelectorAll('.tab-btn');
    tabBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            
            // Remove active from all siblings
            btn.parentElement.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            // Hide all contents
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(targetId).classList.add('active');
        });
    });

    // Delete Confirmation
    document.querySelectorAll('[data-delete-url]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const url = btn.getAttribute('data-delete-url');
            if (confirm('Are you sure you want to delete this item?')) {
                window.location.href = url;
            }
        });
    });

    // POS Cart System
    if (document.getElementById('cartItems')) {
        initCartSystem();
    }
});

function showConfirm(title, message, onConfirm) {
    if (confirm(`${title}\n\n${message}`)) {
        if (typeof onConfirm === 'function') onConfirm();
    }
}

// CART SYSTEM
window.cart = [];

function addToCart(id, name, price) {
    const existing = window.cart.find(item => item.id === id);
    if (existing) {
        existing.quantity += 1;
    } else {
        window.cart.push({ id, name, price, quantity: 1 });
    }
    renderCart();
}

function updateQuantity(id, delta) {
    const item = window.cart.find(i => i.id === id);
    if (item) {
        item.quantity += delta;
        if (item.quantity <= 0) {
            removeFromCart(id);
        } else {
            renderCart();
        }
    }
}

function removeFromCart(id) {
    window.cart = window.cart.filter(item => item.id !== id);
    renderCart();
}

function renderCart() {
    const cartContainer = document.getElementById('cartItems');
    const cartCount = document.getElementById('cartCount');
    const cartTotal = document.getElementById('cartTotal');
    const confirmBtn = document.getElementById('confirmSaleBtn');

    if (window.cart.length === 0) {
        cartContainer.innerHTML = '<div class="empty-state">Cart is empty</div>';
        cartCount.textContent = '0 items';
        cartTotal.textContent = '₱0.00';
        confirmBtn.disabled = true;
        return;
    }

    let html = '';
    let total = 0;
    let count = 0;

    window.cart.forEach(item => {
        const subtotal = item.price * item.quantity;
        total += subtotal;
        count += item.quantity;

        html += `
            <div class="cart-item" style="display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px solid #eee;">
                <div class="item-info">
                    <div style="font-weight: bold;">${item.name}</div>
                    <div class="text-muted" style="font-size: 0.9em;">₱${item.price.toFixed(2)}</div>
                </div>
                <div class="item-actions" style="display: flex; align-items: center; gap: 0.5rem;">
                    <button class="btn btn-sm btn-secondary" onclick="updateQuantity(${item.id}, -1)">-</button>
                    <span>${item.quantity}</span>
                    <button class="btn btn-sm btn-secondary" onclick="updateQuantity(${item.id}, 1)">+</button>
                    <button class="btn btn-sm btn-danger" onclick="removeFromCart(${item.id})" style="margin-left: 0.5rem;"><i class="fas fa-trash"></i></button>
                </div>
            </div>
        `;
    });

    cartContainer.innerHTML = html;
    cartCount.textContent = `${count} items`;
    cartTotal.textContent = `₱${total.toFixed(2)}`;
    confirmBtn.disabled = false;
}

function confirmSale() {
    if (window.cart.length === 0) return;
    
    const btn = document.getElementById('confirmSaleBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';

    const items = window.cart.map(i => ({ product_id: i.id, quantity: i.quantity }));
    const payload = {
        items: items,
        csrf_token: window.APP ? window.APP.csrfToken : ''
    };

    fetch((window.APP ? window.APP.baseUrl : '') + '/api/process-sale.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            window.cart = [];
            renderCart();
            let msg = `Sale successful! Total: ₱${data.total_amount.toFixed(2)}`;
            if (data.low_stock_alerts && data.low_stock_alerts.length > 0) {
                msg += `\n\nWarning! Low stock for: ${data.low_stock_alerts.join(', ')}`;
            }
            alert(msg);
        } else {
            alert('Error processing sale: ' + data.message);
        }
    })
    .catch(err => {
        alert('Network error while processing sale.');
    })
    .finally(() => {
        btn.disabled = window.cart.length === 0;
        btn.innerHTML = '<i class="fas fa-check"></i> Confirm Sale';
    });
}

function initCartSystem() {
    // Category filter
    const catBtns = document.querySelectorAll('.category-btn');
    const cards = document.querySelectorAll('.product-card');

    catBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            catBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            
            const cat = btn.getAttribute('data-category');
            cards.forEach(card => {
                if (cat === 'all' || card.getAttribute('data-category') === cat) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    });

    // Product search
    const searchInput = document.getElementById('productSearch');
    if (searchInput) {
        searchInput.addEventListener('input', () => {
            const term = searchInput.value.toLowerCase();
            const activeCat = document.querySelector('.category-btn.active').getAttribute('data-category');
            
            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const cat = card.getAttribute('data-category');
                
                const matchesCat = (activeCat === 'all' || cat === activeCat);
                const matchesSearch = name.includes(term);
                
                if (matchesCat && matchesSearch) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }
}
