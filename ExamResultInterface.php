<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Only handle POST requests ────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: index.html");
    exit();
}

// ─── Sanitize inputs ──────────────────────────────────────────────────────────
$email    = trim($_POST["Email"]    ?? "");
$password = trim($_POST["Password"] ?? "");

if (empty($email) || empty($password)) {
    header("Location: index.html?error=empty_fields");
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

// ─── Authenticate student ─────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT s.student_ID,
           s.student_name,
           s.student_email,
           s.student_password,
           s.program_code,
           p.program_name,
           p.program_faculty
    FROM   student_tb s
    JOIN   program_tb p ON s.program_code = p.program_code
    WHERE  s.student_email = ?
    LIMIT  1
");
$stmt->execute([$email]);
$student = $stmt->fetch();

if (!$student || $password !== $student["student_password"]) {
    header("Location: index.html?error=invalid_credentials");
    exit();
}

$_SESSION["student_ID"]   = $student["student_ID"];
$_SESSION["student_name"] = $student["student_name"];

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
function pillClass(string $g): string {
    return match(true) {
        $g === 'A'               => 'pill-a',
        str_starts_with($g, 'B') => 'pill-b',
        str_starts_with($g, 'C') => 'pill-c',
        str_starts_with($g, 'D') => 'pill-d',
        default                  => 'pill-f',
    };
}
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
        /* spacer so title centers between logo-text and empty right */
        .header-right { width: 160px; }

        /* ── Tab nav ── */
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
            background: #e8e8e8;
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
            background: #111;
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
            background: #f0f0f0;
            font-size: .82rem;
            font-weight: 500;
            cursor: pointer;
            color: #111;
            transition: background .15s;
        }
        .btn-action:hover,
        .btn-action.active { background: #ddd; }

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

        /* ── Print button ── */
        .print-row {
            display: flex;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-bottom: 2rem;
        }
        .btn-print {
            padding: .45rem 1.4rem;
            border: 1px solid #888;
            border-radius: 20px;
            background: #e8e8e8;
            font-size: .9rem;
            font-weight: 600;
            cursor: pointer;
            color: #111;
        }
        .btn-print:hover { background: #d0d0d0; }

        /* ── Empty state ── */
        .empty { text-align: center; padding: 2.5rem; color: #888; }

        /* Note: printing now happens on the dedicated PrintStatement.php page,
           opened by the Print button below, so no @media print override is
           needed here anymore. */
    </style>
</head>
<body>

<header class="site-header">
    <h4 class="uni-name">Cavendish University</h4>
    <h1 class="portal-title">Academic Performance and Goal Planning</h1>
    <div class="header-right"></div>
</header>

<nav class="tab-nav">
    <span class="tab-btn active">Results</span>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Goal Planning</a>
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
                            <span class="pill <?= pillClass($r['letter_grade']) ?>">
                                <?= htmlspecialchars($r["letter_grade"]) ?>
                            </span>
                        </td>
                        <td><?= (int)$r["final_total"] ?>%</td>
                        <td><?= htmlspecialchars($r["credit_unit"]) ?></td>
                        <td><?= htmlspecialchars($r["grade_point"]) ?></td>
                        <td>
                            <button class="btn-report"
                                onclick="reportModule('<?= htmlspecialchars($r['module_code']) ?>')">
                                Report Module
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sem-footer">
                <div class="gpa-group">
                    <span class="badge-label">GPA</span>
                    <span class="badge-value">
                        <?= isset($gpaMap[(int)$latestSem])
                            ? number_format($gpaMap[(int)$latestSem]["gpa_value"], 2)
                            : '—' ?>
                    </span>
                </div>

                <?php
                    $latestKey = "{$latestYear}-{$latestSem}";
                    $latestCgpa = $cgpaMap[$latestKey] ?? null;
                ?>
                <?php if (!((int)$latestYear === 1 && (int)$latestSem === 1)) : ?>
                <div class="gpa-group">
                    <span class="badge-label">CGPA</span>
                    <span class="badge-value">
                        <?= $latestCgpa ? number_format($latestCgpa["cgpa_value"], 2) : '—' ?>
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
                        <td><?= (int)$r["cat1_mk"] ?></td>
                        <td><?= (int)$r["cat2_mk"] ?></td>
                        <td><?= (int)$r["exam_mk"] ?></td>
                        <td><?= (int)$r["final_total"] ?>%</td>
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
                                    <span class="pill <?= pillClass($r['letter_grade']) ?>">
                                        <?= htmlspecialchars($r["letter_grade"]) ?>
                                    </span>
                                </td>
                                <td><?= (int)$r["final_total"] ?>%</td>
                                <td><?= htmlspecialchars($r["credit_unit"]) ?></td>
                                <td><?= htmlspecialchars($r["grade_point"]) ?></td>
                                <td>
                                    <button class="btn-report"
                                        onclick="reportModule('<?= htmlspecialchars($r['module_code']) ?>')">
                                        Report Module
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php
                        $blockKey     = "{$block['year']}-{$block['sem']}";
                        $blockCgpa    = $cgpaMap[$blockKey] ?? null;
                        $showCgpaHere = !((int)$block["year"] === 1 && (int)$block["sem"] === 1);
                        $hasGpaHere   = isset($gpaMap[(int)$block["sem"]]);
                    ?>
                    <?php if ($hasGpaHere || ($showCgpaHere && $blockCgpa)): ?>
                    <div class="sem-footer">
                        <?php if ($hasGpaHere): ?>
                        <div class="gpa-group">
                            <span class="badge-label">GPA</span>
                            <span class="badge-value">
                                <?= number_format($gpaMap[(int)$block["sem"]]["gpa_value"], 2) ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($showCgpaHere && $blockCgpa): ?>
                        <div class="gpa-group">
                            <span class="badge-label">CGPA</span>
                            <span class="badge-value">
                                <?= number_format($blockCgpa["cgpa_value"], 2) ?>
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
        <button class="btn-print" onclick="window.open('PrintStatement.php', '_blank')">Print</button>
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

    function reportModule(code) {
        alert('Report submitted for module: ' + code);
    }
</script>

</body>
</html>