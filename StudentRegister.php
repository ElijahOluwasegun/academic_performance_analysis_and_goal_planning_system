<?php
$db_host = '127.0.0.1'; $db_port = '3306'; $db_name = 'apaagps_db'; $db_user = 'root'; $db_pass = '';
try {
    $pdo = new PDO("mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $programs = $pdo->query("SELECT program_code, program_name FROM program_tb ORDER BY program_name ASC")->fetchAll();
} catch (PDOException $e) {
    $programs = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration — Cavendish University</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html, body { min-height: 100vh; }

        body {
            font-family: 'Inter', sans-serif;
            color: #1c2433;
            background: #f7f8fb;
            display: flex;
        }

        /* ── Split layout ── */
        .portal {
            display: grid;
            grid-template-columns: 1.05fr 1fr;
            width: 100%;
            min-height: 100vh;
            align-items: stretch;
        }

        /* ── Left panel ── */
        .welcome-panel {
            position: sticky;
            top: 0;
            height: 100vh;
            overflow: hidden;
            background:
                linear-gradient(160deg, rgba(13,23,48,0.93) 0%, rgba(33,55,105,0.86) 55%, rgba(13,23,48,0.95) 100%),
                url("images/cavendish_building_2.jpg") center / cover no-repeat;
            padding: 3.2rem 3.6rem;
            display: flex;
            align-items: center;
            color: #fff;
        }

        .welcome-overlay {
            position: absolute;
            inset: 0;
            background: radial-gradient(circle at 12% 88%, rgba(201,162,39,0.16), transparent 55%);
            pointer-events: none;
        }

        .welcome-crest {
            position: absolute;
            right: -6rem;
            bottom: -5rem;
            width: 28rem;
            opacity: 0.16;
            mix-blend-mode: screen;
            filter: grayscale(1) invert(1);
            pointer-events: none;
            user-select: none;
        }

        .welcome-content {
            position: relative;
            z-index: 1;
            max-width: 30rem;
        }

        .welcome-eyebrow {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.32rem;
            text-transform: uppercase;
            color: #d9c581;
            margin-bottom: 1.1rem;
        }

        .welcome-title {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.15;
            letter-spacing: -0.01em;
            margin-bottom: 1.1rem;
        }

        .welcome-sub {
            font-size: 0.98rem;
            line-height: 1.6;
            color: rgba(255,255,255,0.82);
            margin-bottom: 2.4rem;
            max-width: 26rem;
        }

        .welcome-steps {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            margin-bottom: 2.6rem;
        }

        .welcome-steps li {
            display: flex;
            align-items: flex-start;
            gap: 0.9rem;
            font-size: 0.9rem;
            line-height: 1.5;
            color: rgba(255,255,255,0.88);
        }

        .step-badge {
            flex: 0 0 auto;
            width: 1.8rem;
            height: 1.8rem;
            border-radius: 50%;
            background: rgba(201,162,39,0.22);
            border: 1px solid rgba(201,162,39,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 700;
            color: #d9c581;
        }

        .welcome-vision {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.78rem;
            font-weight: 500;
            letter-spacing: 0.18rem;
            text-transform: uppercase;
            color: rgba(255,255,255,0.5);
            padding-top: 1.6rem;
            border-top: 1px solid rgba(255,255,255,0.16);
        }

        /* ── Right panel ── */
        .form-panel {
            overflow-y: auto;
            max-height: 100vh;
            padding: 2.8rem 2.5rem;
            background: #f7f8fb;
            display: flex;
            align-items: flex-start;
            justify-content: center;
        }

        .form-card {
            width: 100%;
            max-width: 28rem;
        }

        /* ── Header ── */
        .form-card-header { margin-bottom: 2rem; }

        .brand-eyebrow {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.22rem;
            text-transform: uppercase;
            color: #213769;
            margin-bottom: 0.7rem;
        }

        .form-title {
            font-size: 1.45rem;
            font-weight: 700;
            color: #16213f;
            margin-bottom: 0.45rem;
        }

        .form-sub {
            font-size: 0.86rem;
            color: #5a6378;
            line-height: 1.5;
        }

        /* ── Alert ── */
        .form-alert {
            background: #fdecec;
            border: 1px solid #f3b9b9;
            color: #9b2c2c;
            font-size: 0.84rem;
            padding: 0.7rem 0.9rem;
            border-radius: 8px;
            margin-bottom: 1.4rem;
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

        .field-label {
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
            margin: 1.4rem 0 1.2rem;
        }

        .section-divider span {
            font-family: 'Montserrat', sans-serif;
            font-size: 0.72rem;
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

        /* ── Admission letter callout ── */
        .admission-note {
            background: #eef2f9;
            border: 1px solid #c3cfe8;
            border-left: 3px solid #213769;
            border-radius: 8px;
            padding: 0.75rem 0.9rem;
            margin-bottom: 1.2rem;
            font-size: 0.8rem;
            color: #2b3550;
            line-height: 1.55;
        }

        .admission-note strong {
            display: block;
            font-weight: 700;
            color: #16213f;
            margin-bottom: 0.2rem;
        }

        /* ── Submit button ── */
        .btn-register {
            font-size: 0.93rem;
            font-weight: 700;
            font-family: 'Inter', sans-serif;
            background-color: #213769;
            color: #fff;
            border: 0;
            border-radius: 9px;
            width: 100%;
            padding: 0.85em 1em;
            margin-top: 0.4em;
            cursor: pointer;
            transition: background-color 0.15s ease;
        }

        .btn-register:hover { background-color: #121e38; }

        .btn-register:focus-visible {
            outline: 2px solid #c9a227;
            outline-offset: 2px;
        }

        /* ── Footer links ── */
        .form-footnote {
            margin-top: 1.6rem;
            font-size: 0.78rem;
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

        /* ── Responsive ── */
        @media (max-width: 920px) {
            .portal { grid-template-columns: 1fr; }

            .welcome-panel {
                position: relative;
                height: auto;
                padding: 2.4rem 1.8rem;
            }

            .form-panel {
                max-height: none;
                overflow-y: visible;
                padding: 2.2rem 1.5rem 3rem;
            }
        }

        @media (max-width: 480px) {
            .field-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="portal">

    <!-- Left panel -->
    <section class="welcome-panel">
        <div class="welcome-overlay"></div>
        <img class="welcome-crest" src="images/cu_logo.jpg" alt="">

        <div class="welcome-content">
            <p class="welcome-eyebrow">Student Portal</p>
            <h1 class="welcome-title">Join<br>Cavendish.</h1>
            <p class="welcome-sub">
                Create your student account to access exam results, track your academic progress,
                and plan the modules ahead.
            </p>

            <ul class="welcome-steps">
                <li>
                    <span class="step-badge">1</span>
                    <span>Enter your personal and programme details</span>
                </li>
                <li>
                    <span class="step-badge">2</span>
                    <span>Fill in your intake details exactly as recorded in your admission letter</span>
                </li>
                <li>
                    <span class="step-badge">3</span>
                    <span>Sign in to start tracking your results and GPA</span>
                </li>
            </ul>

            <p class="welcome-vision">A centre of excellence, innovation and transformation</p>
        </div>
    </section>

    <!-- Right panel -->
    <section class="form-panel">
        <div class="form-card">

            <div class="form-card-header">
                <p class="brand-eyebrow">Cavendish University</p>
                <h2 class="form-title">Create your student account</h2>
                <p class="form-sub">Fill in your details below. All fields are required.</p>
            </div>

            <?php if (isset($_GET['error'])): ?>
                <div class="form-alert" role="alert">
                    <?php
                        $errors = [
                            'missing_fields'    => 'Please fill in all required fields.',
                            'email_taken'       => 'An account with that email already exists.',
                            'id_taken'          => 'That student ID is already registered.',
                            'password_mismatch' => 'Passwords do not match. Please try again.',
                            'invalid_program'   => 'That programme code does not exist. Please check your admission letter.',
                            'invalid_session'   => 'Intake session must be JAN, MAY, or AUG.',
                        ];
                        echo htmlspecialchars($errors[$_GET['error']] ?? 'Something went wrong. Please try again.');
                    ?>
                </div>
            <?php endif; ?>

            <form action="StudentRegisterHandler.php" method="post" novalidate>

                <!-- Personal information -->
                <div class="field-group">
                    <label class="field-label" for="full_name">Full name</label>
                    <input type="text" name="FullName" id="full_name"
                           placeholder="e.g. Amara Nalwanga" autocomplete="name" required>
                </div>

                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="student_id">Student ID</label>
                        <input type="text" name="StudentID" id="student_id"
                               placeholder="e.g. 230-456" autocomplete="off" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="program_code">Programme</label>
                        <select name="ProgramCode" id="program_code" required>
                            <option value="" disabled selected>Select programme</option>
                            <?php foreach ($programs as $p): ?>
                            <option value="<?= htmlspecialchars($p['program_code']) ?>">
                                <?= htmlspecialchars($p['program_code']) ?> — <?= htmlspecialchars($p['program_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="student_email">Student email</label>
                    <input type="email" name="Email" id="student_email"
                           placeholder="aa999999@students.cavendish.ac.ug" autocomplete="email" required>
                </div>

                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="password">Password</label>
                        <input type="password" name="Password" id="password"
                               placeholder="Min. 8 characters" autocomplete="new-password" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="confirm_password">Confirm password</label>
                        <input type="password" name="ConfirmPassword" id="confirm_password"
                               placeholder="Repeat password" autocomplete="new-password" required>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="gender">Gender</label>
                        <select name="Gender" id="gender" required>
                            <option value="" disabled selected>Select</option>
                            <option value="M">Male</option>
                            <option value="F">Female</option>
                        </select>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="nationality">Nationality</label>
                        <input type="text" name="Nationality" id="nationality"
                               placeholder="e.g. Ugandan" autocomplete="off" required>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="dob">Date of birth</label>
                    <input type="date" name="DateOfBirth" id="dob" required>
                </div>

                <!-- Admission letter details -->
                <div class="section-divider"><span>Admission letter details</span></div>

                <div class="admission-note">
                    <strong>Use your admission letter for the fields below.</strong>
                    Intake year, intake session, and mode of entry must be entered exactly as they
                    appear in your official admission letter.
                </div>

                <div class="field-row">
                    <div class="field-group">
                        <label class="field-label" for="intake_year">Intake year</label>
                        <input type="number" name="IntakeYear" id="intake_year"
                               placeholder="e.g. 2023" min="2000" max="2100" autocomplete="off" required>
                    </div>
                    <div class="field-group">
                        <label class="field-label" for="intake_session">Intake session</label>
                        <select name="IntakeSession" id="intake_session" required>
                            <option value="" disabled selected>Select</option>
                            <option value="JAN">January (JAN)</option>
                            <option value="MAY">May (MAY)</option>
                            <option value="AUG">August (AUG)</option>
                        </select>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="mode_of_entry">Mode of entry</label>
                    <select name="ModeOfEntry" id="mode_of_entry" required>
                        <option value="" disabled selected>Select</option>
                        <option value="Direct">Direct</option>
                        <option value="Transfer">Transfer</option>
                        <option value="Foundation">Foundation</option>
                        <option value="Mature">Mature</option>
                        <option value="Diploma">Diploma</option>
                    </select>
                </div>

                <button type="submit" name="Submit" class="btn-register">Create account</button>
            </form>

            <p class="form-footnote">
                Having trouble? Contact the IT helpdesk at your campus.
            </p>

            <p class="login-link">Already have an account? <a href="index.php">Sign in</a></p>
        </div>
    </section>

</div>

</body>
</html>
