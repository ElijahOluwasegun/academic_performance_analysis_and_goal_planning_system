<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Auth: this page is opened from the Results page, so it relies on the
//     session set at login rather than re-checking POST credentials ──────────
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

// ─── Student bio data + program info ──────────────────────────────────────────
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
    header("Location: index.html?error=invalid_session");
    exit();
}

// ─── All results, joined with module name/credit unit and calendar term ──────
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

// ─── GPA per semester (sem_no runs 1–6 across the full program) ──────────────
$stmtG = $pdo->prepare("
    SELECT sem_no, gpa_value
    FROM   gpa_tb
    WHERE  student_ID = ?
");
$stmtG->execute([$studentID]);
$gpaMap = [];
foreach ($stmtG->fetchAll() as $g) {
    $gpaMap[(int)$g["sem_no"]] = (float)$g["gpa_value"];
}

// ─── CGPA per (year, sem) — running CGPA history ──────────────────────────────
$stmtC = $pdo->prepare("
    SELECT year_no, sem_no, cgpa_value
    FROM   cgpa_tb
    WHERE  student_ID = ?
");
$stmtC->execute([$studentID]);
$cgpaMap = [];
foreach ($stmtC->fetchAll() as $c) {
    $cgpaMap["{$c['year_no']}-{$c['sem_no']}"] = (float)$c["cgpa_value"];
}

// ─── Group by (year, sem) in chronological order, Year 1 Sem 1 first ──────────
$grouped = [];
foreach ($allResults as $row) {
    $grouped["{$row['year_no']}-{$row['sem_no']}"]["year"] = (int)$row["year_no"];
    $grouped["{$row['year_no']}-{$row['sem_no']}"]["sem"]  = (int)$row["sem_no"];
    $grouped["{$row['year_no']}-{$row['sem_no']}"]["rows"][] = $row;
}
ksort($grouped, SORT_NATURAL);

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
    <title>Provisional Statement of Results – <?= htmlspecialchars($student["student_ID"]) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 11px;
            color: #000;
            background: #fff;
            padding: 1.5rem 2rem;
        }

        .sheet { max-width: 850px; margin: 0 auto; }

        /* ── Letterhead ── */
        .letterhead {
            display: flex;
            gap: 2em;
            justify-content: center;
            align-items: center;
            text-align: center;
            margin-bottom: 1rem;
        }

        img {
            width: 6em;
        }

        .letterhead h1 {
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 0.2rem;
        }
        .letterhead p {
            font-size: 10px;
            line-height: 1.4;
        }
        .letterhead .doc-title {
            font-size: 12px;
            font-weight: 700;
            margin-top: 0.5rem;
            letter-spacing: 0.03em;
        }

        /* ── Bio info table ── */
        .bio-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        .bio-table td {
            border: 1px solid #000;
            padding: 4px 8px;
            font-size: 11px;
            vertical-align: top;
        }
        .bio-table td.label {
            font-weight: 700;
            width: 14%;
            background: #fafafa;
        }
        .bio-table td.value {
            width: 36%;
        }

        /* ── Semester block ── */
        .sem-section { margin-bottom: 0.85rem; }

        .sem-title {
            background: #000;
            color: #fff;
            font-weight: 700;
            font-size: 11px;
            padding: 4px 8px;
            text-transform: uppercase;
        }

        table.results-table {
            width: 100%;
            border-collapse: collapse;
        }
        table.results-table th,
        table.results-table td {
            border: 1px solid #000;
            padding: 3px 6px;
            font-size: 10.5px;
        }
        table.results-table th {
            background: #d9d9d9;
            font-weight: 700;
            text-align: left;
        }
        table.results-table td.num { text-align: center; }
        table.results-table th.num { text-align: center; }

        .gpa-footer-row td {
            font-weight: 700;
            background: #f0f0f0;
            text-align: right;
        }
        .gpa-footer-row td.gpa-val,
        .gpa-footer-row td.cgpa-val {
            text-align: center;
            background: #fff;
        }

        /* ── Award block ── */
        .award-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }
        .award-table td {
            border: 1px solid #000;
            padding: 4px 8px;
            font-size: 11px;
        }
        .award-table td.label { font-weight: 700; width: 20%; background: #fafafa; }

        .medium-note {
            text-align: center;
            font-weight: 700;
            font-style: italic;
            font-size: 11px;
            border: 1px solid #000;
            padding: 4px;
            margin-top: 0.4rem;
            background: #f0f0f0;
        }

        /* ── Signature / footer ── */
        .sign-block {
            margin-top: 2.2rem;
            text-align: center;
            font-size: 10.5px;
        }
        .sign-line {
            display: inline-block;
            border-bottom: 1px solid #000;
            width: 220px;
            margin-bottom: 0.3rem;
        }

        .doc-footer {
            display: flex;
            justify-content: end;
            margin-top: 0.6rem;
            font-size: 9.5px;
            color: #333;
        }
        .doc-footer .warning { font-weight: 700; }

        /* ── Print controls (hidden when actually printing) ── */
        .print-controls {
            text-align: center;
            margin-bottom: 1.2rem;
        }
        .print-controls button {
            padding: 0.5rem 1.3rem;
            border-radius: 20px;
            border: 1px solid #888;
            background: #213769;
            color: #fff;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            font-family: Arial, sans-serif;
        }
        .print-controls button:hover { background: #121e38; }

        @media print {
            .print-controls { display: none !important; }
            body { padding: 0; }
            .sheet { max-width: 100%; }
        }

        @page {
            size: A4;
            margin: 14mm;
        }
    </style>
</head>
<body>

    <div class="print-controls">
        <button onclick="window.print()">Print this statement</button>
    </div>

    <div class="sheet">

        <!-- ── Letterhead ── -->
        <div class="letterhead">
            <div class="letterhead_image">
                <img src="images\cu_logo.jpg" alt="Cavendish Logo">
            </div>
            <div class="letterhead_text">
                <h1>Cavendish University Uganda</h1>
                <p>P.O Box 33145, Kampala, Uganda; Tel/Fax: +256 414 531700</p>
                <p>Email: info@cavendish.ac.ug; Web: www.cavendish.ac.ug</p>
                <p class="doc-title">PROVISIONAL STATEMENT OF RESULTS</p>
            </div>
        </div>

        <!-- ── Bio data ── -->
        <table class="bio-table">
            <tr>
                <td class="label">Name</td>
                <td class="value"><?= htmlspecialchars($student["student_name"]) ?></td>
                <td class="label">Gender</td>
                <td class="value"><?= htmlspecialchars($student["gender"]) ?></td>
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

        <?php if (empty($grouped)): ?>
            <p style="text-align:center; padding: 2rem; color:#555;">No results have been recorded yet.</p>
        <?php else: ?>

            <?php
            $finalYear = null; $finalSem = null;
            foreach ($grouped as $block) {
                $finalYear = $block["year"];
                $finalSem  = $block["sem"];
            }
            ?>

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
                            <th style="width:12%">Module Code</th>
                            <th>Module Name</th>
                            <th class="num" style="width:8%">Score</th>
                            <th class="num" style="width:6%">CU</th>
                            <th class="num" style="width:6%">GP</th>
                            <th class="num" style="width:8%">Grade</th>
                            <th class="num" style="width:10%">Comment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r["module_code"]) ?></td>
                            <td><?= htmlspecialchars($r["module_name"]) ?></td>
                            <td class="num"><?= (int)$r["final_total"] ?></td>
                            <td class="num"><?= htmlspecialchars($r["credit_unit"]) ?></td>
                            <td class="num"><?= htmlspecialchars($r["grade_point"]) ?></td>
                            <td class="num"><?= htmlspecialchars($r["letter_grade"]) ?></td>
                            <td class="num">
                                <?= strcasecmp($r["status_retake_pass"], "Pass") === 0 ? "NP" : htmlspecialchars($r["status_retake_pass"]) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <tr class="gpa-footer-row">
                            <?php if ($cgpa !== null): ?>
                                <td colspan="3">GPA</td>
                                <td class="gpa-val" colspan="1"><?= number_format($gpa ?? 0, 2) ?></td>
                                <td colspan="1">CGPA</td>
                                <td class="cgpa-val" colspan="2"><?= number_format($cgpa, 2) ?></td>
                            <?php else: ?>
                                <td colspan="5">GPA</td>
                                <td class="gpa-val" colspan="2"><?= number_format($gpa ?? 0, 2) ?></td>
                            <?php endif; ?>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>

            <!-- ── Award block (only meaningful once the program is complete) ── -->
            <table class="award-table">
                <tr>
                    <td class="label">AWARD</td>
                    <td colspan="3"><?= htmlspecialchars($student["program_name"]) ?></td>
                </tr>
                <tr>
                    <td class="label">Date of Completion</td>
                    <td style="width:30%">&nbsp;</td>
                    <td class="label">Class of Award</td>
                    <td>&nbsp;</td>
                </tr>
            </table>
            <div class="medium-note">The Medium of Instruction is English</div>

        <?php endif; ?>

        <!-- ── Signature ── -->
        <div class="sign-block">
            <div class="sign-line"></div><br>
            <div>For Examinations Office</div>
            <span class="warning">WARNING: This reflects provisional results as such should NOT be substituted for a Transcript</span>
        </div>
                                
        <div class="doc-footer">
            <span>Printed on: <?= $today ?></span>
        </div>

    </div>

    <script>
        // Open the print dialog automatically once the page has rendered.
        window.addEventListener('load', function () {
            setTimeout(function () { window.print(); }, 300);
        });
    </script>

</body>
</html>