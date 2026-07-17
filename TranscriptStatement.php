<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";
$db_pass = "";

// ─── Auth (session set at login) ──────────────────────────────────────────────
if (empty($_SESSION["student_ID"])) {
    header("Location: index.php?error=session_expired");
    exit();
}
$studentID = $_SESSION["student_ID"];

// ─── PDO ──────────────────────────────────────────────────────────────────────
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

// ─── Student bio data + program ───────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.gender, s.nationality, s.date_of_birth,
           s.intake_year, s.intake_session, s.mode_of_entry, s.program_code,
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

// ═══════════════════════════════════════════════════════════════════════════════
// ELIGIBILITY GUARD — a transcript may only be printed when EVERY module in the
// program curriculum has a recorded result. This mirrors ExamResultInterface.php
// and is re-checked here so the page can't be opened directly to bypass the gate.
// ═══════════════════════════════════════════════════════════════════════════════
$stmtCur = $pdo->prepare("SELECT module_code FROM module_tb WHERE program_code = ?");
$stmtCur->execute([$student["program_code"]]);
$curriculumCodes = array_column($stmtCur->fetchAll(), "module_code");

$stmtHave = $pdo->prepare("SELECT DISTINCT module_code FROM results_tb WHERE student_ID = ?");
$stmtHave->execute([$studentID]);
$haveCodes = array_column($stmtHave->fetchAll(), "module_code");

$missing = array_diff($curriculumCodes, $haveCodes);
if (!empty($missing)) {
    // Not eligible — bounce back to the results page.
    header("Location: ExamResultInterface.php?error=transcript_locked");
    exit();
}

