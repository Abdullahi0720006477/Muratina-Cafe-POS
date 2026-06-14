<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('pos');

$pdo = db();
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$products   = $pdo->query(
    'SELECT id, name, selling_price, stock_qty, category_id FROM products WHERE is_active=1 ORDER BY name'
)->fetchAll();
$customers  = $pdo->query('SELECT id, name FROM customers ORDER BY name')->fetchAll();
$taxRate    = (float) (settings()['tax_rate'] ?? 0);

$pageTitle = 'POS Sales';
$activeNav = 'pos';
require __DIR__ . '/includes/header.php';
?>
<script>window.CSRF = "<?= csrf_token() ?>"; window.TAX_RATE = <?= $taxRate ?>;</script>

<div class="pos-layout">
    <!-- Products -->
    <div>
        <div class="d-flex gap-2 mb-3">
            <input type="text" id="posSearch" class="form-control" placeholder="🔍 Search product or scan barcode…">
        </div>
        <div class="pos-cats" id="posCats">
            <button class="cat-pill active" data-cat="all">All</button>
            <?php foreach ($categories as $c): ?>
                <button class="cat-pill" data-cat="<?= $c['id'] ?>"><i class="fa-solid <?= e($c['icon']) ?>"></i> <?= e($c['name']) ?></button>
            <?php endforeach; ?>
        </div>
        <div class="product-grid" id="productGrid"></div>
    </div>

    <!-- Cart -->
    <div class="cart-panel">
        <div class="cart-head d-flex justify-content-between align-items-center">
            <span><i class="fa-solid fa-cart-shopping"></i> Current Order</span>
            <button class="btn btn-sm btn-outline-danger" onclick="POS.clear()"><i class="fa-solid fa-trash"></i></button>
        </div>
        <div class="cart-items" id="cartItems">
            <div class="empty-cart"><i class="fa-solid fa-mug-hot fa-2x mb-2"></i><br>Tap products to add</div>
        </div>
        <div class="cart-foot">
            <div class="mb-2">
                <select id="customerSelect" class="form-select form-select-sm">
                    <?php foreach ($customers as $cu): ?>
                        <option value="<?= $cu['id'] ?>"><?= e($cu['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cart-line"><span>Subtotal</span><span id="cSubtotal"><?= money(0) ?></span></div>
            <div class="cart-line">
                <span>Discount</span>
                <span><input type="number" id="cDiscount" value="0" min="0" class="form-control form-control-sm text-end" style="width:90px;display:inline-block" oninput="POS.render()"></span>
            </div>
            <div class="cart-line"><span>Tax (<?= $taxRate ?>%)</span><span id="cTax"><?= money(0) ?></span></div>
            <div class="cart-line cart-total"><span>Total</span><span id="cTotal"><?= money(0) ?></span></div>

            <div class="pay-grid">
                <div class="pay-opt active" data-pay="Cash"><i class="fa-solid fa-money-bill"></i> Cash</div>
                <div class="pay-opt" data-pay="M-Pesa"><i class="fa-solid fa-mobile-screen"></i> M-Pesa</div>
                <div class="pay-opt" data-pay="Card"><i class="fa-solid fa-credit-card"></i> Card</div>
                <div class="pay-opt" data-pay="Bank Transfer"><i class="fa-solid fa-building-columns"></i> Bank</div>
            </div>
            <input type="text" id="cNote" class="form-control form-control-sm mb-2" placeholder="Order note (optional)">
            <button class="btn btn-brand w-100 btn-lg" id="checkoutBtn" onclick="POS.checkout()">
                <i class="fa-solid fa-circle-check"></i> Charge <span id="chargeAmt"><?= money(0) ?></span>
            </button>
        </div>
    </div>
</div>

<script>
const PRODUCTS = <?= json_encode($products) ?>;
const POS = {
    cart: [], category: 'all', search: '', payment: 'Cash',

    grid() {
        const g = document.getElementById('productGrid');
        const list = PRODUCTS.filter(p =>
            (this.category === 'all' || p.category_id == this.category) &&
            p.name.toLowerCase().includes(this.search.toLowerCase()));
        g.innerHTML = list.map(p => {
            const out = p.stock_qty <= 0;
            const low = p.stock_qty > 0 && p.stock_qty <= 5;
            return `<div class="product-tile ${out ? 'out' : ''}" onclick="POS.add(${p.id})">
                <span class="stock-tag badge-soft ${out ? 'badge-low' : (low ? 'badge-warn' : 'badge-ok')}">${out ? 'Out' : p.stock_qty + ' left'}</span>
                <div class="p-thumb"><i class="fa-solid fa-mug-hot"></i></div>
                <div class="p-name">${p.name}</div>
                <div class="p-price">${fmtMoney(p.selling_price)}</div>
            </div>`;
        }).join('') || '<p class="text-muted">No products found.</p>';
    },

    add(id) {
        const p = PRODUCTS.find(x => x.id == id);
        const inCart = this.cart.find(x => x.id == id);
        const qty = inCart ? inCart.qty + 1 : 1;
        if (qty > p.stock_qty) { return; }
        if (inCart) inCart.qty = qty;
        else this.cart.push({ id: p.id, name: p.name, price: +p.selling_price, qty: 1, max: p.stock_qty });
        this.render();
    },

    setQty(id, delta) {
        const it = this.cart.find(x => x.id == id);
        if (!it) return;
        it.qty += delta;
        if (it.qty <= 0) this.cart = this.cart.filter(x => x.id != id);
        else if (it.qty > it.max) it.qty = it.max;
        this.render();
    },

    clear() { this.cart = []; document.getElementById('cDiscount').value = 0; this.render(); },

    totals() {
        const sub = this.cart.reduce((s, i) => s + i.price * i.qty, 0);
        let disc = Math.min(parseFloat(document.getElementById('cDiscount').value) || 0, sub);
        const tax = (sub - disc) * (window.TAX_RATE / 100);
        return { sub, disc, tax, total: sub - disc + tax };
    },

    render() {
        const box = document.getElementById('cartItems');
        if (!this.cart.length) {
            box.innerHTML = '<div class="empty-cart"><i class="fa-solid fa-mug-hot fa-2x mb-2"></i><br>Tap products to add</div>';
        } else {
            box.innerHTML = this.cart.map(i => `
                <div class="cart-row">
                    <div style="flex:1">
                        <div class="ci-name">${i.name}</div>
                        <div class="ci-price">${fmtMoney(i.price)} × ${i.qty} = ${fmtMoney(i.price * i.qty)}</div>
                    </div>
                    <button class="qty-btn" onclick="POS.setQty(${i.id},-1)">−</button>
                    <span class="qty-val">${i.qty}</span>
                    <button class="qty-btn" onclick="POS.setQty(${i.id},1)">+</button>
                </div>`).join('');
        }
        const t = this.totals();
        document.getElementById('cSubtotal').textContent = fmtMoney(t.sub);
        document.getElementById('cTax').textContent = fmtMoney(t.tax);
        document.getElementById('cTotal').textContent = fmtMoney(t.total);
        document.getElementById('chargeAmt').textContent = fmtMoney(t.total);
    },

    checkout() {
        if (!this.cart.length) { alert('Cart is empty.'); return; }
        const t = this.totals();
        const btn = document.getElementById('checkoutBtn');
        const restore = () => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Charge <span id="chargeAmt"></span>'; this.render(); };
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing…';
        postJSON(BASE_URL + '/api/process_sale.php', {
            items: this.cart.map(i => ({ id: i.id, qty: i.qty })),
            discount: t.disc,
            payment_method: this.payment,
            customer_id: document.getElementById('customerSelect').value,
            note: document.getElementById('cNote').value
        }).then(res => {
            if (res.ok) {
                window.open(BASE_URL + '/receipt.php?no=' + encodeURIComponent(res.receipt_no), '_blank');
                this.clear();
                location.reload();
            } else {
                alert(res.error || 'Sale failed.');
                restore();
            }
        }).catch(() => { alert('Network error.'); restore(); });
    }
};

// Wire up filters and payment selection
document.getElementById('posCats').addEventListener('click', e => {
    const b = e.target.closest('.cat-pill'); if (!b) return;
    document.querySelectorAll('.cat-pill').forEach(x => x.classList.remove('active'));
    b.classList.add('active'); POS.category = b.dataset.cat; POS.grid();
});
document.getElementById('posSearch').addEventListener('input', e => { POS.search = e.target.value; POS.grid(); });
document.querySelectorAll('.pay-opt').forEach(o => o.addEventListener('click', () => {
    document.querySelectorAll('.pay-opt').forEach(x => x.classList.remove('active'));
    o.classList.add('active'); POS.payment = o.dataset.pay;
}));
POS.grid(); POS.render();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
