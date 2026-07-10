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

// ─── Class average per module (peer benchmarking) ────────────────────────────
$classAvgByModule = [];
foreach ($pdo->query("
    SELECT module_code, ROUND(AVG(final_total), 1) AS avg_score
    FROM   results_tb
    GROUP  BY module_code
")->fetchAll() as $row) {
    $classAvgByModule[$row['module_code']] = (float)$row['avg_score'];
}
// Attach class_avg to each entry so the JS module chart can draw a benchmark bar
foreach ($moduleDataBySemester as $key => &$mods) {
    foreach ($mods as &$m) {
        $m['class_avg'] = $classAvgByModule[$m['code']] ?? null;
    }
}
unset($mods, $m);

// ─── Ungraded modules for What-if CGPA simulator ─────────────────────────────
$stmtFuture = $pdo->prepare("
    SELECT m.module_code, m.module_name, m.credit_unit, m.year_no, m.sem_no
    FROM   module_tb m
    WHERE  m.program_code = ?
      AND  m.module_code NOT IN (
               SELECT module_code FROM results_tb WHERE student_ID = ?
           )
    ORDER  BY m.year_no ASC, m.sem_no ASC, m.module_code ASC
");
$stmtFuture->execute([$student['program_code'], $studentID]);
$futureModules = $stmtFuture->fetchAll();

// Running totals (baseline for the what-if calculation)
$existingQP = 0.0;
$existingCU = 0.0;
foreach ($allResults as $r) {
    $existingQP += (float)$r['grade_point'] * (float)$r['credit_unit'];
    $existingCU += (float)$r['credit_unit'];
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
$jsExistingQP     = json_encode(round($existingQP, 4));
$jsExistingCU     = json_encode($existingCU);
$cgpaLast         = !empty($cgpaSeries) ? end($cgpaSeries) : null;
$jsCurrentCGPA    = json_encode($cgpaLast);

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

        /* ── CGPA Gauge card ─────────────────────────────────────────────────── */
        .gauge-card {
            display: flex; align-items: center; gap: 2rem;
            background: #fff; border: 1px solid #999; border-radius: 6px;
            padding: 1.2rem 1.5rem; margin-bottom: 1.75rem; flex-wrap: wrap;
        }
        .gauge-wrap { width: 180px; height: 100px; flex: 0 0 auto; position: relative; }
        .gauge-label {
            position: absolute; bottom: 0; left: 50%; transform: translateX(-50%);
            text-align: center; pointer-events: none; width: 100%;
        }
        .gauge-val { font-size: 1.7rem; font-weight: 800; color: #121e38; line-height: 1; }
        .gauge-sub { font-size: .68rem; color: #666; margin-top: .15rem; }
        .gauge-stats { flex: 1; min-width: 200px; }
        .gs-item {
            display: flex; justify-content: space-between;
            padding: .42rem 0; border-bottom: 1px solid #eee; font-size: .84rem;
        }
        .gs-item:last-child { border-bottom: 0; }
        .gs-label { color: #555; }
        .gs-val { font-weight: 700; color: #121e38; }

        /* ── Performance Heatmap ─────────────────────────────────────────────── */
        .heatmap-scroll { overflow-x: auto; }
        .heatmap-table {
            width: 100%; border-collapse: collapse;
            font-size: .8rem; min-width: 520px;
        }
        .heatmap-table th {
            background: #121e38; color: #fff; padding: .45rem .7rem;
            text-align: center; font-weight: 600; white-space: nowrap;
        }
        .heatmap-table th.col-mod { text-align: left; min-width: 180px; }
        .heatmap-table td { padding: .32rem .6rem; text-align: center; border-bottom: 1px solid #e0e0e0; }
        .heatmap-table td.mod-cell { text-align: left; }
        .heatmap-table tr:hover td { filter: brightness(0.94); }
        .hm-row-hdr td {
            background: #e0e7ef !important; font-weight: 700; font-size: .74rem;
            padding: .28rem .7rem !important; color: #213769; text-align: left !important;
        }
        .hm-chip {
            border-radius: 4px; padding: .18rem .45rem; font-weight: 700;
            display: inline-block; min-width: 2em; letter-spacing: .01em;
        }

        /* ── What-if Simulator ───────────────────────────────────────────────── */
        .whatif-intro { font-size: .84rem; color: #444; margin-bottom: 1rem; line-height: 1.5; }
        .whatif-result {
            display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap;
            background: #121e38; color: #fff; border-radius: 8px;
            padding: 1rem 1.4rem; margin-bottom: 1.2rem;
        }
        .wr-block { text-align: center; }
        .wr-label { font-size: .75rem; opacity: .8; margin-bottom: .15rem; }
        .wr-big   { font-size: 1.75rem; font-weight: 800; line-height: 1; }
        .wr-sep   { width: 1px; height: 2.8rem; background: rgba(255,255,255,0.2); }
        .whatif-modules { display: flex; flex-direction: column; gap: .65rem; max-height: 420px; overflow-y: auto; padding-right: 4px; }
        .wi-row {
            background: #fff; border: 1px solid #ddd; border-radius: 6px;
            padding: .65rem .9rem; display: flex; align-items: center; gap: .8rem; flex-wrap: wrap;
        }
        .wi-mod { flex: 1; min-width: 150px; }
        .wi-mod-name { font-weight: 600; font-size: .86rem; color: #121e38; }
        .wi-mod-meta { font-size: .73rem; color: #777; }
        .wi-slider-wrap { flex: 2; min-width: 180px; display: flex; align-items: center; gap: .55rem; }
        .wi-slider-wrap input[type=range] { flex: 1; accent-color: #213769; }
        .wi-mark { font-size: .88rem; font-weight: 700; color: #121e38; min-width: 2.8em; text-align: right; }
        .wi-grade { font-size: .75rem; font-weight: 700; padding: .15rem .5rem; border-radius: 10px; min-width: 2.2em; text-align: center; }
        .gA  { background:#d1fae5;color:#065f46; }
        .gBp { background:#bbf7d0;color:#065f46; }
        .gB  { background:#dbeafe;color:#1e3a8a; }
        .gCp { background:#ede9fe;color:#4c1d95; }
        .gC  { background:#fef9c3;color:#78350f; }
        .gDp { background:#ffedd5;color:#9a3412; }
        .gD  { background:#fee2e2;color:#991b1b; }
        .g0  { background:#fca5a5;color:#7f1d1d; }
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
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
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

    <!-- ── CGPA Gauge + Quick Stats ── -->
    <div class="gauge-card">
        <div class="gauge-wrap">
            <canvas id="cgpaGauge"></canvas>
            <div class="gauge-label">
                <div class="gauge-val">
                    <?= $cgpaLast !== null ? number_format($cgpaLast, 2) : '—' ?>
                </div>
                <div class="gauge-sub">CGPA / 5.0</div>
            </div>
        </div>
        <div class="gauge-stats">
            <div class="gs-item">
                <span class="gs-label">Semesters on Record</span>
                <span class="gs-val"><?= count($semesterLabels) ?></span>
            </div>
            <div class="gs-item">
                <span class="gs-label">Modules Passed</span>
                <span class="gs-val" style="color:#065f46"><?= $passCount ?></span>
            </div>
            <div class="gs-item">
                <span class="gs-label">Modules on Retake</span>
                <span class="gs-val" style="<?= $retakeCount > 0 ? 'color:#991b1b' : 'color:#065f46' ?>"><?= $retakeCount ?: '0 ✓' ?></span>
            </div>
            <div class="gs-item">
                <span class="gs-label">Programme</span>
                <span class="gs-val"><?= htmlspecialchars($student['program_code']) ?> · <?= htmlspecialchars($student['program_name']) ?></span>
            </div>
            <?php
            $cgpaClass = $cgpaLast !== null
                ? ($cgpaLast >= 4.5 ? 'First Class' : ($cgpaLast >= 3.5 ? 'Upper Second' : ($cgpaLast >= 2.5 ? 'Lower Second' : 'Pass')))
                : null;
            if ($cgpaClass): ?>
            <div class="gs-item">
                <span class="gs-label">Current Standing</span>
                <span class="gs-val"><?= htmlspecialchars($cgpaClass) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>

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

    <!-- ── Assessment Heatmap ── -->
    <div class="card">
        <div class="card-header">
            Assessment Heatmap
            <span class="card-sub">Green = strong · Red = needs attention</span>
        </div>
        <div class="card-body">
            <div class="heatmap-scroll">
                <table class="heatmap-table">
                    <thead>
                        <tr>
                            <th class="col-mod">Module</th>
                            <th>CAT 1 <span style="opacity:.65;font-weight:400">/20</span></th>
                            <th>CAT 2 <span style="opacity:.65;font-weight:400">/20</span></th>
                            <th>Exam <span style="opacity:.65;font-weight:400">/60</span></th>
                            <th>Total <span style="opacity:.65;font-weight:400">/100</span></th>
                            <th>Grade</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $hmColor = function(float $pct): string {
                        $h = round($pct * 1.2); // 0→0° red, 100→120° green
                        return "background:hsl({$h},65%,87%);color:hsl({$h},55%,28%)";
                    };
                    $gradeStyle = [
                        'A'  => 'background:#d1fae5;color:#065f46',
                        'B+' => 'background:#bbf7d0;color:#065f46',
                        'B'  => 'background:#dbeafe;color:#1e3a8a',
                        'C+' => 'background:#ede9fe;color:#4c1d95',
                        'C'  => 'background:#fef9c3;color:#78350f',
                        'D+' => 'background:#ffedd5;color:#9a3412',
                        'D'  => 'background:#fee2e2;color:#991b1b',
                        '0'  => 'background:#fca5a5;color:#7f1d1d',
                    ];
                    foreach ($grouped as $year => $sems):
                        foreach ($sems as $sem => $rows): ?>
                        <tr class="hm-row-hdr"><td colspan="6">Year <?= $year ?> · Semester <?= $sem ?></td></tr>
                        <?php foreach ($rows as $r):
                            $gc = $gradeStyle[$r['letter_grade']] ?? 'background:#eee;color:#333';
                        ?>
                        <tr>
                            <td class="mod-cell">
                                <strong><?= htmlspecialchars($r['module_code']) ?></strong>
                                <br><span style="font-size:.72rem;color:#666;font-weight:400"><?= htmlspecialchars($r['module_name']) ?></span>
                            </td>
                            <td><span class="hm-chip" style="<?= $hmColor($r['cat1_mk'] / 20 * 100) ?>"><?= $r['cat1_mk'] ?></span></td>
                            <td><span class="hm-chip" style="<?= $hmColor($r['cat2_mk'] / 20 * 100) ?>"><?= $r['cat2_mk'] ?></span></td>
                            <td><span class="hm-chip" style="<?= $hmColor($r['exam_mk'] / 60 * 100) ?>"><?= $r['exam_mk'] ?></span></td>
                            <td><span class="hm-chip" style="<?= $hmColor((float)$r['final_total']) ?>"><?= $r['final_total'] ?></span></td>
                            <td><span class="hm-chip" style="<?= $gc ?>"><?= htmlspecialchars($r['letter_grade']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Per-module score bar chart with class benchmark ── -->
    <div class="card">
        <div class="card-header">
            Module Scores vs. Class Average
            <select class="sem-select" id="semFilter"></select>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="moduleScoreChart"></canvas>
            </div>
        </div>
    </div>

    <?php if (!empty($futureModules)): ?>
    <!-- ── What-if CGPA Simulator ── -->
    <div class="card">
        <div class="card-header">
            What-if CGPA Simulator
            <span class="card-sub">Set target scores for remaining modules — see your projected graduation CGPA</span>
        </div>
        <div class="card-body">
            <p class="whatif-intro">
                You have <strong><?= count($futureModules) ?> module<?= count($futureModules) == 1 ? '' : 's' ?></strong>
                remaining in your programme. Drag the sliders to your expected marks and watch your
                projected CGPA update in real time.
            </p>
            <div class="whatif-result">
                <div class="wr-block">
                    <div class="wr-label">Current CGPA</div>
                    <div class="wr-big"><?= $cgpaLast !== null ? number_format($cgpaLast, 2) : '—' ?></div>
                </div>
                <div class="wr-sep"></div>
                <div class="wr-block">
                    <div class="wr-label">Projected Graduation CGPA</div>
                    <div class="wr-big" id="projCGPA">—</div>
                </div>
                <div class="wr-sep"></div>
                <div class="wr-block">
                    <div class="wr-label">Projected Standing</div>
                    <div class="wr-big" style="font-size:1rem;padding-top:.3rem" id="projClass">—</div>
                </div>
            </div>
            <div class="whatif-modules">
                <?php
                $prevYS = null;
                foreach ($futureModules as $fm):
                    $ys = "Y{$fm['year_no']} S{$fm['sem_no']}";
                    if ($ys !== $prevYS):
                        $prevYS = $ys;
                ?>
                <div style="font-size:.74rem;font-weight:700;color:#213769;padding:.3rem 0 .1rem;border-top:1px solid #ddd;margin-top:.3rem">
                    Year <?= $fm['year_no'] ?> · Semester <?= $fm['sem_no'] ?>
                </div>
                <?php endif; ?>
                <div class="wi-row" data-cu="<?= htmlspecialchars((string)$fm['credit_unit']) ?>">
                    <div class="wi-mod">
                        <div class="wi-mod-name"><?= htmlspecialchars($fm['module_code']) ?></div>
                        <div class="wi-mod-meta"><?= htmlspecialchars($fm['module_name']) ?> · <?= $fm['credit_unit'] ?> CU</div>
                    </div>
                    <div class="wi-slider-wrap">
                        <input type="range" min="0" max="100" value="65" class="wi-slider"
                               data-cu="<?= htmlspecialchars((string)$fm['credit_unit']) ?>">
                        <span class="wi-mark">65%</span>
                    </div>
                    <span class="wi-grade gCp">C+</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

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

    // ── Module score chart with class benchmark ────────────────────────────────
    let moduleChart;
    function renderModuleChart(key) {
        const rows      = moduleData[key] || [];
        const labels    = rows.map(r => r.code);
        const scores    = rows.map(r => r.score);
        const classAvgs = rows.map(r => r.class_avg ?? null);

        if (moduleChart) {
            moduleChart.data.labels              = labels;
            moduleChart.data.datasets[0].data    = scores;
            moduleChart.data.datasets[1].data    = classAvgs;
            moduleChart.update();
            return;
        }
        moduleChart = new Chart(document.getElementById('moduleScoreChart'), {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    { label: 'Your Score (%)',  data: scores,    backgroundColor: blue },
                    { label: 'Class Avg (%)',   data: classAvgs, backgroundColor: 'rgba(184,134,11,0.55)', borderColor: amber, borderWidth: 1 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { y: { beginAtZero: true, max: 100 } }
            }
        });
    }
    renderModuleChart(semSelect.value);
    semSelect.addEventListener('change', () => renderModuleChart(semSelect.value));

    // ── CGPA Gauge (half-doughnut speedometer) ─────────────────────────────────
    const currentCGPA = <?= $jsCurrentCGPA ?>;
    if (currentCGPA !== null && document.getElementById('cgpaGauge')) {
        const v   = Math.min(Math.max(currentCGPA, 0), 5);
        const col = v >= 4 ? '#059669' : v >= 3 ? '#2c4a8a' : v >= 2 ? '#d97706' : '#dc2626';
        new Chart(document.getElementById('cgpaGauge'), {
            type: 'doughnut',
            data: {
                datasets: [{
                    data: [v, 5 - v],
                    backgroundColor: [col, '#e5e7eb'],
                    borderWidth: 0,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '70%',
                rotation: -90,
                circumference: 180,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                animation: { duration: 1200 }
            }
        });
    }

    // ── What-if CGPA Simulator ────────────────────────────────────────────────
    const existingQP = <?= $jsExistingQP ?>;
    const existingCU = <?= $jsExistingCU ?>;

    const GRADE_SCALE = [
        { min: 80, gp: 5.00, letter: 'A',  css: 'gA'  },
        { min: 75, gp: 4.50, letter: 'B+', css: 'gBp' },
        { min: 70, gp: 4.00, letter: 'B',  css: 'gB'  },
        { min: 65, gp: 3.50, letter: 'C+', css: 'gCp' },
        { min: 60, gp: 3.00, letter: 'C',  css: 'gC'  },
        { min: 55, gp: 2.50, letter: 'D+', css: 'gDp' },
        { min: 50, gp: 2.00, letter: 'D',  css: 'gD'  },
        { min:  0, gp: 0.00, letter: '0',  css: 'g0'  },
    ];
    const markToGrade = m => GRADE_SCALE.find(g => m >= g.min) || GRADE_SCALE[GRADE_SCALE.length - 1];
    const cgpaToClass = c =>
        c >= 4.50 ? 'First Class' : c >= 3.50 ? 'Upper Second' : c >= 2.50 ? 'Lower Second' : 'Pass';

    function recalcWhatif() {
        let addQP = 0, addCU = 0;
        document.querySelectorAll('.wi-slider').forEach(s => {
            const mark = parseInt(s.value, 10);
            const cu   = parseFloat(s.dataset.cu);
            const g    = markToGrade(mark);
            addQP += g.gp * cu;
            addCU += cu;
            const row  = s.closest('.wi-row');
            row.querySelector('.wi-mark').textContent = mark + '%';
            const pill = row.querySelector('.wi-grade');
            pill.textContent = g.letter;
            pill.className   = 'wi-grade ' + g.css;
        });
        if (addCU === 0) return;
        const proj = (existingQP + addQP) / (existingCU + addCU);
        document.getElementById('projCGPA').textContent  = proj.toFixed(2);
        document.getElementById('projClass').textContent = cgpaToClass(proj);
    }

    document.querySelectorAll('.wi-slider').forEach(s => s.addEventListener('input', recalcWhatif));
    recalcWhatif();
</script>
<?php endif; ?>


</body>
</html>