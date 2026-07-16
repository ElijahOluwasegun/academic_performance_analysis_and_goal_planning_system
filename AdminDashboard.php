<?php
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: AdminLogin.php');
    exit();
}

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";
$db_pass = "";

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

// ─── Grade scale (to derive letter/GP when approving a corrected mark) ────────
$grades = $pdo->query("SELECT min_mark, max_mark, letter_grade, grade_point FROM grade_system")->fetchAll();
function gradeFromScore(int $score, array $grades): array {
    foreach ($grades as $g) {
        if ($score >= (int)$g["min_mark"] && $score <= (int)$g["max_mark"]) {
            return ["letter_grade" => $g["letter_grade"], "grade_point" => $g["grade_point"]];
        }
    }
    return ["letter_grade" => "0", "grade_point" => "0.00"];
}

// ─── Handle POST (assign / unassign / mark-correction review) — PRG pattern ──
$flashMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Mark-correction review: approve or reject a lecturer's proposed mark ──
    if ($action === 'approve_correction' || $action === 'reject_correction') {
        $corrID    = (int)($_POST['correction_id'] ?? 0);
        $adminNote = trim($_POST['admin_note'] ?? '');

        $cs = $pdo->prepare("SELECT * FROM mark_correction_tb WHERE correction_ID = ? AND status = 'Pending'");
        $cs->execute([$corrID]);
        $corr = $cs->fetch();

        if (!$corr) {
            header('Location: AdminDashboard.php?msg=corr_missing');
            exit();
        }

        if ($action === 'reject_correction') {
            $pdo->prepare("UPDATE mark_correction_tb SET status='Rejected', admin_note=?, reviewed_at=NOW() WHERE correction_ID=?")
                ->execute([$adminNote !== '' ? $adminNote : null, $corrID]);
            header('Location: AdminDashboard.php?msg=corr_rejected');
            exit();
        }

        // Approve → write the corrected COMPONENT (CAT1/CAT2/Exam) + recomputed
        // total into results_tb, which fires the GPA/CGPA recalculation trigger.
        $newTotal  = (int)$corr['new_total'];
        $g         = gradeFromScore($newTotal, $grades);
        $passStat  = $newTotal >= 50 ? 'Pass' : 'Retake';
        $catColumn = ['CAT1' => 'cat1_mk', 'CAT2' => 'cat2_mk', 'Exam' => 'exam_mk'];
        $col       = $catColumn[$corr['category']] ?? null;
        try {
            $pdo->beginTransaction();

            if ($col !== null && $corr['new_component'] !== null) {
                // Update the exact reported component ($col is from a fixed whitelist)
                $pdo->prepare("
                    UPDATE results_tb
                    SET {$col} = ?, final_total = ?, grade_point = ?, letter_grade = ?, status_retake_pass = ?
                    WHERE student_ID = ? AND module_code = ?
                ")->execute([(int)$corr['new_component'], $newTotal, $g['grade_point'], $g['letter_grade'], $passStat,
                             $corr['student_ID'], $corr['module_code']]);
            } else {
                // Fallback for legacy corrections with no component recorded → total only
                $pdo->prepare("
                    UPDATE results_tb
                    SET final_total = ?, grade_point = ?, letter_grade = ?, status_retake_pass = ?
                    WHERE student_ID = ? AND module_code = ?
                ")->execute([$newTotal, $g['grade_point'], $g['letter_grade'], $passStat,
                             $corr['student_ID'], $corr['module_code']]);
            }

            $pdo->prepare("UPDATE mark_correction_tb SET status='Approved', admin_note=?, reviewed_at=NOW() WHERE correction_ID=?")
                ->execute([$adminNote !== '' ? $adminNote : null, $corrID]);

            // Close the loop: mark the originating student report as resolved
            $pdo->prepare("UPDATE module_report_tb SET status='Resolved' WHERE report_ID=?")
                ->execute([$corr['report_ID']]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            header('Location: AdminDashboard.php?msg=error');
            exit();
        }
        header('Location: AdminDashboard.php?msg=corr_approved');
        exit();
    }

    $lecturerID = trim($_POST['lecturer_id'] ?? '');
    $moduleCode = trim($_POST['module_code']  ?? '');

    if ($lecturerID && $moduleCode) {
        // Verify both records exist before touching the junction table
        $okLec = $pdo->prepare("SELECT 1 FROM lecturer_tb  WHERE lecturer_ID  = ? LIMIT 1");
        $okMod = $pdo->prepare("SELECT 1 FROM module_tb    WHERE module_code   = ? LIMIT 1");
        $okLec->execute([$lecturerID]);
        $okMod->execute([$moduleCode]);

        if ($okLec->fetch() && $okMod->fetch()) {
            if ($action === 'assign') {
                // Prevent assigning a module already held by any other lecturer
                $chk = $pdo->prepare("SELECT lecturer_ID FROM lecturer_module_tb WHERE module_code = ? LIMIT 1");
                $chk->execute([$moduleCode]);
                if ($chk->fetch()) {
                    header('Location: AdminDashboard.php?msg=module_taken&lec=' . urlencode($lecturerID));
                    exit();
                }
                $pdo->prepare("INSERT INTO lecturer_module_tb (lecturer_ID, module_code) VALUES (?, ?)")
                    ->execute([$lecturerID, $moduleCode]);
                header('Location: AdminDashboard.php?msg=assigned&lec=' . urlencode($lecturerID));
                exit();
            } elseif ($action === 'unassign') {
                $pdo->prepare("DELETE FROM lecturer_module_tb WHERE lecturer_ID = ? AND module_code = ?")
                    ->execute([$lecturerID, $moduleCode]);
                header('Location: AdminDashboard.php?msg=unassigned&lec=' . urlencode($lecturerID));
                exit();
            }
        }
    }

    header('Location: AdminDashboard.php?msg=error');
    exit();
}

