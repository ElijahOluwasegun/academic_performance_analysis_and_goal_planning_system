<?php
session_start();

// ─── Admin credentials ────────────────────────────────────────────────────────
// To change the password, update ADMIN_PASSWORD below.
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'Admin@2024');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        header('Location: AdminLogin.php?error=empty_fields');
        exit();
    }

    if ($username === ADMIN_USERNAME && $password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: AdminDashboard.php');
        exit();
    }

    header('Location: AdminLogin.php?error=invalid_credentials');
    exit();
}

// Already logged in — skip the form
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: AdminDashboard.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Cavendish University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f8fb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #213769;
            padding: 1.2em 1.8rem;
        }
        header h4 {
            color: #d9c581;
            font-family: 'Montserrat', sans-serif;
            letter-spacing: 0.22rem;
            font-weight: 600;
            font-size: 0.72rem;
            text-transform: uppercase;
            margin-bottom: 0.2rem;
        }
        header h1 {
            color: #fff;
            font-size: 1.15rem;
            font-weight: 700;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2.5rem 1.5rem;
        }

        .login-card {
            background: #fff;
            border: 1px solid #d4d8e2;
            border-radius: 12px;
            padding: 2.4rem 2.4rem 2rem;
            width: 100%;
            max-width: 22rem;
            box-shadow: 0 2px 12px rgba(33,55,105,0.07);
        }

        .eyebrow {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.18rem;
            text-transform: uppercase;
            color: #213769;
            margin-bottom: 0.55rem;
        }

        .login-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #16213f;
            margin-bottom: 0.35rem;
        }

        .login-sub {
            font-size: 0.84rem;
            color: #5a6378;
            margin-bottom: 1.7rem;
            line-height: 1.5;
        }

        .alert {
            background: #fdecec;
            border: 1px solid #f3b9b9;
            color: #9b2c2c;
            font-size: 0.84rem;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            margin-bottom: 1.2rem;
        }

        form { display: flex; flex-direction: column; }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1.15rem;
        }

        label { font-size: 0.8rem; font-weight: 600; color: #2b3550; }

        input {
            height: 2.75em;
            padding: 0 0.85em;
            border: 1.5px solid #d4d8e2;
            border-radius: 9px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #1c2433;
        }
        input:focus {
            outline: none;
            border-color: #213769;
            box-shadow: 0 0 0 3px rgba(33,55,105,0.12);
        }

        .btn-login {
            font-size: 0.92rem;
            font-weight: 700;
            background: #213769;
            color: #fff;
            border: 0;
            border-radius: 9px;
            padding: 0.78em 1em;
            margin-top: 0.4em;
            cursor: pointer;
        }
        .btn-login:hover { background: #121e38; }

        .footnote {
            margin-top: 1.4rem;
            font-size: 0.77rem;
            color: #7b8294;
            text-align: center;
        }

        .portal-link {
            margin-top: 0.85rem;
            font-size: 0.8rem;
            text-align: center;
            color: #7b8294;
        }
        .portal-link a { color: #213769; font-weight: 600; text-decoration: none; }
        .portal-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<header>
    <h4>Cavendish University</h4>
    <h1>Admin Panel</h1>
</header>

<main>
    <div class="login-card">
        <p class="eyebrow">Administration</p>
        <h2 class="login-title">Admin Login</h2>
        <p class="login-sub">Sign in to manage module assignments and lecturer accounts.</p>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert">
                <?php
                echo match($_GET['error']) {
                    'invalid_credentials' => 'Incorrect username or password. Please try again.',
                    'empty_fields'        => 'Please enter both username and password.',
                    default               => 'Something went wrong. Please try again.',
                };
                ?>
            </div>
        <?php endif; ?>

        <form method="post" action="AdminLogin.php">
            <div class="field-group">
                <label for="admin_user">Username</label>
                <input type="text" name="username" id="admin_user"
                       placeholder="admin" autocomplete="username" required>
            </div>
            <div class="field-group">
                <label for="admin_pass">Password</label>
                <input type="password" name="password" id="admin_pass"
                       placeholder="••••••••" autocomplete="current-password" required>
            </div>
            <button type="submit" class="btn-login">Sign in</button>
        </form>

        <p class="footnote">Admin access only. Contact IT if you need access.</p>
        <p class="portal-link"><a href="index.php">← Student portal</a></p>
        <p class="portal-link"><a href="LecturerLoginInterface.php">← Lecturer portal</a></p>
    </div>
</main>

</body>
</html>
