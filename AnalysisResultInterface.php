<?php
session_start();

// Allow access if they are logging in via POST OR if they already have an active session
if ($_SERVER["REQUEST_METHOD"] !== "POST" && empty($_SESSION["student_ID"])) {
    header("Location: index.php");
    exit();
}

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Require an active session (this page is reached via the navbar, not a login form) ──
if (empty($_SESSION["student_ID"])) {
    header("Location: index.php?error=session_expired");
    exit();
}

$studentID = $_SESSION["student_ID"];

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

// ─── Re-fetch student + program info (session only stores ID + name) ──────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code,
           p.program_name, p.program_faculty
    FROM   student_tb s
    JOIN   program_tb p ON s.program_code = p.program_code
    WHERE  s.student_ID = ?
    LIMIT  1
");
$stmtS->execute([$studentID]);
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
           r.status_retake_pass
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = ?
    ORDER  BY r.year_no ASC, r.sem_no ASC, r.module_code ASC
");
$stmtR->execute([$studentID]);
$allResults = $stmtR->fetchAll();

// ─── Fetch GPA per semester ────────────────────────────────────────────────────
$stmtG = $pdo->prepare("
    SELECT sem_no, gpa_value
    FROM   gpa_tb
    WHERE  student_ID = ?
    ORDER  BY sem_no ASC
");
$stmtG->execute([$studentID]);
$gpaRows = $stmtG->fetchAll();
$gpaMap = [];
foreach ($gpaRows as $g) {
    $gpaMap[(int)$g["sem_no"]] = (float)$g["gpa_value"];
}

// ─── Fetch CGPA per (year, semester) — running CGPA history ───────────────────
$stmtC = $pdo->prepare("
    SELECT year_no, sem_no, cgpa_value
    FROM   cgpa_tb
    WHERE  student_ID = ?
    ORDER  BY year_no ASC, sem_no ASC
");
$stmtC->execute([$studentID]);
$cgpaRows = $stmtC->fetchAll();
$cgpaMap = [];
foreach ($cgpaRows as $c) {
    $cgpaMap["{$c['year_no']}-{$c['sem_no']}"] = (float)$c["cgpa_value"];
}

// ─── Group results by year → semester ──────────────────────────────────────────
$grouped = [];
foreach ($allResults as $row) {
    $grouped[(int)$row["year_no"]][(int)$row["sem_no"]][] = $row;
}

// ─── Build chronological semester list + per-semester averages ────────────────
// semesterLabels: e.g. "Y1 S1", "Y1 S2", "Y2 S1" ...
$semesterLabels   = [];
$avgCat1          = [];
$avgCat2          = [];
$avgExam          = [];
$gpaSeries        = [];
$cgpaSeries       = [];
$semesterKeys     = []; // "year-sem" in chronological order, for module filter dropdown

foreach ($grouped as $year => $sems) {
    foreach ($sems as $sem => $rows) {
        $semesterLabels[] = "Y{$year} S{$sem}";
        $semesterKeys[]   = "{$year}-{$sem}";

        $c1 = $c2 = $ex = 0;
        $n  = count($rows);
        foreach ($rows as $r) {
            $c1 += (float)$r["cat1_mk"];
            $c2 += (float)$r["cat2_mk"];
            $ex += (float)$r["exam_mk"];
        }
        $avgCat1[]  = $n ? round($c1 / $n, 1) : 0;
        $avgCat2[]  = $n ? round($c2 / $n, 1) : 0;
        $avgExam[]  = $n ? round($ex / $n, 1) : 0;

        $gpaSeries[]  = $gpaMap[$sem] ?? null;
        $cgpaSeries[] = $cgpaMap["{$year}-{$sem}"] ?? null;
    }
}

// ─── Build per-module score dataset, grouped by semester key, for the bar chart filter ─
$moduleDataBySemester = []; // key => [ {code, name, score}, ... ]
foreach ($grouped as $year => $sems) {
    foreach ($sems as $sem => $rows) {
        $key = "{$year}-{$sem}";
        $moduleDataBySemester[$key] = array_map(function ($r) {
            return [
                "code"  => $r["module_code"],
                "name"  => $r["module_name"],
                "score" => (int)$r["final_total"],
            ];
        }, $rows);
    }
}

// ─── Pass / Retake breakdown (overall) ─────────────────────────────────────────
$passCount = 0;
$retakeCount = 0;
foreach ($allResults as $r) {
    if (strcasecmp($r["status_retake_pass"], "Pass") === 0) {
        $passCount++;
    } else {
        $retakeCount++;
    }
}

// ════════════════════════════════════════════════════════════════════════
// STUDENT-FACING INSIGHTS — turns raw numbers into a narrative
// ════════════════════════════════════════════════════════════════════════

// ─── GPA trend direction across the last few semesters ────────────────────────
$gpaTrend = "steady";
$gpaTrendDelta = 0.0;
$validGpaSeries = array_values(array_filter($gpaSeries, fn($v) => $v !== null));
if (count($validGpaSeries) >= 2) {
    $recent  = array_slice($validGpaSeries, -3); // up to last 3 semesters
    $gpaTrendDelta = round(end($recent) - $recent[0], 2);
    if ($gpaTrendDelta > 0.1) {
        $gpaTrend = "rising";
    } elseif ($gpaTrendDelta < -0.1) {
        $gpaTrend = "falling";
    }
}
$latestGpa = !empty($validGpaSeries) ? end($validGpaSeries) : null;

// ─── Grade scale, used to compute "marks from next grade" ─────────────────────
$stmtGrades = $pdo->query("SELECT min_mark, max_mark, grade_point, letter_grade FROM grade_system ORDER BY min_mark ASC");
$gradeScale = $stmtGrades->fetchAll();

/**
 * Given a mark, find how many marks away the student is from the next
 * grade band up. Returns null if already at the top band.
 */
function marksFromNextGrade(array $gradeScale, int $mark): ?array {
    foreach ($gradeScale as $i => $band) {
        if ($mark >= (int)$band["min_mark"] && $mark <= (int)$band["max_mark"]) {
            $nextBand = $gradeScale[$i + 1] ?? null;
            if (!$nextBand) {
                return null; // already top grade
            }
            return [
                "marks_needed" => (int)$nextBand["min_mark"] - $mark,
                "next_grade"   => $nextBand["letter_grade"],
            ];
        }
    }
    return null;
}

// ─── "Close calls" — modules within 4 marks of the next grade band up ─────────
$closeCalls = [];
foreach ($allResults as $r) {
    $gap = marksFromNextGrade($gradeScale, (int)$r["final_total"]);
    if ($gap !== null && $gap["marks_needed"] > 0 && $gap["marks_needed"] <= 4) {
        $closeCalls[] = [
            "module_name"  => $r["module_name"],
            "module_code"  => $r["module_code"],
            "marks_needed" => $gap["marks_needed"],
            "next_grade"   => $gap["next_grade"],
            "current_mark" => (int)$r["final_total"],
        ];
    }
}
// Show the closest ones first (smallest gap = most encouraging/actionable)
usort($closeCalls, fn($a, $b) => $a["marks_needed"] <=> $b["marks_needed"]);
$closeCalls = array_slice($closeCalls, 0, 4);

// ─── Strongest subject area this semester (simple: highest mark module) ───────
$topModule = null;
if (!empty($allResults)) {
    $sortedByMark = $allResults;
    usort($sortedByMark, fn($a, $b) => (int)$b["final_total"] <=> (int)$a["final_total"]);
    $topModule = $sortedByMark[0];
}

// ─── Any goals the student has set (from Goal Planning) for modules already
//     reflected in results, to show "you hit your target" or "almost there" ──
$stmtGoals = $pdo->prepare("
    SELECT g.module_code, g.target_mark, m.module_name
    FROM   goal_setting_tb g
    JOIN   module_tb m ON g.module_code = m.module_code
    WHERE  g.student_ID = ?
");
$stmtGoals->execute([$studentID]);
$goalRows = $stmtGoals->fetchAll();

$goalComparisons = [];
foreach ($goalRows as $goal) {
    foreach ($allResults as $r) {
        if ($r["module_code"] === $goal["module_code"]) {
            $goalComparisons[] = [
                "module_name" => $goal["module_name"],
                "target_mark" => (int)$goal["target_mark"],
                "actual_mark" => (int)$r["final_total"],
                "hit_goal"    => (int)$r["final_total"] >= (int)$goal["target_mark"],
            ];
            break;
        }
    }
}

// ─── JSON payloads for Chart.js ────────────────────────────────────────────────
$jsSemesterLabels = json_encode($semesterLabels);
$jsSemesterKeys   = json_encode($semesterKeys);
$jsAvgCat1        = json_encode($avgCat1);
$jsAvgCat2        = json_encode($avgCat2);
$jsAvgExam        = json_encode($avgExam);
$jsGpaSeries      = json_encode($gpaSeries);
$jsCgpaSeries     = json_encode($cgpaSeries);
$jsModuleData     = json_encode($moduleDataBySemester);
$jsPassRetake     = json_encode(["pass" => $passCount, "retake" => $retakeCount]);

$hasData = !empty($allResults);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analysis – Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            color: #111;
            background: #fff;
        }

        /* ── Header (brand-consistent with Results page) ── */
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

        /* ── Tab nav (brand-consistent with Results page) ── */
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
        .page-wrap { max-width: 1000px; margin: 1.5rem auto; padding: 0 1.5rem 3rem; }

        /* ── Student greeting (same as Results page) ── */
        .student-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 1.5rem;
        }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid  { font-size: .95rem; font-weight: 400; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; }

        /* ── Section card ── */
        .card {
            border: 1px solid #999;
            border-radius: 6px;
            margin-bottom: 1.75rem;
            overflow: hidden;
        }
        .card-header {
            background: #121e38;
            color: #fff;
            font-weight: 700;
            font-size: .92rem;
            padding: .65rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: .5rem;
        }
        .card-body {
            background: #e8e8e8;
            padding: 1.1rem 1rem 1.4rem;
        }
        .card-sub {
            font-size: .8rem;
            font-weight: 400;
            opacity: .85;
        }

        /* ── Chart canvas wrapper ── */
        .chart-wrap { position: relative; height: 320px; background: #fff; border-radius: 4px; padding: .75rem; }

        /* ── Summary stat row ── */
        .stat-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.75rem;
        }
        .stat-box {
            flex: 1;
            min-width: 150px;
            background: #fff;
            border: 1px solid #999;
            border-radius: 6px;
            padding: .9rem 1.1rem;
        }
        .stat-box .label {
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #555;
            margin-bottom: .3rem;
        }
        .stat-box .value {
            font-size: 1.5rem;
            font-weight: 700;
            color: #121e38;
        }
        .stat-box .value.green { color: #065f46; }
        .stat-box .value.red   { color: #991b1b; }

        /* ── Semester selector for module bar chart ── */
        select.sem-select {
            padding: .35rem .6rem;
            border-radius: 4px;
            border: 1px solid #aaa;
            font-size: .82rem;
            background: #fff;
            color: #111;
        }

        /* ── Empty state ── */
        .empty { text-align: center; padding: 3rem 1rem; color: #888; }

        /* ── Narrative hero (the headline framing) ── */
        .hero {
            border-radius: 8px;
            padding: 1.4rem 1.6rem;
            margin-bottom: 1.5rem;
            color: #fff;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
        }
        .hero.trend-rising  { background: linear-gradient(135deg, #14532d, #166534); }
        .hero.trend-steady  { background: linear-gradient(135deg, #1e3a6e, #213769); }
        .hero.trend-falling { background: linear-gradient(135deg, #7c2d12, #92400e); }
        .hero-icon { font-size: 1.8rem; line-height: 1; flex: 0 0 auto; }
        .hero-title { font-size: 1.15rem; font-weight: 700; margin-bottom: .35rem; }
        .hero-sub { font-size: .88rem; opacity: .92; line-height: 1.5; }

        /* ── Close-call module cards (marks from next grade) ── */
        .close-call-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: .85rem;
        }
        .close-call-card {
            background: #fff;
            border: 1px solid #000;
            border-left: 4px solid #16213f;
            border-radius: 6px;
            padding: .85rem 1rem;
        }
        .close-call-card .cc-module { font-weight: 700; font-size: .9rem; color: #16213f; margin-bottom: .2rem; }
        .close-call-card .cc-detail { font-size: .82rem; color: #444; }
        .close-call-card .cc-marks { font-weight: 700; color: #0d1730; }

        /* ── Goal comparison strip ── */
        .goal-strip { display: flex; flex-direction: column; gap: .6rem; }
        .goal-row {
            display: flex; align-items: center; justify-content: space-between;
            background: #fff; border: 1px solid #999; border-radius: 6px;
            padding: .7rem .9rem; font-size: .85rem; gap: .75rem; flex-wrap: wrap;
        }
        .goal-row .goal-mod { font-weight: 600; }
        .goal-pill {
            font-size: .76rem; font-weight: 700; padding: .2rem .6rem; border-radius: 12px;
        }
        .goal-pill.hit  { background: #d1fae5; color: #065f46; }
        .goal-pill.miss { background: #fde2e2; color: #991b1b; }

        /* ── Reassurance banner (used when retakes = 0) ── */
        .reassurance {
            display: flex; align-items: center; gap: .75rem;
            background: #ecfdf5; border: 1px solid #a7e0c4; border-radius: 6px;
            padding: .9rem 1.1rem; font-size: .88rem; color: #065f46;
        }
        .reassurance .r-icon { font-size: 1.3rem; }

        @media (max-width: 640px) {
            .stat-row { flex-direction: column; }
            .hero { flex-direction: column; }
        }
    </style>
</head>
<body>

<!-- ── Site Header ── -->
<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Career & Module Planning</span>
    </div>
    <div class="header-right"></div>
</header>

<!-- ── Tab Navigation ── -->
<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <span class="tab-btn active">Analysis</span>
    <a class="tab-btn" href="GoalPlanning.php">Career & Module Planner</a>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <!-- Student greeting row -->
    <div class="student-row">
        <div class="student-name">
            Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?>
        </div>
        <div class="student-sid">
            <strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?>
        </div>
    </div>

    <?php if (!$hasData): ?>
        <p class="empty">No results found yet — analysis will appear once results are recorded.</p>
    <?php else: ?>

    <!-- ── Narrative hero: the headline framing ── -->
    <div class="hero trend-<?= $gpaTrend ?>">
        <div class="hero-icon">
            <?= $gpaTrend === 'rising' ? '📈' : ($gpaTrend === 'falling' ? '⚠️' : '🎯') ?>
        </div>
        <div>
            <?php if ($gpaTrend === 'rising'): ?>
                <div class="hero-title">You're trending upward — nice work.</div>
                <div class="hero-sub">
                    Your GPA has climbed by <?= number_format(abs($gpaTrendDelta), 2) ?> point<?= abs($gpaTrendDelta) == 1 ? '' : 's' ?>
                    over your last few semesters<?= $latestGpa !== null ? ', reaching ' . number_format($latestGpa, 2) . ' most recently' : '' ?>.
                    Keep doing what's working — consistency from here builds your CGPA fastest.
                </div>
            <?php elseif ($gpaTrend === 'falling'): ?>
                <div class="hero-title">Your GPA has dipped recently — let's turn it around.</div>
                <div class="hero-sub">
                    It's dropped by <?= number_format(abs($gpaTrendDelta), 2) ?> point<?= abs($gpaTrendDelta) == 1 ? '' : 's' ?>
                    over your last few semesters. That's recoverable — check the close calls below and
                    use Goal Planning to set targets for next semester's modules.
                </div>
            <?php else: ?>
                <div class="hero-title">You're holding steady.</div>
                <div class="hero-sub">
                    Your GPA has stayed consistent across recent semesters<?= $latestGpa !== null ? ' at around ' . number_format($latestGpa, 2) : '' ?>.
                    If you're aiming higher, the close calls below show exactly where a few extra marks would move the needle.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Close calls: marks away from the next grade ── -->
    <?php if (!empty($closeCalls)): ?>
    <div class="card">
        <div class="card-header">
            So Close — A Few Marks From the Next Grade
            <span class="card-sub">Where extra effort would pay off fastest</span>
        </div>
        <div class="card-body">
            <div class="close-call-grid">
                <?php foreach ($closeCalls as $cc): ?>
                <div class="close-call-card">
                    <div class="cc-module"><?= htmlspecialchars($cc["module_name"]) ?></div>
                    <div class="cc-detail">
                        Scored <?= $cc["current_mark"] ?>% —
                        <span class="cc-marks"><?= $cc["marks_needed"] ?> mark<?= $cc["marks_needed"] == 1 ? '' : 's' ?> away</span>
                        from a <?= htmlspecialchars($cc["next_grade"]) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Goal comparisons (only if the student has set targets in Goal Planning) ── -->
    <?php if (!empty($goalComparisons)): ?>
    <div class="card">
        <div class="card-header">
            Your Targets vs. What You Scored
            <span class="card-sub">From your Goal Planning targets</span>
        </div>
        <div class="card-body">
            <div class="goal-strip">
                <?php foreach ($goalComparisons as $gc): ?>
                <div class="goal-row">
                    <span class="goal-mod"><?= htmlspecialchars($gc["module_name"]) ?></span>
                    <span>
                        Target: <?= $gc["target_mark"] ?>% · Scored: <?= $gc["actual_mark"] ?>%
                        <span class="goal-pill <?= $gc["hit_goal"] ? 'hit' : 'miss' ?>">
                            <?= $gc["hit_goal"] ? 'Goal hit' : 'Just short' ?>
                        </span>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Quick stats ── -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="label">Semesters Recorded</div>
            <div class="value"><?= count($semesterLabels) ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Modules Passed</div>
            <div class="value green"><?= $passCount ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Modules on Retake</div>
            <div class="value <?= $retakeCount > 0 ? 'red' : '' ?>"><?= $retakeCount ?></div>
        </div>
        <div class="stat-box">
            <div class="label">Latest CGPA</div>
            <div class="value">
                <?= !empty($cgpaSeries) && end($cgpaSeries) !== null
                    ? number_format(end($cgpaSeries), 2)
                    : '—' ?>
            </div>
        </div>
    </div>

    <?php if ($retakeCount === 0): ?>
    <div class="reassurance">
        <span class="r-icon">✅</span>
        <span>No retakes on record — every module you've taken so far, you've passed. Keep it up.</span>
    </div>
    <?php endif; ?>

    <!-- ── GPA / CGPA trend ── -->
    <div class="card">
        <div class="card-header">
            GPA &amp; CGPA Trend
            <span class="card-sub">Your progress, semester by semester</span>
        </div>
        <div class="card-body">
            <?php if ($topModule): ?>
            <p style="font-size:.85rem; color:#333; margin-bottom:.75rem;">
                Your strongest result so far was <strong><?= htmlspecialchars($topModule["module_name"]) ?></strong>
                at <?= (int)$topModule["final_total"] ?>%. Watching this line climb is the clearest sign your study approach is paying off.
            </p>
            <?php endif; ?>
            <div class="chart-wrap">
                <canvas id="gpaTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── CAT1 / CAT2 / Exam trend ── -->
    <div class="card">
        <div class="card-header">
            CAT &amp; Exam Performance Trend
            <span class="card-sub">Are exams or continuous assessment your strength?</span>
        </div>
        <div class="card-body">
            <p style="font-size:.85rem; color:#333; margin-bottom:.75rem;">
                If one line consistently sits below the others, that's where to focus next —
                e.g. low exam marks relative to CATs often means revision timing, not understanding, is the gap.
            </p>
            <div class="chart-wrap">
                <canvas id="catTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── Per-module score bar chart, filterable by semester ── -->
    <div class="card">
        <div class="card-header">
            Module Scores
            <select class="sem-select" id="semFilter"></select>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="moduleScoreChart"></canvas>
            </div>
        </div>
    </div>

    <?php endif; ?>

</main>

<?php if ($hasData): ?>
<script>
    const semesterLabels = <?= $jsSemesterLabels ?>;
    const semesterKeys   = <?= $jsSemesterKeys ?>;
    const avgCat1        = <?= $jsAvgCat1 ?>;
    const avgCat2        = <?= $jsAvgCat2 ?>;
    const avgExam        = <?= $jsAvgExam ?>;
    const gpaSeries       = <?= $jsGpaSeries ?>;
    const cgpaSeries      = <?= $jsCgpaSeries ?>;
    const moduleData      = <?= $jsModuleData ?>;
    const passRetake      = <?= $jsPassRetake ?>;

    const navy   = '#121e38';
    const blue   = '#2c4a8a';
    const teal   = '#0e8a72';
    const amber  = '#b8860b';
    const green  = '#065f46';
    const red    = '#991b1b';

    // ── CAT1 / CAT2 / Exam trend (line chart) ──────────────────────────────
    new Chart(document.getElementById('catTrendChart'), {
        type: 'line',
        data: {
            labels: semesterLabels,
            datasets: [
                { label: 'CAT 1 Avg', data: avgCat1, borderColor: blue,  backgroundColor: blue,  tension: 0.3 },
                { label: 'CAT 2 Avg', data: avgCat2, borderColor: teal,  backgroundColor: teal,  tension: 0.3 },
                { label: 'Exam Avg',  data: avgExam, borderColor: amber, backgroundColor: amber, tension: 0.3 },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });

    // ── GPA / CGPA trend (line chart) ──────────────────────────────────────
    new Chart(document.getElementById('gpaTrendChart'), {
        type: 'line',
        data: {
            labels: semesterLabels,
            datasets: [
                { label: 'GPA',  data: gpaSeries,  borderColor: navy, backgroundColor: navy, tension: 0.3, spanGaps: true },
                { label: 'CGPA', data: cgpaSeries, borderColor: teal, backgroundColor: teal, tension: 0.3, spanGaps: true, borderDash: [6,4] },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true, max: 5 } }
        }
    });

    // ── Module score bar chart, filtered by semester ────────────────────────
    const semSelect = document.getElementById('semFilter');
    semesterKeys.forEach((key, i) => {
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = semesterLabels[i];
        semSelect.appendChild(opt);
    });
    // Default to the most recent semester
    semSelect.value = semesterKeys[semesterKeys.length - 1];

    let moduleChart;
    function renderModuleChart(key) {
        const rows = moduleData[key] || [];
        const labels = rows.map(r => r.code);
        const scores = rows.map(r => r.score);

        if (moduleChart) {
            moduleChart.data.labels = labels;
            moduleChart.data.datasets[0].data = scores;
            moduleChart.update();
            return;
        }

        moduleChart = new Chart(document.getElementById('moduleScoreChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{ label: 'Score (%)', data: scores, backgroundColor: blue }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
    }
    renderModuleChart(semSelect.value);
    semSelect.addEventListener('change', () => renderModuleChart(semSelect.value));
</script>
<?php endif; ?>


</body>
</html>