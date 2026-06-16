<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('pos');

$pdo = db();
$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$products   = $pdo->query(
    'SELECT id, name, selling_price, stock_qty, category_id, image FROM products WHERE is_active=1 ORDER BY name'
)->fetchAll();
$customers  = $pdo->query('SELECT id, name, loyalty_points, phone FROM customers ORDER BY name')->fetchAll();
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
            <span>
                <i class="fa-solid fa-cart-shopping"></i> Current Order
                <small class="d-block text-muted" style="font-weight:600">
                    <i class="fa-solid fa-user-clock"></i> Served by <?= e(current_user()['full_name']) ?> (<?= e(ucfirst(user_role())) ?>)
                </small>
            </span>
            <button class="btn btn-sm btn-outline-danger" onclick="POS.clear()"><i class="fa-solid fa-trash"></i></button>
        </div>
        <div class="cart-items" id="cartItems">
            <div class="empty-cart"><i class="fa-solid fa-mug-hot fa-2x mb-2"></i><br>Tap products to add</div>
        </div>
        <div class="cart-foot">
            <div class="mb-2">
                <select id="customerSelect" class="form-select form-select-sm" onchange="POS.onCustomerChange()">
                    <?php foreach ($customers as $cu): ?>
                        <option value="<?= $cu['id'] ?>" data-points="<?= (int)$cu['loyalty_points'] ?>" data-phone="<?= e($cu['phone'] ?? '') ?>"><?= e($cu['name']) ?> (<?= (int)$cu['loyalty_points'] ?> pts)</option>
                    <?php endforeach; ?>
                </select>
                <div id="loyaltyPointsWrap" class="mt-1 d-none" style="font-size:0.8rem;font-weight:600;">
                    <span class="text-muted"><i class="fa-solid fa-star text-warning"></i> <span id="customerPointsVal">0</span> points available</span>
                    <button type="button" class="btn btn-xs btn-outline-warning ms-2 py-0 px-2" style="font-size:0.75rem;" id="btnRedeem" onclick="POS.redeem()">Redeem</button>
                </div>
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
    customerPoints: 0, customerPhone: '', redeemedPoints: 0,
    mpesaPhone: '', mpesaTxId: '',

    grid() {
        const g = document.getElementById('productGrid');
        const list = PRODUCTS.filter(p =>
            (this.category === 'all' || p.category_id == this.category) &&
            p.name.toLowerCase().includes(this.search.toLowerCase()));
        g.innerHTML = list.map(p => {
            const out = p.stock_qty <= 0;
            const low = p.stock_qty > 0 && p.stock_qty <= 5;
            const imgHtml = p.image ? `<img src="${window.BASE_URL}/${p.image}" alt="${p.name}">` : `<i class="fa-solid fa-mug-hot"></i>`;
            return `<div class="product-tile ${out ? 'out' : ''}" onclick="POS.add(${p.id})">
                <span class="stock-tag badge-soft ${out ? 'badge-low' : (low ? 'badge-warn' : 'badge-ok')}">${out ? 'Out' : p.stock_qty + ' left'}</span>
                <div class="p-thumb">${imgHtml}</div>
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

    clear() {
        this.cart = [];
        document.getElementById('cDiscount').value = 0;
        this.redeemedPoints = 0;
        const btn = document.getElementById('btnRedeem');
        if (btn) {
            btn.textContent = 'Redeem';
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-outline-warning');
        }
        this.render();
    },

    totals() {
        const sub = this.cart.reduce((s, i) => s + i.price * i.qty, 0);
        let disc = Math.min(parseFloat(document.getElementById('cDiscount').value) || 0, sub);
        const totalDisc = Math.min(disc + this.redeemedPoints, sub);
        const tax = (sub - totalDisc) * (window.TAX_RATE / 100);
        return { sub, disc, tax, total: sub - totalDisc + tax, totalDisc };
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

    onCustomerChange() {
        const select = document.getElementById('customerSelect');
        const opt = select.options[select.selectedIndex];
        const points = parseInt(opt.dataset.points) || 0;
        const phone = opt.dataset.phone || '';
        this.customerPoints = points;
        this.customerPhone = phone;
        this.redeemedPoints = 0;

        const wrap = document.getElementById('loyaltyPointsWrap');
        const val = document.getElementById('customerPointsVal');
        const btn = document.getElementById('btnRedeem');

        if (points > 0) {
            wrap.classList.remove('d-none');
            val.textContent = points;
            btn.textContent = 'Redeem';
            btn.disabled = false;
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-outline-warning');
        } else {
            wrap.classList.add('d-none');
        }
        this.render();
    },

    redeem() {
        const btn = document.getElementById('btnRedeem');
        if (this.redeemedPoints > 0) {
            this.redeemedPoints = 0;
            btn.textContent = 'Redeem';
            btn.classList.remove('btn-warning');
            btn.classList.add('btn-outline-warning');
        } else {
            const sub = this.cart.reduce((s, i) => s + i.price * i.qty, 0);
            const disc = Math.min(parseFloat(document.getElementById('cDiscount').value) || 0, sub);
            const maxRedeemable = Math.min(this.customerPoints, Math.max(0, sub - disc));
            if (maxRedeemable > 0) {
                this.redeemedPoints = maxRedeemable;
                btn.textContent = 'Undo';
                btn.classList.remove('btn-outline-warning');
                btn.classList.add('btn-warning');
            } else {
                alert('No balance to redeem points against.');
            }
        }
        this.render();
    },

    checkout() {
        if (!this.cart.length) { alert('Cart is empty.'); return; }
        const t = this.totals();
        
        if (this.payment === 'M-Pesa') {
            document.getElementById('mpesaPhone').value = this.customerPhone || '';
            document.getElementById('mpesaPromptAmt').textContent = t.total.toFixed(2);
            document.getElementById('mpesaStep1').classList.remove('d-none');
            document.getElementById('mpesaStep2').classList.add('d-none');
            document.getElementById('mpesaStep3').classList.add('d-none');
            document.getElementById('mpesaStep4').classList.add('d-none');
            const mpesaModal = new bootstrap.Modal(document.getElementById('mpesaModal'));
            mpesaModal.show();
        } else {
            this.submitCheckout(t.disc, '');
        }
    },

    sendStkPush() {
        const phone = document.getElementById('mpesaPhone').value.trim();
        if (!/^\d{9,12}$/.test(phone)) {
            alert('Please enter a valid phone number.');
            return;
        }
        this.mpesaPhone = phone;
        document.getElementById('mpesaStep1').classList.add('d-none');
        document.getElementById('mpesaStep2').classList.remove('d-none');
        document.getElementById('mpesaPin').value = '';
    },

    cancelStk() {
        document.getElementById('mpesaStep2').classList.add('d-none');
        document.getElementById('mpesaStep1').classList.remove('d-none');
    },

    cancelMpesa() {
        const m = bootstrap.Modal.getInstance(document.getElementById('mpesaModal'));
        if (m) m.hide();
    },

    confirmPin() {
        const pin = document.getElementById('mpesaPin').value;
        if (!/^\d{4}$/.test(pin)) {
            alert('Please enter a 4-digit M-Pesa PIN.');
            return;
        }
        document.getElementById('mpesaStep2').classList.add('d-none');
        document.getElementById('mpesaStep3').classList.remove('d-none');
        
        setTimeout(() => {
            const randomId = 'MP' + Math.random().toString(36).substring(2, 10).toUpperCase();
            this.mpesaTxId = randomId;
            document.getElementById('mpesaTxId').textContent = randomId;
            document.getElementById('mpesaStep3').classList.add('d-none');
            document.getElementById('mpesaStep4').classList.remove('d-none');
        }, 1800);
    },

    finalizeMpesaCheckout() {
        this.cancelMpesa();
        const t = this.totals();
        const extraNote = `M-Pesa Ref: ${this.mpesaTxId} (Phone: ${this.mpesaPhone})`;
        this.submitCheckout(t.disc, extraNote);
    },

    submitCheckout(discountValue, extraNote) {
        const btn = document.getElementById('checkoutBtn');
        const restore = () => { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Charge <span id="chargeAmt"></span>'; this.render(); };
        btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing…';
        
        const noteInput = document.getElementById('cNote').value.trim();
        const note = noteInput ? (noteInput + ' | ' + extraNote) : extraNote;

        postJSON(BASE_URL + '/api/process_sale.php', {
            items: this.cart.map(i => ({ id: i.id, qty: i.qty })),
            discount: discountValue,
            redeemed_points: this.redeemedPoints,
            payment_method: this.payment,
            customer_id: document.getElementById('customerSelect').value,
            note: note
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
POS.grid(); POS.onCustomerChange();
</script>

<!-- M-Pesa STK Push Simulator Modal -->
<div class="modal fade" id="mpesaModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered" style="max-width:380px;">
    <div class="modal-content glass" style="border: 1px solid var(--border); border-radius: 20px; background: rgba(var(--surface-rgb), 0.85); backdrop-filter: blur(12px);">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:var(--brand-2);"><i class="fa-solid fa-mobile-screen me-2"></i> M-Pesa Checkout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="POS.cancelMpesa()"></button>
      </div>
      <div class="modal-body text-center pt-2">
        <div id="mpesaStep1">
            <p class="text-muted small">Enter the customer's phone number to send the M-Pesa STK Push payment prompt.</p>
            <div class="input-group mb-3">
                <span class="input-group-text"><i class="fa-solid fa-phone"></i></span>
                <input type="text" id="mpesaPhone" class="form-control" placeholder="e.g. 0701234567" value="">
            </div>
            <button type="button" class="btn btn-brand w-100 py-2" onclick="POS.sendStkPush()"><i class="fa-solid fa-paper-plane"></i> Initiate STK Push</button>
        </div>
        
        <div id="mpesaStep2" class="d-none">
            <!-- Simulated Phone Frame -->
            <div class="phone-frame mx-auto mb-3">
                <div class="phone-screen">
                    <div class="stk-prompt">
                        <div class="stk-logo mb-2"><span class="badge bg-success">M-PESA</span></div>
                        <div class="stk-text mb-3">Do you want to pay KSh <span id="mpesaPromptAmt">0</span> to <b>Muratina Café</b>?</div>
                        <input type="password" id="mpesaPin" class="form-control text-center mb-2" placeholder="Enter PIN" maxlength="4" inputmode="numeric">
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-sm btn-outline-danger w-50" onclick="POS.cancelStk()">Cancel</button>
                            <button type="button" class="btn btn-sm btn-success w-50" onclick="POS.confirmPin()">Send</button>
                        </div>
                    </div>
                </div>
            </div>
            <p class="text-muted small mb-0">Simulating STK Push PIN prompt on customer's phone...</p>
        </div>
        
        <div id="mpesaStep3" class="d-none py-4">
            <div class="spinner-border text-warning mb-3" role="status"></div>
            <h6 class="fw-bold">Verifying Transaction...</h6>
            <p class="text-muted small mb-0">Waiting for M-Pesa IPN confirmation...</p>
        </div>
        
        <div id="mpesaStep4" class="d-none py-3">
            <div class="text-success fa-3x mb-2"><i class="fa-solid fa-circle-check"></i></div>
            <h6 class="fw-bold text-success">Payment Confirmed!</h6>
            <p class="text-muted small mb-3">Transaction ID: <code id="mpesaTxId">MPESA-XXX</code></p>
            <button type="button" class="btn btn-brand w-100" onclick="POS.finalizeMpesaCheckout()">Complete Order</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
