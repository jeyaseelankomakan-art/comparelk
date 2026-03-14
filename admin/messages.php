<?php
/**
 * Admin Contact Messages - compare.lk
 */
$adminTitle = 'Contact Messages';
require_once __DIR__ . '/header.php';

$pdo = getDB();
$msg = '';

// Delete message (POST + CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        $error = 'Security check failed.';
    } else {
        $pdo->prepare("DELETE FROM contact_messages WHERE id=?")->execute([(int) $_POST['delete_id']]);
        $msg = 'Message deleted.';
    }
}

$messages = $pdo->query("SELECT * FROM contact_messages ORDER BY created_at DESC")->fetchAll();
?>

<?php if ($msg): ?>
    <div class="alert alert-success mb-3"><i class="bi bi-check-circle me-2"></i><?= e($msg) ?></div><?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-3">
    <span class="text-muted"><?= count($messages) ?> message<?= count($messages) != 1 ? 's' : '' ?></span>
</div>

<?php if (empty($messages)): ?>
    <div class="empty-state" style="background:#fff;border-radius:var(--admin-radius);padding:3rem;text-align:center;">
        <i class="bi bi-envelope" style="font-size:3rem;color:#ccc;"></i>
        <h5 class="mt-3">No messages yet</h5>
        <p class="text-muted">Contact form submissions will appear here.</p>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($messages as $m): ?>
            <div class="col-lg-6">
                <div class="form-card h-100">
                    <div class="form-card-header d-flex align-items-start justify-content-between">
                        <div>
                            <div class="fw-700"><?= e($m['name']) ?></div>
                            <a href="mailto:<?= e($m['email']) ?>" class="text-primary" style="font-size:.85rem;">
                                <i class="bi bi-envelope me-1"></i><?= e($m['email']) ?>
                            </a>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted"
                                style="font-size:.78rem;"><?= date('d M Y H:i', strtotime($m['created_at'])) ?></span>
                            <form method="POST" action="<?= url('admin/messages.php') ?>" class="d-inline"
                                onsubmit="return confirm('Delete this message?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="delete_id" value="<?= $m['id'] ?>">
                                <button type="submit" class="btn btn-icon btn-outline-danger btn-sm" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="form-card-body">
                        <p class="mb-0" style="font-size:.9rem;line-height:1.7;"><?= nl2br(e($m['message'])) ?></p>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
    .fw-700 {
        font-weight: 700;
    }
</style>
<?php require_once __DIR__ . '/footer.php'; ?>