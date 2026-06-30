<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Portal – Cavendish University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f8fb;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        header {
            background: #213769;
            padding: 1.2em 1.5rem;
        }
        header h4 {
            color: #fff;
            font-family: 'Montserrat', sans-serif;
            letter-spacing: 0.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
        }
        header h1 {
            color: #fff;
            font-size: 1.2rem;
            font-weight: 700;
            margin-top: 0.2rem;
        }

        main {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1.5rem;
        }

        .login-card {
            background: #fff;
            border: 1px solid #d4d8e2;
            border-radius: 10px;
            padding: 2.2rem 2.2rem 1.8rem;
            width: 100%;
            max-width: 23rem;
        }

        .eyebrow {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.18rem;
            text-transform: uppercase;
            color: #213769;
            margin-bottom: 0.6rem;
        }

        .login-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #16213f;
            margin-bottom: 0.4rem;
        }

        .login-sub {
            font-size: 0.85rem;
            color: #5a6378;
            margin-bottom: 1.6rem;
            line-height: 1.5;
        }

        .alert {
            background: #fdecec;
            border: 1px solid #f3b9b9;
            color: #9b2c2c;
            font-size: 0.85rem;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            margin-bottom: 1.2rem;
        }

        form { display: flex; flex-direction: column; }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
            margin-bottom: 1.2rem;
        }

        label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2b3550;
        }

        input {
            height: 2.7em;
            padding: 0 0.85em;
            border: 1.5px solid #d4d8e2;
            border-radius: 8px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
        }

        input:focus {
            outline: none;
            border-color: #213769;
            box-shadow: 0 0 0 3px rgba(33, 55, 105, 0.12);
        }

        button {
            font-size: 0.92rem;
            font-weight: 700;
            background: #213769;
            color: #fff;
            border: 0;
            border-radius: 8px;
            padding: 0.75em 1em;
            margin-top: 0.4em;
            cursor: pointer;
        }
        button:hover { background: #121e38; }

        .footnote {
            margin-top: 1.4rem;
            font-size: 0.78rem;
            color: #7b8294;
            text-align: center;
        }

        .student-link {
            margin-top: 1rem;
            font-size: 0.8rem;
            text-align: center;
        }
        .student-link a { color: #213769; font-weight: 600; text-decoration: none; }
        .student-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <header>
        <h4>Cavendish University</h4>
        <h1>Lecturer Portal — Result Uploads</h1>
    </header>

    <main>
        <div class="login-card">
            <p class="eyebrow">Staff Sign In</p>
            <h2 class="login-title">Lecturer Login</h2>
            <p class="login-sub">Sign in to upload exam results for the modules assigned to you.</p>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_credentials'): ?>
                <div class="alert" role="alert">Invalid email or password. Please try again.</div>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                <div class="alert" role="alert">Your session expired. Please log in again.</div>
            <?php elseif (isset($_GET['error']) && $_GET['error'] === 'empty_fields'): ?>
                <div class="alert" role="alert">Please enter both your email and password.</div>
            <?php endif; ?>

            <form action="LecturerUploadInterface.php" method="post">
                <div class="field-group">
                    <label for="lecturer_email">Staff email</label>
                    <input
                        type="email"
                        name="Email"
                        id="lecturer_email"
                        placeholder="phern@cavendish.ac.ug"
                        autocomplete="email"
                        required
                    >
                </div>

                <div class="field-group">
                    <label for="lecturer_password">Password</label>
                    <input
                        type="password"
                        name="Password"
                        id="lecturer_password"
                        placeholder="••••••••"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" name="Submit">Log in</button>
            </form>

            <p class="footnote">Having trouble signing in? Contact the IT helpdesk.</p>
            <p class="student-link" style="margin-top:1rem;">Don't have an account? <a href="LecturerRegister.php">Register here</a></p>
            <p class="student-link">Are you a student? <a href="index.php">Go to the student portal</a></p>
        </div>
    </main>

</body>
</html>