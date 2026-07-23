<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   
$db_pass = "";       

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

// ─── Flatten into a newest-first timeline (Year 3 Sem 2 → Year 1 Sem 1) ──────
$timelineBlocks = [];
foreach ($grouped as $year => $sems) {
    foreach ($sems as $sem => $rows) {
        $timelineBlocks[] = ["year" => (int)$year, "sem" => (int)$sem, "rows" => $rows];
    }
}

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

// ─── Overall (latest) CGPA for the timeline header ────────────────────────────
$overallCgpa = null;
if (!empty($timelineBlocks)) {
    $tb0 = $timelineBlocks[0];
    $overallCgpa = $cgpaMap["{$tb0['year']}-{$tb0['sem']}"]['cgpa_value'] ?? null;
}

// ─── Initials for the header avatar (e.g. "Jane Mukasa" → "JM") ───────────────
$nameParts = preg_split('/\s+/', trim($student["student_name"]));
$first     = $nameParts[0] ?? '';
$last      = count($nameParts) > 1 ? end($nameParts) : '';
$initials  = strtoupper(substr($first, 0, 1) . substr($last, 0, 1));
if ($initials === '') { $initials = 'S'; }

// ─── Provisional statement: available to ALL students, any time ──────────────
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

$isFinalYear        = $maxYearReached >= 3;   // reached Year 3
$allModulesMarked   = empty($missingModules); // whole curriculum recorded
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #101828; background: #eef0f5; }

        /* ── Header ── */
        .site-header { display: flex; align-items: center; gap: .9rem; padding: 1rem 1.5rem; background: #213769; border-bottom: 1px solid #16213f; }
        .site-header .crest { width: 2.6rem; height: 2.6rem; object-fit: contain; flex: 0 0 auto; background: #fff; border-radius: 6px; padding: 2px; }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name { font-weight: 600; font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; color: #d9c581; }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }

        .header-user { margin-left: auto; display: flex; align-items: center; gap: .7rem; }
        .hu-text { display: flex; flex-direction: column; text-align: right; line-height: 1.25; }
        .hu-name { color: #fff; font-weight: 700; font-size: .85rem; }
        .hu-sid  { color: #c7cede; font-size: .72rem; }
        .hu-avatar { width: 2.4rem; height: 2.4rem; border-radius: 50%; background: #c9a227; color: #16213f; font-weight: 700; display: flex; align-items: center; justify-content: center; font-size: .82rem; flex: 0 0 auto; }
        .logout-btn { color: #cdd6ef; font-size: .8rem; font-weight: 600; text-decoration: none; border: 1px solid rgba(255,255,255,.3); border-radius: 999px; padding: .35rem .9rem; white-space: nowrap; transition: background .15s, color .15s; }
        .logout-btn:hover { background: rgba(255,255,255,.12); color: #fff; }

        /* ── Tab nav ── */
        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn { padding: .8rem 1.1rem .7rem; border: none; background: transparent; font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,.68); text-decoration: none; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s, background .15s; }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        /* ── Page content ── */
        .page-wrap { max-width: 1040px; margin: 0 auto; padding: 0 1.5rem 3rem; }

        /* ── Student greeting ── */
        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin: 1.4rem 0 .4rem; }
        .student-name { font-size: 1rem; }
        .student-sid { font-size: .95rem; color: #475467; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; color: #101828; }

        /* ── Timeline heading ── */
        .timeline-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin: 1.2rem 0 1.5rem; flex-wrap: wrap; }
        .tl-h-title { font-size: 1.55rem; font-weight: 800; color: #16213f; }
        .tl-h-sub { color: #667085; margin-top: .25rem; font-size: .9rem; }
        .cgpa-big { text-align: right; }
        .cgpa-big .num { font-size: 2rem; font-weight: 800; line-height: 1; color: #213769; }
        .cgpa-big .lab { font-size: .68rem; letter-spacing: .06em; color: #98a2b3; font-weight: 700; text-transform: uppercase; }

        /* ── Timeline / nodes ── */
        .timeline { position: relative; }
        .tl-item { display: flex; gap: 1.1rem; position: relative; padding-bottom: 1.4rem; }
        .tl-rail { position: relative; flex: 0 0 2.5rem; display: flex; justify-content: center; }
        .tl-rail::before { content: ""; position: absolute; left: 50%; top: 2.5rem; bottom: -1.4rem; width: 2px; background: linear-gradient(var(--navy,#213769),#c7cfe8); transform: translateX(-50%); }
        .tl-item:last-child .tl-rail::before { display: none; }
        .tl-node { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; z-index: 1; flex: 0 0 auto; }
        .tl-node.star { background: #213769; color: #fff; }
        .tl-node.num { background: rgb(138, 146, 166); box-shadow: rgb(238, 240, 245) 0px 0px 0px 4px; color: #ffff; border: 1px solid #e0e4ea; }

        /* ── Semester card ── */
        .tl-card { flex: 1 1 auto; min-width: 0; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.06); overflow: hidden; }
        .card-top { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 1rem 1.25rem .85rem; flex-wrap: wrap; }
        .card-titlewrap { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
        .card-title { font-size: 1.05rem; font-weight: 700; color: #16213f; }
        .card-term { color: #98a2b3; font-weight: 500; font-size: .82rem; }
        .badge { font-size: .66rem; font-weight: 700; letter-spacing: .04em; padding: .22rem .5rem; border-radius: 6px; text-transform: uppercase; }
        .badge-latest { background: #e7f6ec; color: #177245; }
        .badge-completed { background: #eef1f4; color: #667085; }
        .card-gpa { display: flex; gap: 1.4rem; align-items: baseline; }
        .card-gpa .lab { font-size: .68rem; letter-spacing: .05em; color: #98a2b3; font-weight: 700; }
        .card-gpa .val { font-size: 1rem; font-weight: 700; margin-left: .35rem; color: #213769; }

        /* ── Module rows ── */
        .mrow { display: flex; align-items: center; gap: 1rem; padding: .7rem 1.25rem; border-top: 1px solid #f0f1f4; }
        .mrow .code  { flex: 0 0 4.5rem; color: #98a2b3; font-weight: 600; font-size: .8rem; }
        .mrow .mname { flex: 1 1 auto; font-weight: 600; color: #16213f; min-width: 0; }
        .mrow .score { flex: 0 0 3.2rem; text-align: right; color: #475467; font-weight: 600; font-size: .88rem; }
        .mrow .rep   { flex: 0 0 auto; }

        .pill { padding: 0; border-radius: 0; font-weight: 700; font-size: .85rem; display: inline-block; min-width: 2.2rem; text-align: center; background: none; color: #33528f; }
        .pill-a, .pill-b, .pill-c, .pill-d, .pill-f { background: none; color: #213769; }

        .btn-report { border: 1px solid #d0d5dd; background: #fff; color: #475467; border-radius: 999px; padding: .25rem .8rem; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; white-space: nowrap; }
        .btn-report:hover { background: #f5f6f8; }

        /* ── Breakdown ── */
        .btn-break { display: inline-flex; align-items: center; gap: .4rem; margin: .6rem 1.25rem 1.1rem; border: 1px solid #d0d5dd; background: #fff; color: #344054; border-radius: 999px; padding: .5rem 1rem; font-size: .82rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .btn-break:hover { background: #f5f6f8; }
        .btn-break.is-open { background: #16213f; color: #fff; border-color: #16213f; }

        .breakdown { display: none; border-top: 1px solid #eef0f3; padding: 1rem 1.25rem 1.25rem; background: #fcfcfd; }
        .breakdown.open { display: block; }
        .breakdown h4 { font-size: .72rem; letter-spacing: .05em; text-transform: uppercase; color: #16213f; margin-bottom: .6rem; }
        .bd-scroll { overflow-x: auto; }
        .bd-table { width: 100%; border-collapse: collapse; }
        .bd-table th { text-align: left; font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: #16213f; font-weight: 700; padding: .3rem .5rem; white-space: nowrap; }
        .bd-table td { padding: .45rem .5rem; border-top: 1px solid #f0f1f4; font-size: .85rem; color: #344054; }
        .bd-table td.code { color: #98a2b3; font-weight: 600; }
        .bd-table th.num, .bd-table td.num { text-align: center; }
        .bd-table th.score, .bd-table td.score { text-align: right; }
        .bd-table td.score { font-weight: 700; color: #101828; }

        /* ── Bottom actions ── */
        .actions-row { display: flex; flex-direction: column; align-items: flex-end; gap: .6rem; margin: 1.5rem 0 3rem; }
        .actions { display: flex; gap: .7rem; flex-wrap: wrap; justify-content: flex-end; }
        .btn { padding: .6rem 1.3rem; border-radius: 999px; font-weight: 600; font-size: .88rem; cursor: pointer; font-family: inherit; border: 1px solid transparent; }
        .btn-outline { background: #fff; border-color: #d0d5dd; color: #344054; }
        .btn-outline:hover { background: #f5f6f8; }
        .btn-solid { background: #16213f; color: #fff; }
        .btn-solid:hover:not(:disabled) { background: #0c1730; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }

        .lock-note { font-size: .8rem; color: #98a2b3; text-align: right; max-width: 40rem; line-height: 1.5; }
        .lock-note strong { color: #667085; }

        .missing-note { width: 100%; background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; border-radius: 10px; padding: .9rem 1.1rem; font-size: .84rem; color: #7c4a03; line-height: 1.5; text-align: left; }
        .missing-note strong { color: #92400e; }
        .missing-list { margin: .6rem 0 .4rem 1.1rem; padding: 0; }
        .missing-list li { margin-bottom: .2rem; }
        .missing-loc { color: #a06a1b; font-size: .8rem; }
        .missing-hint { margin-top: .5rem; font-size: .8rem; color: #8a5a12; }

        .empty { text-align: center; padding: 3rem 1rem; color: #98a2b3; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; }

        /* ── Report Module modal ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(13,23,48,.55); align-items: center; justify-content: center; padding: 1rem; z-index: 100; }
        .modal-overlay.visible { display: flex; }
        .modal-card { background: #fff; border-radius: 12px; width: 100%; max-width: 26rem; padding: 1.4rem 1.5rem 1.6rem; box-shadow: 0 10px 40px rgba(0,0,0,.25); }
        .modal-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: .4rem; }
        .modal-title { font-size: 1.05rem; font-weight: 700; color: #16213f; }
        .modal-module { font-size: .82rem; color: #555; margin-bottom: 1.1rem; }
        .modal-close { border: none; background: none; font-size: 1.3rem; line-height: 1; cursor: pointer; color: #888; padding: 0; }
        .modal-close:hover { color: #333; }
        .modal-field { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1rem; }
        .modal-field label { font-size: .82rem; font-weight: 600; color: #2b3550; }
        .modal-field select, .modal-field textarea { padding: .55rem .7rem; border: 1.5px solid #c9cdda; border-radius: 6px; font-family: 'Inter', sans-serif; font-size: .88rem; resize: vertical; }
        .modal-field textarea { min-height: 5.5rem; }
        .modal-actions { display: flex; justify-content: flex-end; gap: .6rem; margin-top: .4rem; }
        .modal-btn-cancel { padding: .5rem 1.1rem; border: 1px solid #ccc; border-radius: 20px; background: #f5f5f5; font-size: .85rem; cursor: pointer; }
        .modal-btn-submit { padding: .5rem 1.3rem; border: none; border-radius: 20px; background: #213769; color: #fff; font-weight: 600; font-size: .85rem; cursor: pointer; }
        .modal-btn-submit:hover { background: #121e38; }
        .modal-btn-submit:disabled { opacity: .6; cursor: not-allowed; }
        .modal-status-msg { font-size: .82rem; margin-bottom: .9rem; padding: .55rem .75rem; border-radius: 6px; display: none; }
        .modal-status-msg.success { display: block; background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }
        .modal-status-msg.error { display: block; background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        @media (max-width: 620px) {
            .tl-rail { flex-basis: 2rem; }
            .tl-node { width: 2rem; height: 2rem; font-size: .8rem; }
            .card-top { gap: .5rem; }
            .mrow { flex-wrap: wrap; gap: .5rem .8rem; }
            .mrow .mname { flex-basis: 100%; order: 3; }
        }
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
        <span class="portal-title">Academic Performance and Career &amp; Module Planning</span>
    </div>
    <div class="header-user">
        <div class="hu-text">
            <span class="hu-name"><?= htmlspecialchars($student["student_name"]) ?></span>
            <span class="hu-sid">SID <?= htmlspecialchars($student["student_ID"]) ?></span>
        </div>
        <div class="hu-avatar"><?= htmlspecialchars($initials) ?></div>
        <a class="logout-btn" href="logout.php">Log out</a>
    </div>
</header>

<nav class="tab-nav">
    <span class="tab-btn active">Results</span>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Career &amp; Module Planner</a>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <strong><?= htmlspecialchars(strtoupper($student["student_name"])) ?></strong></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <div class="timeline-head">
        <div>
            <div class="tl-h-title">Your results timeline</div>
            <div class="tl-h-sub">Every semester you've completed, newest first.</div>
        </div>
        <?php if ($overallCgpa !== null): ?>
        <div class="cgpa-big">
            <div class="num"><?= number_format((float)$overallCgpa, 2) ?></div>
            <div class="lab">Cumulative GPA</div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($timelineBlocks)): ?>
        <p class="empty">No exam results found for your account yet.</p>
    <?php else: ?>

    <div class="timeline">
        <?php foreach ($timelineBlocks as $idx => $block):
            $year   = $block["year"];
            $sem    = $block["sem"];
            $rows   = $block["rows"];
            $isLatest = ($idx === 0);
            $complete = semComplete($rows);
            $term     = $rows[0]["calendar_year"] . " · " . $rows[0]["calendar_month"];
            $gpaVal   = ($complete && isset($gpaMap[$sem])) ? number_format($gpaMap[$sem]["gpa_value"], 2) : "—";
            $cgpaRow  = $cgpaMap["{$year}-{$sem}"] ?? null;
            $cgpaVal  = ($complete && $cgpaRow) ? number_format($cgpaRow["cgpa_value"], 2) : "—";
        ?>
        <div class="tl-item">
            <div class="tl-rail">
                <?php if ($isLatest): ?>
                    <div class="tl-node star" title="Latest semester">&#9733;</div>
                <?php else: ?>
                    <div class="tl-node num"><?= $idx ?></div>
                <?php endif; ?>
            </div>

            <div class="tl-card">
                <div class="card-top">
                    <div class="card-titlewrap">
                        <span class="card-title">Year <?= $year ?>, Semester <?= $sem ?></span>
                        <span class="card-term"><?= htmlspecialchars($term) ?></span>
                        <?php if ($isLatest): ?>
                            <span class="badge badge-latest">Latest</span>
                        <?php else: ?>
                            <span class="badge badge-completed">Completed</span>
                        <?php endif; ?>
                    </div>
                    <div class="card-gpa">
                        <div><span class="lab">GPA</span><span class="val"><?= $gpaVal ?></span></div>
                        <?php if (!($year === 1 && $sem === 1)): ?>
                        <div><span class="lab">CGPA</span><span class="val"><?= $cgpaVal ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="mrows">
                    <?php foreach ($rows as $r): ?>
                    <div class="mrow">
                        <span class="code"><?= htmlspecialchars($r["module_code"]) ?></span>
                        <span class="mname"><?= htmlspecialchars($r["module_name"]) ?></span>
                        <span class="grade">
                            <?php if ($r["letter_grade"] !== null && $r["letter_grade"] !== ""): ?>
                                <span class="pill <?= pillClass($r["letter_grade"]) ?>"><?= htmlspecialchars($r["letter_grade"]) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </span>
                        <span class="score"><?= fmtScore($r["final_total"]) ?></span>
                        <span class="rep">
                            <button class="btn-report"
                                onclick="reportModule('<?= htmlspecialchars($r['module_code']) ?>', '<?= htmlspecialchars(addslashes($r['module_name'])) ?>')">
                                Report
                            </button>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn-break" onclick="toggleBreak(this)">Show breakdown</button>
                <div class="breakdown">
                    <h4>Assessment Breakdown</h4>
                    <div class="bd-scroll">
                    <table class="bd-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Module</th>
                                <th class="num">CAT 1</th>
                                <th class="num">CAT 2</th>
                                <th class="num">Exam</th>
                                <th class="score">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                            <tr>
                                <td class="code"><?= htmlspecialchars($r["module_code"]) ?></td>
                                <td><?= htmlspecialchars($r["module_name"]) ?></td>
                                <td class="num"><?= fmtMark($r["cat1_mk"]) ?></td>
                                <td class="num"><?= fmtMark($r["cat2_mk"]) ?></td>
                                <td class="num"><?= fmtMark($r["exam_mk"]) ?></td>
                                <td class="score"><?= fmtScore($r["final_total"]) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- ── Bottom actions ── -->
    <div class="actions-row">
        <div class="actions">
            <button class="btn btn-outline" onclick="window.location.href='UploadResults.php'">
                &#8593; Upload results statement
            </button>
            <button class="btn btn-solid" onclick="window.open('Printstatement.php', '_blank')">
                Print provisional statement
            </button>
            <?php if ($isFinalYear): ?>
                <button class="btn btn-solid" <?= $canPrintTranscript ? "onclick=\"window.open('TranscriptStatement.php', '_blank')\"" : "disabled title='Some modules have no marks yet'" ?>>
                    Print transcript
                </button>
            <?php endif; ?>
        </div>

        <p class="lock-note" style="margin:0;">
            &#9888; Printed statements and transcripts are <strong>test documents</strong> produced by this
            project and are <strong>not official</strong> Cavendish University records.
        </p>

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
                    Missing a whole statement of marks? Use <strong>Upload results statement</strong> above to add them from your faculty PDF or emailed screenshot.
                </div>
            </div>
        <?php else: ?>
            <p class="lock-note">
                Your official <strong>transcript</strong> unlocks in your final year, once every module has a recorded mark.
                You can print your <strong>provisional statement</strong> any time.
            </p>
        <?php endif; ?>
    </div>

</main>

<script>
    // Per-card assessment breakdown toggle
    function toggleBreak(btn) {
        const wrap = btn.closest('.tl-card').querySelector('.breakdown');
        const open = wrap.classList.toggle('open');
        btn.textContent = open ? 'Hide breakdown' : 'Show breakdown';
        btn.classList.toggle('is-open', open);
    }

    // ── Report Module modal ──
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
            const response = await fetch('SubmitModuleReport.php', { method: 'POST', body: formData });
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

    document.getElementById('reportModal').addEventListener('click', function (e) {
        if (e.target === this) closeReportModal();
    });
</script>

</body>
</html>
