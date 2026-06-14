<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('settings');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $logo = $_POST['existing_logo'] ?? null;
    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg'], true)) {
            $fn = 'logo_' . time() . '.' . $ext;
            move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . '/logo/' . $fn);
            $logo = 'uploads/logo/' . $fn;
        }
    }

    $pdo->prepare(
        'UPDATE settings SET company_name=?, logo=?, currency=?, tax_rate=?, address=?, phone=?, email=?, receipt_footer=? WHERE id=1'
    )->execute([
        trim($_POST['company_name']), $logo, trim($_POST['currency']), (float) $_POST['tax_rate'],
        trim($_POST['address']), trim($_POST['phone']), trim($_POST['email']), trim($_POST['receipt_footer']),
    ]);
    audit('settings_update', 'Company settings updated');
    flash('Settings saved.');
    redirect('settings.php');
}

$s = settings();
$pageTitle = 'Settings';
$activeNav = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card"><div class="card-body">
    <h3 class="section-title"><i class="fa-solid fa-gear"></i> System Settings</h3>
    <form method="post" enctype="multipart/form-data" class="row g-3">
        <?= csrf_field() ?>
        <input type="hidden" name="existing_logo" value="<?= e($s['logo'] ?? '') ?>">
        <div class="col-md-6"><label class="form-label">Company Name</label>
            <input name="company_name" class="form-control" value="<?= e($s['company_name'] ?? '') ?>" required></div>
        <div class="col-md-3"><label class="form-label">Currency</label>
            <input name="currency" class="form-control" value="<?= e($s['currency'] ?? 'KSh') ?>"></div>
        <div class="col-md-3"><label class="form-label">Tax Rate (%)</label>
            <input type="number" step="0.01" name="tax_rate" class="form-control" value="<?= e($s['tax_rate'] ?? 16) ?>"></div>
        <div class="col-md-6"><label class="form-label">Phone</label>
            <input name="phone" class="form-control" value="<?= e($s['phone'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= e($s['email'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Store Address</label>
            <input name="address" class="form-control" value="<?= e($s['address'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Receipt Footer Message</label>
            <input name="receipt_footer" class="form-control" value="<?= e($s['receipt_footer'] ?? '') ?>"></div>
        <div class="col-md-6"><label class="form-label">Company Logo</label>
            <input type="file" name="logo" class="form-control" accept="image/*">
            <?php if (!empty($s['logo'])): ?><small class="text-muted">Current: <?= e($s['logo']) ?></small><?php endif; ?></div>
        <div class="col-12 text-end"><button class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save Settings</button></div>
    </form>
</div></div>
</div></div>
<?php require __DIR__ . '/includes/footer.php'; ?>
