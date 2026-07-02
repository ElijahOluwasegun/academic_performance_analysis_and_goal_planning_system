<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lecturer Registration — Cavendish University</title>
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
            padding: 1.2em 1.8rem;
            flex-shrink: 0;
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
            padding: 2.4rem 1.5rem;
        }

        .register-card {
            background: #fff;
            border: 1px solid #d4d8e2;
            border-radius: 12px;
            padding: 2.4rem 2.4rem 2rem;
            width: 100%;
            max-width: 26rem;
            box-shadow: 0 2px 12px rgba(33,55,105,0.06);
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

        .register-title {
            font-size: 1.35rem;
            font-weight: 700;
            color: #16213f;
            margin-bottom: 0.4rem;
        }

        .register-sub {
            font-size: 0.84rem;
            color: #5a6378;
            margin-bottom: 1.8rem;
            line-height: 1.55;
        }

        /* ── Alert ── */
        .alert {
            background: #fdecec;
            border: 1px solid #f3b9b9;
            color: #9b2c2c;
            font-size: 0.84rem;
            padding: 0.65rem 0.85rem;
            border-radius: 8px;
            margin-bottom: 1.2rem;
        }

        /* ── Fields ── */
        form { display: flex; flex-direction: column; }

        .field-group {
            display: flex;
            flex-direction: column;
            gap: 0.42rem;
            margin-bottom: 1.15rem;
        }

        .field-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.85rem;
        }

        label {
            font-size: 0.8rem;
            font-weight: 600;
            color: #2b3550;
        }

        input, select {
            height: 2.75em;
            width: 100%;
            padding: 0 0.85em;
            border: 1.5px solid #d4d8e2;
            border-radius: 9px;
            font-family: 'Inter', sans-serif;
            font-size: 0.9rem;
            color: #1c2433;
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        input::placeholder { color: #a3a9b8; }

        input:focus, select:focus {
            outline: none;
            border-color: #213769;
            box-shadow: 0 0 0 3px rgba(33,55,105,0.12);
        }

        select {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%235a6378' stroke-width='1.5' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.85em center;
            padding-right: 2.2em;
            cursor: pointer;
        }

        /* ── Section divider ── */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin: 1.2rem 0 1.1rem;
        }

        .section-divider span {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.68rem;
            font-weight: 700;
            letter-spacing: 0.14rem;
            text-transform: uppercase;
            color: #7b8294;
            white-space: nowrap;
        }

        .section-divider::before,
        .section-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #d4d8e2;
        }

        /* ── Submit button ── */
        .btn-register {
            font-size: 0.93rem;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            background: #213769;
            color: #fff;
            border: 0;
            border-radius: 9px;
            padding: 0.82em 1em;
            margin-top: 0.4em;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .btn-register:hover { background: #121e38; }

        .btn-register:focus-visible {
            outline: 2px solid #c9a227;
            outline-offset: 2px;
        }

        /* ── Footer ── */
        .footnote {
            margin-top: 1.4rem;
            font-size: 0.77rem;
            color: #7b8294;
            text-align: center;
        }

        .login-link {
            margin-top: 0.9rem;
            font-size: 0.8rem;
            text-align: center;
            color: #7b8294;
        }

        .login-link a {
            color: #213769;
            font-weight: 600;
            text-decoration: none;
        }

        .login-link a:hover { text-decoration: underline; }

        .student-link {
            margin-top: 0.5rem;
            font-size: 0.8rem;
            text-align: center;
        }

        .student-link a {
            color: #213769;
            font-weight: 600;
            text-decoration: none;
        }

        .student-link a:hover { text-decoration: underline; }

        @media (max-width: 480px) {
            .field-row { grid-template-columns: 1fr; }
            .register-card { padding: 2rem 1.5rem 1.8rem; }
        }
    </style>
</head>
<body>

    <header>
        <h4>Cavendish University</h4>
        <h1>Lecturer Portal — Result Uploads</h1>
    </header>

    <main>
        <div class="register-card">
            <p class="eyebrow">Staff Registration</p>
            <h2 class="register-title">Create your lecturer account</h2>
            <p class="register-sub">
                Register to access the result upload portal for your assigned modules.
            </p>

            <?php if (isset($_GET['error'])): ?>
                <div class="alert" role="alert">
                    <?php
                        $errors = [
                            'missing_fields'    => 'Please fill in all required fields.',
                            'email_taken'       => 'An account with that email already exists.',
                            'id_taken'          => 'That staff ID is already registered.',
                            'password_mismatch' => 'Passwords do not match. Please try again.',
                            'password_short'    => 'Password must be at least 8 characters.',
                            'invalid_email'     => 'Please enter a valid staff email address.',
                        ];
                        echo htmlspecialchars($errors[$_GET['error']] ?? 'Something went wrong. Please try again.');
                    ?>
                </div>
            <?php endif; ?>

            <form action="LecturerRegisterHandler.php" method="post" novalidate>

                <div class="field-group">
                    <label for="full_name">Full name</label>
                    <input type="text" name="FullName" id="full_name"
                           placeholder="e.g. Dr. Patricia Hernandez" autocomplete="name" required>
                </div>

                <div class="field-row">
                    <div class="field-group">
                        <label for="staff_id">Staff ID</label>
                        <input type="text" name="StaffID" id="staff_id"
                               placeholder="e.g. LEC00123" autocomplete="off" required>
                    </div>
                    <div class="field-group">
                        <label for="title">Title</label>
                        <select name="Title" id="title" required>
                            <option value="" disabled selected>Select</option>
                            <option value="Mr">Mr</option>
                            <option value="Mrs">Mrs</option>
                            <option value="Ms">Ms</option>
                            <option value="Dr">Dr</option>
                            <option value="Prof">Prof</option>
                        </select>
                    </div>
                </div>

                <div class="field-group">
                    <label for="staff_email">Staff email</label>
                    <input type="email" name="Email" id="staff_email"
                           placeholder="phern@cavendish.ac.ug" autocomplete="email" required>
                </div>

                <!-- Academic details -->
                <div class="section-divider"><span>Academic details</span></div>

                <div class="field-group">
                    <label for="faculty">Faculty / School</label>
                    <input type="text" name="Faculty" id="faculty"
                           placeholder="e.g. School of Computing" autocomplete="off" required>
                </div>

                <div class="field-group">
                    <label for="department">Department</label>
                    <input type="text" name="Department" id="department"
                           placeholder="e.g. Computer Science" autocomplete="off" required>
                </div>

                <!-- Credentials -->
                <div class="section-divider"><span>Set password</span></div>

                <div class="field-row">
                    <div class="field-group">
                        <label for="password">Password</label>
                        <input type="password" name="Password" id="password"
                               placeholder="Min. 8 characters" autocomplete="new-password" required>
                    </div>
                    <div class="field-group">
                        <label for="confirm_password">Confirm password</label>
                        <input type="password" name="ConfirmPassword" id="confirm_password"
                               placeholder="Repeat password" autocomplete="new-password" required>
                    </div>
                </div>

                <button type="submit" name="Submit" class="btn-register">Create account</button>
            </form>

            <p class="footnote">Having trouble? Contact the IT helpdesk.</p>
            <p class="login-link">Already have an account? <a href="LecturerLoginInterface.php">Sign in</a></p>
            <p class="student-link">Are you a student? <a href="StudentRegister.php">Student registration</a></p>
        </div>
    </main>

</body>
</html>
