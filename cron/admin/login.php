<?php

/**
 * Admin Login - compare.lk
 */
if (session_status() === PHP_SESSION_NONE)
    session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/lang.php';

function loginThrottleKey(string $username): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return strtolower(trim($username)) . '|' . $ip;
}

function loginThrottleState(string $key): array
{
    $state = $_SESSION['admin_login_throttle'][$key] ?? [
        'fails' => 0,
        'lock_until' => 0,
    ];
    return [
        'fails' => (int) ($state['fails'] ?? 0),
        'lock_until' => (int) ($state['lock_until'] ?? 0),
    ];
}

function loginThrottleFail(string $key): void
{
    $state = loginThrottleState($key);
    $state['fails']++;
    if ($state['fails'] >= 5) {
        $state['fails'] = 0;
        $state['lock_until'] = time() + 600;
    }
    $_SESSION['admin_login_throttle'][$key] = $state;
}

function loginThrottleSuccess(string $key): void
{
    unset($_SESSION['admin_login_throttle'][$key]);
}

// Already logged in
if (isAdminLoggedIn())
    redirect(url('admin/dashboard.php'));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $throttleKey = loginThrottleKey($username);
        $throttle = loginThrottleState($throttleKey);

        if (!$username || !$password) {
            $error = t('fill_all_fields');
        } elseif ($throttle['lock_until'] > time()) {
            $wait = $throttle['lock_until'] - time();
            $error = 'Too many failed attempts. Try again in ' . $wait . ' seconds.';
        } else {
            $pdo = getDB();
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? LIMIT 1");
            $stmt->execute([$username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                loginThrottleSuccess($throttleKey);
                session_regenerate_id(true);
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_username'] = $admin['username'];
                redirect(url('admin/dashboard.php'));
            } else {
                loginThrottleFail($throttleKey);
                $error = 'Invalid username or password.'; // Keep in English for security messages
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(t('admin_login')) ?> — compare.lk</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('assets/css/fonts.css') ?>">
    <link rel="stylesheet" href="<?= url('assets/css/admin.css') ?>">
    <style>
        body {
            background: linear-gradient(135deg, #2B1010 0%, #3A1818 45%, #8B2C2C 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: #fff;
            border-radius: 20px;
            padding: 2.5rem;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 30px 80px rgba(0, 0, 0, .35);
        }

        .login-logo {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #8B2C2C, #F6A623);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            color: #fff;
            margin: 0 auto 1rem;
        }

        .form-control {
            border-radius: 10px;
            border: 1.5px solid #E6D3CC;
            padding: .65rem 1rem;
        }

        .form-control:focus {
            border-color: #F6A623;
            box-shadow: 0 0 0 3px rgba(246, 166, 35, .25);
        }

        .btn-login {
            background: #F6A623;
            border: none;
            border-radius: 10px;
            padding: .75rem;
            font-weight: 700;
            font-size: 1rem;
            letter-spacing: .3px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
        }

        .btn-login:hover {
            background: #D48806;
        }

        /* Eye-toggle button inside the password input group */
        .pwd-eye {
            border: 1.5px solid #E4E9F5;
            border-left: none;
            border-radius: 0 10px 10px 0;
            background: #F8F9FC;
            padding: 0 .85rem;
            cursor: pointer;
            color: #6b7a99;
            transition: color .15s;
            display: flex;
            align-items: center;
        }

        .pwd-eye:hover {
            color: #F6A623;
        }

        /* Keep border colour when the input inside is focused */
        .pwd-group:focus-within .pwd-eye {
            border-color: #F6A623;
        }

        .pwd-group:focus-within .form-control {
            border-color: #F6A623;
            box-shadow: 0 0 0 3px rgba(246, 166, 35, .25);
        }

        /* Remove the right-border-radius from the password input (eye sits next to it) */
        .pwd-group .form-control {
            border-radius: 0 !important;
            border-right: none;
        }
    </style>
</head>

<body>
    <div class="login-card">
        <div class="text-center mb-4">
            <div class="login-logo"><i class="bi bi-bar-chart-line-fill"></i></div>
            <div style="font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.4rem;">
                <span style="color:#8B2C2C;">compare</span><span style="color:#F6A623;">.lk</span>
            </div>
            <div class="text-muted mt-1" style="font-size:.875rem;"><?= e(t('admin_panel')) ?> — <?= e(t('sign_in')) ?>
            </div>
            <p class="small text-muted mt-2 mb-0">After login you will be redirected to the <strong>Admin
                    Dashboard</strong> (categories, products, stores, prices, messages).</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 py-2 mb-3"
                style="border-radius:10px;font-size:.875rem;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="<?= url('admin/login.php') ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" style="font-weight:600;font-size:.85rem;"><?= e(t('username')) ?></label>
                <div class="input-group">
                    <span class="input-group-text"
                        style="border-radius:10px 0 0 10px;border:1.5px solid #E4E9F5;border-right:none;background:#F8F9FC;">
                        <i class="bi bi-person text-muted"></i>
                    </span>
                    <input type="text" name="username" class="form-control" style="border-radius:0 10px 10px 0;"
                        placeholder="admin" autocomplete="username"
                        value="<?= isset($_POST['username']) ? e($_POST['username']) : '' ?>" required>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label" style="font-weight:600;font-size:.85rem;"><?= e(t('password')) ?></label>
                <div class="input-group pwd-group">
                    <span class="input-group-text"
                        style="border-radius:10px 0 0 10px;border:1.5px solid #E4E9F5;border-right:none;background:#F8F9FC;">
                        <i class="bi bi-lock text-muted"></i>
                    </span>
                    <input type="password" name="password" id="loginPassword" class="form-control"
                        placeholder="••••••••" autocomplete="current-password" required>
                    <button type="button" class="pwd-eye" id="togglePwd"
                        title="Show / hide password" aria-label="Toggle password visibility">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-login w-100 text-white">
                <i class="bi bi-box-arrow-in-right"></i><?= e(t('sign_in')) ?>
            </button>
        </form>

        <div class="text-center mt-4 d-flex flex-column gap-2">
            <a href="<?= url('admin/dashboard.php') ?>" class="text-primary small fw-600">Go to Admin Dashboard (if
                already logged in)</a>
            <a href="<?= url('index.php') ?>" class="text-muted" style="font-size:.8rem;">
                <i class="bi bi-arrow-left me-1"></i><?= e(t('back_to_site')) ?>
            </a>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            var btn = document.getElementById('togglePwd');
            var inp = document.getElementById('loginPassword');
            var icon = document.getElementById('eyeIcon');
            if (!btn) return;
            btn.addEventListener('click', function() {
                var isPassword = inp.type === 'password';
                inp.type = isPassword ? 'text' : 'password';
                icon.className = isPassword ? 'bi bi-eye-slash' : 'bi bi-eye';
                btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
            });
        })();
    </script>
</body>

</html>