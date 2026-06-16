<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('settings'); // Manager-only access

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM salary_payments WHERE id = ?')->execute([$id]);
        flash('Salary record deleted.');
    } elseif (!empty($_POST['id'])) {
        // Edit salary
        $id = (int) $_POST['id'];
        $pdo->prepare('UPDATE salary_payments SET user_id=?, month=?, amount=?, payment_date=?, payment_method=?, notes=? WHERE id=?')
            ->execute([
                (int) $_POST['user_id'],
                trim($_POST['month']),
                (float) $_POST['amount'],
                $_POST['payment_date'],
                trim($_POST['payment_method']),
                trim($_POST['notes']),
                $id
            ]);
        flash('Salary record updated.');
    } else {
        // Log new salary
        $pdo->prepare('INSERT INTO salary_payments (user_id, month, amount, payment_date, payment_method, notes) VALUES (?,?,?,?,?,?)')
            ->execute([
                (int) $_POST['user_id'],
                trim($_POST['month']),
                (float) $_POST['amount'],
                $_POST['payment_date'],
                trim($_POST['payment_method']),
                trim($_POST['notes'])
            ]);
        flash('Salary payment logged successfully.');
    }
    redirect('salaries.php');
}

// Fetch active users for selection
$staff = $pdo->query('SELECT id, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name')->fetchAll();

// Fetch salary payments history
$payments = $pdo->query('
    SELECT sp.*, u.full_name, u.role 
    FROM salary_payments sp 
    JOIN users u ON u.id = sp.user_id 
    ORDER BY sp.payment_date DESC, sp.id DESC
')->fetchAll();

$pageTitle = 'Salary Payments';
$activeNav = 'salaries';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between mb-3">
    <h3 class="section-title mb-0">Salary Payments</h3>
    <button class="btn btn-brand" onclick="openSalary()"><i class="fa-solid fa-plus"></i> Log Salary Payment</button>
</div>

<div class="card table-card"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead>
        <tr>
            <th>Employee</th>
            <th>Role</th>
            <th>Month / Year</th>
            <th>Amount Paid</th>
            <th>Payment Date</th>
            <th>Method</th>
            <th>Notes</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
        <tr>
            <td><strong><?= e($p['full_name']) ?></strong></td>
            <td><span class="badge bg-secondary"><?= e(ucfirst($p['role'])) ?></span></td>
            <td><?= e($p['month']) ?></td>
            <td><strong><?= money($p['amount']) ?></strong></td>
            <td><?= e($p['payment_date']) ?></td>
            <td><span class="badge-soft badge-ok"><?= e($p['payment_method']) ?></span></td>
            <td><small class="text-muted"><?= e($p['notes'] ?: '-') ?></small></td>
            <td class="text-end">
                <button class="btn btn-sm btn-outline-secondary" onclick='openSalary(<?= json_encode($p) ?>)'><i class="fa-solid fa-pen"></i></button>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete this payment record?')"><?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash"></i></button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$payments): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No salary records found.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div></div>

<!-- Log / Edit Salary Payment Modal -->
<div class="modal fade" id="salaryModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?>
        <input type="hidden" name="id" id="pid">
        <div class="modal-header">
            <h5 class="modal-title" id="mTitle">Log Salary Payment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label">Employee *</label>
                <select name="user_id" id="puser" class="form-select" required>
                    <option value="">-- Select Employee --</option>
                    <?php foreach ($staff as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= e($u['full_name']) ?> (<?= e(ucfirst($u['role'])) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Month *</label>
                <input type="month" name="month" id="pmonth" class="form-control" value="<?= date('Y-m') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Amount Paid *</label>
                <input type="number" step="0.01" name="amount" id="pamount" class="form-control" required min="0">
            </div>
            <div class="mb-3">
                <label class="form-label">Payment Date *</label>
                <input type="date" name="payment_date" id="pdate" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Payment Method *</label>
                <select name="payment_method" id="pmethod" class="form-select" required>
                    <option value="Cash">Cash</option>
                    <option value="M-Pesa">M-Pesa</option>
                    <option value="Bank Transfer">Bank Transfer</option>
                    <option value="Card">Card</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Notes / Details</label>
                <textarea name="notes" id="pnotes" class="form-control" rows="3" placeholder="e.g. Basic salary + bonus"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-brand">Save Record</button>
        </div>
    </form>
</div></div>

<script>
const salaryModal = new bootstrap.Modal('#salaryModal');

const pid = document.getElementById('pid');
const puser = document.getElementById('puser');
const pmonth = document.getElementById('pmonth');
const pamount = document.getElementById('pamount');
const pdate = document.getElementById('pdate');
const pmethod = document.getElementById('pmethod');
const pnotes = document.getElementById('pnotes');

function openSalary(p) {
    document.querySelector('#salaryModal form').reset();
    document.getElementById('mTitle').textContent = p ? 'Edit Salary Payment' : 'Log Salary Payment';
    
    pid.value = p ? p.id : '';
    puser.value = p ? p.user_id : '';
    pmonth.value = p ? p.month : '<?= date('Y-m') ?>';
    pamount.value = p ? p.amount : '';
    pdate.value = p ? p.payment_date : '<?= date('Y-m-d') ?>';
    pmethod.value = p ? p.payment_method : 'Cash';
    pnotes.value = p ? (p.notes || '') : '';
    
    salaryModal.show();
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
