<?php
/**
 * Footer - compare.lk
 */
if (!function_exists('t')) {
    require_once __DIR__ . '/lang.php';
}
$footerLogoPath = __DIR__ . '/../assets/img/logo.png';
$footerHasLogo = file_exists($footerLogoPath);
?>
<footer class="site-footer mt-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="footer-brand mb-3">
                    <a href="<?= url('') ?>" class="d-flex align-items-center text-decoration-none">
                        <?php if ($footerHasLogo): ?>
                            <img src="<?= url('assets/img/logo.png') ?>" alt="compare.lk"
                                class="site-logo-img site-logo-img-footer">
                        <?php else: ?>
                            <div class="brand-icon me-2">
                                <i class="bi bi-bar-chart-line-fill"></i>
                            </div>
                            <span class="brand-name text-white">compare<span class="brand-dot">.lk</span></span>
                        <?php endif; ?>
                    </a>
                </div>
                <p class="footer-text"><?= e(t('footer_tagline')) ?></p>
                <div class="social-icons mt-3">
                    <a href="#" class="social-icon" title="Facebook"><i class="bi bi-facebook"></i></a>
                    <a href="#" class="social-icon" title="Twitter / X"><i class="bi bi-twitter-x"></i></a>
                    <a href="#" class="social-icon" title="Instagram"><i class="bi bi-instagram"></i></a>
                    <a href="#" class="social-icon" title="WhatsApp"><i class="bi bi-whatsapp"></i></a>
                </div>
            </div>
            <div class="col-lg-2 col-md-4">
                <h6 class="footer-heading"><?= e(t('footer_categories')) ?></h6>
                <div class="footer-categories-scroll">
                <ul class="footer-links">
                    <?php
                    // $categories may not be set on all pages — re-fetch if needed
                    $footerCategories = isset($categories) ? $categories : getCategories();
                    foreach ($footerCategories as $cat): ?>
                        <li><a href="<?= url('category.php?slug=' . urlencode($cat['slug'])) ?>">
                                <i class="bi <?= e($cat['icon']) ?> me-1"></i><?= e($cat['name']) ?>
                            </a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            </div>
            <div class="col-lg-2 col-md-4">
                <h6 class="footer-heading"><?= e(t('footer_quick_links')) ?></h6>
                <ul class="footer-links">
                    <li><a href="<?= url('') ?>"><i class="bi bi-house me-1"></i><?= e(t('footer_home')) ?></a></li>
                    <li><a href="<?= url('search.php') ?>"><i
                                class="bi bi-search me-1"></i><?= e(t('footer_search')) ?></a></li>
                    <li><a href="<?= url('pages/about.php') ?>"><i
                                class="bi bi-info-circle me-1"></i><?= e(t('footer_about')) ?></a></li>
                    <li><a href="<?= url('pages/contact.php') ?>"><i
                                class="bi bi-envelope me-1"></i><?= e(t('footer_contact')) ?></a></li>
                    <li><a href="<?= url('admin/login.php') ?>"><i class="bi bi-lock me-1"></i><?= e(t('admin')) ?> /
                            Dashboard</a></li>
                </ul>
            </div>
            <div class="col-lg-4 col-md-4">
                <h6 class="footer-heading"><?= e(t('footer_top_stores')) ?></h6>
                <div class="footer-stores">
                    <?php $stores = getStores();
                    foreach ($stores as $store): ?>
                        <a href="<?= e($store['website_url']) ?>" target="_blank" rel="noopener" class="footer-store-badge">
                            <?= e($store['name']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="disclaimer mt-3">
                    <small><i class="bi bi-info-circle me-1"></i><?= e(t('footer_disclaimer')) ?></small>
                </div>
            </div>
        </div>

        <div class="footer-bottom mt-4 pt-3">
            <div class="row align-items-center">
                <div class="col-md-6 text-center text-md-start">
                    <p class="mb-0">&copy; <?= date('Y') ?> <strong>compare.lk</strong> —
                        <?= e(t('footer_copyright')) ?>
                    </p>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <p class="mb-0"><?= e(t('footer_made')) ?> <i class="bi bi-heart-fill text-danger"></i>
                        <?= e(t('footer_in_srilanka')) ?></p>
                </div>
            </div>
        </div>
    </div>
</footer>

<!-- Theme toggle (must run early, does not depend on main.js) -->
<script>
    (function () {
        function getTheme() { return localStorage.getItem('theme') || 'light'; }
        function setTheme(theme) {
            localStorage.setItem('theme', theme);
            document.documentElement.setAttribute('data-theme', theme);
            document.body.classList.toggle('theme-dark', theme === 'dark');
            var iconDark = document.getElementById('iconDark');
            var iconLight = document.getElementById('iconLight');
            if (iconDark) iconDark.classList.toggle('d-none', theme === 'dark');
            if (iconLight) iconLight.classList.toggle('d-none', theme !== 'dark');
        }
        setTheme(getTheme());
        var btn = document.getElementById('themeToggle');
        if (btn) btn.addEventListener('click', function () { setTheme(getTheme() === 'dark' ? 'light' : 'dark'); });
    })();
</script>
<!-- Bootstrap JS -->
<script src="<?= url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<!-- Chart.js -->
<script src="<?= url('assets/vendor/chart.umd.min.js') ?>"></script>
<!-- Custom JS -->
<script src="<?= url('assets/js/main.js') ?>"></script>
</body>

</html>