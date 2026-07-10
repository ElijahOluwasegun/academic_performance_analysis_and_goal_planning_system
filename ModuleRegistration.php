<?php
session_start();

if (empty($_SESSION['student_ID'])) {
    header('Location: index.php?error=session_expired');
    exit();
}

$db_host  = '127.0.0.1';
$db_port  = '3306';
$db_name  = 'apaagps_db';
$db_user  = 'root';
$db_pass  = '';
$studentID = $_SESSION['student_ID'];

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// ─── Student + programme info ─────────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code, s.intake_year, s.intake_session,
           p.program_name
    FROM   student_tb s
    JOIN   program_tb p ON s.program_code = p.program_code
    WHERE  s.student_ID = ?
    LIMIT  1
");
$stmtS->execute([$studentID]);
$student = $stmtS->fetch();
if (!$student) {
    header('Location: index.php?error=invalid_session');
    exit();
}

// ─── Current semester: intake_year + term_mapping_tb + today ─────────────────
$monthToNum = ['JAN' => 1, 'MAY' => 5, 'AUG' => 8];
$monthNames = [1 => 'January', 5 => 'May', 8 => 'August'];

$stmtTM = $pdo->prepare("
    SELECT year_no, sem_no, year_offset, term_month
    FROM   term_mapping_tb
    WHERE  intake_session = ?
    ORDER  BY year_no ASC, sem_no ASC
");
$stmtTM->execute([$student['intake_session']]);
$termRows = $stmtTM->fetchAll();

$currentYearNo   = null;
$currentSemNo    = null;
$currentSemStart = null;
$today = new DateTime('today');

foreach ($termRows as $t) {
    $m        = $monthToNum[$t['term_month']] ?? 1;
    $calYear  = (int)$student['intake_year'] + (int)$t['year_offset'];
    $semStart = new DateTime(sprintf('%04d-%02d-01', $calYear, $m));
    if ($semStart <= $today) {
        $currentYearNo   = (int)$t['year_no'];
        $currentSemNo    = (int)$t['sem_no'];
        $currentSemStart = ($monthNames[$m] ?? '') . ' ' . $calYear;
    }
}

// ─── POST handler (PRG) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    if ($currentYearNo && $currentSemNo) {
        $selectedCodes = array_filter(array_map('trim', $_POST['modules'] ?? []));
        $retakeCodes   = array_map('trim', $_POST['retakes'] ?? []);

        // Determine which module codes belong to the current semester
        $rr = $pdo->prepare("SELECT module_code FROM module_tb WHERE program_code=? AND year_no=? AND sem_no=?");
        $rr->execute([$student['program_code'], $currentYearNo, $currentSemNo]);
        $semModCodes = array_column($rr->fetchAll(), 'module_code');

        // Replace all registrations for this year/semester
        $pdo->prepare("DELETE FROM module_registration_tb WHERE student_ID=? AND year_no=? AND sem_no=?")
            ->execute([$studentID, $currentYearNo, $currentSemNo]);

        if (!empty($selectedCodes)) {
            $ins = $pdo->prepare("
                INSERT INTO module_registration_tb (student_ID, module_code, year_no, sem_no, is_retake)
                VALUES (?, ?, ?, ?, ?)
            ");
            foreach ($selectedCodes as $mc) {
                // Modules not in current semester standard list are always retakes
                $isRetake = !in_array($mc, $semModCodes, true) ? 1
                          : (in_array($mc, $retakeCodes, true) ? 1 : 0);
                $ins->execute([$studentID, $mc, $currentYearNo, $currentSemNo, $isRetake]);
            }
        }
    }
    header('Location: ModuleRegistration.php?msg=saved');
    exit();
}

