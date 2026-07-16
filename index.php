<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cavendish Exam and Goal Portal</title>
    <link rel="stylesheet" href="css/stylesheet.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:ital,opsz,wght@0,14..32,100..900;1,14..32,100..900&family=Montserrat:ital,wght@0,100..900;1,100..900&family=Roboto:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
</head>
<body>

    <div class="portal">

        <!-- ── Left panel: welcome / brand side ── -->
        <section class="welcome-panel">
            <div class="welcome-overlay"></div>
            <img class="welcome-crest" src="images/cu_logo.jpg" alt="">

            <div class="welcome-content">
                <p class="welcome-eyebrow">Student Portal</p>
                <h1 class="welcome-title">Welcome back to<br>Cavendish.</h1>
                <p class="welcome-sub">
                    Sign in to view your exam results, track your GPA and CGPA
                    across semesters, and plan the modules ahead with confidence.
                </p>

                <ul class="welcome-points">
                    <li>
                        <span class="point-icon" aria-hidden="true">
                            <!-- open book icon -->
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M3 5.5C3 4.67 3.67 4 4.5 4H11V19H4.5C3.67 19 3 18.33 3 17.5V5.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M21 5.5C21 4.67 20.33 4 19.5 4H13V19H19.5C20.33 19 21 18.33 21 17.5V5.5Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
                                <path d="M6 7.5H8.4M6 10H8.4M16 7.5H18M16 10H18" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                            </svg>
                        </span>
                        <span>Review every semester's results, CAT marks, and grade breakdowns</span>
                    </li>
                    <li>
                        <span class="point-icon" aria-hidden="true">
                            <!-- chart/trend icon -->
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M4 19V5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                <path d="M4 19H20" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
                                <path d="M6.5 15.5L10.5 11L13.5 13.5L18 8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span>Track your GPA and CGPA trends as they evolve</span>
                    </li>
                    <li>
                        <span class="point-icon" aria-hidden="true">
                            <!-- compass / goal icon -->
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.4"/>
                                <path d="M14.8 9.2L13 13L9.2 14.8L11 11L14.8 9.2Z" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                            </svg>
                        </span>
                        <span>Plan upcoming modules around your career goals</span>
                    </li>
                </ul>

                <p class="welcome-vision">A centre of excellence, innovation and transformation</p>
            </div>
        </section>

        <!-- ── Right panel: login form ── -->
        <section class="form-panel">
            <div class="form-card">

                <div class="form-card-header">
                    <p class="brand-eyebrow">Cavendish University</p>
                    <h2 class="form-title">Sign in to your account</h2>
                    <p class="form-sub">Enter your student email and password to continue.</p>
                </div>

                <?php if (isset($_GET['error']) && $_GET['error'] === 'invalid_credentials'): ?>
                    <div class="form-alert" role="alert">
                        Invalid email or password. Please try again.
                    </div>
                <?php elseif (isset($_GET['error']) && $_GET['error'] === 'session_expired'): ?>
                    <div class="form-alert" role="alert">
                        Your session expired. Please log in again.
                    </div>
                <?php elseif (isset($_GET['error']) && $_GET['error'] === 'empty_fields'): ?>
                    <div class="form-alert" role="alert">
                        Please enter both your email and password.
                    </div>
                <?php endif; ?>

                <form action="ExamResultInterface.php" method="post">
                    <div class="field-group">
                        <label class="field-label" for="student_email">Student email</label>
                        <input
                            type="email"
                            name="Email"
                            id="student_email"
                            placeholder="aa999999@students.cavendish.ac.ug"
                            autocomplete="email"
                            required
                        >
                    </div>

                    <div class="field-group">
                        <label class="field-label" for="student_password">Password</label>
                        <input
                            type="password"
                            name="Password"
                            id="student_password"
                            placeholder="••••••••"
                            autocomplete="current-password"
                            required
                        >
                    </div>

                    <button type="submit" name="Submit" class="btn-login">Log in</button>
                </form>

                <p class="form-footnote">
                    Having trouble signing in? Contact the IT helpdesk at your campus.
                </p>

                <p class="staff-link" style="margin-top:1rem;">
                    Don't have an account? <a href="StudentRegister.php">Register here</a>
                </p>

                <p class="staff-link">Are you a lecturer? <a href="LecturerLoginInterface.php">Go to the lecturer portal</a></p>
            </div>
        </section>

    </div>

</body>
</html>