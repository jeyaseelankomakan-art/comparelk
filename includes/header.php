<?php
/**
 * Header - compare.lk
 */
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/lang.php';
$categories = getCategories();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$currentLang = getCurrentLang();
$langBaseUri = preg_replace('/[?&]lang=[^&]*/', '', $_SERVER['REQUEST_URI'] ?? '');
$langBaseUri = rtrim($langBaseUri, '?&');
$langSep = strpos($langBaseUri, '?') !== false ? '&' : '?';
$logoPath = __DIR__ . '/../assets/img/logo.png';
$hasLogo = file_exists($logoPath);
?>
<!DOCTYPE html>
<html lang="<?= $currentLang === 'si' ? 'si' : ($currentLang === 'ta' ? 'ta' : 'en') ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? e($pageTitle) . ' | compare.lk' : 'compare.lk — ' . e(t('site_tagline')) ?></title>
    <meta name="description"
        content="<?= isset($pageDesc) ? e($pageDesc) : 'Compare prices from top Sri Lankan online stores. Find the best deals on phones, laptops, TVs and more.' ?>">
    <script>
        (function () {
            var t = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link rel="stylesheet" href="<?= url('assets/css/fonts.css') ?>">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
    <script>
        window.APP_BASE = <?= json_encode(rtrim(url(''), '/')) ?>;
    </script>
</head>

<body>
    <script>
        (function () {
            try {
                var t = localStorage.getItem('theme') || 'light';
                document.body.classList.toggle('theme-dark', t === 'dark');
            } catch (e) { }
        })();
    </script>

    <!-- Top Bar (language + location) -->
    <div class="topbar">
        <div class="container">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 py-1">
                <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i> <?= e(t('topbar_msg')) ?></span>
                <div class="d-flex align-items-center gap-3">
                    <span><i class="bi bi-geo-alt-fill me-1"></i> Sri Lanka</span>

                </div>
            </div>
        </div>
    </div>

    <!-- Main Navigation -->
    <nav class="navbar navbar-expand-lg navbar-light sticky-top" id="mainNav">
        <div class="container">
            <a class="navbar-brand d-flex align-items-center" href="<?= url('') ?>">
                <?php if ($hasLogo): ?>
                    <img src="<?= url('assets/img/logo.png') ?>" alt="compare.lk" class="site-logo-img">
                <?php else: ?>
                    <div class="brand-icon me-2">
                        <i class="bi bi-bar-chart-line-fill"></i>
                    </div>
                    <div>
                        <span class="brand-name">compare<span class="brand-dot">.lk</span></span>
                    </div>
                <?php endif; ?>
            </a>

            <!-- Search Bar -->
            <form class="search-form d-none d-lg-flex flex-grow-1 mx-4" action="<?= url('search.php') ?>" method="GET">
                <div class="input-group">
                    <input type="text" name="q" class="form-control search-input"
                        placeholder="<?= e(t('search_placeholder')) ?>"
                        value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>" autocomplete="off" id="mainSearchInput">
                    <button class="btn btn-search" type="submit">
                        <i class="bi bi-search"></i> <?= e(t('nav_search')) ?>
                    </button>
                </div>
                <div class="search-suggestions" id="searchSuggestions"></div>
            </form>

            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>

            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center gap-1">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-grid me-1"></i><?= e(t('nav_categories')) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-categories">
                            <?php foreach ($categories as $cat): ?>
                                <li>
                                    <a class="dropdown-item"
                                        href="<?= url('category.php?slug=' . urlencode($cat['slug'])) ?>">
                                        <i class="bi <?= e($cat['icon']) ?> me-2" style="color:var(--primary);"></i>
                                        <?= e($cat['name']) ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('pages/about.php') ?>"><i
                                class="bi bi-info-circle me-1"></i><?= e(t('nav_about')) ?></a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('pages/contact.php') ?>"><i
                                class="bi bi-envelope me-1"></i><?= e(t('nav_contact')) ?></a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navLangDropdown" role="button"
                            data-bs-toggle="dropdown" aria-expanded="false">
                            <i
                                class="bi bi-globe me-1"></i><?= $currentLang === 'en' ? 'EN' : ($currentLang === 'ta' ? 'தமிழ்' : 'සිංහල') ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navLangDropdown">
                            <li><a class="dropdown-item <?= $currentLang === 'en' ? 'active' : '' ?>"
                                    href="<?= e($langBaseUri . $langSep . 'lang=en') ?>">English</a></li>
                            <li><a class="dropdown-item <?= $currentLang === 'ta' ? 'active' : '' ?>"
                                    href="<?= e($langBaseUri . $langSep . 'lang=ta') ?>">தமிழ்</a></li>
                            <li><a class="dropdown-item <?= $currentLang === 'si' ? 'active' : '' ?>"
                                    href="<?= e($langBaseUri . $langSep . 'lang=si') ?>">සිංහල</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <button type="button"
                            class="btn btn-outline-secondary btn-sm theme-toggle-btn border-0 py-2 px-3 rounded"
                            id="themeToggle" title="Toggle dark/light theme" aria-label="Toggle theme">
                            <i class="bi bi-moon-stars-fill d-none" id="iconDark" aria-hidden="true"></i>
                            <i class="bi bi-sun-fill" id="iconLight" aria-hidden="true"></i>
                        </button>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('admin/login.php') ?>"><i
                                class="bi bi-lock me-1"></i><?= e(t('admin')) ?></a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Mobile Search -->
    <div class="mobile-search d-lg-none">
        <div class="container py-2">
            <form action="<?= url('search.php') ?>" method="GET">
                <div class="input-group">
                    <input type="text" name="q" class="form-control"
                        placeholder="<?= e(t('search_placeholder_short')) ?>"
                        value="<?= isset($_GET['q']) ? e($_GET['q']) : '' ?>">
                    <button class="btn" style="background:var(--primary);color:#fff;border-color:var(--primary);"
                        type="submit"><i class="bi bi-search"></i></button>
                </div>
            </form>
        </div>
    </div>