// ─── Current semester standard modules ───────────────────────────────────────
$currentModules = [];
if ($currentYearNo && $currentSemNo) {
    $stmt = $pdo->prepare("
        SELECT module_code, module_name, credit_unit
        FROM   module_tb
        WHERE  program_code=? AND year_no=? AND sem_no=?
        ORDER  BY module_code ASC
    ");
    $stmt->execute([$student['program_code'], $currentYearNo, $currentSemNo]);
    $currentModules = $stmt->fetchAll();
}
$currentModCodes = array_column($currentModules, 'module_code');

// ─── Saved registrations for this semester ────────────────────────────────────
$registeredMap = []; // module_code → is_retake
if ($currentYearNo && $currentSemNo) {
    $stmt = $pdo->prepare("
        SELECT module_code, is_retake
        FROM   module_registration_tb
        WHERE  student_ID=? AND year_no=? AND sem_no=?
    ");
    $stmt->execute([$studentID, $currentYearNo, $currentSemNo]);
    foreach ($stmt->fetchAll() as $r) {
        $registeredMap[$r['module_code']] = (bool)$r['is_retake'];
    }
}
$isFirstTime = empty($registeredMap); // pre-check all on first visit

// ─── Previous results (detect retake eligibility) ────────────────────────────
$prevResults = [];
$stmt = $pdo->prepare("
    SELECT module_code, letter_grade, grade_point, final_total
    FROM   results_tb WHERE student_ID=?
");
$stmt->execute([$studentID]);
foreach ($stmt->fetchAll() as $r) {
    $prevResults[$r['module_code']] = $r;
}

// ─── Previous semester results grouped by semester ────────────────────────────
$prevBySem = [];
$stmtPrev = $pdo->prepare("
    SELECT r.module_code, r.year_no, r.sem_no, r.letter_grade, r.grade_point,
           r.final_total, m.module_name, m.credit_unit
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = ?
      AND  NOT (r.year_no = ? AND r.sem_no = ?)
    ORDER  BY r.year_no ASC, r.sem_no ASC, r.module_code ASC
");
$stmtPrev->execute([$studentID, $currentYearNo ?? 0, $currentSemNo ?? 0]);
foreach ($stmtPrev->fetchAll() as $r) {
    $key = "Y{$r['year_no']}S{$r['sem_no']}";
    if (!isset($prevBySem[$key])) {
        $prevBySem[$key] = ['year_no' => (int)$r['year_no'], 'sem_no' => (int)$r['sem_no'], 'modules' => []];
    }
    $prevBySem[$key]['modules'][] = $r;
}

// ─── Registration history (all semesters) ────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT mr.module_code, mr.year_no, mr.sem_no, mr.is_retake,
           DATE_FORMAT(mr.registered_at, '%d %b %Y') AS reg_date,
           m.module_name, m.credit_unit
    FROM   module_registration_tb mr
    JOIN   module_tb m ON mr.module_code = m.module_code
    WHERE  mr.student_ID = ?
    ORDER  BY mr.year_no ASC, mr.sem_no ASC, mr.module_code ASC
");
$stmt->execute([$studentID]);
$historyRows = $stmt->fetchAll();

// ─── Flash message ────────────────────────────────────────────────────────────
$msgType = $msgText = null;
if (isset($_GET['msg'])) {
    match($_GET['msg']) {
        'saved' => [$msgType, $msgText] = ['success', 'Module registration saved successfully.'],
        default => null,
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Registration — Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #fff; }

        /* ── Header ── */
        .site-header {
            display: flex; align-items: center; gap: .9rem;
            padding: 1rem 1.5rem; background: #213769;
            border-bottom: 1px solid #16213f;
        }
        .site-header .crest {
            width: 2.6rem; height: 2.6rem; object-fit: contain;
            flex: 0 0 auto; background: #fff; border-radius: 6px; padding: 2px;
        }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name {
            font-weight: 600; font-size: .72rem; letter-spacing: .14em;
            text-transform: uppercase; color: #d9c581;
        }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }

        /* ── Tab nav ── */
        .tab-nav {
            display: flex; gap: .35rem; padding: 0 1.5rem;
            background: #16213f; border-bottom: 1px solid #0d1730; overflow-x: auto;
        }
        .tab-btn {
            padding: .8rem 1.1rem .7rem; border: none; background: transparent;
            font-size: .85rem; font-weight: 600; cursor: pointer; white-space: nowrap;
            color: rgba(255,255,255,.68); text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        /* ── Page ── */
        .page-wrap { max-width: 860px; margin: 1.5rem auto; padding: 0 1.5rem 3rem; }

        .student-row {
            display: flex; justify-content: space-between; align-items: baseline;
            margin-bottom: 1.4rem;
        }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid  { font-size: .9rem; }
        .student-sid strong { font-weight: 700; margin-right: .3rem; }

        /* ── Flash ── */
        .flash {
            padding: .7rem 1rem; border-radius: 6px; font-size: .85rem;
            margin-bottom: 1.2rem;
        }
        .flash.success { background: #ecfdf5; border: 1px solid #a7e0c4; color: #065f46; }
        .flash.error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        /* ── Semester banner ── */
        .sem-banner {
            display: flex; align-items: flex-start; gap: 1.1rem;
            background: #121e38; color: #fff; border-radius: 8px;
            padding: 1.1rem 1.3rem; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .sem-icon { font-size: 1.6rem; flex: 0 0 auto; }
        .sem-title { font-size: 1.05rem; font-weight: 700; margin-bottom: .25rem; }
        .sem-sub   { font-size: .82rem; opacity: .82; line-height: 1.5; }

        /* ── Cards ── */
        .card { border: 1px solid #999; border-radius: 6px; margin-bottom: 1.75rem; overflow: hidden; }
        .card-header {
            background: #121e38; color: #fff; font-weight: 700;
            font-size: .92rem; padding: .65rem 1rem;
            display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .5rem;
        }
        .card-sub  { font-size: .8rem; font-weight: 400; opacity: .85; }
        .card-body { background: #e8e8e8; padding: 1rem; }

        /* ── Registration toolbar ── */
        .reg-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: .85rem; flex-wrap: wrap; gap: .5rem;
        }
        .cu-display { font-size: .85rem; font-weight: 600; color: #213769; }
        .btn-sm {
            font-size: .78rem; font-weight: 600; padding: .28rem .75rem;
            border: 1.5px solid #999; border-radius: 20px; background: #fff;
            cursor: pointer; color: #333;
        }
        .btn-sm:hover { background: #f0f0f0; }

        /* ── Module rows ── */
        .mod-rows { display: flex; flex-direction: column; gap: .5rem; }

        .mod-row {
            background: #fff; border: 1.5px solid #ddd; border-radius: 6px;
            padding: .7rem .9rem;
        }
        .mod-row.is-checked { border-color: #213769; }

        .mod-main {
            display: flex; align-items: flex-start; gap: .8rem; cursor: pointer;
        }
        .mod-check {
            width: 1.1em; height: 1.1em; margin-top: .12em;
            accent-color: #213769; flex: 0 0 auto; cursor: pointer;
        }
        .mod-code { font-weight: 700; font-size: .88rem; color: #121e38; }
        .mod-name { font-size: .81rem; color: #555; margin-top: .1rem; }

        .prev-badge {
            display: inline-flex; align-items: center; gap: .25rem;
            font-size: .71rem; font-weight: 700; padding: .1rem .4rem;
            border-radius: 10px; margin-left: .4rem; vertical-align: middle;
        }
        .prev-pass { background: #d1fae5; color: #065f46; }
        .prev-fail { background: #fee2e2; color: #991b1b; }

        .mod-cu-right {
            margin-left: auto; font-size: .76rem; font-weight: 700;
            color: #213769; white-space: nowrap; padding-left: .5rem;
            flex: 0 0 auto; padding-top: .1rem;
        }

        /* ── Retake sub-toggle ── */
        .retake-row {
            display: flex; align-items: center; gap: .5rem; cursor: pointer;
            margin-top: .55rem; padding-top: .55rem;
            border-top: 1px dashed #ddd; font-size: .8rem; color: #555;
        }
        .retake-check { accent-color: #9a3412; width: 1em; height: 1em; flex: 0 0 auto; }
        .retake-warn  { color: #9a3412; font-weight: 600; }

        /* ── Failed / retake modules section ── */
        .section-sep {
            margin: 1.1rem 0 .8rem;
            padding-top: 1rem;
            border-top: 1px solid #ccc;
        }
        .section-sep-title {
            font-weight: 700; font-size: .84rem;
            color: #7f1d1d; margin-bottom: .35rem;
        }
        .section-sep-sub {
            font-size: .81rem; color: #666; line-height: 1.5;
            margin-bottom: .75rem;
        }
        .failed-row {
            background: #fff; border: 1.5px solid #f3b9b9; border-radius: 6px;
            padding: .65rem .9rem; display: flex; align-items: flex-start;
            gap: .8rem; cursor: pointer;
        }
        .failed-row.is-checked { border-color: #991b1b; }
        .failed-check { accent-color: #991b1b; width: 1.1em; height: 1.1em; margin-top: .1em; flex: 0 0 auto; }
        .failed-code  { font-weight: 700; font-size: .88rem; color: #7f1d1d; }
        .failed-name  { font-size: .8rem; color: #555; margin-top: .1rem; }
        .failed-meta  {
            margin-left: auto; flex: 0 0 auto; text-align: right;
            font-size: .75rem; font-weight: 700;
            background: #fee2e2; color: #991b1b;
            padding: .12rem .45rem; border-radius: 10px; white-space: nowrap;
        }

        /* ── Save bar ── */
        .save-bar {
            display: flex; align-items: center; justify-content: space-between;
            margin-top: 1rem; padding-top: .85rem;
            border-top: 1px solid #ccc; flex-wrap: wrap; gap: .6rem;
        }
        .save-total { font-size: .84rem; color: #555; }
        .save-total strong { color: #121e38; }
        .btn-save {
            background: #213769; color: #fff; font-weight: 700;
            font-size: .9rem; border: none; border-radius: 8px;
            padding: .65rem 1.5rem; cursor: pointer;
        }
        .btn-save:hover { background: #121e38; }

        /* ── History table ── */
        .tbl-wrap { overflow-x: auto; }
        .hist-table { width: 100%; border-collapse: collapse; font-size: .82rem; min-width: 480px; }
        .hist-table th {
            background: #121e38; color: #fff; padding: .42rem .7rem;
            text-align: left; font-weight: 600; white-space: nowrap;
        }
        .hist-table td { padding: .35rem .7rem; border-bottom: 1px solid #e0e0e0; }
        .hist-table tr:last-child td { border-bottom: 0; }
        .hist-table tr:hover td { background: #f7f7f7; }
        .pill {
            font-size: .72rem; font-weight: 700;
            padding: .1rem .45rem; border-radius: 10px;
        }
        .pill-retake { background: #fff3cd; color: #856404; }
        .pill-fresh  { background: #d1fae5; color: #065f46; }

        /* ── Empty / info states ── */
        .empty-state { text-align: center; padding: 1.8rem 1rem; color: #888; font-size: .85rem; }
        .no-sem-card {
            background: #eef2f9; border: 1px solid #c3cfe8; border-left: 4px solid #213769;
            border-radius: 8px; padding: 1.1rem 1.3rem; color: #2b3550;
            font-size: .88rem; line-height: 1.6; margin-bottom: 1.5rem;
        }

        /* ── Previous semester results table ── */
        .prev-sem-group { border-bottom: 1px solid #e0e0e0; }
        .prev-sem-group:last-of-type { border-bottom: none; }
        .prev-sem-header {
            background: #eef2f9; font-weight: 700; font-size: .82rem;
            padding: .42rem .9rem; color: #121e38;
            border-bottom: 1px solid #c3cfe8;
            display: flex; align-items: center; gap: .6rem;
        }
        .prev-sem-warn {
            font-size: .74rem; font-weight: 600; color: #92400e;
            background: #fef3c7; padding: .1rem .4rem; border-radius: 6px;
        }
        .prev-table { width: 100%; border-collapse: collapse; font-size: .82rem; min-width: 500px; }
        .prev-table th {
            background: #2a3a5e; color: #fff; padding: .38rem .7rem;
            text-align: left; font-weight: 600; white-space: nowrap; font-size: .78rem;
        }
        .prev-table td { padding: .38rem .7rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .prev-table tbody tr:last-child td { border-bottom: none; }
        .row-fail { background: #fff8f8; }
        .row-fail:hover { background: #fff0f0; }
        .row-pass { background: #fff; }
        .row-pass:hover { background: #f9fafb; }
        .score-fail { color: #b91c1c; font-weight: 700; }
        .score-pass { color: #065f46; font-weight: 700; }
        .badge-pass {
            font-size: .71rem; font-weight: 700;
            background: #d1fae5; color: #065f46;
            padding: .13rem .5rem; border-radius: 10px;
        }
        .badge-in-curr {
            font-size: .71rem; font-weight: 700;
            background: #e0e7ff; color: #3730a3;
            padding: .13rem .5rem; border-radius: 10px;
        }
        .btn-retake-label {
            display: inline-flex; align-items: center; gap: .25rem;
            font-size: .75rem; font-weight: 700; cursor: pointer;
            background: #fef2f2; color: #b91c1c;
            border: 1.5px solid #fca5a5; border-radius: 6px;
            padding: .22rem .65rem; transition: background .12s, border-color .12s;
            white-space: nowrap; user-select: none;
        }
        .btn-retake-label:hover { background: #fee2e2; border-color: #f87171; }
        .btn-retake-label.is-registered {
            background: #dbeafe; color: #1e40af; border-color: #93c5fd;
        }
        .btn-retake-label .lbl-reg { display: none; }
        .btn-retake-label.is-registered .lbl-unreg { display: none; }
        .btn-retake-label.is-registered .lbl-reg { display: inline; }
        .visually-hidden {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0,0,0,0); white-space: nowrap; border: 0;
        }

        @media (max-width: 580px) {
            .student-row { flex-direction: column; gap: .3rem; }
            .mod-cu-right, .failed-meta { display: none; }
            .prev-table th:nth-child(5),
            .prev-table td:nth-child(5) { display: none; }
        }
    </style>
</head>
<body>

<!-- ── Header ── -->
<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Career &amp; Module Planning</span>
    </div>
</header>

<!-- ── Nav ── -->
<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Career &amp; Module Planner</a>
    <span class="tab-btn active">Module Registration</span>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <!-- ── Greeting ── -->
    <div class="student-row">
        <div class="student-name">Dear, <?= htmlspecialchars(strtoupper($student['student_name'])) ?></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student['student_ID']) ?></div>
    </div>

    <?php if ($msgType): ?>
    <div class="flash <?= $msgType ?>"><?= htmlspecialchars($msgText) ?></div>
    <?php endif; ?>

    <!-- ── Semester banner ── -->
    <?php if ($currentYearNo): ?>
    <div class="sem-banner">
        <div class="sem-icon">🗓</div>
        <div>
            <div class="sem-title">
                You are in Year <?= $currentYearNo ?> · Semester <?= $currentSemNo ?>
            </div>
            <div class="sem-sub">
                <?= htmlspecialchars($student['program_name']) ?> (<?= htmlspecialchars($student['program_code']) ?>)
                &nbsp;·&nbsp; Semester started <?= htmlspecialchars($currentSemStart) ?>
                &nbsp;·&nbsp; Intake: <?= htmlspecialchars($student['intake_session']) ?> <?= $student['intake_year'] ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="no-sem-card">
        <strong>Unable to determine your current semester.</strong><br>
        Your intake details may not match the academic calendar. Please contact the academic office.
    </div>
    <?php endif; ?>

    <?php if ($currentYearNo): ?>
    <!-- ── Registration Form ── -->
    <form method="post" action="ModuleRegistration.php" id="regForm">
        <input type="hidden" name="action" value="register">

        <div class="card">
            <div class="card-header">
                Year <?= $currentYearNo ?> · Semester <?= $currentSemNo ?> — Module Registration
                <span class="card-sub">Tick every module you are taking this semester; mark retakes where applicable</span>
            </div>
            <div class="card-body">

                <?php if (!empty($currentModules)): ?>
                <!-- Toolbar -->
                <div class="reg-toolbar">
                    <div class="cu-display">
                        Selected: <strong><span id="cuTop">0</span> Credit Units</strong>
                    </div>
                    <button type="button" class="btn-sm" id="toggleAll">Select All</button>
                </div>

                <!-- Module list -->
                <div class="mod-rows" id="modRows">
                <?php foreach ($currentModules as $m):
                    $mc         = $m['module_code'];
                    $isChecked  = $isFirstTime || isset($registeredMap[$mc]);
                    $isRetakeOn = isset($registeredMap[$mc]) && $registeredMap[$mc];
                    $prev       = $prevResults[$mc] ?? null;
                    $prevFailed = $prev && (float)$prev['grade_point'] === 0.0;
                ?>
                    <div class="mod-row <?= $isChecked ? 'is-checked' : '' ?>" id="row-<?= htmlspecialchars($mc) ?>">
                        <label class="mod-main" for="mod-<?= htmlspecialchars($mc) ?>">
                            <input type="checkbox"
                                   class="mod-check" id="mod-<?= htmlspecialchars($mc) ?>"
                                   name="modules[]" value="<?= htmlspecialchars($mc) ?>"
                                   data-cu="<?= $m['credit_unit'] ?>"
                                   <?= $isChecked ? 'checked' : '' ?>>
                            <span>
                                <span class="mod-code">
                                    <?= htmlspecialchars($mc) ?>
                                    <?php if ($prev): ?>
                                    <span class="prev-badge <?= $prevFailed ? 'prev-fail' : 'prev-pass' ?>">
                                        <?= $prevFailed
                                            ? '⚠ Previously failed'
                                            : ('Prev: ' . htmlspecialchars($prev['letter_grade']) . ' · ' . $prev['final_total'] . '%') ?>
                                    </span>
                                    <?php endif; ?>
                                </span>
                                <div class="mod-name"><?= htmlspecialchars($m['module_name']) ?></div>
                            </span>
                            <span class="mod-cu-right"><?= $m['credit_unit'] ?> CU</span>
                        </label>

                        <?php if ($prev): ?>
                        <label class="retake-row" for="rt-<?= htmlspecialchars($mc) ?>">
                            <input type="checkbox"
                                   class="retake-check" id="rt-<?= htmlspecialchars($mc) ?>"
                                   name="retakes[]" value="<?= htmlspecialchars($mc) ?>"
                                   <?= $isRetakeOn ? 'checked' : '' ?>>
                            <?php if ($prevFailed): ?>
                            <span class="retake-warn">
                                Mark as retake — failed with <?= htmlspecialchars($prev['letter_grade']) ?> (<?= $prev['final_total'] ?>%)
                            </span>
                            <?php else: ?>
                            <span>
                                Mark as retake — previously scored <?= htmlspecialchars($prev['letter_grade']) ?> (<?= $prev['final_total'] ?>%)
                            </span>
                            <?php endif; ?>
                        </label>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p class="empty-state">
                    No standard modules found for Year <?= $currentYearNo ?> · Semester <?= $currentSemNo ?>.
                    <?= $currentYearNo > 3 ? 'You may have completed all semesters of your programme.' : '' ?>
                </p>
                <?php endif; ?>

                <!-- ── Save bar ── -->
                <?php if (!empty($currentModules)): ?>
                <div class="save-bar">
                    <div class="save-total">
                        Total registered: <strong><span class="cu-total-display">0</span> Credit Units</strong>
                    </div>
                    <button type="submit" class="btn-save">Save Registration</button>
                </div>
                <?php endif; ?>

            </div>
        </div>

        <!-- ── Previous Semester Results ── -->
        <?php if (!empty($prevBySem)): ?>
        <div class="card" style="margin-top:1.25rem;">
            <div class="card-header">
                Previous Semester Results
                <span class="card-sub">Modules scored below 50% can be registered as retakes this semester</span>
            </div>
            <div class="card-body" style="background:#fff; padding:0;">
                <?php foreach ($prevBySem as $semData):
                    $hasBelow50 = false;
                    foreach ($semData['modules'] as $pm) {
                        if ((int)$pm['final_total'] < 50) { $hasBelow50 = true; break; }
                    }
                ?>
                <div class="prev-sem-group">
                    <div class="prev-sem-header">
                        Year <?= $semData['year_no'] ?> &middot; Semester <?= $semData['sem_no'] ?>
                        <?php if ($hasBelow50): ?>
                        <span class="prev-sem-warn">&#9888; Contains failed modules</span>
                        <?php endif; ?>
                    </div>
                    <div class="tbl-wrap">
                    <table class="prev-table">
                        <thead>
                            <tr>
                                <th>Module</th>
                                <th>Name</th>
                                <th style="width:55px">Grade</th>
                                <th style="width:70px">Score</th>
                                <th style="width:42px">CU</th>
                                <th style="width:185px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($semData['modules'] as $pm):
                            $pmc            = $pm['module_code'];
                            $isBelow50      = (int)$pm['final_total'] < 50;
                            $isInCurrentSem = in_array($pmc, $currentModCodes, true);
                            $isAlreadyReg   = isset($registeredMap[$pmc]) && !$isInCurrentSem;
                        ?>
                        <tr class="<?= $isBelow50 ? 'row-fail' : 'row-pass' ?>">
                            <td><strong><?= htmlspecialchars($pmc) ?></strong></td>
                            <td><?= htmlspecialchars($pm['module_name']) ?></td>
                            <td style="text-align:center"><?= htmlspecialchars($pm['letter_grade']) ?></td>
                            <td style="text-align:center" class="<?= $isBelow50 ? 'score-fail' : 'score-pass' ?>">
                                <?= $pm['final_total'] ?>%
                            </td>
                            <td style="text-align:center"><?= $pm['credit_unit'] ?></td>
                            <td>
                                <?php if ($isInCurrentSem): ?>
                                    <span class="badge-in-curr">In Current Sem</span>
                                <?php elseif ($isBelow50): ?>
                                    <label class="btn-retake-label <?= $isAlreadyReg ? 'is-registered' : '' ?>"
                                           for="prev-<?= htmlspecialchars($pmc) ?>">
                                        <input type="checkbox"
                                               class="retake-reg-check visually-hidden"
                                               id="prev-<?= htmlspecialchars($pmc) ?>"
                                               name="modules[]"
                                               value="<?= htmlspecialchars($pmc) ?>"
                                               data-cu="<?= $pm['credit_unit'] ?>"
                                               <?= $isAlreadyReg ? 'checked' : '' ?>>
                                        <span class="lbl-unreg">+ Register as Retake</span>
                                        <span class="lbl-reg">&#10003; Retake Registered</span>
                                    </label>
                                <?php else: ?>
                                    <span class="badge-pass">Passed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
                <?php endforeach; ?>
                <div class="save-bar" style="padding:.75rem 1rem; border-top:1px solid #e0e0e0; margin-top:0;">
                    <div class="save-total">
                        Total selected: <strong><span class="cu-total-display">0</span> Credit Units</strong>
                    </div>
                    <button type="submit" class="btn-save">Save All Registrations</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </form>
    <?php endif; ?>

    <!-- ── Registration History ── -->
    <div class="card">
        <div class="card-header">
            My Registration History
            <span class="card-sub">All modules registered across semesters</span>
        </div>
        <div class="card-body" style="background:#fff; padding:0;">
            <?php if (!empty($historyRows)): ?>
            <div class="tbl-wrap">
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th>Module</th>
                            <th>Name</th>
                            <th>Yr · Sem</th>
                            <th>CU</th>
                            <th>Type</th>
                            <th>Saved On</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($historyRows as $h): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($h['module_code']) ?></strong></td>
                            <td><?= htmlspecialchars($h['module_name']) ?></td>
                            <td>Y<?= $h['year_no'] ?> · S<?= $h['sem_no'] ?></td>
                            <td><?= $h['credit_unit'] ?></td>
                            <td>
                                <span class="pill <?= $h['is_retake'] ? 'pill-retake' : 'pill-fresh' ?>">
                                    <?= $h['is_retake'] ? 'Retake' : 'Fresh' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($h['reg_date']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <p class="empty-state">No registrations yet — save your first registration above.</p>
            <?php endif; ?>
        </div>
    </div>

</main>

<script>
    // ── Credit unit counter ──────────────────────────────────────────────────
    function calcTotal() {
        let total = 0;
        document.querySelectorAll('.mod-check:checked, .retake-reg-check:checked').forEach(cb => {
            total += parseFloat(cb.dataset.cu || 0);
        });
        const display = Number.isInteger(total) ? total : total.toFixed(1);
        document.querySelectorAll('#cuTop, .cu-total-display').forEach(el => { if (el) el.textContent = display; });
    }

    // ── Checked state drives border colour (current semester rows) ──────────
    function syncRowStyle(checkbox) {
        const row = checkbox.closest('.mod-row');
        if (!row) return;
        row.classList.toggle('is-checked', checkbox.checked);
    }

    document.querySelectorAll('.mod-check').forEach(cb => {
        cb.addEventListener('change', () => { syncRowStyle(cb); calcTotal(); });
    });

    // ── Retake-reg checkboxes (previous semester section) ───────────────────
    document.querySelectorAll('.retake-reg-check').forEach(cb => {
        const label = cb.closest('.btn-retake-label');
        cb.addEventListener('change', () => {
            if (label) label.classList.toggle('is-registered', cb.checked);
            calcTotal();
        });
    });

    calcTotal();

    // ── Select / Deselect All (current semester modules only) ──────────────
    const toggleBtn = document.getElementById('toggleAll');
    if (toggleBtn) {
        const checks = () => [...document.querySelectorAll('.mod-check')];
        const allOn  = () => checks().every(c => c.checked);

        toggleBtn.textContent = allOn() ? 'Deselect All' : 'Select All';

        toggleBtn.addEventListener('click', () => {
            const next = !allOn();
            checks().forEach(c => { c.checked = next; syncRowStyle(c); });
            toggleBtn.textContent = next ? 'Deselect All' : 'Select All';
            calcTotal();
        });
    }
</script>

</body>
</html>
