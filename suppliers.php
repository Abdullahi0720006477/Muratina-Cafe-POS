<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('suppliers');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Supplier deleted.');
    } elseif (!empty($_POST['id'])) {
        $pdo->prepare('UPDATE suppliers SET name=?, phone=?, email=?, address=? WHERE id=?')
            ->execute([trim($_POST['name']), $_POST['phone'], $_POST['email'], $_POST['address'], (int) $_POST['id']]);
        flash('Supplier updated.');
    } else {
        $pdo->prepare('INSERT INTO suppliers (name, phone, email, address) VALUES (?,?,?,?)')
            ->execute([trim($_POST['name']), $_POST['phone'], $_POST['email'], $_POST['address']]);
        flash('Supplier added.');
    }
    redirect('suppliers.php');
}

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY name')->fetchAll();

$pageTitle = 'Suppliers';
$activeNav = 'suppliers';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between mb-3">
    <h3 class="section-title mb-0">Suppliers</h3>
    <button class="btn btn-brand" onclick="openSup()"><i class="fa-solid fa-plus"></i> Add Supplier</button>
</div>

<div class="card table-card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>Address</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($suppliers as $s): ?>
        <tr>
            <td><strong><?= e($s['name']) ?></strong></td>
            <td><?= e($s['phone']) ?></td><td><?= e($s['email']) ?></td><td><?= e($s['address']) ?></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" onclick='openSup(<?= json_encode($s) ?>)'><i class="fa-solid fa-pen"></i></button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete supplier?')"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button></form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$suppliers): ?><tr><td colspan="5" class="text-center text-muted py-4">No suppliers yet.</td></tr><?php endif; ?>
    </tbody>
</table>
</div></div>

<div class="modal fade" id="supModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="id" id="sid">
        <div class="modal-header"><h5 class="modal-title" id="smTitle">Add Supplier</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">Name *</label><input name="name" id="sname" class="form-control mb-2" required>
            <label class="form-label">Phone</label><input name="phone" id="sphone" class="form-control mb-2">
            <label class="form-label">Email</label><input type="email" name="email" id="semail" class="form-control mb-2">
            <label class="form-label">Address</label><input name="address" id="saddr" class="form-control">
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Save</button></div>
    </form>
</div></div>

<script>
const supModal = new bootstrap.Modal('#supModal');

const sid = document.getElementById('sid');
const sname = document.getElementById('sname');
const sphone = document.getElementById('sphone');
const semail = document.getElementById('semail');
const saddr = document.getElementById('saddr');

function openSup(s) {
    document.querySelector('#supModal form').reset();
    document.getElementById('smTitle').textContent = s ? 'Edit Supplier' : 'Add Supplier';
    sid.value = s ? s.id : ''; sname.value = s ? s.name : ''; sphone.value = s ? (s.phone||'') : '';
    semail.value = s ? (s.email||'') : ''; saddr.value = s ? (s.address||'') : '';
    supModal.show();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
