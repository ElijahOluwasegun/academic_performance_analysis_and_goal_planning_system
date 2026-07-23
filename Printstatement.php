<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   
$db_pass = "";       

// ─── Auth: opened from the Results page, relies on the login session ──────────
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

// ─── Student bio data + program info ──────────────────────────────────────────
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

// ─── All results, joined with module name and calendar term ───────────────────
$stmtR = $pdo->prepare("
    SELECT r.year_no, r.sem_no, r.module_code, m.module_name,
           r.final_total, r.letter_grade, r.status_retake_pass,
           IFNULL(t.term_month, '—') AS calendar_month,
           (s.intake_year + IFNULL(t.year_offset, 0)) AS calendar_year
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    JOIN   student_tb s ON r.student_ID = s.student_ID
    LEFT JOIN term_mapping_tb t ON s.intake_session = t.intake_session
                               AND r.year_no = t.year_no
                               AND r.sem_no = t.sem_no
    WHERE  r.student_ID = ?
    ORDER  BY r.year_no ASC, r.sem_no ASC, r.module_code ASC
");
$stmtR->execute([$studentID]);
$allResults = $stmtR->fetchAll();

// ─── Group by (year, sem) chronologically — Semester in Program 1 → 6 ─────────
$grouped = [];
foreach ($allResults as $row) {
    $k = "{$row['year_no']}-{$row['sem_no']}";
    $grouped[$k]["year"]   = (int)$row["year_no"];
    $grouped[$k]["sem"]    = (int)$row["sem_no"];
    $grouped[$k]["rows"][] = $row;
}
ksort($grouped, SORT_NATURAL);

// ─── Full faculty name (DB stores the abbreviation) ───────────────────────────
$facultyNames = [
    "FST" => "Faculty of Science & Technology",
    "FBS" => "Faculty of Business & Management",
    "FBC" => "Faculty of Social Sciences",
];
$facultyName = $facultyNames[$student["program_faculty"]] ?? $student["program_faculty"];

$dateIssued = (new DateTime())->format('j F Y'); // e.g. 14 July 2026
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provisional Statement of Results – <?= htmlspecialchars($student["student_ID"]) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
            padding: 1.5rem 2rem;
            /* Keep gray fills when printing (browsers strip backgrounds otherwise) */
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .sheet { max-width: 850px; margin: 0 auto; position: relative; }

        /* Watermark — position:fixed repeats it on every printed page */
        .watermark {
            position: fixed; top: 46%; left: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: 3.3rem; font-weight: 800; letter-spacing: .05em;
            color: rgba(22, 33, 63, .07); white-space: nowrap;
            z-index: 9999; pointer-events: none;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        /* ── Letterhead: crest pinned left, heading block centred on the page ── */
        .letterhead {
            position: relative;
            text-align: center;
            min-height: 6em;
            margin-bottom: 1.1rem;
            padding: 0 1rem;
        }
        .letterhead img { position: absolute; left: 0; top: .2em; width: 5.5em; }
        .letterhead h1 {
            font-size: 15px;
            font-weight: 700;
            display: inline-block;
            padding: 0 1.4em 2px;
            border-bottom: 1.5px solid #000;
            margin-bottom: .35rem;
        }
        .letterhead p  { font-size: 11px; font-weight: 700; line-height: 1.5; }
        .letterhead .doc-title { font-size: 12px; font-weight: 700; margin-top: .5rem; }
        .letterhead .doc-sub   { font-size: 10px; font-weight: 400; letter-spacing: .08em; }
        .letterhead .specimen  { margin-top: .35rem; font-size: 10px; font-weight: 700; color: #b42318; letter-spacing: .03em; }

        /* ── Bio block (label / value) ── */
        .bio {
            margin-bottom: 1rem;
            font-size: 12px;
            line-height: 1.7;
        }
        .bio .row { display: flex; }
        .bio .label { font-weight: 700; width: 11rem; }

        /* ── Results table ── */
        table.results-table { width: 100%; border-collapse: collapse; }
        table.results-table th,
        table.results-table td {
            border: 1px solid #000;
            padding: 4px 7px;
            font-size: 11px;
        }
        table.results-table thead th {
            background: #d9d9d9;
            font-weight: 700;
            text-align: center;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        table.results-table thead th.left { text-align: left; }
        table.results-table td.center { text-align: center; }

        /* Term separator row spanning the whole width */
        tr.term-row td {
            background: #d0d0d0;
            font-weight: 700;
            text-align: center;
            font-style: italic;
            font-size: 11px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Test-document warning footer (replaces the signature block) ── */
        .warn-footer { margin-top: 2.6rem; text-align: center; }
        .warn-dots   { letter-spacing: 2px; font-size: 13px; }
        .warn-office { font-weight: 700; font-size: 11px; margin-top: .15rem; }
        .warn-msg    { font-weight: 700; font-size: 11px; margin-top: .7rem; color: #b42318; line-height: 1.5; max-width: 44rem; margin: .7rem auto 0; }
        .warn-printed{ font-size: 9.5px; color: #333; margin-top: .55rem; }

        /* ── Print controls (hidden when actually printing) ── */
        .print-controls { text-align: center; margin-bottom: 1.2rem; }
        .print-controls button {
            padding: .5rem 1.3rem; border-radius: 20px; border: 1px solid #888;
            background: #213769; color: #fff; font-weight: 600; font-size: .85rem;
            cursor: pointer; font-family: Arial, sans-serif;
        }
        .print-controls button:hover { background: #121e38; }

        @media print {
            .print-controls { display: none !important; }
            body { padding: 12mm 14mm; }
            .sheet { max-width: 100%; }
        }
        /* margin:0 removes the browser's default header/footer (date, title, URL,
           page number); the body padding above restores the printable margin. */
        @page { size: A4; margin: 0; }
    </style>
</head>
<body>

    <div class="print-controls">
        <button onclick="window.print()">Print this statement</button>
    </div>

    <div class="sheet">

        <div class="watermark">NOT AN OFFICIAL PROVISIONAL STATEMENT</div>

        <!-- ── Letterhead ── -->
        <div class="letterhead">
            <div class="letterhead_image">
                <img src="images/cu_logo.jpg" alt="Cavendish Logo">
            </div>
            <div class="letterhead_text">
                <h1>Cavendish University Uganda</h1>
                <p><?= htmlspecialchars($facultyName) ?></p>
                <p><?= htmlspecialchars($student["program_name"]) ?></p>
                <p class="doc-title">OFFICIAL EXAMINATION RESULTS STATEMENT</p>
                <p class="doc-sub">PROGRESSION</p>
                <p class="specimen">SPECIMEN — TEST DOCUMENT, NOT AN OFFICIAL RESULTS STATEMENT</p>
            </div>
        </div>

        <!-- ── Bio ── -->
        <div class="bio">
            <div class="row"><span class="label">DATE ISSUED:</span><span><?= htmlspecialchars($dateIssued) ?></span></div>
            <div class="row"><span class="label">STUDENT NAME:</span><span><?= htmlspecialchars($student["student_name"]) ?></span></div>
            <div class="row"><span class="label">STUDENT NUMBER:</span><span><?= htmlspecialchars($student["student_ID"]) ?></span></div>
        </div>

        <?php if (empty($grouped)): ?>
            <p style="text-align:center; padding:2rem; color:#555;">No results have been recorded yet.</p>
        <?php else: ?>

        <!-- ── Results table ── -->
        <table class="results-table">
            <thead>
                <tr>
                    <th style="width:12%">CODE</th>
                    <th class="left">MODULE</th>
                    <th style="width:10%">GRADE</th>
                    <th style="width:10%">SCORE</th>
                    <th style="width:16%">REMARKS</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped as $block):
                    $first = $block["rows"][0];
                    $termLabel = "Term: {$first['calendar_year']}-{$first['calendar_month']} - Semester in Program: {$block['sem']}";
                ?>
                <tr class="term-row"><td colspan="5"><?= htmlspecialchars($termLabel) ?></td></tr>
                <?php foreach ($block["rows"] as $r):
                    // REMARKS is blank for a normal pass; otherwise show the status
                    $remark = (strcasecmp($r["status_retake_pass"], "Pass") === 0) ? "" : $r["status_retake_pass"];
                ?>
                <tr>
                    <td><?= htmlspecialchars($r["module_code"]) ?></td>
                    <td><?= htmlspecialchars($r["module_name"]) ?></td>
                    <td class="center"><?= htmlspecialchars($r["letter_grade"]) ?></td>
                    <td class="center"><?= (int)$r["final_total"] ?>%</td>
                    <td class="center"><?= htmlspecialchars($remark) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php endif; ?>

        <!-- ── Test-document warning footer ── -->
        <div class="warn-footer">
            <div class="warn-dots">......................................................</div>
            <div class="warn-office">For Examinations Office</div>
            <div class="warn-msg">
                WARNING: This document is generated by the APAAGPS student project for
                demonstration purposes only. It reflects provisional results, should NOT be
                substituted for a Transcript, and is <u>NOT</u> an official Cavendish University
                Uganda results statement.
            </div>
            <div class="warn-printed">Printed on: <?= htmlspecialchars($dateIssued) ?></div>
        </div>

    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>

</body>
</html>
