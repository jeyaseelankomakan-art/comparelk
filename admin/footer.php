<?php
/**
 * Admin Footer - compare.lk (Redesigned)
 */
$currentFile = basename($_SERVER['PHP_SELF']);
?>
<?php if ($currentFile !== 'login.php'): ?>
    </div><!-- /admin-content -->
    </div><!-- /admin-main -->
<?php endif; ?>
</div><!-- /admin-wrapper -->

<script src="<?= url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
<script>
    /* ── Toast auto show ── */
    document.querySelectorAll('.toast').forEach(t => new bootstrap.Toast(t, { delay: 3000 }).show());

    /* ── Alert auto-hide ── */
    document.querySelectorAll('.alert-success, .alert-danger').forEach(el => {
        setTimeout(() => {
            el.style.transition = 'opacity .6s ease, transform .4s ease';
            el.style.opacity = '0';
            el.style.transform = 'translateY(-4px)';
            setTimeout(() => el.remove(), 700);
        }, 4500);
    });

    /* ── Theme toggle ── */
    (function () {
        function getTheme() { return localStorage.getItem('theme') || 'light'; }
        function applyTheme(theme) {
            localStorage.setItem('theme', theme);
            document.documentElement.setAttribute('data-theme', theme);
            if (theme === 'dark') document.documentElement.classList.add('theme-dark');
            else document.documentElement.classList.remove('theme-dark');
            var dIcon = document.getElementById('iconDark');
            var lIcon = document.getElementById('iconLight');
            if (dIcon) dIcon.classList.toggle('d-none', theme !== 'dark');
            if (lIcon) lIcon.classList.toggle('d-none', theme === 'dark');
        }
        applyTheme(getTheme());
        var btn = document.getElementById('themeToggle');
        if (btn) btn.addEventListener('click', () => applyTheme(getTheme() === 'dark' ? 'light' : 'dark'));
    })();

    /* ── Sidebar (mobile) ── */
    function toggleSidebar() {
        var sb = document.getElementById('adminSidebar');
        var ov = document.getElementById('sidebarOverlay');
        var isOpen = sb.classList.toggle('open');
        if (ov) ov.style.display = isOpen ? 'block' : 'none';
    }
    function closeSidebar() {
        var sb = document.getElementById('adminSidebar');
        var ov = document.getElementById('sidebarOverlay');
        if (sb) sb.classList.remove('open');
        if (ov) ov.style.display = 'none';
    }
    /* Close sidebar when a link is clicked on mobile */
    document.querySelectorAll('.sidebar-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth <= 992) closeSidebar();
        });
    });
</script>
</body>

</html>