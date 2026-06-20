<?php
// ── TEMPORARY DEBUG: remove these two lines once the page is working ──────────
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();

// Allow access if they are logging in via POST OR if they already have an active session
if ($_SERVER["REQUEST_METHOD"] !== "POST" && empty($_SESSION["student_ID"])) {
    header("Location: index.html");
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
    header("Location: index.html?error=session_expired");
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
    header("Location: index.html?error=invalid_session");
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

        /* ── Header (same as Results page) ── */
        .site-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.2em 1.5rem;
            background: #213769;
            border-bottom: 1px solid #ccc;
        }
        .site-header .uni-name {
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #fff;
        }
        .site-header .portal-title {
            font-weight: 700;
            color: #fff;
        }
        .header-right { width: 160px; }

        /* ── Tab nav (same as Results page) ── */
        .tab-nav {
            display: flex;
            gap: 0;
            padding: .75rem 1.5rem 0;
            border-bottom: 2px solid #ccc;
            background: #fff;
        }
        .tab-btn {
            padding: .45rem 1.1rem;
            border: 1px solid #999;
            border-bottom: none;
            background: #f0f0f0;
            font-size: .85rem;
            font-weight: 600;
            cursor: pointer;
            border-radius: 4px 4px 0 0;
            color: #444;
            text-decoration: none;
            transition: background .15s;
        }
        .tab-btn:first-child { margin-right: 4px; }
        .tab-btn.active,
        .tab-btn:hover {
            background: #fff;
            color: #111;
            border-color: #888;
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

        @media (max-width: 640px) {
            .stat-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<!-- ── Site Header ── -->
<header class="site-header">
    <h4 class="uni-name">Cavendish University</h4>
    <h1 class="portal-title">Academic Performance and Goal Planning</h1>
    <div class="header-right"></div>
</header>

<!-- ── Tab Navigation ── -->
<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <span class="tab-btn active">Analysis</span>
    <a class="tab-btn" href="GoalPlanning.php">Goal Planning</a>
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

    <!-- ── CAT1 / CAT2 / Exam trend ── -->
    <div class="card">
        <div class="card-header">
            CAT &amp; Exam Performance Trend
            <span class="card-sub">Average marks per semester</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="catTrendChart"></canvas>
            </div>
        </div>
    </div>

    <!-- ── GPA / CGPA trend ── -->
    <div class="card">
        <div class="card-header">
            GPA &amp; CGPA Trend
            <span class="card-sub">Progress across semesters</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap">
                <canvas id="gpaTrendChart"></canvas>
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

    <!-- ── Pass vs Retake breakdown ── -->
    <div class="card">
        <div class="card-header">
            Pass / Retake Breakdown
            <span class="card-sub">All recorded modules</span>
        </div>
        <div class="card-body">
            <div class="chart-wrap" style="max-width:340px; margin:0 auto; height:280px;">
                <canvas id="passRetakeChart"></canvas>
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

    // ── Pass / Retake breakdown (doughnut) ──────────────────────────────────
    new Chart(document.getElementById('passRetakeChart'), {
        type: 'doughnut',
        data: {
            labels: ['Pass', 'Retake'],
            datasets: [{
                data: [passRetake.pass, passRetake.retake],
                backgroundColor: [green, red]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
</script>
<?php endif; ?>

</body>
</html>