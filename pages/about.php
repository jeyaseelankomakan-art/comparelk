<?php
/**
 * About Page - compare.lk
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';
$pageTitle = t('about_us');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="breadcrumb-section">
    <div class="container">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?= url('index.php') ?>"><i class="bi bi-house-fill"></i></a></li>
                <li class="breadcrumb-item active"><?= e(t('about_us')) ?></li>
            </ol>
        </nav>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="text-center mb-5">
                <div class="brand-icon mx-auto mb-3" style="width:72px;height:72px;border-radius:20px;font-size:2rem;">
                    <i class="bi bi-bar-chart-line-fill"></i>
                </div>
                <h1 class="fw-800" style="font-size:2.4rem;letter-spacing:-.5px;"><?= e(t('about_us')) ?> <span
                        style="color:var(--primary);">compare.lk</span></h1>
                <p class="text-muted lead mt-3"><?= e(t('about_tagline')) ?></p>
            </div>

            <div class="info-card mb-4">
                <h5 class="fw-700 mb-3"><i class="bi bi-lightbulb me-2 text-warning"></i><?= e(t('our_mission')) ?></h5>
                <p><?= e(t('about_mission_p1')) ?></p>
                <p><?= e(t('about_mission_p2')) ?></p>
            </div>

            <div class="info-card info-card-prices mb-4">
                <h5 class="fw-700 mb-3"><i class="bi bi-info-circle me-2"
                        style="color:var(--primary);"></i><?= e(t('about_prices')) ?></h5>
                <div class="alert alert-warning d-flex align-items-start gap-2">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                    <div>
                        <?= nl2br(e(t('about_prices_alert'))) ?>
                    </div>
                </div>
                <ul style="font-size:.9rem;line-height:2;">
                    <li><?= e(t('about_prices_b1')) ?></li>
                    <li><?= e(t('about_prices_b2')) ?></li>
                    <li><?= e(t('about_prices_b3')) ?></li>
                    <li><?= e(t('about_prices_b4')) ?></li>
                    <li><?= e(t('about_prices_b5')) ?></li>
                </ul>
            </div>

            <div class="row g-3 mb-4">
                <?php $features = [
                    ['bi-search', t('about_feature1_title'), t('about_feature1_desc')],
                    ['bi-table', t('about_feature2_title'), t('about_feature2_desc')],
                    ['bi-graph-up', t('about_feature3_title'), t('about_feature3_desc')],
                    ['bi-shield-check', t('about_feature4_title'), t('about_feature4_desc')],
                ];
                foreach ($features as $f): ?>
                    <div class="col-md-6">
                        <div class="d-flex gap-3 info-card h-100">
                            <div
                                style="width:42px;height:42px;border-radius:12px;background:var(--primary-light);color:var(--primary);display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;">
                                <i class="bi <?= $f[0] ?>"></i>
                            </div>
                            <div>
                                <div class="fw-700"><?= $f[1] ?></div>
                                <div class="text-muted" style="font-size:.875rem;"><?= $f[2] ?></div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="info-card mb-4">
                <h5 class="fw-700 mb-3"><i class="bi bi-shop me-2 text-success"></i><?= e(t('about_partner_title')) ?>
                </h5>
                <p class="text-muted" style="font-size:.9rem;"><?= e(t('about_partner_desc')) ?></p>
                <div class="d-flex flex-wrap gap-2 mt-2">
                    <?php $stores = getStores();
                    foreach ($stores as $s): ?>
                        <a href="<?= e($s['website_url']) ?>" target="_blank" rel="noopener" class="footer-store-badge"
                            style="background:var(--gray-200);border-color:var(--border);color:var(--text-primary);">
                            <i class="bi bi-box-arrow-up-right me-1" style="font-size:.7rem;"></i><?= e($s['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="text-center">
                <a href="<?= url('pages/contact.php') ?>" class="btn me-2"
                    style="background:var(--primary);color:#fff;border-color:var(--primary);">
                    <i class="bi bi-envelope me-2"></i><?= e(t('contact_us')) ?>
                </a>
                <a href="<?= url('index.php') ?>" class="btn"
                    style="color:var(--primary);border-color:var(--primary);background:transparent;">
                    <i class="bi bi-search me-2"></i><?= e(t('start_comparing')) ?>
                </a>
            </div>
        </div>
    </div>
</div>

<style>
    .fw-700 {
        font-weight: 700;
    }

    .fw-800 {
        font-weight: 800;
    }

    .info-card-prices {
        background: #FFF7E6;
        border-color: var(--accent);
    }

    .info-card-prices h5,
    .info-card-prices p,
    .info-card-prices li,
    .info-card-prices strong {
        color: var(--text-primary);
    }

    .info-card-prices .alert-warning {
        background-color: rgba(246, 166, 35, 0.16);
        /* soft gold, matches logo */
        border-color: var(--primary);
        color: var(--text-primary);
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>