// ─── All results, joined with module + calendar term ──────────────────────────
$stmtR = $pdo->prepare("
    SELECT r.year_no, r.sem_no, r.module_code, m.module_name, m.credit_unit,
           r.final_total, r.grade_point, r.letter_grade, r.status_retake_pass,
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

// ─── GPA per semester ─────────────────────────────────────────────────────────
$stmtG = $pdo->prepare("SELECT sem_no, gpa_value FROM gpa_tb WHERE student_ID = ?");
$stmtG->execute([$studentID]);
$gpaMap = [];
foreach ($stmtG->fetchAll() as $g) { $gpaMap[(int)$g["sem_no"]] = (float)$g["gpa_value"]; }

// ─── CGPA per (year, sem) — the final one is the graduating CGPA ──────────────
$stmtC = $pdo->prepare("SELECT year_no, sem_no, cgpa_value FROM cgpa_tb WHERE student_ID = ?");
$stmtC->execute([$studentID]);
$cgpaMap = [];
$finalCgpa = 0.0;
foreach ($stmtC->fetchAll() as $c) {
    $cgpaMap["{$c['year_no']}-{$c['sem_no']}"] = (float)$c["cgpa_value"];
}

// ─── Group by (year, sem), chronological ──────────────────────────────────────
$grouped = [];
foreach ($allResults as $row) {
    $k = "{$row['year_no']}-{$row['sem_no']}";
    $grouped[$k]["year"]   = (int)$row["year_no"];
    $grouped[$k]["sem"]    = (int)$row["sem_no"];
    $grouped[$k]["rows"][] = $row;
}
ksort($grouped, SORT_NATURAL);

// Final CGPA = the CGPA of the last (year, sem) block
$lastKey = array_key_last($grouped);
if ($lastKey !== null) {
    $finalCgpa = $cgpaMap[$lastKey] ?? 0.0;
}

// ─── Class of award from final CGPA (Ugandan honours classification) ──────────
function classOfAward(float $cgpa): string {
    return match(true) {
        $cgpa >= 4.40 => "First Class Honours",
        $cgpa >= 3.60 => "Second Class Honours (Upper Division)",
        $cgpa >= 2.80 => "Second Class Honours (Lower Division)",
        $cgpa >= 2.00 => "Pass",
        default       => "Fail",
    };
}
$awardClass = classOfAward($finalCgpa);

// ─── Helpers ───────────────────────────────────────────────────────────────────
function fmtDob(?string $dob): string {
    if (!$dob) return '—';
    $d = DateTime::createFromFormat('Y-m-d', $dob);
    return $d ? $d->format('d/m/Y') : htmlspecialchars($dob);
}
$today = (new DateTime())->format('d/m/Y H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Transcript – <?= htmlspecialchars($student["student_ID"]) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: Arial, Helvetica, sans-serif; font-size: 11px; color: #000;
            background: #fff; padding: 1.5rem 2rem;
            /* Keep every fill (navy bars, gray headers) when printing */
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }
        .sheet { max-width: 850px; margin: 0 auto; position: relative; }

        /* Watermark — position:fixed repeats it on every printed page */
        .watermark {
            position: fixed; top: 46%; left: 50%;
            transform: translate(-50%, -50%) rotate(-32deg);
            font-size: 4.4rem; font-weight: 800; letter-spacing: .06em;
            color: rgba(22, 33, 63, .07); white-space: nowrap;
            z-index: 9999; pointer-events: none;
            -webkit-print-color-adjust: exact; print-color-adjust: exact;
        }

        /* Letterhead: crest pinned left, heading block centred on the page */
        .letterhead { position: relative; text-align: center; min-height: 6.4em; margin-bottom: 1rem; padding: 0 1rem; }
        .letterhead img { position: absolute; left: 0; top: .2em; width: 6em; }
        .letterhead h1 { font-size: 16px; font-weight: 700; margin-bottom: .25rem; }
        .letterhead p { font-size: 10px; line-height: 1.45; }
        .letterhead .doc-title { font-size: 13px; font-weight: 700; margin-top: .5rem; letter-spacing: .05em; }
        .letterhead .specimen { margin-top: .3rem; font-size: 10px; font-weight: 700; color: #b42318; letter-spacing: .03em; }

        /* Bio block + passport-photo box */
        .bio-table { width: 100%; border-collapse: collapse; margin-bottom: 1rem; }
        .bio-table td { border: 1px solid #000; padding: 4px 8px; font-size: 11px; vertical-align: top; }
        .bio-table td.label { font-weight: 700; width: 13%; background: #ececec; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .bio-table td.value { width: 27%; }
        .bio-table td.photo-box {
            width: 120px; text-align: center; vertical-align: middle;
            color: #9aa0ac; font-size: 9px; font-weight: 700; letter-spacing: .08em; line-height: 1.6;
        }

        /* Semester sections */
        .sem-section { margin-bottom: .85rem; }
        .sem-title { background: #16213f; color: #fff; font-weight: 700; font-size: 11px; padding: 5px 8px;
            text-transform: uppercase; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        table.results-table { width: 100%; border-collapse: collapse; }
        table.results-table th, table.results-table td { border: 1px solid #000; padding: 3px 6px; font-size: 10.5px; }
        table.results-table th { background: #d9d9d9; font-weight: 700; text-align: left;
            -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        table.results-table td.num, table.results-table th.num { text-align: center; }

        .spacer-row td { height: 14px; }
        .gpa-footer-row td.gpa-lab { font-weight: 700; text-align: center; background: #d9d9d9;
            -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .gpa-footer-row td.gpa-num { font-weight: 700; text-align: center; }

        /* Award + medium of instruction */
        .award-table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        .award-table td { border: 1px solid #000; padding: 5px 8px; font-size: 11px; }
        .award-table td.label { font-weight: 700; width: 18%; background: #ececec; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .award-table td.award-val { font-weight: 700; }
        .medium-note { text-align: center; font-weight: 700; font-style: italic; font-size: 11px;
            border: 1px solid #000; border-top: none; padding: 5px; background: #d9d9d9;
            -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        /* Warning / test-document footer */
        .warn-footer { margin-top: 2.4rem; text-align: center; }
        .warn-dots { letter-spacing: 2px; font-size: 13px; }
        .warn-office { font-weight: 700; font-size: 11px; margin-top: .15rem; }
        .warn-msg { font-weight: 700; font-size: 11px; margin-top: .7rem; color: #b42318; line-height: 1.5; max-width: 44rem; margin-left: auto; margin-right: auto; }
        .warn-printed { font-size: 9.5px; color: #333; margin-top: .55rem; }

        .print-controls { text-align: center; margin-bottom: 1.2rem; }
        .print-controls button { padding: .5rem 1.3rem; border-radius: 20px; border: 1px solid #888; background: #213769; color: #fff; font-weight: 600; font-size: .85rem; cursor: pointer; font-family: Arial, sans-serif; }
        .print-controls button:hover { background: #121e38; }

        @media print { .print-controls { display: none !important; } body { padding: 12mm 14mm; } .sheet { max-width: 100%; } }
        /* margin:0 removes the browser's default header/footer (date, title, URL, page #) */
        @page { size: A4; margin: 0; }
    </style>
</head>
<body>

    <div class="print-controls">
        <button onclick="window.print()">Print this transcript</button>
    </div>

    <div class="sheet">

        <div class="watermark">NOT AN OFFICIAL TRANSCRIPT</div>

        <div class="letterhead">
            <img src="images/cu_logo.jpg" alt="Cavendish Logo">
            <div class="letterhead_text">
                <h1>Cavendish University Uganda</h1>
                <p>P.O Box 33145, Kampala, Uganda; Tel/Fax: +256 414 531700</p>
                <p>Email: info@cavendish.ac.ug; Web: www.cavendish.ac.ug</p>
                <p class="doc-title">ACADEMIC TRANSCRIPT</p>
                <p class="specimen">SPECIMEN — TEST DOCUMENT, NOT AN OFFICIAL TRANSCRIPT</p>
            </div>
        </div>

        <table class="bio-table">
            <tr>
                <td class="label">Name</td>
                <td class="value"><?= htmlspecialchars($student["student_name"]) ?></td>
                <td class="label">Gender</td>
                <td class="value"><?= htmlspecialchars($student["gender"]) ?></td>
                <td class="photo-box" rowspan="5">AFFIX<br>PASSPORT<br>PHOTO</td>
            </tr>
            <tr>
                <td class="label">Student No.</td>
                <td class="value"><?= htmlspecialchars($student["student_ID"]) ?></td>
                <td class="label">Faculty</td>
                <td class="value"><?= htmlspecialchars($student["program_faculty"]) ?></td>
            </tr>
            <tr>
                <td class="label">Course</td>
                <td class="value"><?= htmlspecialchars($student["program_name"]) ?></td>
                <td class="label">Date of Birth</td>
                <td class="value"><?= fmtDob($student["date_of_birth"]) ?></td>
            </tr>
            <tr>
                <td class="label">Year of Entry</td>
                <td class="value"><?= htmlspecialchars($student["intake_year"]) ?></td>
                <td class="label">Nationality</td>
                <td class="value"><?= htmlspecialchars($student["nationality"]) ?></td>
            </tr>
            <tr>
                <td class="label">Mode of Entry</td>
                <td class="value"><?= htmlspecialchars($student["mode_of_entry"]) ?></td>
                <td class="label"></td>
                <td class="value"></td>
            </tr>
        </table>

        <?php foreach ($grouped as $key => $block):
            $year = $block["year"];
            $sem  = $block["sem"];
            $rows = $block["rows"];
            $gpa  = $gpaMap[$sem] ?? null;
            $cgpa = $cgpaMap["{$year}-{$sem}"] ?? null;
        ?>
        <div class="sem-section">
            <div class="sem-title">Year <?= $year ?>, SEMESTER <?= $sem ?></div>
            <table class="results-table">
                <thead>
                    <tr>
                        <th style="width:11%">Module Code</th>
                        <th>Module Name</th>
                        <th class="num" style="width:8%">Score</th>
                        <th class="num" style="width:6%">CU</th>
                        <th class="num" style="width:6%">GP</th>
                        <th class="num" style="width:8%">Grade</th>
                        <th class="num" style="width:9%">Comment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        // Comment: "NP" (Normal Progress) on a clean pass, else the status
                        $comment = (strcasecmp($r["status_retake_pass"], "Pass") === 0) ? "NP" : $r["status_retake_pass"];
                    ?>
                    <tr>
                        <td><?= htmlspecialchars($r["module_code"]) ?></td>
                        <td><?= htmlspecialchars($r["module_name"]) ?></td>
                        <td class="num"><?= (int)$r["final_total"] ?></td>
                        <td class="num"><?= htmlspecialchars($r["credit_unit"]) ?></td>
                        <td class="num"><?= htmlspecialchars($r["grade_point"]) ?></td>
                        <td class="num"><?= htmlspecialchars($r["letter_grade"]) ?></td>
                        <td class="num"><?= htmlspecialchars($comment) ?></td>
                    </tr>
                    <?php endforeach; ?>

                    <tr class="spacer-row"><td colspan="7">&nbsp;</td></tr>
                    <tr class="gpa-footer-row">
                        <?php if ($cgpa !== null): ?>
                            <td colspan="3"></td>
                            <td class="gpa-lab">GPA</td>
                            <td class="gpa-num"><?= number_format($gpa ?? 0, 2) ?></td>
                            <td class="gpa-lab">CGPA</td>
                            <td class="gpa-num"><?= number_format($cgpa, 2) ?></td>
                        <?php else: ?>
                            <td colspan="5"></td>
                            <td class="gpa-lab">GPA</td>
                            <td class="gpa-num"><?= number_format($gpa ?? 0, 2) ?></td>
                        <?php endif; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- ── Award block ── -->
        <table class="award-table">
            <tr>
                <td class="label">AWARD</td>
                <td colspan="3" class="award-val"><?= htmlspecialchars($student["program_name"]) ?></td>
            </tr>
            <tr>
                <td class="label">Final CGPA</td>
                <td class="award-val"><?= number_format($finalCgpa, 2) ?> / 5.00</td>
                <td class="label">Class of Award</td>
                <td class="award-val"><?= htmlspecialchars($awardClass) ?></td>
            </tr>
        </table>
        <div class="medium-note">The Medium of Instruction is English</div>

        <!-- ── Test-document warning footer ── -->
        <div class="warn-footer">
            <div class="warn-dots">......................................................</div>
            <div class="warn-office">For Examinations Office</div>
            <div class="warn-msg">
                WARNING: This document is generated by the APAAGPS student project for
                demonstration purposes only. It reflects provisional results and is
                <u>NOT</u> an official Cavendish University Uganda transcript.
            </div>
            <div class="warn-printed">Printed on: <?= $today ?></div>
        </div>

    </div>

    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>

</body>
</html>
