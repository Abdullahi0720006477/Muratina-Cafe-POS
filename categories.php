<?php
require_once __DIR__ . '/includes/auth.php';
require_permission('products');

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Category deleted.');
    } elseif (!empty($_POST['id'])) {
        $pdo->prepare('UPDATE categories SET name=?, icon=? WHERE id=?')
            ->execute([trim($_POST['name']), $_POST['icon'] ?: 'fa-mug-hot', (int) $_POST['id']]);
        flash('Category updated.');
    } else {
        $pdo->prepare('INSERT INTO categories (name, icon) VALUES (?,?)')
            ->execute([trim($_POST['name']), $_POST['icon'] ?: 'fa-mug-hot']);
        flash('Category added.');
    }
    audit('category_save', $_POST['name'] ?? '');
    redirect('categories.php');
}

$cats = $pdo->query(
    'SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active=1) AS cnt
     FROM categories c ORDER BY c.name'
)->fetchAll();

$pageTitle = 'Categories';
$activeNav = 'categories';
require __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between mb-3">
    <h3 class="section-title mb-0">Product Categories</h3>
    <button class="btn btn-brand" onclick="openCat()"><i class="fa-solid fa-plus"></i> Add Category</button>
</div>

<div class="row g-3">
    <?php foreach ($cats as $c): ?>
        <div class="col-md-4 col-lg-3">
            <div class="card"><div class="card-body d-flex align-items-center gap-3">
                <span class="kpi-icon bg-c1 mb-0"><i class="fa-solid <?= e($c['icon']) ?>"></i></span>
                <div class="flex-fill">
                    <strong><?= e($c['name']) ?></strong><br>
                    <small class="text-muted"><?= (int) $c['cnt'] ?> products</small>
                </div>
                <div class="dropdown">
                    <button class="icon-btn" data-bs-toggle="dropdown"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#" onclick='openCat(<?= json_encode($c) ?>);return false;'><i class="fa-solid fa-pen me-2"></i>Edit</a></li>
                        <li><form method="post" onsubmit="return confirm('Delete category?')"><?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button class="dropdown-item text-danger"><i class="fa-solid fa-trash me-2"></i>Delete</button></form></li>
                    </ul>
                </div>
            </div></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="modal fade" id="catModal" tabindex="-1"><div class="modal-dialog">
    <form class="modal-content" method="post"><?= csrf_field() ?><input type="hidden" name="id" id="cid">
        <div class="modal-header"><h5 class="modal-title" id="cmTitle">Add Category</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <label class="form-label">Name *</label><input name="name" id="cname" class="form-control mb-3" required>
            <label class="form-label">Icon (Font Awesome class)</label>
            <input name="icon" id="cicon" class="form-control" placeholder="fa-mug-hot" value="fa-mug-hot">
            <small class="text-muted">e.g. fa-mug-hot, fa-bowl-food, fa-ice-cream</small>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand">Save</button></div>
    </form>
</div></div>

<script>
const catModal = new bootstrap.Modal('#catModal');

const cid = document.getElementById('cid');
const cname = document.getElementById('cname');
const cicon = document.getElementById('cicon');

function openCat(c) {
    document.querySelector('#catModal form').reset();
    document.getElementById('cmTitle').textContent = c ? 'Edit Category' : 'Add Category';
    cid.value = c ? c.id : ''; cname.value = c ? c.name : ''; cicon.value = c ? c.icon : 'fa-mug-hot';
    catModal.show();
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
