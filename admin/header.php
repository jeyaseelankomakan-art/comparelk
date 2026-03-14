<?php

/**
 * Admin Header - compare.lk
 */
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/../includes/functions.php';

$currentFile = basename($_SERVER['PHP_SELF']);
if ($currentFile !== 'login.php') {
    if (!isAdminLoggedIn()) {
        redirect(url('admin/login.php'));
    }
}

require_once __DIR__ . '/../includes/lang.php';
$adminPage = $currentFile;
$currentLang = getCurrentLang();
$langBaseUri = preg_replace('/[?&]lang=[^&]*/', '', $_SERVER['REQUEST_URI'] ?? '');
$langBaseUri = rtrim($langBaseUri, '?&');
$langSep = strpos($langBaseUri, '?') !== false ? '&' : '?';
$adminUser = $_SESSION['admin_username'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($adminTitle) ? e($adminTitle) . ' — Admin' : 'Admin Panel' ?> | compare.lk</title>
    <link href="<?= url('assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/vendor/bootstrap-icons/font/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/admin.css') ?>">
    <script>
        (function() {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
            if (t === 'dark') document.documentElement.classList.add('theme-dark');
        })();
    </script>
</head>

<body>
    <div class="admin-wrapper">

        <?php if ($currentFile !== 'login.php'): ?>

            <!-- ═══════════ SIDEBAR ═══════════ -->
            <aside class="admin-sidebar" id="adminSidebar">

                <!-- Brand -->
                <div class="sidebar-brand">
                    <div class="sidebar-logo-row">
                        <div class="sidebar-logo-icon">
                            <i class="bi bi-bar-chart-line-fill"></i>
                        </div>
                        <div class="sidebar-logo-text">
                            <span class="brand-name">compare<span class="brand-dot">.lk</span></span>
                            <small>Admin Panel</small>
                        </div>
                    </div>
                </div>

                <!-- Nav -->
                <nav class="sidebar-nav">
                    <span class="nav-section-label"><?= e(t('admin_main')) ?></span>
                    <a href="<?= url('admin/dashboard.php') ?>"
                        class="sidebar-link <?= $adminPage === 'dashboard.php' ? 'active' : '' ?>">
                        <i class="bi bi-speedometer2"></i> <?= e(t('admin_dashboard')) ?>
                    </a>

                    <span class="nav-section-label"><?= e(t('admin_catalog')) ?></span>
                    <a href="<?= url('admin/categories.php') ?>"
                        class="sidebar-link <?= $adminPage === 'categories.php' ? 'active' : '' ?>">
                        <i class="bi bi-grid-3x3-gap"></i> <?= e(t('admin_categories')) ?>
                    </a>
                    <a href="<?= url('admin/stores.php') ?>"
                        class="sidebar-link <?= $adminPage === 'stores.php' ? 'active' : '' ?>">
                        <i class="bi bi-shop"></i> <?= e(t('admin_stores')) ?>
                    </a>
                    <a href="<?= url('admin/products.php') ?>"
                        class="sidebar-link <?= $adminPage === 'products.php' ? 'active' : '' ?>">
                        <i class="bi bi-box-seam"></i> <?= e(t('admin_products')) ?>
                    </a>
                    <a href="<?= url('admin/imported-products.php') ?>"
                        class="sidebar-link <?= $adminPage === 'imported-products.php' ? 'active' : '' ?>">
                        <i class="bi bi-cloud-arrow-down"></i> Imported Products
                    </a>
                    <a href="<?= url('admin/import-sources.php') ?>"
                        class="sidebar-link <?= $adminPage === 'import-sources.php' ? 'active' : '' ?>">
                        <i class="bi bi-rss"></i> Import Sources
                    </a>

                        <a href="<?= url('admin/prices.php') ?>"
                            class="sidebar-link <?= $adminPage === 'prices.php' ? 'active' : '' ?>">
                            <i class="bi bi-tags"></i> <?= e(t('admin_prices')) ?>
                        </a>

                        <span class="nav-section-label"><?= e(t('admin_reports')) ?></span>
                        <a href="<?= url('admin/price-log.php') ?>"
                            class="sidebar-link <?= $adminPage === 'price-log.php' ? 'active' : '' ?>">
                            <i class="bi bi-journal-text"></i> <?= e(t('admin_price_log')) ?>
                        </a>
                        <a href="<?= url('admin/messages.php') ?>"
                            class="sidebar-link <?= $adminPage === 'messages.php' ? 'active' : '' ?>">
                            <i class="bi bi-envelope"></i> <?= e(t('admin_messages')) ?>
                        </a>

                        <span class="nav-section-label"><?= e(t('admin_tools')) ?></span>
                        <a href="<?= url('admin/scraper.php') ?>"
                            class="sidebar-link <?= $adminPage === 'scraper.php' ? 'active' : '' ?>">
                            <i class="bi bi-cloud-download"></i> <?= e(t('admin_scraper')) ?>
                        </a>

                        <span class="nav-section-label"><?= e(t('admin_site')) ?></span>
                        <a href="<?= url('') ?>" target="_blank" class="sidebar-link">
                            <i class="bi bi-box-arrow-up-right"></i> <?= e(t('admin_view_site')) ?>
                        </a>
                </nav>

                <!-- Footer -->
                <div class="sidebar-footer">
                    <div class="sidebar-footer-user">
                        <div class="sf-avatar"><?= strtoupper(substr($adminUser, 0, 1)) ?></div>
                        <div>
                            <span class="sf-username"><?= e($adminUser) ?></span>
                            <span class="sf-role">Administrator</span>
                        </div>
                    </div>
                    <a href="<?= url('admin/logout.php') ?>" class="sidebar-logout-btn">
                        <i class="bi bi-box-arrow-left"></i> <?= e(t('admin_logout')) ?>
                    </a>
                </div>
            </aside>

            <!-- Overlay for mobile -->
            <div id="sidebarOverlay"
                style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:290;backdrop-filter:blur(2px);"
                onclick="closeSidebar()"></div>

            <!-- ═══════════ MAIN ═══════════ -->
            <div class="admin-main">

                <!-- Top Bar -->
                <header class="admin-topbar">
                    <div class="topbar-left">
                        <!-- Mobile toggle -->
                        <button class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleSidebar()">
                            <i class="bi bi-list"></i>
                        </button>

                        <div>
                            <div class="topbar-page-title"><?= isset($adminTitle) ? e($adminTitle) : 'Dashboard' ?></div>
                            <div class="topbar-breadcrumb">
                                <i class="bi bi-house"></i>
                                <span>Admin</span>
                                <?php if (isset($adminTitle)): ?>
                                    <i class="bi bi-chevron-right" style="font-size:.6rem;"></i>
                                    <span><?= e($adminTitle) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="topbar-right">
                        <!-- Language selector -->
                        <div class="dropdown">
                            <a href="#" class="btn btn-sm btn-outline-secondary admin-lang-btn" id="adminLangDropdown"
                                role="button" data-bs-toggle="dropdown" aria-expanded="false" style="gap:.3rem;">
                                <i class="bi bi-translate"></i>
                                <?= $currentLang === 'en' ? 'EN' : ($currentLang === 'ta' ? 'TA' : 'SI') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminLangDropdown">
                                <li><a class="dropdown-item <?= $currentLang === 'en' ? 'active' : '' ?>"
                                        href="<?= e($langBaseUri . $langSep . 'lang=en') ?>">🇬🇧 English</a></li>
                                <li><a class="dropdown-item <?= $currentLang === 'ta' ? 'active' : '' ?>"
                                        href="<?= e($langBaseUri . $langSep . 'lang=ta') ?>">🇮🇳 தமிழ்</a></li>
                                <li><a class="dropdown-item <?= $currentLang === 'si' ? 'active' : '' ?>"
                                        href="<?= e($langBaseUri . $langSep . 'lang=si') ?>">🇱🇰 සිංහල</a></li>
                            </ul>
                        </div>

                        <!-- Theme toggle -->
                        <button type="button" class="theme-toggle-btn" id="themeToggle" title="Toggle theme">
                            <i class="bi bi-moon-stars-fill d-none" id="iconDark"></i>
                            <i class="bi bi-sun-fill" id="iconLight"></i>
                        </button>

                        <!-- View site -->
                        <a href="<?= url('') ?>" target="_blank"
                            class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex">
                            <i class="bi bi-arrow-up-right-square"></i> <span>View Site</span>
                        </a>

                        <!-- Avatar + logout -->
                        <div class="dropdown">
                            <button class="admin-avatar" data-bs-toggle="dropdown" aria-expanded="false"
                                style="border:none;cursor:pointer;" title="<?= e($adminUser) ?>">
                                <?= strtoupper(substr($adminUser, 0, 1)) ?>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <span class="dropdown-item disabled" style="opacity:.6;font-size:.78rem;">
                                        Signed in as <strong><?= e($adminUser) ?></strong>
                                    </span>
                                </li>
                                <li>
                                    <hr class="dropdown-divider my-1">
                                </li>
                                <li>
                                    <a class="dropdown-item text-danger" href="<?= url('admin/logout.php') ?>">
                                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                                    </a>
                                </li>
                            </ul>
                        </div>
                    </div>
                </header>

                <div class="admin-content">
                <?php endif; ?>