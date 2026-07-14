<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── POST = login attempt → authenticate, set session, redirect (PRG) ─────────
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email    = trim($_POST["Email"]    ?? "");
    $password = trim($_POST["Password"] ?? "");

    if (empty($email) || empty($password)) {
        header("Location: index.php?error=empty_fields");
        exit();
    }

    try {
        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
        $pdoAuth = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }

    $stmt = $pdoAuth->prepare("
        SELECT s.student_ID, s.student_name, s.student_email, s.student_password,
               s.program_code, p.program_name, p.program_faculty
        FROM   student_tb s
        JOIN   program_tb p ON s.program_code = p.program_code
        WHERE  s.student_email = ?
        LIMIT  1
    ");
    $stmt->execute([$email]);
    $authStudent = $stmt->fetch();

    if (!$authStudent || !password_verify($password, $authStudent["student_password"])) {
        header("Location: index.php?error=invalid_credentials");
        exit();
    }

    $_SESSION["student_ID"]   = $authStudent["student_ID"];
    $_SESSION["student_name"] = $authStudent["student_name"];

    // PRG: redirect to GET so the Results tab works as a normal link from any
    // other page, and the back button never triggers a resubmission warning.
    header("Location: ExamResultInterface.php");
    exit();
}

// ─── GET = display results (requires active session) ─────────────────────────
if (empty($_SESSION["student_ID"])) {
    header("Location: index.php?error=session_expired");
    exit();
}

// ─── PDO connection ───────────────────────────────────────────────────────────
try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ─── Re-fetch student info using the session ID ───────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code,
           p.program_name, p.program_faculty
    FROM   student_tb s
    JOIN   program_tb p ON s.program_code = p.program_code
    WHERE  s.student_ID = ?
    LIMIT  1
");
$stmtS->execute([$_SESSION["student_ID"]]);
$student = $stmtS->fetch();

if (!$student) {
    header("Location: index.php?error=invalid_session");
    exit();
}

