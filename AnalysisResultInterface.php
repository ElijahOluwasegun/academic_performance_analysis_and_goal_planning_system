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
    SELECT r.year_no, r.sem_no, r.module_code, m.module_name, m.credit_unit,
           r.cat1_mk, r.cat2_mk, r.exam_mk, r.final_total,
           r.letter_grade, r.grade_point, r.status_retake_pass
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = ?
    ORDER  BY r.year_no ASC, r.sem_no ASC, r.module_code ASC
");
$stmtR->execute([$studentID]);
$allResults = $stmtR->fetchAll();

// ─── Fetch GPA per semester ────────────────────────────────────────────────────
$stmtG = $pdo->prepare("SELECT sem_no, gpa_value FROM gpa_tb WHERE student_ID = ? ORDER BY sem_no ASC");
$stmtG->execute([$studentID]);
$gpaMap = [];
foreach ($stmtG->fetchAll() as $g) {
    $gpaMap[(int)$g["sem_no"]] = (float)$g["gpa_value"];
}

// ─── Fetch CGPA per (year, semester) — running CGPA history ───────────────────
$stmtC = $pdo->prepare("SELECT year_no, sem_no, cgpa_value FROM cgpa_tb WHERE student_ID = ? ORDER BY year_no ASC, sem_no ASC");
$stmtC->execute([$studentID]);
$cgpaMap = [];
foreach ($stmtC->fetchAll() as $c) {
    $cgpaMap["{$c['year_no']}-{$c['sem_no']}"] = (float)$c["cgpa_value"];
}

// ─── Group results by year → semester ──────────────────────────────────────────
$grouped = [];
foreach ($allResults as $row) {
    $grouped[(int)$row["year_no"]][(int)$row["sem_no"]][] = $row;
}

// ─── Chronological semester list + GPA/CGPA series ────────────────────────────
$semesterLabels = [];
$semesterKeys   = [];
$gpaSeries      = [];
$cgpaSeries     = [];
foreach ($grouped as $year => $sems) {
    foreach ($sems as $sem => $rows) {
        $semesterLabels[] = "Y{$year} S{$sem}";
        $semesterKeys[]   = "{$year}-{$sem}";
        $gpaSeries[]      = $gpaMap[$sem] ?? null;
        $cgpaSeries[]     = $cgpaMap["{$year}-{$sem}"] ?? null;
    }
}

// ─── Pass / Retake breakdown (kept for future use) ────────────────────────────
$passCount = $retakeCount = 0;
foreach ($allResults as $r) {
    (strcasecmp($r["status_retake_pass"], "Pass") === 0) ? $passCount++ : $retakeCount++;
}

// ─── GPA trend direction across the last few semesters ────────────────────────
$gpaTrend = "steady";
$gpaTrendDelta = 0.0;
$validGpaSeries = array_values(array_filter($gpaSeries, fn($v) => $v !== null));
if (count($validGpaSeries) >= 2) {
    $recent = array_slice($validGpaSeries, -3);
    $gpaTrendDelta = round(end($recent) - $recent[0], 2);
    if ($gpaTrendDelta > 0.1)      { $gpaTrend = "rising"; }
    elseif ($gpaTrendDelta < -0.1) { $gpaTrend = "falling"; }
}
$firstRecentGpa = count($validGpaSeries) ? $validGpaSeries[max(0, count($validGpaSeries) - 3)] : null;
$latestGpa      = !empty($validGpaSeries) ? end($validGpaSeries) : null;

// ─── Cumulative GPA + delta since last semester ───────────────────────────────
$cgpaLast  = !empty($cgpaSeries) ? end($cgpaSeries) : null;
$validCgpa = array_values(array_filter($cgpaSeries, fn($v) => $v !== null));
$cgpaDelta = null;
if (count($validCgpa) >= 2) {
    $cgpaDelta = round($validCgpa[count($validCgpa) - 1] - $validCgpa[count($validCgpa) - 2], 2);
}

// ─── Latest semester label (e.g. "Year 3, Semester 2") ────────────────────────
$latestSemLabelFull = null;
if (!empty($semesterKeys)) {
    [$ly, $ls] = explode('-', end($semesterKeys));
    $latestSemLabelFull = "Year {$ly}, Semester {$ls}";
}

