<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('products');

$pdo = db();

// ---- Handle add / edit / delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $pdo->prepare('UPDATE products SET is_active = 0 WHERE id = ?')->execute([(int) $_POST['id']]);
        audit('product_delete', 'Product #' . (int) $_POST['id']);
        flash('Product removed.');
        redirect('products.php');
    }

    // Optional image upload
    $image = $_POST['existing_image'] ?? null;
    if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $fn = 'p_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . '/products/' . $fn);
            $image = 'uploads/products/' . $fn;
        }
    }

    $fields = [
        trim($_POST['name']), $_POST['sku'] ?: null, $_POST['barcode'] ?: null,
        (int) $_POST['category_id'] ?: null, (int) $_POST['supplier_id'] ?: null,
        (float) $_POST['purchase_price'], (float) $_POST['selling_price'],
        (int) $_POST['stock_qty'], (int) $_POST['low_stock'],
        $image, $_POST['expiry_date'] ?: null,
    ];

    if (!empty($_POST['id'])) {
        $fields[] = (int) $_POST['id'];
        $pdo->prepare(
            'UPDATE products SET name=?, sku=?, barcode=?, category_id=?, supplier_id=?, purchase_price=?,
             selling_price=?, stock_qty=?, low_stock=?, image=?, expiry_date=? WHERE id=?'
        )->execute($fields);
        flash('Product updated.');
    } else {
        $pdo->prepare(
            'INSERT INTO products (name, sku, barcode, category_id, supplier_id, purchase_price,
             selling_price, stock_qty, low_stock, image, expiry_date) VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($fields);
        flash('Product added.');
    }
    audit('product_save', $_POST['name'] ?? '');
    redirect('products.php');
}

// ---- Listing with search/filter ----
$search = trim($_GET['q'] ?? '');
$catFilter = (int) ($_GET['cat'] ?? 0);
$where = 'p.is_active = 1';
$params = [];
if ($search !== '') { $where .= ' AND (p.name LIKE ? OR p.sku LIKE ? OR p.barcode LIKE ?)'; $params = array_fill(0, 3, "%$search%"); }
if ($catFilter)     { $where .= ' AND p.category_id = ?'; $params[] = $catFilter; }

$products = $pdo->prepare(
    "SELECT p.*, c.name cat, s.name supplier FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     LEFT JOIN suppliers s ON s.id = p.supplier_id
     WHERE $where ORDER BY p.name"
);
$products->execute($params);
$products = $products->fetchAll();

$categories = $pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
$suppliers  = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();

$pageTitle = 'Products';
$activeNav = 'products';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
    <form class="d-flex gap-2" method="get">
        <input type="text" name="q" class="form-control" placeholder="Search name / SKU / barcode" value="<?= e($search) ?>">
        <select name="cat" class="form-select" onchange="this.form.submit()">
            <option value="0">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $catFilter == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn btn-outline-secondary"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
    <button class="btn btn-brand" onclick="openProduct()"><i class="fa-solid fa-plus"></i> Add Product</button>
</div>

<div class="card table-card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Buy</th><th>Sell</th><th>Stock</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <tr>
            <td><strong><?= e($p['name']) ?></strong><br><small class="text-muted"><?= e($p['supplier'] ?? '') ?></small></td>
            <td><?= e($p['sku']) ?></td>
            <td><?= e($p['cat'] ?? '—') ?></td>
            <td><?= money($p['purchase_price']) ?></td>
            <td><?= money($p['selling_price']) ?></td>
            <td>
                <?php $low = $p['stock_qty'] <= $p['low_stock']; ?>
                <span class="badge-soft <?= $low ? 'badge-low' : 'badge-ok' ?>"><?= (int) $p['stock_qty'] ?></span>
            </td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" onclick='openProduct(<?= json_encode($p) ?>)'><i class="fa-solid fa-pen"></i></button>
                <form method="post" class="d-inline" onsubmit="return confirm('Remove this product?')">
                    <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$products): ?><tr><td colspan="7" class="text-center text-muted py-4">No products found.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>

<!-- Product modal -->
<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <form class="modal-content" method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="pid"><input type="hidden" name="existing_image" id="pexisting">
      <div class="modal-header"><h5 class="modal-title" id="pmTitle">Add Product</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body row g-3">
        <div class="col-md-6"><label class="form-label">Product Name *</label><input name="name" id="pname" class="form-control" required></div>
        <div class="col-md-3"><label class="form-label">SKU</label><input name="sku" id="psku" class="form-control"></div>
        <div class="col-md-3"><label class="form-label">Barcode</label><input name="barcode" id="pbarcode" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Category</label><select name="category_id" id="pcat" class="form-select">
            <option value="">—</option><?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Supplier</label><select name="supplier_id" id="psup" class="form-select">
            <option value="">—</option><?php foreach ($suppliers as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Purchase Price</label><input type="number" step="0.01" name="purchase_price" id="pbuy" class="form-control" value="0"></div>
        <div class="col-md-3"><label class="form-label">Selling Price *</label><input type="number" step="0.01" name="selling_price" id="psell" class="form-control" value="0" required></div>
        <div class="col-md-3"><label class="form-label">Stock Qty</label><input type="number" name="stock_qty" id="pstock" class="form-control" value="0"></div>
        <div class="col-md-3"><label class="form-label">Low Stock Alert</label><input type="number" name="low_stock" id="plow" class="form-control" value="5"></div>
        <div class="col-md-6"><label class="form-label">Expiry Date</label><input type="date" name="expiry_date" id="pexp" class="form-control"></div>
        <div class="col-md-6"><label class="form-label">Product Image</label><input type="file" name="image" class="form-control" accept="image/*"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Save Product</button></div>
    </form>
  </div>
</div>

<script>
const productModal = new bootstrap.Modal('#productModal');
function openProduct(p) {
    document.querySelector('#productModal form').reset();
    if (p) {
        document.getElementById('pmTitle').textContent = 'Edit Product';
        pid.value = p.id; pname.value = p.name; psku.value = p.sku || ''; pbarcode.value = p.barcode || '';
        pcat.value = p.category_id || ''; psup.value = p.supplier_id || '';
        pbuy.value = p.purchase_price; psell.value = p.selling_price; pstock.value = p.stock_qty;
        plow.value = p.low_stock; pexp.value = p.expiry_date || ''; pexisting.value = p.image || '';
    } else {
        document.getElementById('pmTitle').textContent = 'Add Product';
        pid.value = ''; pexisting.value = '';
    }
    productModal.show();
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