// ─── Fetch all results joined with module names ───────────────────────────────
$stmtR = $pdo->prepare("
    SELECT r.year_no,
           r.sem_no,
           r.module_code,
           m.module_name,
           m.credit_unit,
           r.cat1_mk,
           r.cat2_mk,
           r.exam_mk,
           r.final_total,
           r.letter_grade,
           r.grade_point,
           r.status_retake_pass,
           (s.intake_year + IFNULL(t.year_offset, 0)) AS calendar_year,
           IFNULL(t.term_month, '—') AS calendar_month
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    JOIN   student_tb s ON r.student_ID = s.student_ID
    LEFT JOIN term_mapping_tb t ON s.intake_session = t.intake_session 
                               AND r.year_no = t.year_no 
                               AND r.sem_no = t.sem_no
    WHERE  r.student_ID = ?
    ORDER  BY r.year_no DESC, r.sem_no DESC
");
$stmtR->execute([$student["student_ID"]]);
$allResults = $stmtR->fetchAll();

// ─── Fetch GPA rows ───────────────────────────────────────────────────────────
$stmtG = $pdo->prepare("
    SELECT sem_no, gpa_value, total_quality_points, total_credit_units
    FROM   gpa_tb
    WHERE  student_ID = ?
    ORDER  BY sem_no DESC
");
$stmtG->execute([$student["student_ID"]]);
$gpaMap = [];
foreach ($stmtG->fetchAll() as $g) {
    $gpaMap[(int)$g["sem_no"]] = $g;
}

// ─── Fetch CGPA ───────────────────────────────────────────────────────────────
$stmtC = $pdo->prepare("
    SELECT year_no, sem_no, cgpa_value, total_quality_points, total_credit_units
    FROM   cgpa_tb
    WHERE  student_ID = ?
    ORDER  BY year_no ASC, sem_no ASC
");
$stmtC->execute([$student["student_ID"]]);
$cgpaMap = [];
foreach ($stmtC->fetchAll() as $c) {
    $cgpaMap["{$c['year_no']}-{$c['sem_no']}"] = $c;
}

// ─── Group by year → semester (DESC order preserved) ─────────────────────────
$grouped = [];
foreach ($allResults as $row) {
    $grouped[$row["year_no"]][$row["sem_no"]][] = $row;
}

// Extract the latest semester block and older ones
$latestYear = null; $latestSem = null;
$latestRows = [];
$previousBlocks = []; // [ [year, sem, rows], ... ]

$firstBlock = true;
foreach ($grouped as $year => $sems) {
    foreach ($sems as $sem => $rows) {
        if ($firstBlock) {
            $latestYear = $year;
            $latestSem  = $sem;
            $latestRows = $rows;
            $firstBlock = false;
        } else {
            $previousBlocks[] = ["year" => $year, "sem" => $sem, "rows" => $rows];
        }
    }
}


// ─── Term label helper (sem 1 = AUG, sem 2 = JAN) ────────────────────────────
// Adjust year logic: sem 1 starts in AUG of the intake year, sem 2 in JAN next year
/* function termLabel(int $year, int $sem): string {
    // Approximate: Year 1 Sem 1 = 2023-AUG, Year 1 Sem 2 = 2024-JAN, etc.
    // Base intake year not stored, so just display Year X, Sem Y
    $baseYear = 2022; // adjust to match your actual intake year
    $calYear  = $baseYear + $year + ($sem === 2 ? 1 : 0);
    $month    = $sem === 1 ? "AUG" : "JAN";
    return "{$calYear}-{$month}";
}
 */
// ─── Grade pill class ─────────────────────────────────────────────────────────
function pillClass(?string $g): string {
    if ($g === null || $g === '') return 'pill-f';
    return match(true) {
        $g === 'A'               => 'pill-a',
        str_starts_with($g, 'B') => 'pill-b',
        str_starts_with($g, 'C') => 'pill-c',
        str_starts_with($g, 'D') => 'pill-d',
        default                  => 'pill-f',
    };
}

// ─── Null-safe display helpers ────────────────────────────────────────────────
function fmtScore($v): string  { return ($v === null || $v === '') ? '—' : (int)$v . '%'; }
function fmtMark($v): string   { return ($v === null || $v === '') ? '—' : (string)(int)$v; }
function fmtGrade(?string $v): string { return ($v === null || $v === '') ? '—' : htmlspecialchars($v); }
function fmtPoint($v): string  { return ($v === null || $v === '') ? '—' : htmlspecialchars((string)$v); }

// A semester is complete when every module row has a final total and letter grade
function semComplete(array $rows): bool {
    foreach ($rows as $r) {
        if ($r['final_total'] === null || $r['final_total'] === ''
            || $r['letter_grade'] === null || $r['letter_grade'] === '') {
            return false;
        }
    }
    return true;
}

// ─── Provisional statement: available to ALL students, any time ──────────────
// (kept as a variable for clarity even though it's always true now)
$canPrintProvisional = true;

// ─── Transcript eligibility: EVERY module in the program curriculum must have
//     a recorded result. We also only surface the transcript tooling once the
//     student has reached their final year (Year 3). ───────────────────────────
$stmtCurric = $pdo->prepare("
    SELECT module_code, module_name, year_no, sem_no
    FROM   module_tb
    WHERE  program_code = ?
    ORDER  BY sem_no ASC, module_code ASC
");
$stmtCurric->execute([$student["program_code"]]);
$curriculum = $stmtCurric->fetchAll();

// Codes the student already has a result row for
$resultCodes = [];
$maxYearReached = 0;
foreach ($allResults as $r) {
    $resultCodes[$r["module_code"]] = true;
    $maxYearReached = max($maxYearReached, (int)$r["year_no"]);
}

// Curriculum modules with no result yet → "missing marks"
$missingModules = [];
foreach ($curriculum as $c) {
    if (!isset($resultCodes[$c["module_code"]])) {
        $missingModules[] = $c;
    }
}

$isFinalYear     = $maxYearReached >= 3;          // reached Year 3
$allModulesMarked = empty($missingModules);       // whole curriculum recorded
$canPrintTranscript = $isFinalYear && $allModulesMarked;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Results – Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #111;
            background: #fff;
        }

        /* ── Header ── */
        .site-header {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            padding: 1rem 1.5rem;
            background: #213769;
            border-bottom: 1px solid #16213f;
        }
        .site-header .crest {
            width: 2.6rem;
            height: 2.6rem;
            object-fit: contain;
            flex: 0 0 auto;
            background: #fff;
            border-radius: 6px;
            padding: 2px;
        }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name {
            font-weight: 600;
            font-size: 0.72rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            color: #d9c581;
        }
        .site-header .portal-title {
            font-weight: 700;
            font-size: 1.05rem;
            color: #fff;
        }
        .header-right { margin-left: auto; width: 1px; }

        /* ── Tab nav ── */
        .tab-nav {
            display: flex;
            gap: 0.35rem;
            padding: 0 1.5rem;
            background: #16213f;
            border-bottom: 1px solid #0d1730;
        }
        .tab-btn {
            padding: .8rem 1.1rem .7rem;
            border: none;
            background: transparent;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            color: rgba(255,255,255,0.68);
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover {
            color: #fff;
            background: rgba(255,255,255,0.06);
        }
        .tab-btn.active {
            color: #fff;
            border-bottom-color: #c9a227;
        }

        /* ── Page content ── */
        .page-wrap { max-width: 860px; margin: 1.5rem; padding: 0 1.5rem; }

        /* ── Student greeting ── */
        .student-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 1.25rem;
        }
        .student-name {
            font-size: 1rem;
            font-weight: 700;
        }
        .student-sid {
            font-size: .95rem;
            font-weight: 400;
        }
        .student-sid strong { font-weight: 700; margin-right: .35rem; }

        /* ── Semester block ── */
        .sem-block { margin-bottom: 1.5rem; }

        /* ── Semester table wrapper ── */
        .sem-table-wrap {
            border: 1px solid #999;
            border-radius: 4px;
            overflow: hidden;
        }

        /* ── Semester header row ── */
        .sem-header {
            display: grid;
            grid-template-columns: 150px 1fr;
            background: #e8e8e8;
        }
        .sem-label {
            background: #121e38;
            color: #fff;
            font-weight: 700;
            font-size: .88rem;
            padding: .55rem .9rem;
            border-top-right-radius: 0.6em;
            width: 9.3em;
        }
        .sem-term {
            font-style: italic;
            font-size: .88rem;
            color: #333;
            padding: .55rem .9rem;
            background: #e8e8e8;
        }

        /* ── Results table ── */
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #121e38;
            color: white;
            font-weight: 700;
            font-size: .82rem;
            padding: .5rem .75rem;
            text-align: center;
            border: 1px solid #000;
        }
        thead th:first-child  { text-align: left; }
        thead th:nth-child(2) { text-align: left; }
        thead th:last-child   { border-right: none; }

        tbody td {
            background: #fff;
            padding: .5rem .75rem;
            border-bottom: 1px solid #a5a5a5;
            border-right: 1px solid #a5a5a5;
            text-align: center;
            font-size: .88rem;
        }
        tbody td:first-child  { text-align: left; font-weight: 600; }
        tbody td:nth-child(2) { text-align: left; font-weight: 600; }
        tbody td:last-child   { border-right: none; }
        tbody tr:last-child td { border-bottom: none; }

        /* ── Grade pill ── */
        .pill {
            display: inline-block;
            font-weight: 700;
            font-size: .85rem;
            min-width: 28px;
            text-align: center;
        }

        /* ── Report Module button ── */
        .btn-report {
            display: inline-block;
            padding: .25rem .75rem;
            border: 1px solid #999;
            border-radius: 20px;
            font-size: .78rem;
            background: #f5f5f5;
            color: #333;
            cursor: pointer;
            white-space: nowrap;
            transition: background .15s;
        }
        .btn-report:hover { background: #e0e0e0; }

        /* ── Footer bar inside sem block ── */
        .sem-footer {
            background: #d9d9d9;
            padding: .65rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: .75rem;
            border-top: 1px solid #a5a5a5;
        }

        /* GPA / CGPA badges */
        .gpa-group {
            display: flex;
            align-items: center;
            gap: .3rem;
        }
        .badge-label {
            background: #16213f;
            color: #fff;
            font-weight: 700;
            font-size: .8rem;
            padding: .25rem .6rem;
            border-radius: 3px;
        }
        .badge-value {
            border: 1px solid #999;
            background: #fff;
            font-size: .85rem;
            font-weight: 600;
            padding: .22rem .65rem;
            border-radius: 3px;
            min-width: 42px;
            text-align: center;
        }

        /* Action buttons */
        .btn-action {
            padding: .35rem .85rem;
            border: 1px solid #888;
            border-radius: 20px;
            background: #16213f;
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            color: #fff;
            transition: background .15s;
        }
        .btn-action:hover,
        .btn-action.active { background: #000000;  }

        /* ── Breakdown table (hidden by default) ── */
        .breakdown-wrap {
            display: none;
            margin-top: 1rem;
            border: 1px solid #999;
            border-radius: 4px;
            overflow: hidden;
        }
        .breakdown-wrap.visible { display: block; }

        /* ── Previous results section ── */
        .prev-results {
            display: none;
            margin-top: 1rem;
        }
        .prev-results.visible { display: block; }

        /* ── Print / action buttons ── */
        .print-row {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: .6rem;
            margin-top: 1.5rem;
            padding-bottom: 2rem;
        }
        .print-actions {
            display: flex;
            gap: .6rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .btn-print {
            padding: .45rem 1.4rem;
            border: 1px solid #16213f;
            border-radius: 20px;
            background: #16213f;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            color: #fff;
        }
        .btn-print:hover:not(:disabled) { background: #0c1730; }
        .btn-upload { background: #1f6b46; border-color: #1f6b46; }
        .btn-upload:hover:not(:disabled) { background: #17563a; }
        .btn-print:disabled {
            background: #b0b5c0;
            border-color: #b0b5c0;
            cursor: not-allowed;
            opacity: .7;
        }
        .print-lock-note {
            font-size: .8rem;
            color: #7b8294;
            text-align: right;
            max-width: 34rem;
        }

        /* ── Missing-marks note (transcript locked) ── */
        .missing-note {
            width: 100%;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-left: 4px solid #f59e0b;
            border-radius: 6px;
            padding: .8rem 1rem;
            font-size: .84rem;
            color: #7c4a03;
            line-height: 1.5;
            text-align: left;
        }
        .missing-note strong { color: #92400e; }
        .missing-list {
            margin: .6rem 0 .4rem 1.1rem;
            padding: 0;
        }
        .missing-list li { margin-bottom: .2rem; }
        .missing-loc { color: #a06a1b; font-size: .8rem; }
        .missing-hint { margin-top: .5rem; font-size: .8rem; color: #8a5a12; }

        /* ── Empty state ── */
        .empty { text-align: center; padding: 2.5rem; color: #888; }

        /* ── Report Module modal ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(13, 23, 48, 0.55);
            align-items: center;
            justify-content: center;
            padding: 1rem;
            z-index: 100;
        }
        .modal-overlay.visible { display: flex; }

        .modal-card {
            background: #fff;
            border-radius: 8px;
            width: 100%;
            max-width: 26rem;
            padding: 1.4rem 1.5rem 1.6rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.25);
        }
        .modal-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: .4rem;
        }
        .modal-title { font-size: 1.05rem; font-weight: 700; color: #16213f; }
        .modal-module { font-size: .82rem; color: #555; margin-bottom: 1.1rem; }
        .modal-close {
            border: none; background: none; font-size: 1.3rem; line-height: 1;
            cursor: pointer; color: #888; padding: 0;
        }
        .modal-close:hover { color: #333; }

        .modal-field { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1rem; }
        .modal-field label { font-size: .82rem; font-weight: 600; color: #2b3550; }
        .modal-field select,
        .modal-field textarea {
            padding: .55rem .7rem;
            border: 1.5px solid #c9cdda;
            border-radius: 6px;
            font-family: 'Inter', sans-serif;
            font-size: .88rem;
            resize: vertical;
        }
        .modal-field textarea { min-height: 5.5rem; }

        .modal-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: .4rem; }
        .modal-btn-cancel {
            padding: .5rem 1.1rem; border: 1px solid #ccc; border-radius: 20px;
            background: #f5f5f5; font-size: .85rem; cursor: pointer;
        }
        .modal-btn-submit {
            padding: .5rem 1.3rem; border: none; border-radius: 20px;
            background: #213769; color: #fff; font-weight: 600; font-size: .85rem; cursor: pointer;
        }
        .modal-btn-submit:hover { background: #121e38; }
        .modal-btn-submit:disabled { opacity: .6; cursor: not-allowed; }

        .modal-status-msg { font-size: .82rem; margin-bottom: .9rem; padding: .55rem .75rem; border-radius: 6px; display: none; }
        .modal-status-msg.success { display: block; background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }
        .modal-status-msg.error   { display: block; background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        /* Note: printing now happens on the dedicated PrintStatement.php page,
           opened by the Print button below, so no @media print override is
           needed here anymore. */
    </style>
</head>
<body>

<!-- ── Report Module Modal ── -->
<div class="modal-overlay" id="reportModal">
    <div class="modal-card">
        <div class="modal-head">
            <div class="modal-title">Report an issue</div>
            <button class="modal-close" onclick="closeReportModal()" aria-label="Close">&times;</button>
        </div>
        <div class="modal-module" id="reportModalModuleLabel"></div>

        <div class="modal-status-msg" id="reportModalStatus"></div>

        <form id="reportModalForm">
            <input type="hidden" id="reportModuleCode" name="module_code">

            <div class="modal-field">
                <label for="reportCategory">What's this about?</label>
                <select id="reportCategory" name="category" required>
                    <option value="CAT1">CAT 1</option>
                    <option value="CAT2">CAT 2</option>
                    <option value="Exam">Exam mark</option>
                </select>
            </div>

            <div class="modal-field">
                <label for="reportMessage">Describe the issue</label>
                <textarea id="reportMessage" name="message" placeholder="e.g. My CAT 2 mark seems lower than what I scored on the marked script I was shown in class." required></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="modal-btn-cancel" onclick="closeReportModal()">Cancel</button>
                <button type="submit" class="modal-btn-submit" id="reportSubmitBtn">Submit report</button>
            </div>
        </form>
    </div>
</div>

<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Career & Module Planning</span>
    </div>
    <div class="header-right"></div>
</header>

<nav class="tab-nav">
    <span class="tab-btn active">Results</span>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Career & Module Planner</a>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">
    
    <div class="student-row">
        <div class="student-name">
            Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?>
        </div>
        <div class="student-sid">
            <strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?>
        </div>
    </div>

    <?php if ($latestRows): ?>

    <div class="sem-block" id="latest-block">
        <div class="sem-table-wrap">

            <div class="sem-header">
                <div class="sem-label">Year <?= (int)$latestYear ?>, Sem <?= (int)$latestSem ?></div>
                <div class="sem-term">Term: <?= htmlspecialchars($latestRows[0]['calendar_year'] . '-' . $latestRows[0]['calendar_month']) ?></div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th style="width:130px">Module Code</th>
                        <th>Module Name</th>
                        <th style="width:70px">Grade</th>
                        <th style="width:75px">Score</th>
                        <th style="width:80px">Credit Unit</th>
                        <th style="width:90px">Grade Point</th>
                        <th style="width:130px"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latestRows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r["module_code"]) ?></td>
                        <td><?= htmlspecialchars($r["module_name"]) ?></td>
                        <td>
                            <?php if ($r['letter_grade'] !== null && $r['letter_grade'] !== ''): ?>
                                <span class="pill <?= pillClass($r['letter_grade']) ?>">
                                    <?= htmlspecialchars($r["letter_grade"]) ?>
                                </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?= fmtScore($r["final_total"]) ?></td>
                        <td><?= htmlspecialchars($r["credit_unit"]) ?></td>
                        <td><?= fmtPoint($r["grade_point"]) ?></td>
                        <td>
                            <button class="btn-report"
                                onclick="reportModule('<?= htmlspecialchars($r['module_code']) ?>', '<?= htmlspecialchars(addslashes($r['module_name'])) ?>')">
                                Report Module
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
                $latestComplete = semComplete($latestRows);
                $latestKey      = "{$latestYear}-{$latestSem}";
                $latestCgpa     = $cgpaMap[$latestKey] ?? null;
            ?>
            <div class="sem-footer">
                <div class="gpa-group">
                    <span class="badge-label">GPA</span>
                    <span class="badge-value">
                        <?= ($latestComplete && isset($gpaMap[(int)$latestSem]))
                            ? number_format($gpaMap[(int)$latestSem]["gpa_value"], 2)
                            : '—' ?>
                    </span>
                </div>

                <?php if (!((int)$latestYear === 1 && (int)$latestSem === 1)) : ?>
                <div class="gpa-group">
                    <span class="badge-label">CGPA</span>
                    <span class="badge-value">
                        <?= ($latestComplete && $latestCgpa)
                            ? number_format($latestCgpa["cgpa_value"], 2) : '—' ?>
                    </span>
                </div>
                <?php endif; ?>

                <button class="btn-action" id="btn-breakdown"
                        onclick="toggleBreakdown()">Show Breakdown</button>

                <?php if (!empty($previousBlocks)): ?>
                <button class="btn-action" id="btn-prev"
                        onclick="togglePrevious()">See Previous Results</button>
                <?php endif; ?>

                <button class="btn-action"
                        onclick="window.location.href='CalculateGPA.php'">
                    Calculate GPA &amp; CGPA
                </button>
            </div>
        </div><div class="breakdown-wrap" id="breakdown-table">
            <table>
                <thead>
                    <tr>
                        <th style="width:130px">Module Code</th>
                        <th>Module Name</th>
                        <th style="width:65px">CAT 1</th>
                        <th style="width:65px">CAT 2</th>
                        <th style="width:65px">EXAM</th>
                        <th style="width:75px">Score</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($latestRows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r["module_code"]) ?></td>
                        <td><?= htmlspecialchars($r["module_name"]) ?></td>
                        <td><?= fmtMark($r["cat1_mk"]) ?></td>
                        <td><?= fmtMark($r["cat2_mk"]) ?></td>
                        <td><?= fmtMark($r["exam_mk"]) ?></td>
                        <td><?= fmtScore($r["final_total"]) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div><?php if (!empty($previousBlocks)): ?>
        <div class="prev-results" id="prev-results">
            <?php foreach ($previousBlocks as $block): ?>
            <div class="sem-block" style="margin-top:1rem;">
                <div class="sem-table-wrap">

                    <div class="sem-header">
                        <div class="sem-label">Year <?= (int)$block["year"] ?>, Sem <?= (int)$block["sem"] ?></div>
                        <div class="sem-term">Term: <?= htmlspecialchars($block["rows"][0]['calendar_year'] . '-' . $block["rows"][0]['calendar_month']) ?></div>
                    </div>

                    <table>
                        <thead>
                            <tr>
                                <th style="width:130px">Module Code</th>
                                <th>Module Name</th>
                                <th style="width:70px">Grade</th>
                                <th style="width:75px">Score</th>
                                <th style="width:80px">Credit Unit</th>
                                <th style="width:90px">Grade Point</th>
                                <th style="width:130px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($block["rows"] as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r["module_code"]) ?></td>
                                <td><?= htmlspecialchars($r["module_name"]) ?></td>
                                <td>
                                    <?php if ($r['letter_grade'] !== null && $r['letter_grade'] !== ''): ?>
                                        <span class="pill <?= pillClass($r['letter_grade']) ?>">
                                            <?= htmlspecialchars($r["letter_grade"]) ?>
                                        </span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td><?= fmtScore($r["final_total"]) ?></td>
                                <td><?= htmlspecialchars($r["credit_unit"]) ?></td>
                                <td><?= fmtPoint($r["grade_point"]) ?></td>
                                <td>
                                    <button class="btn-report"
                                        onclick="reportModule('<?= htmlspecialchars($r['module_code']) ?>', '<?= htmlspecialchars(addslashes($r['module_name'])) ?>')">
                                        Report Module
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                        $blockKey      = "{$block['year']}-{$block['sem']}";
                        $blockCgpa     = $cgpaMap[$blockKey] ?? null;
                        $showCgpaHere  = !((int)$block["year"] === 1 && (int)$block["sem"] === 1);
                        $hasGpaHere    = isset($gpaMap[(int)$block["sem"]]);
                        $blockComplete = semComplete($block["rows"]);
                    ?>
                    <?php if ($hasGpaHere || ($showCgpaHere && $blockCgpa)): ?>
                    <div class="sem-footer">
                        <?php if ($hasGpaHere): ?>
                        <div class="gpa-group">
                            <span class="badge-label">GPA</span>
                            <span class="badge-value">
                                <?= ($blockComplete)
                                    ? number_format($gpaMap[(int)$block["sem"]]["gpa_value"], 2)
                                    : '—' ?>
                            </span>
                        </div>
                        <?php endif; ?>

                        <?php if ($showCgpaHere && $blockCgpa): ?>
                        <div class="gpa-group">
                            <span class="badge-label">CGPA</span>
                            <span class="badge-value">
                                <?= ($blockComplete)
                                    ? number_format($blockCgpa["cgpa_value"], 2)
                                    : '—' ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                </div></div><?php endforeach; ?>
        </div><?php endif; ?>

    </div><?php else: ?>
        <p class="empty">No exam results found for your account yet.</p>
    <?php endif; ?>

    <div class="print-row">
        <div class="print-actions">
            <button class="btn-print btn-upload" onclick="window.location.href='UploadResults.php'">
                &#8593; Upload Results Statement
            </button>

            <button class="btn-print" onclick="window.open('Printstatement.php', '_blank')">
                Print Provisional Statement
            </button>

            <?php if ($isFinalYear): ?>
                <?php if ($canPrintTranscript): ?>
                    <button class="btn-print" onclick="window.open('TranscriptStatement.php', '_blank')">
                        Print Transcript
                    </button>
                <?php else: ?>
                    <button class="btn-print" disabled title="Some modules have no marks yet">
                        Print Transcript
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($isFinalYear && !$allModulesMarked): ?>
            <div class="missing-note">
                <strong>&#9888; Your transcript is locked.</strong>
                The following <?= count($missingModules) ?> module<?= count($missingModules) === 1 ? "" : "s" ?>
                <?= count($missingModules) === 1 ? "has" : "have" ?> no marks recorded yet. Once all of them are marked,
                the Print Transcript button will unlock automatically.
                <ul class="missing-list">
                    <?php foreach ($missingModules as $mm): ?>
                    <li>
                        <strong><?= htmlspecialchars($mm["module_code"]) ?></strong>
                        — <?= htmlspecialchars($mm["module_name"]) ?>
                        <span class="missing-loc">(Year <?= (int)$mm["year_no"] ?>, Sem <?= (int)$mm["sem_no"] ?>)</span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="missing-hint">
                    Missing a whole statement of marks? Use <strong>Upload Results Statement</strong> above to add them from your faculty PDF.
                </div>
            </div>
        <?php elseif (!$isFinalYear): ?>
            <p class="print-lock-note">
                Your official <strong>Transcript</strong> unlocks in your final year, once every module has a recorded mark.
                You can print your <strong>Provisional Statement</strong> at any time.
            </p>
        <?php endif; ?>
    </div>

</main>

<script>
    function toggleBreakdown() {
        const table = document.getElementById('breakdown-table');
        const btn   = document.getElementById('btn-breakdown');
        const open  = table.classList.toggle('visible');
        btn.textContent = open ? 'Hide Breakdown' : 'Show Breakdown';
        btn.classList.toggle('active', open);
    }

    function togglePrevious() {
        const prev = document.getElementById('prev-results');
        const btn  = document.getElementById('btn-prev');
        const open = prev.classList.toggle('visible');
        btn.textContent = open ? 'Hide Previous Results' : 'See Previous Results';
        btn.classList.toggle('active', open);
    }

    function reportModule(code, name) {
        document.getElementById('reportModalModuleLabel').textContent = name ? `${name} (${code})` : code;
        document.getElementById('reportModalStatus').className = 'modal-status-msg';
        document.getElementById('reportModalStatus').textContent = '';
        document.getElementById('reportModalForm').reset();
        document.getElementById('reportModuleCode').value = code;
        document.getElementById('reportModal').classList.add('visible');
    }

    function closeReportModal() {
        document.getElementById('reportModal').classList.remove('visible');
    }

    document.getElementById('reportModalForm').addEventListener('submit', async function (e) {
        e.preventDefault();

        const statusEl = document.getElementById('reportModalStatus');
        const submitBtn = document.getElementById('reportSubmitBtn');
        const formData = new FormData(this);

        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting…';
        statusEl.className = 'modal-status-msg';
        statusEl.textContent = '';

        try {
            const response = await fetch('SubmitModuleReport.php', {
                method: 'POST',
                body: formData,
            });
            const result = await response.json();

            if (result.success) {
                statusEl.className = 'modal-status-msg success';
                statusEl.textContent = 'Report submitted. You can track its progress under "My Reports".';
                submitBtn.textContent = 'Submitted';
                setTimeout(closeReportModal, 1800);
            } else {
                statusEl.className = 'modal-status-msg error';
                statusEl.textContent = result.error || 'Something went wrong. Please try again.';
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit report';
            }
        } catch (err) {
            statusEl.className = 'modal-status-msg error';
            statusEl.textContent = 'Could not reach the server. Please try again.';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Submit report';
        }
    });

    // Close modal on backdrop click (but not when clicking inside the card)
    document.getElementById('reportModal').addEventListener('click', function (e) {
        if (e.target === this) closeReportModal();
    });
</script>

</body>
</html>