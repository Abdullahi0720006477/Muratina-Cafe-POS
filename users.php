<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('users');

$pdo = db();

/** Build a unique username from a person's name (used for waiters). */
function gen_username(PDO $pdo, string $name): string
{
    $base = preg_replace('/[^a-z0-9]/', '', strtolower(strtok($name, ' '))) ?: 'waiter';
    $username = $base;
    $check = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
    $i = 1;
    while ($check->execute([$username]) && $check->fetch()) {
        $username = $base . (++$i);
    }
    return $username;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle') {
        $pdo->prepare('UPDATE users SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
        flash('Account status updated.');
    } elseif ($action === 'reset') {
        $new = $_POST['new_password'] ?? '';
        if (strlen($new) < 6) { flash('Password must be at least 6 characters.', 'error'); }
        else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT), $id]);
            audit('password_reset', "User #$id");
            flash('Password reset.');
        }
    } elseif ($action === 'delete') {
        if ($id !== (int) current_user()['id']) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash('User deleted.');
        } else { flash('You cannot delete your own account.', 'error'); }
    } else {
        $role     = in_array($_POST['role'], ['manager', 'cashier', 'inventory', 'waiter'], true) ? $_POST['role'] : 'cashier';
        $name     = trim($_POST['full_name']);
        $passcode = trim($_POST['passcode'] ?? '');
        $isWaiter = $role === 'waiter';

        // Validate the passcode (PIN) when one is supplied or required.
        $passcodeHash = null;
        $passErr = false;
        if ($passcode !== '') {
            if (!preg_match('/^\d{4,8}$/', $passcode)) {
                flash('Passcode must be 4–8 digits.', 'error'); $passErr = true;
            } elseif (passcode_in_use($passcode, $id ?: null)) {
                flash('That passcode is already used by another staff member. Choose a different PIN.', 'error'); $passErr = true;
            } else {
                $passcodeHash = password_hash($passcode, PASSWORD_BCRYPT);
            }
        } elseif ($isWaiter && !$id) {
            flash('A waiter needs a passcode (PIN) to log in.', 'error'); $passErr = true;
        }

        if ($passErr) {
            redirect('users.php');
        }

        if ($id) {
            // ---- Update existing user ----
            if ($isWaiter) {
                $pdo->prepare('UPDATE users SET full_name=?, email=?, phone=?, role=? WHERE id=?')
                    ->execute([$name, $_POST['email'] ?: null, $_POST['phone'] ?: null, $role, $id]);
            } else {
                $pdo->prepare('UPDATE users SET full_name=?, username=?, email=?, phone=?, role=? WHERE id=?')
                    ->execute([$name, trim($_POST['username']), $_POST['email'], $_POST['phone'], $role, $id]);
            }
            if ($passcodeHash) {
                $pdo->prepare('UPDATE users SET passcode=? WHERE id=?')->execute([$passcodeHash, $id]);
            }
            audit('user_update', "User #$id ($name)");
            flash('User updated.');
        } elseif ($isWaiter) {
            // ---- Register a new waiter (name + passcode; username auto-generated) ----
            $username = gen_username($pdo, $name);
            $randomPw = password_hash(bin2hex(random_bytes(8)), PASSWORD_BCRYPT); // waiters sign in via PIN
            try {
                $pdo->prepare('INSERT INTO users (full_name, username, email, phone, role, password_hash, passcode) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $username, $_POST['email'] ?: null, $_POST['phone'] ?: null, $role, $randomPw, $passcodeHash]);
                audit('user_create', "Waiter $name");
                flash("Waiter \"$name\" registered. They can now sign in with their passcode.");
            } catch (PDOException $e) {
                flash('Could not register waiter. Please try again.', 'error');
            }
        } else {
            // ---- Create manager / cashier / inventory user ----
            if (strlen($_POST['password'] ?? '') < 6) {
                flash('Password must be at least 6 characters.', 'error');
            } else {
                try {
                    $pdo->prepare('INSERT INTO users (full_name, username, email, phone, role, password_hash, passcode) VALUES (?,?,?,?,?,?,?)')
                        ->execute([$name, trim($_POST['username']), $_POST['email'], $_POST['phone'], $role,
                            password_hash($_POST['password'], PASSWORD_BCRYPT), $passcodeHash]);
                    audit('user_create', $_POST['username']);
                    flash('User created.');
                } catch (PDOException $e) {
                    flash('Username already exists.', 'error');
                }
            }
        }
    }
    redirect('users.php');
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, full_name')->fetchAll();
$logins = $pdo->query(
    'SELECT l.*, u.full_name FROM login_history l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 15'
)->fetchAll();
$audits = $pdo->query(
    'SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.id DESC LIMIT 15'
)->fetchAll();

$pageTitle = 'User Management';
$activeNav = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between mb-3">
    <h3 class="section-title mb-0">System Users</h3>
    <button class="btn btn-brand" onclick="openUser()"><i class="fa-solid fa-user-plus"></i> Create Account</button>
</div>

<div class="card table-card mb-4"><div class="table-responsive">
<table class="table align-middle mb-0">
    <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Contact</th><th>Status</th><th>Last Login</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
        <tr>
            <td><strong><?= e($u['full_name']) ?></strong></td>
            <td><?= e($u['username']) ?></td>
            <td><span class="badge-soft badge-warn"><?= e(ucfirst($u['role'])) ?></span></td>
            <td><small><?= e($u['email']) ?><br><?= e($u['phone']) ?></small></td>
            <td><span class="badge-soft <?= $u['is_active'] ? 'badge-ok' : 'badge-low' ?>"><?= $u['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td><small><?= $u['last_login'] ? e(date('d M H:i', strtotime($u['last_login']))) : 'Never' ?></small></td>
            <td class="text-end">
                <div class="dropdown">
                    <button class="icon-btn" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick='openUser(<?= json_encode($u) ?>);return false;'><i class="fa-solid fa-pen me-2"></i>Edit</a></li>
                        <li><a class="dropdown-item" href="#" onclick='openReset(<?= (int)$u['id'] ?>);return false;'><i class="fa-solid fa-key me-2"></i>Reset Password</a></li>
                        <li><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button class="dropdown-item"><i class="fa-solid fa-power-off me-2"></i><?= $u['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></li>
                        <li><form method="post" onsubmit="return confirm('Delete user?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                            <button class="dropdown-item text-danger"><i class="fa-solid fa-trash me-2"></i>Delete</button></form></li>
                    </ul>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div></div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <h3 class="section-title"><i class="fa-solid fa-right-to-bracket"></i> Login History</h3>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>User</th><th>IP</th><th>Result</th><th>Time</th></tr></thead><tbody>
            <?php foreach ($logins as $l): ?>
                <tr><td><?= e($l['full_name'] ?? $l['username']) ?></td><td><small><?= e($l['ip_address']) ?></small></td>
                <td><span class="badge-soft <?= $l['success'] ? 'badge-ok' : 'badge-low' ?>"><?= $l['success'] ? 'Success' : 'Failed' ?></span></td>
                <td><small><?= e(date('d M H:i', strtotime($l['created_at']))) ?></small></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div></div></div>
    <div class="col-lg-6"><div class="card"><div class="card-body">
        <h3 class="section-title"><i class="fa-solid fa-clipboard-list"></i> Audit Log</h3>
        <div class="table-responsive"><table class="table table-sm mb-0">
            <thead><tr><th>User</th><th>Action</th><th>Details</th><th>Time</th></tr></thead><tbody>
            <?php foreach ($audits as $a): ?>
                <tr><td><?= e($a['full_name'] ?? '—') ?></td><td><span class="badge-soft badge-warn"><?= e($a['action']) ?></span></td>
                <td><small><?= e($a['details']) ?></small></td><td><small><?= e(date('d M H:i', strtotime($a['created_at']))) ?></small></td></tr>
            <?php endforeach; ?>
        </tbody></table></div>
    </div></div></div>
</div>

<!-- User modal -->
<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="id" id="uid">
        <div class="modal-header"><h5 class="modal-title" id="umTitle">Create Account</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body row g-2">
            <div class="col-12"><label class="form-label">Full Name *</label><input name="full_name" id="ufull" class="form-control" required></div>
            <div class="col-md-6"><label class="form-label">Role *</label><select name="role" id="urole" class="form-select">
                <option value="cashier">Cashier</option><option value="waiter">Waiter</option><option value="inventory">Inventory Officer</option><option value="manager">Manager</option></select></div>
            <div class="col-md-6" id="unameWrap"><label class="form-label">Username *</label><input name="username" id="uname" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" id="uemail" class="form-control"></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input name="phone" id="uphone" class="form-control"></div>
            <div class="col-12" id="pwWrap"><label class="form-label">Password *</label><input type="password" name="password" id="upass" class="form-control"></div>
            <div class="col-12" id="pinWrap">
                <label class="form-label">Login Passcode / PIN <span id="pinReq">*</span></label>
                <input name="passcode" id="upin" class="form-control" inputmode="numeric" pattern="\d{4,8}" maxlength="8" placeholder="4–8 digit PIN">
                <small class="text-muted" id="pinHint">Waiters sign in on the login screen with this PIN. Leave blank to keep unchanged when editing.</small>
            </div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Save</button></div>
    </form>
</div></div>

<!-- Reset password modal -->
<div class="modal fade" id="resetModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" id="rid">
        <div class="modal-header"><h5 class="modal-title">Reset Password</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><label class="form-label">New Password *</label><input type="text" name="new_password" class="form-control" minlength="6" required></div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Reset</button></div>
    </form>
</div></div>

<script>
const userModal = new bootstrap.Modal('#userModal');
const resetModal = new bootstrap.Modal('#resetModal');
let editing = false;
function toggleRoleFields() {
    const isWaiter = urole.value === 'waiter';
    // Waiters: name + PIN only (username auto-generated, no password).
    document.getElementById('unameWrap').style.display = isWaiter ? 'none' : '';
    document.getElementById('pwWrap').style.display = (isWaiter || editing) ? 'none' : '';
    document.getElementById('pinWrap').style.display = '';
    uname.required = !isWaiter && !editing;
    upass.required = !isWaiter && !editing;
    // PIN required only when creating a new waiter.
    upin.required = isWaiter && !editing;
    document.getElementById('pinReq').style.display = (isWaiter && !editing) ? '' : 'none';
    document.getElementById('pinHint').textContent = editing
        ? 'Leave blank to keep the current PIN unchanged.'
        : (isWaiter ? 'Required — the waiter signs in with this PIN.' : 'Optional — lets this user also do a quick PIN login.');
}
urole.addEventListener('change', toggleRoleFields);

function openUser(u) {
    document.querySelector('#userModal form').reset();
    editing = !!u;
    if (u) {
        document.getElementById('umTitle').textContent = 'Edit User';
        uid.value = u.id; ufull.value = u.full_name; uname.value = u.username;
        uemail.value = u.email || ''; uphone.value = u.phone || ''; urole.value = u.role;
    } else {
        document.getElementById('umTitle').textContent = 'Create Account';
        uid.value = '';
    }
    toggleRoleFields();
    userModal.show();
}
function openReset(id) { rid.value = id; resetModal.show(); }
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
