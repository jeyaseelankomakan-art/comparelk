<?php
/**
 * Contact Page - compare.lk
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (!$name || !$email || !$message) {
            $error = t('fill_all_fields');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = t('valid_email');
        } elseif (strlen($message) < 10) {
            $error = t('message_too_short');
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare("INSERT INTO contact_messages (name, email, message) VALUES (?, ?, ?)");
            $stmt->execute([$name, $email, $message]);
            $success = true;
            unset($_POST['name'], $_POST['email'], $_POST['message']);
        }
    }
}

$pageTitle = t('contact_us');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('') ?>"><i class="bi bi-house-fill"></i></a></li>
                <li class="breadcrumb-item active"><?= e(t('contact_us')) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="text-center mb-4">
                <div
                    style="width:60px;height:60px;border-radius:18px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 1rem;">
                    <i class="bi bi-envelope-fill"></i>
                </div>
                <h1 class="fw-800 mb-1" style="font-size:2rem;"><?= e(t('contact_us')) ?></h1>
                <p class="text-muted">Have a question or feedback? We'd love to hear from you.</p>
            </div>

            <?php if ($success): ?>
                <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
                    <i class="bi bi-check-circle-fill"></i>
                    <div>
                        <strong><?= e(t('contact_message_sent')) ?></strong> <?= e(t('contact_thanks')) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <div class="info-card">
                <form method="POST" action="<?= url('pages/contact.php') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label fw-600"><?= e(t('your_name')) ?> <span
                                class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g.Jeyaseelan Komakan "
                            value="<?= isset($_POST['name']) ? e($_POST['name']) : '' ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-600"><?= e(t('email_address')) ?> <span
                                class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" placeholder="e.g. Jkomakan@example.com"
                            value="<?= isset($_POST['email']) ? e($_POST['email']) : '' ?>" required>
                    </div>
                    <div class="mb-4">
                        <label class="form-label fw-600"><?= e(t('message')) ?> <span
                                class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="5" placeholder="Tell us how we can help..."
                            required><?= isset($_POST['message']) ? e($_POST['message']) : (isset($_GET['message']) ? e($_GET['message']) : '') ?></textarea>
                    </div>
                    <button type="submit" class="btn w-100"
                        style="background:var(--primary);color:#fff;border-color:var(--primary);font-weight:700;padding:.75rem;border-radius:12px;">
                        <i class="bi bi-send me-2"></i><?= e(t('send_message')) ?>
                    </button>
                </form>
            </div>

            <div class="row g-3 mt-4">
                <div class="col-4 text-center">
                    <div class="info-card py-3">
                        <i class="bi bi-envelope" style="font-size:1.5rem;color:var(--primary);"></i>
                        <div class="fw-600 mt-2" style="font-size:.85rem;">Email</div>
                        <div class="text-muted" style="font-size:.78rem;">jkomakan@gmail.com</div>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="info-card py-3">
                        <i class="bi bi-whatsapp text-success" style="font-size:1.5rem;"></i>
                        <div class="fw-600 mt-2" style="font-size:.85rem;">WhatsApp</div>
                        <div class="text-muted" style="font-size:.78rem;">+94 76 087 0583</div>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="info-card py-3">
                        <i class="bi bi-geo-alt text-danger" style="font-size:1.5rem;"></i>
                        <div class="fw-600 mt-2" style="font-size:.85rem;">Location</div>
                        <div class="text-muted" style="font-size:.78rem;">Kilinochchi, Sri Lanka</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .fw-600 {
        font-weight: 600;
    }

    .fw-800 {
        font-weight: 800;
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>