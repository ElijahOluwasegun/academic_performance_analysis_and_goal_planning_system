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

// ─── Current semester = the one AFTER the student's latest completed results ──
//     Registration follows academic PROGRESS, not the wall-clock calendar: a
//     student who is behind the calendar still registers for their next real
//     semester. (term_mapping_tb is used only to label the term for display.)
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

// Latest completed semester (global sem_no 1-6) from the student's recorded results
$stmtLast = $pdo->prepare("SELECT COALESCE(MAX(sem_no), 0) FROM results_tb WHERE student_ID = ?");
$stmtLast->execute([$studentID]);
$latestSem     = (int)$stmtLast->fetchColumn();
$nextSemGlobal = $latestSem + 1;           // the semester they should register for

$currentYearNo   = null;
$currentSemNo    = null;
$currentSemStart = null;
$programComplete = ($nextSemGlobal > 6);   // finished all six semesters

// Map that semester to its year + calendar term for the banner label
foreach ($termRows as $t) {
    if ((int)$t['sem_no'] === $nextSemGlobal) {
        $currentYearNo = (int)$t['year_no'];
        $currentSemNo  = (int)$t['sem_no'];
        $m       = $monthToNum[$t['term_month']] ?? 1;
        $calYear = (int)$student['intake_year'] + (int)$t['year_offset'];
        $currentSemStart = ($monthNames[$m] ?? '') . ' ' . $calYear;
        break;
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

// ─── Initials for the header avatar (e.g. "Jane Mukasa" → "JM") ───────────────
$np       = preg_split('/\s+/', trim($student['student_name']));
$firstNm  = $np[0] ?? '';
$lastNm   = count($np) > 1 ? end($np) : '';
$initials = strtoupper(substr($firstNm, 0, 1) . substr($lastNm, 0, 1));
if ($initials === '') { $initials = 'S'; }

// ─── Unified registration timeline: current modules + failed-previous retakes ─
$regList = [];
foreach ($currentModules as $m) {
    $mc   = $m['module_code'];
    $prev = $prevResults[$mc] ?? null;
    $regList[] = [
        'code'      => $mc,
        'name'      => $m['module_name'],
        'cu'        => (float)$m['credit_unit'],
        'type'      => 'current',
        'checked'   => $isFirstTime || isset($registeredMap[$mc]),
        'retake_on' => isset($registeredMap[$mc]) && $registeredMap[$mc],
        'prev'      => $prev,
        'fail'      => false,
    ];
}
foreach ($prevBySem as $semData) {
    foreach ($semData['modules'] as $pm) {
        $pmc = $pm['module_code'];
        if ((int)$pm['final_total'] >= 50) { continue; }          // only failed modules
        if (in_array($pmc, $currentModCodes, true)) { continue; } // already listed as current
        $regList[] = [
            'code'      => $pmc,
            'name'      => $pm['module_name'],
            'cu'        => (float)$pm['credit_unit'],
            'type'      => 'retake',
            'checked'   => isset($registeredMap[$pmc]),
            'retake_on' => true,
            'prev'      => $pm,
            'fail'      => true,
        ];
    }
}

// Grade label helper for failed modules ("0" → "F" to read naturally)
function gradeLabel(?string $g): string {
    return ($g === '0' || $g === null || $g === '') ? 'F' : $g;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #101828; background: #fbfbfc; }

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
        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; overflow-x: auto; }
        .tab-btn { padding: .8rem 1.1rem .7rem; border: none; background: transparent; font-size: .85rem; font-weight: 600; cursor: pointer; white-space: nowrap; color: rgba(255,255,255,.68); text-decoration: none; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s, background .15s; }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        /* ── Page ── */
        .page-wrap { max-width: 1040px; margin: 0 auto; padding: 0 1.5rem 3rem; }

        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin: 1.4rem 0 1rem; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid  { font-size: .95rem; color: #475467; }
        .student-sid strong { font-weight: 700; margin-right: .3rem; color: #101828; }

        /* ── Flash ── */
        .flash { padding: .75rem 1rem; border-radius: 10px; font-size: .85rem; margin-bottom: 1.2rem; }
        .flash.success { background: #ecfdf5; border: 1px solid #a7e0c4; color: #065f46; }
        .flash.error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        /* ── Semester banner ── */
        .sem-banner { display: flex; align-items: center; gap: 1rem; background: #16213f; color: #fff; border-radius: 12px; padding: 1.1rem 1.3rem; margin-bottom: 1.6rem; }
        .sem-ico { flex: 0 0 auto; width: 2.4rem; height: 2.4rem; border-radius: 8px; background: rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 700; }
        .sem-title { font-size: 1.05rem; font-weight: 700; margin-bottom: .2rem; }
        .sem-sub   { font-size: .82rem; opacity: .82; line-height: 1.5; }

        /* ── Section heading ── */
        .sec-title { font-size: 1.3rem; font-weight: 800; margin-bottom: .2rem; }
        .sec-sub   { color: #667085; font-size: .9rem; margin-bottom: 1.3rem; }

        /* ── Registration timeline ── */
        .reg-timeline { position: relative; }
        .reg-item { display: flex; gap: 1.1rem; position: relative; padding-bottom: 1rem; }
        .reg-rail { position: relative; flex: 0 0 2.5rem; display: flex; justify-content: center; }
        .reg-rail::before { content: ""; position: absolute; left: 50%; top: 2.5rem; bottom: -1rem; width: 2px; background: #e4e7ec; transform: translateX(-50%); }
        .reg-item:last-child .reg-rail::before { display: none; }
        .reg-node { width: 2.5rem; height: 2.5rem; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: .9rem; z-index: 1; flex: 0 0 auto; transition: background .15s, color .15s; }
        .reg-node.num { background: #eef1f4; color: #667085; border: 1px solid #e0e4ea; }
        .reg-node.checked { background: #16213f; color: #fff; border: 1px solid #16213f; }

        .reg-card { flex: 1 1 auto; min-width: 0; background: #fff; border: 1.5px solid #e6e8ec; border-radius: 12px; box-shadow: 0 1px 3px rgba(16,24,40,.05); transition: border-color .15s, box-shadow .15s; }
        .reg-card.is-checked { border-color: #16213f; }
        .reg-main { display: flex; align-items: center; gap: .9rem; padding: .85rem 1.1rem; cursor: pointer; }
        .reg-check { width: 1.15em; height: 1.15em; accent-color: #16213f; flex: 0 0 auto; cursor: pointer; }
        .reg-info { flex: 1 1 auto; min-width: 0; }
        .reg-code { font-weight: 700; font-size: .9rem; color: #101828; }
        .reg-name { font-size: .82rem; color: #33528f; margin-top: .12rem; }
        .reg-cu { flex: 0 0 auto; font-size: .8rem; font-weight: 700; color: #33528f; white-space: nowrap; }
        .reg-card.is-fail .reg-name { color: #b42318; }

        .fail-flag { display: inline-block; margin-left: .5rem; font-size: .74rem; font-weight: 700; color: #b42318; vertical-align: middle; }

        /* Retake chip (only for a current module that was taken before) */
        .retake-chip { display: inline-flex; align-items: center; margin: 0 1.1rem 0 3.15rem; padding: .2rem .6rem; border-radius: 999px; font-size: .74rem; font-weight: 700; cursor: pointer; user-select: none; border: 1.5px solid #d0d5dd; color: #667085; background: #fff; }
        .retake-wrap { padding-bottom: .85rem; margin-top: -.35rem; }
        .retake-chip.is-on { background: #eef2fb; border-color: #33528f; color: #33528f; }
        .retake-chip .rc-on { display: none; }
        .retake-chip.is-on .rc-off { display: none; }
        .retake-chip.is-on .rc-on { display: inline; }

        /* ── Save bar ── */
        .save-bar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap; background: #fff; border: 1px solid #e6e8ec; border-radius: 12px; box-shadow: 0 1px 3px rgba(16,24,40,.05); padding: 1rem 1.25rem; margin-top: .6rem; }
        .save-total { font-size: .9rem; color: #667085; }
        .save-total strong { color: #16213f; font-weight: 700; }
        .btn-save { background: #16213f; color: #fff; font-weight: 600; font-size: .9rem; border: none; border-radius: 999px; padding: .6rem 1.4rem; cursor: pointer; }
        .btn-save:hover { background: #0c1730; }

        /* ── History card ── */
        .card { background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); overflow: hidden; margin-top: 2rem; }
        .card-header { padding: 1rem 1.25rem; border-bottom: 1px solid #eef0f3; }
        .card-title { font-size: 1rem; font-weight: 700; }
        .card-sub { font-size: .82rem; color: #98a2b3; margin-top: .1rem; }
        .tbl-wrap { overflow-x: auto; }
        .hist-table { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 520px; }
        .hist-table th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #98a2b3; font-weight: 700; padding: .55rem 1.25rem; background: #fcfcfd; white-space: nowrap; }
        .hist-table td { padding: .55rem 1.25rem; border-top: 1px solid #f0f1f4; color: #344054; }
        .hist-table td.code { font-weight: 700; color: #101828; }
        .pill { font-size: .72rem; font-weight: 700; padding: .15rem .55rem; border-radius: 999px; }
        .pill-retake { background: #eef2fb; color: #33528f; }
        .pill-fresh  { background: #eef1f4; color: #667085; }

        /* ── Empty / info states ── */
        .empty-state { text-align: center; padding: 2.2rem 1rem; color: #98a2b3; font-size: .88rem; background: #fff; border: 1px solid #e6e8ec; border-radius: 12px; }
        .no-sem-card { background: #eef2f9; border: 1px solid #c3cfe8; border-left: 4px solid #16213f; border-radius: 12px; padding: 1.1rem 1.3rem; color: #2b3550; font-size: .88rem; line-height: 1.6; margin-bottom: 1.5rem; }

        @media (max-width: 620px) {
            .student-row { flex-direction: column; gap: .3rem; }
            .reg-rail { flex-basis: 2rem; }
            .reg-node { width: 2rem; height: 2rem; font-size: .8rem; }
            .retake-chip { margin-left: 2.65rem; }
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
    <div class="header-user">
        <div class="hu-text">
            <span class="hu-name"><?= htmlspecialchars($student['student_name']) ?></span>
            <span class="hu-sid">SID <?= htmlspecialchars($student['student_ID']) ?></span>
        </div>
        <div class="hu-avatar"><?= htmlspecialchars($initials) ?></div>
        <a class="logout-btn" href="logout.php">Log out</a>
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
        <div class="sem-ico">&#9432;</div>
        <div>
            <div class="sem-title">You are in Year <?= $currentYearNo ?> &middot; Semester <?= $currentSemNo ?></div>
            <div class="sem-sub">
                <?= htmlspecialchars($student['program_name']) ?> (<?= htmlspecialchars($student['program_code']) ?>)
                &nbsp;·&nbsp; Semester started <?= htmlspecialchars($currentSemStart) ?>
                &nbsp;·&nbsp; Intake: <?= htmlspecialchars($student['intake_session']) ?> <?= $student['intake_year'] ?>
            </div>
        </div>
    </div>
    <?php elseif ($programComplete): ?>
    <div class="no-sem-card">
        <strong>&#127891; You've completed all six semesters of your programme.</strong><br>
        There are no further modules to register — congratulations!
    </div>
    <?php else: ?>
    <div class="no-sem-card">
        <strong>Unable to determine your current semester.</strong><br>
        Your intake details may not match the academic calendar. Please contact the academic office.
    </div>
    <?php endif; ?>

    <?php if ($currentYearNo): ?>
    <div class="sec-title">Register your modules</div>
    <div class="sec-sub">Tick every module you're taking this semester; mark retakes where applicable.</div>

    <form method="post" action="ModuleRegistration.php" id="regForm">
        <input type="hidden" name="action" value="register">

        <?php if (!empty($regList)): ?>
        <div class="reg-timeline">
            <?php foreach ($regList as $i => $row):
                $code    = $row['code'];
                $checked = $row['checked'];
                $isFail  = $row['fail'];
                $type    = $row['type'];
                $ord     = $i + 1;
            ?>
            <div class="reg-item">
                <div class="reg-rail">
                    <div class="reg-node <?= $checked ? 'checked' : 'num' ?>" data-ord="<?= $ord ?>">
                        <?= $checked ? '&#10003;' : $ord ?>
                    </div>
                </div>

                <div class="reg-card <?= $checked ? 'is-checked' : '' ?> <?= $isFail ? 'is-fail' : '' ?>">
                    <label class="reg-main" for="mod-<?= htmlspecialchars($code) ?>">
                        <input type="checkbox"
                               class="reg-check"
                               id="mod-<?= htmlspecialchars($code) ?>"
                               name="modules[]"
                               value="<?= htmlspecialchars($code) ?>"
                               data-cu="<?= $row['cu'] ?>"
                               data-type="<?= $type ?>"
                               <?= $checked ? 'checked' : '' ?>>
                        <div class="reg-info">
                            <div class="reg-code">
                                <?= htmlspecialchars($code) ?>
                                <?php if ($isFail && $row['prev']): ?>
                                    <span class="fail-flag">&#9888; Previously failed &middot; <?= htmlspecialchars(gradeLabel($row['prev']['letter_grade'])) ?> (<?= (int)$row['prev']['final_total'] ?>%)</span>
                                <?php endif; ?>
                            </div>
                            <div class="reg-name"><?= htmlspecialchars($row['name']) ?></div>
                        </div>
                        <div class="reg-cu"><?= rtrim(rtrim(number_format($row['cu'], 1), '0'), '.') ?> CU</div>
                    </label>

                    <?php if ($type === 'current' && $row['prev']): ?>
                    <div class="retake-wrap">
                        <label class="retake-chip <?= $row['retake_on'] ? 'is-on' : '' ?>">
                            <input type="checkbox" class="retake-toggle" name="retakes[]"
                                   value="<?= htmlspecialchars($code) ?>"
                                   style="position:absolute;opacity:0;width:0;height:0;"
                                   <?= $row['retake_on'] ? 'checked' : '' ?>>
                            <span class="rc-off">Mark as retake (previously <?= htmlspecialchars(gradeLabel($row['prev']['letter_grade'])) ?>, <?= (int)$row['prev']['final_total'] ?>%)</span>
                            <span class="rc-on">&#10003; Registered as retake</span>
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- ── Save bar ── -->
        <div class="save-bar">
            <div class="save-total">
                Registered <strong id="regCount">0</strong> module<span id="regPlural"></span>
                — <strong id="freshCount">0</strong> fresh &amp; <strong id="retakeCount">0</strong> retake
                &nbsp;·&nbsp; <strong class="cu-total-display">0</strong> CU
            </div>
            <button type="submit" class="btn-save">Save registration</button>
        </div>
        <?php else: ?>
        <p class="empty-state">
            No standard modules found for Year <?= $currentYearNo ?> &middot; Semester <?= $currentSemNo ?>.
            <?= $currentYearNo > 3 ? 'You may have completed all semesters of your programme.' : '' ?>
        </p>
        <?php endif; ?>

    </form>
    <?php endif; ?>

    <!-- ── Registration History ── -->
    <div class="card">
        <div class="card-header">
            <div class="card-title">My Registration History</div>
            <div class="card-sub">All modules registered across semesters</div>
        </div>
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
                        <td class="code"><?= htmlspecialchars($h['module_code']) ?></td>
                        <td><?= htmlspecialchars($h['module_name']) ?></td>
                        <td>Y<?= $h['year_no'] ?> · S<?= $h['sem_no'] ?></td>
                        <td><?= rtrim(rtrim(number_format((float)$h['credit_unit'], 1), '0'), '.') ?></td>
                        <td><span class="pill <?= $h['is_retake'] ? 'pill-retake' : 'pill-fresh' ?>"><?= $h['is_retake'] ? 'Retake' : 'Fresh' ?></span></td>
                        <td><?= htmlspecialchars($h['reg_date']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="empty-state" style="border:none;border-radius:0;">No registrations yet — save your first registration above.</p>
        <?php endif; ?>
    </div>

</main>

<script>
    function refresh() {
        let total = 0, fresh = 0, retake = 0;

        document.querySelectorAll('.reg-check').forEach(cb => {
            const item = cb.closest('.reg-item');
            const node = item.querySelector('.reg-node');
            const card = item.querySelector('.reg-card');

            if (cb.checked) {
                total += parseFloat(cb.dataset.cu || 0);

                // Retake if it's an out-of-semester module, or its retake toggle is on
                let isRetake = cb.dataset.type === 'retake';
                const rt = item.querySelector('.retake-toggle');
                if (cb.dataset.type === 'current' && rt && rt.checked) { isRetake = true; }
                isRetake ? retake++ : fresh++;

                node.classList.add('checked'); node.classList.remove('num');
                node.innerHTML = '&#10003;';
                card.classList.add('is-checked');
            } else {
                node.classList.remove('checked'); node.classList.add('num');
                node.textContent = node.dataset.ord;
                card.classList.remove('is-checked');
            }
        });

        // Retake chip visual state
        document.querySelectorAll('.retake-toggle').forEach(rt => {
            const chip = rt.closest('.retake-chip');
            if (chip) chip.classList.toggle('is-on', rt.checked);
        });

        const disp = Number.isInteger(total) ? total : total.toFixed(1);
        document.querySelectorAll('.cu-total-display').forEach(e => e.textContent = disp);

        const regN = fresh + retake;
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        set('regCount', regN);
        set('freshCount', fresh);
        set('retakeCount', retake);
        const pl = document.getElementById('regPlural'); if (pl) pl.textContent = regN === 1 ? '' : 's';
    }

    document.querySelectorAll('.reg-check, .retake-toggle').forEach(cb => cb.addEventListener('change', refresh));

    // Prevent the retake chip's click from toggling the row's main checkbox label
    document.querySelectorAll('.retake-chip').forEach(chip => {
        chip.addEventListener('click', e => e.stopPropagation());
    });

    refresh();
</script>

</body>
</html>