// ─── Ungraded modules for What-if CGPA simulator ─────────────────────────────
$stmtFuture = $pdo->prepare("
    SELECT m.module_code, m.module_name, m.credit_unit, m.year_no, m.sem_no
    FROM   module_tb m
    WHERE  m.program_code = ?
      AND  m.module_code NOT IN (SELECT module_code FROM results_tb WHERE student_ID = ?)
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

// ─── Initials for the header avatar (e.g. "Jane Mukasa" → "JM") ───────────────
$np       = preg_split('/\s+/', trim($student['student_name']));
$firstNm  = $np[0] ?? '';
$lastNm   = count($np) > 1 ? end($np) : '';
$initials = strtoupper(substr($firstNm, 0, 1) . substr($lastNm, 0, 1));
if ($initials === '') { $initials = 'S'; }

// ─── JSON payloads for Chart.js / JS ──────────────────────────────────────────
$jsSemesterLabels = json_encode($semesterLabels);
$jsGpaSeries      = json_encode($gpaSeries);
$jsExistingQP     = json_encode(round($existingQP, 4));
$jsExistingCU     = json_encode($existingCU);
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #101828; background: #eef0f5;; }

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

        /* ── Tab nav ── */
        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; overflow-x: auto; }
        .tab-btn { padding: .8rem 1.1rem .7rem; border: none; background: transparent; font-size: .85rem; font-weight: 600; cursor: pointer; white-space: nowrap; color: rgba(255,255,255,.68); text-decoration: none; border-bottom: 3px solid transparent; transition: color .15s, border-color .15s, background .15s; }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        /* ── Page ── */
        .page-wrap { max-width: 1000px; margin: 0 auto; padding: 0 1.5rem 3rem; }
        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin: 1.4rem 0 1.2rem; }
        .student-name { font-size: 1rem; }
        .student-sid  { font-size: .95rem; color: #475467; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; color: #101828; }

        /* ── Hero ── */
        .hero { position: relative; overflow: hidden; background: #16213f; color: #fff; border-radius: 14px; padding: 1.4rem 1.6rem; margin-bottom: 1.5rem; display: flex; gap: 1rem; align-items: flex-start; }
        .hero-ico { flex: 0 0 auto; width: 2.2rem; height: 2.2rem; border-radius: 8px; background: rgba(255,255,255,.14); display: flex; align-items: center; justify-content: center; font-size: 1.05rem; }
        .hero-title { font-size: 1.1rem; font-weight: 700; margin-bottom: .35rem; }
        .hero-sub { font-size: .86rem; opacity: .9; line-height: 1.55; max-width: 78%; position: relative; z-index: 1; }
        .hero-sub .hl { color: #e9c46a; font-weight: 700; }
        .hero-circle { position: absolute; right: -30px; top: 50%; transform: translateY(-50%); width: 160px; height: 160px; border-radius: 50%; background: rgba(255,255,255,.06); }

        /* ── Stat cards ── */
        .stat-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.6rem; }
        .stat-box { flex: 1; min-width: 190px; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); padding: 1rem 1.2rem; }
        .stat-box .label { font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; color: #98a2b3; font-weight: 700; margin-bottom: .45rem; }
        .stat-box .value { font-size: 1.9rem; font-weight: 800; color: #213769; line-height: 1; }
        .stat-box .value .unit { font-size: .85rem; font-weight: 600; color: #98a2b3; }
        .stat-box .sub { font-size: .78rem; color: #667085; margin-top: .4rem; }
        .stat-box .sub.up   { color: #177245; font-weight: 600; }
        .stat-box .sub.down { color: #b42318; font-weight: 600; }

        /* ── Panel (light card) ── */
        .panel { background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); padding: 1.2rem 1.3rem; margin-bottom: 1.5rem; }
        .panel-title { font-size: 1rem; font-weight: 700; color: #213769; }
        .panel-sub { font-size: .82rem; color: #98a2b3; margin-top: .1rem; }
        .chart-wrap { position: relative; height: 300px; margin-top: 1rem; }

        /* ── What-if simulator ── */
        .whatif-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem; }
        .proj-box { background: #213769; color: #fff; border-radius: 12px; padding: .7rem 1.2rem; text-align: center; flex: 0 0 auto; min-width: 8rem; }
        .proj-label { font-size: .58rem; letter-spacing: .08em; font-weight: 700; opacity: .8; }
        .proj-val { font-size: 1.7rem; font-weight: 800; line-height: 1.15; }
        .proj-delta { font-size: .7rem; opacity: .9; }

        .wi-group-hd { font-size: .72rem; font-weight: 700; color: #213769; text-transform: uppercase; letter-spacing: .04em; margin: 1rem 0 .3rem; }
        .wi-list { display: flex; flex-direction: column; }
        .wi-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; padding: .7rem 0; border-top: 1px solid #f0f1f4; }
        .wi-name { flex: 0 0 14rem; font-size: .85rem; font-weight: 600; color: #101828; }
        .wi-name .cu { color: #98a2b3; font-weight: 500; }
        .wi-slider { flex: 1 1 200px; accent-color: #16213f; height: 4px; }
        .wi-pct { flex: 0 0 3rem; text-align: right; font-weight: 700; color: #101828; font-size: .9rem; }
        .wi-grade { flex: 0 0 auto; background: #16213f; color: #fff; font-size: .72rem; font-weight: 700; padding: .18rem .55rem; border-radius: 6px; min-width: 2.4em; text-align: center; }

        .empty { text-align: center; padding: 3rem 1rem; color: #98a2b3; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; }

        @media (max-width: 640px) {
            .stat-row { flex-direction: column; }
            .hero-sub { max-width: 100%; }
            .wi-name { flex-basis: 100%; }
        }
    </style>
</head>
<body>

<!-- ── Site Header ── -->
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
    </div>
</header>

<!-- ── Tab Navigation ── -->
<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <span class="tab-btn active">Analysis</span>
    <a class="tab-btn" href="GoalPlanning.php">Career &amp; Module Planner</a>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <strong><?= htmlspecialchars(strtoupper($student["student_name"])) ?></strong></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <?php if (!$hasData): ?>
        <p class="empty">No results found yet — analysis will appear once results are recorded.</p>
    <?php else: ?>

    <!-- ── Hero ── -->
    <div class="hero">
        <div class="hero-circle"></div>
        <div class="hero-ico">
            <?= $gpaTrend === 'rising' ? '&#9989;' : ($gpaTrend === 'falling' ? '&#9888;' : '&#127919;') ?>
        </div>
        <div>
            <?php if ($gpaTrend === 'rising'): ?>
                <div class="hero-title">You're trending upward — nice work.</div>
                <div class="hero-sub">
                    Your semester GPA has climbed
                    <?php if ($firstRecentGpa !== null && $latestGpa !== null): ?>
                        from <span class="hl"><?= number_format($firstRecentGpa, 2) ?></span> to
                        <span class="hl"><?= number_format($latestGpa, 2) ?></span>
                    <?php endif; ?>
                    over your last few semesters<?= $cgpaLast !== null ? ', lifting your cumulative GPA to <span class="hl">' . number_format($cgpaLast, 2) . '</span>' : '' ?>.
                    Try the what-if simulator below to see where your upcoming modules could take it next.
                </div>
            <?php elseif ($gpaTrend === 'falling'): ?>
                <div class="hero-title">Your GPA has dipped recently — let's turn it around.</div>
                <div class="hero-sub">
                    It's slipped by <span class="hl"><?= number_format(abs($gpaTrendDelta), 2) ?></span>
                    over your last few semesters<?= $cgpaLast !== null ? ', with your cumulative GPA now at <span class="hl">' . number_format($cgpaLast, 2) . '</span>' : '' ?>.
                    That's recoverable — use the what-if simulator below to plan the marks that pull it back up.
                </div>
            <?php else: ?>
                <div class="hero-title">You're holding steady.</div>
                <div class="hero-sub">
                    Your GPA has stayed consistent<?= $latestGpa !== null ? ' at around <span class="hl">' . number_format($latestGpa, 2) . '</span>' : '' ?><?= $cgpaLast !== null ? ', keeping your cumulative GPA at <span class="hl">' . number_format($cgpaLast, 2) . '</span>' : '' ?>.
                    Try the what-if simulator below to see where your upcoming modules could take it next.
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="stat-row">
        <div class="stat-box">
            <div class="label">Cumulative GPA</div>
            <div class="value"><?= $cgpaLast !== null ? number_format($cgpaLast, 2) : '—' ?><span class="unit"> / 5.0</span></div>
            <?php if ($cgpaDelta !== null && $cgpaDelta != 0.0): ?>
                <div class="sub <?= $cgpaDelta > 0 ? 'up' : 'down' ?>">
                    <?= $cgpaDelta > 0 ? '&#9650;' : '&#9660;' ?> <?= number_format(abs($cgpaDelta), 2) ?> since last semester
                </div>
            <?php else: ?>
                <div class="sub">Running cumulative average</div>
            <?php endif; ?>
        </div>
        <div class="stat-box">
            <div class="label">Latest Semester GPA</div>
            <div class="value"><?= $latestGpa !== null ? number_format($latestGpa, 2) : '—' ?></div>
            <div class="sub"><?= htmlspecialchars($latestSemLabelFull ?? '—') ?></div>
        </div>
    </div>

    <!-- ── GPA trend ── -->
    <div class="panel">
        <div class="panel-title">GPA trend</div>
        <div class="panel-sub">Semester by semester</div>
        <div class="chart-wrap"><canvas id="gpaTrendChart"></canvas></div>
    </div>

    <?php if (!empty($futureModules)): ?>
    <!-- ── What-if CGPA Simulator ── -->
    <div class="panel">
        <div class="whatif-head">
            <div>
                <div class="panel-title">What-if CGPA simulator</div>
                <div class="panel-sub">
                    Drag the sliders to set target scores for your upcoming modules
                    and see where your cumulative GPA could land.
                </div>
            </div>
            <div class="proj-box">
                <div class="proj-label">PROJECTED CGPA</div>
                <div class="proj-val" id="projCGPA">—</div>
                <div class="proj-delta" id="projDelta"></div>
            </div>
        </div>

        <div class="wi-list">
            <?php
            $prevYS = null;
            foreach ($futureModules as $fm):
                $ys = "Year {$fm['year_no']} · Semester {$fm['sem_no']}";
                if ($ys !== $prevYS): $prevYS = $ys; ?>
                    <div class="wi-group-hd"><?= htmlspecialchars($ys) ?></div>
                <?php endif; ?>
                <div class="wi-row">
                    <div class="wi-name">
                        <?= htmlspecialchars($fm['module_name']) ?>
                        <span class="cu">· <?= rtrim(rtrim(number_format((float)$fm['credit_unit'], 1), '0'), '.') ?> CU</span>
                    </div>
                    <input type="range" min="0" max="100" value="70" class="wi-slider"
                           data-cu="<?= htmlspecialchars((string)$fm['credit_unit']) ?>">
                    <span class="wi-pct">70%</span>
                    <span class="wi-grade">B</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</main>

<?php if ($hasData): ?>
<script>
    const semesterLabels = <?= $jsSemesterLabels ?>;
    const gpaSeries      = <?= $jsGpaSeries ?>;
    const navy = '#16213f', blue = '#33528f';

    // ── GPA trend (single line) ────────────────────────────────────────────────
    new Chart(document.getElementById('gpaTrendChart'), {
        type: 'line',
        data: {
            labels: semesterLabels,
            datasets: [{
                label: 'GPA',
                data: gpaSeries,
                borderColor: blue,
                backgroundColor: 'rgba(51,82,143,0.08)',
                pointBackgroundColor: blue,
                pointRadius: 4,
                tension: 0.35,
                spanGaps: true,
                fill: true,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { min: 2.5, max: 5.0, ticks: { stepSize: 0.5 } } }
        }
    });

    // ── What-if CGPA Simulator ─────────────────────────────────────────────────
    const existingQP  = <?= $jsExistingQP ?>;
    const existingCU  = <?= $jsExistingCU ?>;
    const currentCGPA = <?= $jsCurrentCGPA ?>;

    const GRADE_SCALE = [
        { min: 80, gp: 5.00, letter: 'A'  },
        { min: 75, gp: 4.50, letter: 'B+' },
        { min: 70, gp: 4.00, letter: 'B'  },
        { min: 65, gp: 3.50, letter: 'C+' },
        { min: 60, gp: 3.00, letter: 'C'  },
        { min: 55, gp: 2.50, letter: 'D+' },
        { min: 50, gp: 2.00, letter: 'D'  },
        { min:  0, gp: 0.00, letter: '0'  },
    ];
    const markToGrade = m => GRADE_SCALE.find(g => m >= g.min) || GRADE_SCALE[GRADE_SCALE.length - 1];

    function recalcWhatif() {
        let addQP = 0, addCU = 0;
        document.querySelectorAll('.wi-slider').forEach(s => {
            const mark = parseInt(s.value, 10);
            const cu   = parseFloat(s.dataset.cu);
            const g    = markToGrade(mark);
            addQP += g.gp * cu;
            addCU += cu;
            const row = s.closest('.wi-row');
            row.querySelector('.wi-pct').textContent   = mark + '%';
            row.querySelector('.wi-grade').textContent = g.letter;
        });
        if (addCU === 0) return;
        const proj = (existingQP + addQP) / (existingCU + addCU);
        document.getElementById('projCGPA').textContent = proj.toFixed(2);

        const deltaEl = document.getElementById('projDelta');
        if (currentCGPA !== null) {
            const d = proj - currentCGPA;
            const arrow = d > 0.001 ? '▲' : (d < -0.001 ? '▼' : '→');
            deltaEl.textContent = `${arrow} ${d >= 0 ? '+' : ''}${d.toFixed(2)} vs. now`;
        }
    }

    document.querySelectorAll('.wi-slider').forEach(s => s.addEventListener('input', recalcWhatif));
    recalcWhatif();
</script>
<?php endif; ?>

</body>
</html>
