<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('inventory');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $pid  = (int) $_POST['product_id'];
    $qty  = (int) $_POST['qty'];
    $type = $_POST['type'] === 'out' ? 'out' : 'in';
    $reason = trim($_POST['reason'] ?? '');

    if ($pid && $qty > 0) {
        $pdo->beginTransaction();
        try {
            if ($type === 'in') {
                $pdo->prepare('UPDATE products SET stock_qty = stock_qty + ? WHERE id = ?')->execute([$qty, $pid]);
            } else {
                $pdo->prepare('UPDATE products SET stock_qty = GREATEST(0, stock_qty - ?) WHERE id = ?')->execute([$qty, $pid]);
            }
            $pdo->prepare('INSERT INTO stock_movements (product_id, type, qty, reason, user_id) VALUES (?,?,?,?,?)')
                ->execute([$pid, $type, $qty, $reason ?: ($type === 'in' ? 'Stock In' : 'Stock Out'), current_user()['id']]);
            $pdo->commit();
            audit('stock_' . $type, "Product #$pid qty $qty");
            flash('Stock ' . ($type === 'in' ? 'added' : 'removed') . ' successfully.');
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash('Failed to update stock.', 'error');
        }
    }
    redirect('inventory.php');
}

$products = $pdo->query('SELECT id, name, stock_qty, low_stock FROM products WHERE is_active=1 ORDER BY name')->fetchAll();
$lowStock = $pdo->query('SELECT name, stock_qty, low_stock FROM products WHERE stock_qty <= low_stock AND is_active=1 ORDER BY stock_qty')->fetchAll();
$movements = $pdo->query(
    'SELECT m.*, p.name pname, u.full_name uname FROM stock_movements m
     LEFT JOIN products p ON p.id = m.product_id
     LEFT JOIN users u ON u.id = m.user_id
     ORDER BY m.id DESC LIMIT 40'
)->fetchAll();

$pageTitle = 'Inventory';
$activeNav = 'inventory';
require __DIR__ . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-body">
            <h3 class="section-title"><i class="fa-solid fa-arrows-rotate"></i> Stock Adjustment</h3>
            <form method="post"><?= csrf_field() ?>
                <label class="form-label">Product</label>
                <select name="product_id" class="form-select mb-2" required>
                    <option value="">Select product…</option>
                    <?php foreach ($products as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= (int) $p['stock_qty'] ?>)</option>
                    <?php endforeach; ?>
                </select>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label">Type</label>
                        <select name="type" class="form-select"><option value="in">Stock In</option><option value="out">Stock Out</option></select></div>
                    <div class="col-6"><label class="form-label">Quantity</label><input type="number" name="qty" min="1" class="form-control" required></div>
                </div>
                <label class="form-label">Reason / Reference</label>
                <input name="reason" class="form-control mb-3" placeholder="e.g. Purchase order, wastage…">
                <button class="btn btn-brand w-100"><i class="fa-solid fa-check"></i> Record Movement</button>
            </form>
        </div></div>

        <div class="card"><div class="card-body">
            <h3 class="section-title text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock Alerts</h3>
            <?php if (!$lowStock): ?><p class="text-muted">All items are well stocked. 🎉</p><?php endif; ?>
            <?php foreach ($lowStock as $l): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span><?= e($l['name']) ?></span>
                    <span class="badge-soft badge-low"><?= (int) $l['stock_qty'] ?> / <?= (int) $l['low_stock'] ?></span>
                </div>
            <?php endforeach; ?>
        </div></div>
    </div>

    <div class="col-lg-8">
        <div class="card table-card"><div class="card-body pb-0"><h3 class="section-title">Recent Stock Movements</h3></div>
        <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Date</th><th>Product</th><th>Type</th><th>Qty</th><th>Reason</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($movements as $m): ?>
                <tr>
                    <td><small><?= e(date('d M H:i', strtotime($m['created_at']))) ?></small></td>
                    <td><?= e($m['pname'] ?? '—') ?></td>
                    <td><span class="badge-soft <?= $m['type'] === 'in' ? 'badge-ok' : 'badge-low' ?>">
                        <i class="fa-solid fa-arrow-<?= $m['type'] === 'in' ? 'down' : 'up' ?>"></i> <?= e(ucfirst($m['type'])) ?></span></td>
                    <td><?= (int) $m['qty'] ?></td>
                    <td><small><?= e($m['reason']) ?></small></td>
                    <td><small><?= e($m['uname'] ?? '—') ?></small></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$movements): ?><tr><td colspan="6" class="text-center text-muted py-4">No movements recorded.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