// ─── Fetch flash message ──────────────────────────────────────────────────────
$msgType = null;
$msgText = null;
if (isset($_GET['msg'])) {
    match($_GET['msg']) {
        'assigned'      => [$msgType, $msgText] = ['success', 'Module assigned successfully.'],
        'unassigned'    => [$msgType, $msgText] = ['success', 'Module removed from lecturer.'],
        'module_taken'  => [$msgType, $msgText] = ['error',   'That module is already assigned to another lecturer.'],
        'corr_approved' => [$msgType, $msgText] = ['success', 'Mark correction approved — the student\'s record and GPA have been updated.'],
        'corr_rejected' => [$msgType, $msgText] = ['success', 'Mark correction rejected. The student\'s mark was left unchanged.'],
        'corr_missing'  => [$msgType, $msgText] = ['error',   'That correction was not found or has already been reviewed.'],
        'error'         => [$msgType, $msgText] = ['error',   'Something went wrong. Please try again.'],
        default         => null,
    };
}

// ─── Fetch all lecturers ──────────────────────────────────────────────────────
$stmtL = $pdo->query("
    SELECT lecturer_ID, lecturer_name, lecturer_title, lecturer_email,
           lecturer_faculty, lecturer_department
    FROM   lecturer_tb
    ORDER  BY lecturer_name
");
$lecturers = $stmtL->fetchAll();

// ─── Fetch all assignments in one query ──────────────────────────────────────
$stmtA = $pdo->query("
    SELECT lm.lecturer_ID, lm.module_code, m.module_name, m.year_no, m.sem_no
    FROM   lecturer_module_tb lm
    JOIN   module_tb m ON lm.module_code = m.module_code
    ORDER  BY lm.lecturer_ID, m.year_no, m.sem_no, m.module_code
");
$assignedByLecturer = [];
foreach ($stmtA->fetchAll() as $a) {
    $assignedByLecturer[$a['lecturer_ID']][] = $a;
}

// ─── Fetch all modules ────────────────────────────────────────────────────────
$stmtM = $pdo->query("
    SELECT module_code, module_name, year_no, sem_no
    FROM   module_tb
    ORDER  BY year_no, sem_no, module_code
");
$allModules = $stmtM->fetchAll();

// ─── Summary stats ────────────────────────────────────────────────────────────
$totalModules      = count($allModules);
$totalLecturers    = count($lecturers);
$assignedModCodes  = $pdo->query("SELECT DISTINCT module_code FROM lecturer_module_tb")->fetchAll(PDO::FETCH_COLUMN);
$totalAssigned     = count($assignedModCodes);
$totalUnassigned   = $totalModules - $totalAssigned;

// ─── Pending mark corrections awaiting admin review ──────────────────────────
$pendingCorrections = $pdo->query("
    SELECT c.correction_ID, c.report_ID, c.old_total, c.new_total, c.new_letter_grade,
           c.new_grade_point, c.old_component, c.new_component, c.lecturer_note, c.created_at, c.module_code,
           s.student_name, s.student_ID, m.module_name,
           l.lecturer_name, r.category, r.message
    FROM   mark_correction_tb c
    JOIN   student_tb s ON c.student_ID  = s.student_ID
    JOIN   module_tb  m ON c.module_code = m.module_code
    LEFT JOIN lecturer_tb      l ON c.lecturer_ID = l.lecturer_ID
    LEFT JOIN module_report_tb r ON c.report_ID   = r.report_ID
    WHERE  c.status = 'Pending'
    ORDER  BY c.created_at ASC
")->fetchAll();

// Highlight the lecturer card that was just modified
$highlightLec = $_GET['lec'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Assignment — Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #f4f5f8; }

        /* ── Header ── */
        .site-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.1rem 1.5rem; background: #213769;
        }
        .site-header h4 {
            color: #d9c581; font-family: 'Montserrat', sans-serif;
            letter-spacing: 0.2rem; font-weight: 600; font-size: 0.72rem;
            text-transform: uppercase; margin-bottom: 0.15rem;
        }
        .site-header h1 { color: #fff; font-size: 1.05rem; font-weight: 700; }
        .logout-link { color: #cdd6ef; font-size: 0.82rem; text-decoration: none; }
        .logout-link:hover { text-decoration: underline; }

        /* ── Nav ── */
        .tab-nav {
            display: flex; gap: 0.35rem; padding: 0 1.5rem;
            background: #16213f; border-bottom: 1px solid #0d1730;
        }
        .tab-btn {
            padding: .75rem 1.1rem .65rem; border: none; background: transparent;
            font-size: .84rem; font-weight: 600; cursor: default;
            color: #fff; border-bottom: 3px solid #c9a227;
            text-decoration: none;
        }

        /* ── Page wrap ── */
        .page-wrap { max-width: 960px; margin: 0 auto; padding: 1.6rem 1.5rem 3rem; }

        /* ── Alert ── */
        .alert {
            padding: .75rem 1rem; border-radius: 7px;
            font-size: .86rem; margin-bottom: 1.25rem;
        }
        .alert-success { background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }
        .alert-error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        /* ── Summary stats ── */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.85rem;
            margin-bottom: 1.6rem;
        }
        .stat-card {
            background: #fff;
            border: 1px solid #d9dce8;
            border-radius: 8px;
            padding: 1rem 1.1rem;
        }
        .stat-label { font-size: .76rem; font-weight: 600; color: #7b8294; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .3rem; }
        .stat-value { font-size: 1.7rem; font-weight: 700; color: #16213f; line-height: 1; }
        .stat-card.warn .stat-value { color: #b45309; }

        /* ── Section heading ── */
        .section-heading {
            font-size: .9rem; font-weight: 700; color: #16213f;
            margin-bottom: 1rem; padding-bottom: .5rem;
            border-bottom: 2px solid #d9dce8;
        }

        /* ── Pending mark corrections ── */
        .corr-section { margin-bottom: 1.8rem; }
        .corr-card { background: #fff; border: 1px solid #f0c891; border-left: 4px solid #d97706; border-radius: 9px; padding: 1rem 1.2rem; margin-bottom: .9rem; }
        .corr-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: .5rem; }
        .corr-mod { font-weight: 700; font-size: .95rem; color: #16213f; }
        .corr-meta { font-size: .8rem; color: #666; margin-top: .15rem; }
        .corr-change { display: inline-flex; align-items: center; gap: .5rem; font-size: .95rem; background: #fff7e6; border: 1px solid #f0d49a; border-radius: 8px; padding: .35rem .7rem; white-space: nowrap; }
        .corr-change .old { color: #991b1b; text-decoration: line-through; font-weight: 600; }
        .corr-change .new { color: #065f46; font-weight: 700; }
        .corr-msg { font-size: .82rem; color: #333; background: #f7f8fa; border-radius: 6px; padding: .6rem .8rem; margin: .6rem 0; line-height: 1.5; }
        .corr-msg .lab { font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #777; display: block; margin-bottom: .25rem; }
        .corr-actions { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-top: .5rem; }
        .corr-actions input.admin-note { flex: 1 1 220px; padding: .5rem .65rem; border: 1.5px solid #c9cdda; border-radius: 6px; font-family: inherit; font-size: .84rem; }
        .btn-approve { padding: .5rem 1.1rem; border: none; border-radius: 18px; background: #157347; color: #fff; font-weight: 700; font-size: .82rem; cursor: pointer; }
        .btn-approve:hover { background: #0f5132; }
        .btn-reject { padding: .5rem 1.1rem; border: 1px solid #dc3545; border-radius: 18px; background: #fff; color: #dc3545; font-weight: 700; font-size: .82rem; cursor: pointer; }
        .btn-reject:hover { background: #dc3545; color: #fff; }

        /* ── Lecturer card ── */
        .lec-card {
            background: #fff;
            border: 1px solid #c8ccd8;
            border-radius: 9px;
            margin-bottom: 1.1rem;
            overflow: hidden;
            transition: box-shadow .15s;
        }
        .lec-card.highlight { border-color: #c9a227; box-shadow: 0 0 0 2px rgba(201,162,39,0.25); }

        .lec-header {
            background: #121e38;
            padding: .7rem 1.1rem;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .lec-name {
            font-weight: 700; font-size: .95rem; color: #fff;
        }
        .lec-name span { font-weight: 400; font-size: .82rem; color: rgba(255,255,255,.6); margin-left: .5rem; }
        .lec-email { font-size: .8rem; color: #adb9d6; }
        .lec-dept  { font-size: .78rem; color: rgba(255,255,255,.5); }

        .lec-body { padding: .95rem 1.1rem 1.1rem; }

        /* ── Module pills ── */
        .pills-label {
            font-size: .74rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #7b8294; margin-bottom: .55rem;
        }
        .pills-wrap { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: .95rem; min-height: 1.8rem; }

        .module-pill {
            display: inline-flex; align-items: center; gap: .3rem;
            background: #e8ecf8; border: 1px solid #c2caea;
            border-radius: 14px;
            padding: .22rem .55rem .22rem .7rem;
            font-size: .8rem; font-weight: 600; color: #16213f;
        }
        .pill-code { font-family: 'Montserrat', sans-serif; letter-spacing: .02em; }
        .pill-sem  { font-size: .72rem; font-weight: 400; color: #5a6378; }

        .btn-remove {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px;
            background: none; border: 1px solid #b0bae8; border-radius: 50%;
            font-size: .8rem; line-height: 1; color: #5a6378;
            cursor: pointer; padding: 0; flex-shrink: 0;
            transition: background .12s, color .12s;
        }
        .btn-remove:hover { background: #c7314a; border-color: #c7314a; color: #fff; }
        .no-modules { font-size: .84rem; color: #aaa; font-style: italic; }

        /* ── Assign form ── */
        .assign-wrap {
            display: flex; align-items: center; gap: .6rem;
            flex-wrap: wrap;
            padding-top: .85rem;
            border-top: 1px solid #e8eaf2;
        }
        .assign-label { font-size: .78rem; font-weight: 600; color: #2b3550; white-space: nowrap; }

        .assign-select {
            flex: 1; min-width: 220px;
            padding: .48rem .7rem;
            border: 1.5px solid #c9cdda; border-radius: 7px;
            font-family: 'Inter', sans-serif; font-size: .86rem;
            background: #fff; color: #1c2433;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='7' viewBox='0 0 11 7'%3E%3Cpath d='M1 1l4.5 4.5L10 1' stroke='%235a6378' stroke-width='1.4' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            padding-right: 2.2rem;
        }
        .assign-select:focus { outline: none; border-color: #213769; box-shadow: 0 0 0 2px rgba(33,55,105,0.12); }

        .btn-assign {
            padding: .48rem 1.15rem; border: none; border-radius: 18px;
            background: #213769; color: #fff;
            font-weight: 700; font-size: .82rem; cursor: pointer;
            white-space: nowrap;
        }
        .btn-assign:hover { background: #121e38; }
        .btn-assign:disabled { background: #b0b5c0; cursor: not-allowed; }

        .all-assigned { font-size: .8rem; color: #7b8294; font-style: italic; }

        @media (max-width: 560px) {
            .summary-row { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <div>
        <h4>Cavendish University</h4>
        <h1>Admin Panel — Module Assignment</h1>
    </div>
    <a class="logout-link" href="AdminLogout.php">Log out</a>
</header>

<nav class="tab-nav">
    <span class="tab-btn">Module Assignment</span>
</nav>

<main class="page-wrap">

    <?php if ($msgText): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msgText) ?></div>
    <?php endif; ?>

    <!-- ── Pending mark corrections (lecturer → admin review) ── -->
    <?php if (!empty($pendingCorrections)): ?>
    <div class="corr-section">
        <div class="section-heading">Pending Mark Corrections (<?= count($pendingCorrections) ?>)</div>
        <?php foreach ($pendingCorrections as $c): ?>
        <div class="corr-card">
            <div class="corr-top">
                <div>
                    <div class="corr-mod"><?= htmlspecialchars($c["module_name"]) ?> (<?= htmlspecialchars($c["module_code"]) ?>)</div>
                    <div class="corr-meta">
                        <?= htmlspecialchars($c["student_name"]) ?> (<?= htmlspecialchars($c["student_ID"]) ?>)
                        · Proposed by <?= htmlspecialchars($c["lecturer_name"] ?? "lecturer") ?>
                        <?php if (!empty($c["category"])): ?> · <?= htmlspecialchars($c["category"]) ?><?php endif; ?>
                        · <?= htmlspecialchars(date('d M Y, H:i', strtotime($c["created_at"]))) ?>
                    </div>
                </div>
                <div class="corr-change">
                    <?php if (!empty($c["category"]) && $c["new_component"] !== null): ?>
                        <span class="old"><?= htmlspecialchars($c["category"]) ?> <?= (int)$c["old_component"] ?></span>
                        &rarr;
                        <span class="new"><?= (int)$c["new_component"] ?></span>
                        <span style="margin-left:.35rem; color:#475467; font-weight:600;">&rarr; total <?= (int)$c["new_total"] ?>% (<?= htmlspecialchars($c["new_letter_grade"]) ?>)</span>
                    <?php else: ?>
                        <span class="old"><?= (int)$c["old_total"] ?>%</span>
                        &rarr;
                        <span class="new"><?= (int)$c["new_total"] ?>% (<?= htmlspecialchars($c["new_letter_grade"]) ?>)</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($c["message"])): ?>
            <div class="corr-msg"><span class="lab">Student's report</span><?= nl2br(htmlspecialchars($c["message"])) ?></div>
            <?php endif; ?>
            <?php if (!empty($c["lecturer_note"])): ?>
            <div class="corr-msg"><span class="lab">Lecturer's note</span><?= nl2br(htmlspecialchars($c["lecturer_note"])) ?></div>
            <?php endif; ?>

            <form method="post" action="AdminDashboard.php" class="corr-actions">
                <input type="hidden" name="correction_id" value="<?= (int)$c["correction_ID"] ?>">
                <input type="text" name="admin_note" class="admin-note" placeholder="Note (optional)">
                <button type="submit" name="action" value="approve_correction" class="btn-approve"
                        onclick="return confirm('Apply this mark change to the student\'s record? Their GPA/CGPA will be recalculated.');">
                    Approve &amp; update mark
                </button>
                <button type="submit" name="action" value="reject_correction" class="btn-reject"
                        onclick="return confirm('Reject this proposed mark change? The student\'s mark stays as-is.');">
                    Reject
                </button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Summary stats ── -->
    <div class="summary-row">
        <div class="stat-card">
            <div class="stat-label">Lecturers</div>
            <div class="stat-value"><?= $totalLecturers ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Modules</div>
            <div class="stat-value"><?= $totalModules ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Modules Assigned</div>
            <div class="stat-value"><?= $totalAssigned ?></div>
        </div>
        <div class="stat-card <?= $totalUnassigned > 0 ? 'warn' : '' ?>">
            <div class="stat-label">Without Lecturer</div>
            <div class="stat-value"><?= $totalUnassigned ?></div>
        </div>
    </div>

    <!-- ── Lecturer cards ── -->
    <div class="section-heading">Lecturers &amp; Module Assignments</div>

    <?php foreach ($lecturers as $lec):
        $lid          = $lec['lecturer_ID'];
        $assigned     = $assignedByLecturer[$lid] ?? [];
        $assignedCodes = array_column($assigned, 'module_code');
        $available    = array_filter($allModules, fn($m) => !in_array($m['module_code'], $assignedModCodes));
        $isHighlight  = ($highlightLec === $lid);
    ?>
    <div class="lec-card <?= $isHighlight ? 'highlight' : '' ?>" id="lec-<?= htmlspecialchars($lid) ?>">

        <!-- Card header -->
        <div class="lec-header">
            <div>
                <div class="lec-name">
                    <?= htmlspecialchars(trim(($lec['lecturer_title'] ? $lec['lecturer_title'] . ' ' : '') . $lec['lecturer_name'])) ?>
                    <span>(<?= htmlspecialchars($lid) ?>)</span>
                </div>
                <?php if ($lec['lecturer_faculty'] || $lec['lecturer_department']): ?>
                <div class="lec-dept">
                    <?= htmlspecialchars(implode(' · ', array_filter([$lec['lecturer_faculty'], $lec['lecturer_department']]))) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="lec-email"><?= htmlspecialchars($lec['lecturer_email']) ?></div>
        </div>

        <!-- Card body -->
        <div class="lec-body">

            <!-- Assigned modules -->
            <div class="pills-label">Assigned modules (<?= count($assigned) ?>)</div>
            <div class="pills-wrap">
                <?php if (empty($assigned)): ?>
                    <span class="no-modules">No modules assigned yet.</span>
                <?php else: ?>
                    <?php foreach ($assigned as $mod): ?>
                    <span class="module-pill">
                        <span class="pill-code"><?= htmlspecialchars($mod['module_code']) ?></span>
                        <span class="pill-sem">Y<?= $mod['year_no'] ?>S<?= $mod['sem_no'] ?></span>
                        <form method="post" action="AdminDashboard.php" style="display:inline;margin:0;">
                            <input type="hidden" name="action"      value="unassign">
                            <input type="hidden" name="lecturer_id" value="<?= htmlspecialchars($lid) ?>">
                            <input type="hidden" name="module_code" value="<?= htmlspecialchars($mod['module_code']) ?>">
                            <button type="submit" class="btn-remove"
                                    title="Remove <?= htmlspecialchars($mod['module_code']) ?> from <?= htmlspecialchars($lec['lecturer_name']) ?>"
                                    onclick="return confirm('Remove <?= htmlspecialchars(addslashes($mod['module_code'])) ?> from <?= htmlspecialchars(addslashes($lec['lecturer_name'])) ?>?')">
                                &times;
                            </button>
                        </form>
                    </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Assign form -->
            <div class="assign-wrap">
                <span class="assign-label">Assign module:</span>
                <?php if (empty($available)): ?>
                    <span class="all-assigned">All modules are already assigned to this lecturer.</span>
                <?php else: ?>
                <form method="post" action="AdminDashboard.php" style="display:contents;">
                    <input type="hidden" name="action"      value="assign">
                    <input type="hidden" name="lecturer_id" value="<?= htmlspecialchars($lid) ?>">

                    <select name="module_code" class="assign-select" required>
                        <option value="" disabled selected>Select a module…</option>
                        <?php
                        $currentYear = null;
                        foreach ($available as $mod):
                            if ($mod['year_no'] !== $currentYear):
                                if ($currentYear !== null) echo '</optgroup>';
                                echo '<optgroup label="Year ' . (int)$mod['year_no'] . '">';
                                $currentYear = $mod['year_no'];
                            endif;
                        ?>
                            <option value="<?= htmlspecialchars($mod['module_code']) ?>">
                                <?= htmlspecialchars($mod['module_code']) ?> — <?= htmlspecialchars($mod['module_name']) ?>
                                (Sem <?= (int)$mod['sem_no'] ?>)
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentYear !== null) echo '</optgroup>'; ?>
                    </select>

                    <button type="submit" class="btn-assign">Assign</button>
                </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

</main>

<?php if ($highlightLec): ?>
<script>
    // Scroll to and briefly flash the recently changed card
    const el = document.getElementById('lec-<?= htmlspecialchars($highlightLec) ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => el.classList.remove('highlight'), 3000);
    }
</script>
<?php endif; ?>

</body>
</html>
