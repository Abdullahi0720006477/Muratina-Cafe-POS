<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('customers');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Customer deleted.');
    } elseif (!empty($_POST['id'])) {
        $pdo->prepare('UPDATE customers SET name=?, phone=?, email=? WHERE id=?')
            ->execute([trim($_POST['name']), $_POST['phone'], $_POST['email'], (int) $_POST['id']]);
        flash('Customer updated.');
    } else {
        $pdo->prepare('INSERT INTO customers (name, phone, email) VALUES (?,?,?)')
            ->execute([trim($_POST['name']), $_POST['phone'], $_POST['email']]);
        flash('Customer added.');
    }
    redirect('customers.php');
}

$search = trim($_GET['q'] ?? '');
$sql = 'SELECT c.*, (SELECT COUNT(*) FROM sales s WHERE s.customer_id=c.id) orders,
        (SELECT COALESCE(SUM(total),0) FROM sales s WHERE s.customer_id=c.id) spent FROM customers c';
$params = [];
if ($search !== '') { $sql .= ' WHERE c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?'; $params = array_fill(0, 3, "%$search%"); }
$sql .= ' ORDER BY c.name';
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$customers = $stmt->fetchAll();

// Purchase history for a selected customer
$historyId = (int) ($_GET['history'] ?? 0);
$history = [];
if ($historyId) {
    $h = $pdo->prepare('SELECT receipt_no, total, payment_method, created_at FROM sales WHERE customer_id=? ORDER BY id DESC LIMIT 20');
    $h->execute([$historyId]);
    $history = $h->fetchAll();
}

$pageTitle = 'Customers';
$activeNav = 'customers';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap gap-2 justify-content-between mb-3">
    <form class="d-flex gap-2" method="get">
        <input type="text" name="q" class="form-control" placeholder="Search customers" value="<?= e($search) ?>">
        <button class="btn btn-outline-secondary"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
    <button class="btn btn-brand" onclick="openCust()"><i class="fa-solid fa-plus"></i> Add Customer</button>
</div>

<div class="row g-3">
<div class="col-lg-<?= $historyId ? 7 : 12 ?>">
<div class="card table-card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Phone</th><th>Loyalty</th><th>Orders</th><th>Spent</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($customers as $c): ?>
        <tr>
            <td><strong><?= e($c['name']) ?></strong><br><small class="text-muted"><?= e($c['email']) ?></small></td>
            <td><?= e($c['phone']) ?></td>
            <td><span class="badge-soft badge-warn"><i class="fa-solid fa-star"></i> <?= (int) $c['loyalty_points'] ?></span></td>
            <td><?= (int) $c['orders'] ?></td>
            <td><?= money($c['spent']) ?></td>
            <td class="text-end">
                <a class="btn btn-sm btn-outline-secondary" href="?history=<?= $c['id'] ?>" title="History"><i class="fa-solid fa-clock-rotate-left"></i></a>
                <button class="btn btn-sm btn-outline-secondary" onclick='openCust(<?= json_encode($c) ?>)'><i class="fa-solid fa-pen"></i></button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete customer?')"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>
</div>

<?php if ($historyId): ?>
<div class="col-lg-5">
    <div class="card"><div class="card-body">
        <h3 class="section-title">Purchase History</h3>
        <?php if (!$history): ?><p class="text-muted">No purchases yet.</p><?php endif; ?>
        <?php foreach ($history as $hh): ?>
            <div class="d-flex justify-content-between border-bottom py-2">
                <div><a href="<?= BASE_URL ?>/receipt.php?no=<?= e($hh['receipt_no']) ?>"><?= e($hh['receipt_no']) ?></a>
                    <br><small class="text-muted"><?= e(date('d M Y H:i', strtotime($hh['created_at']))) ?> · <?= e($hh['payment_method']) ?></small></div>
                <strong><?= money($hh['total']) ?></strong>
            </div>
        <?php endforeach; ?>
    </div></div>
</div>
<?php endif; ?>
</div>

<div class="modal fade" id="custModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="id" id="cuid">
        <div class="modal-header"><h5 class="modal-title" id="cumTitle">Add Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">Name *</label><input name="name" id="cuname" class="form-control mb-2" required>
            <label class="form-label">Phone</label><input name="phone" id="cuphone" class="form-control mb-2">
            <label class="form-label">Email</label><input type="email" name="email" id="cuemail" class="form-control">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Save</button></div>
    </form>
</div></div>

<script>
const custModal = new bootstrap.Modal('#custModal');

const cuid = document.getElementById('cuid');
const cuname = document.getElementById('cuname');
const cuphone = document.getElementById('cuphone');
const cuemail = document.getElementById('cuemail');

function openCust(c) {
    document.querySelector('#custModal form').reset();
    document.getElementById('cumTitle').textContent = c ? 'Edit Customer' : 'Add Customer';
    cuid.value = c ? c.id : ''; cuname.value = c ? c.name : ''; cuphone.value = c ? (c.phone||'') : ''; cuemail.value = c ? (c.email||'') : '';
    custModal.show();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
