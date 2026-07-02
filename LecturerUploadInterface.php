<?php
session_start();

// ════════════════════════════════════════════════════════════════════════
// SETUP REQUIRED (one-time):
//   This page reads .xlsx files using PhpSpreadsheet. Install it via Composer
//   in your project root:
//       composer require phpoffice/phpspreadsheet
//   This creates a vendor/ folder with an autoloader, required below.
// ════════════════════════════════════════════════════════════════════════
require __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ════════════════════════════════════════════════════════════════════════
// AUTH — lecturer must be logged in via lecturer_login.php first.
// If this page is hit via the login form's POST (Email/Password present),
// authenticate first and start the session before checking lecturer_ID.
// ════════════════════════════════════════════════════════════════════════
if (empty($_SESSION["lecturer_ID"]) && isset($_POST["Email"], $_POST["Password"]) && !isset($_POST["result_file"]) && !isset($_FILES["result_file"])) {
    // This branch handles the initial login POST from lecturer_login.php
    $loginEmail    = trim($_POST["Email"]);
    $loginPassword = trim($_POST["Password"]);

    if (empty($loginEmail) || empty($loginPassword)) {
        header("Location: LecturerLoginInterface.php?error=empty_fields");
        exit();
    }

    try {
        $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
        $pdoAuth = new PDO($dsn, $db_user, $db_pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }

    $stmtAuth = $pdoAuth->prepare("SELECT lecturer_ID, lecturer_name, lecturer_password FROM lecturer_tb WHERE lecturer_email = ? LIMIT 1");
    $stmtAuth->execute([$loginEmail]);
    $lecturerAuth = $stmtAuth->fetch();

    if (!$lecturerAuth || !password_verify($loginPassword, $lecturerAuth["lecturer_password"])) {
        header("Location: LecturerLoginInterface.php?error=invalid_credentials");
        exit();
    }

    $_SESSION["lecturer_ID"]   = $lecturerAuth["lecturer_ID"];
    $_SESSION["lecturer_name"] = $lecturerAuth["lecturer_name"];
}

if (empty($_SESSION["lecturer_ID"])) {
    header("Location: LecturerLoginInterface.php?error=session_expired");
    exit();
}
$lecturerID = $_SESSION["lecturer_ID"];

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

// ─── Lecturer info ─────────────────────────────────────────────────────────────
$stmtL = $pdo->prepare("SELECT lecturer_ID, lecturer_name, lecturer_email FROM lecturer_tb WHERE lecturer_ID = ? LIMIT 1");
$stmtL->execute([$lecturerID]);
$lecturer = $stmtL->fetch();

if (!$lecturer) {
    header("Location: LecturerLoginInterface.php?error=session_expired");
    exit();
}

// ─── Modules this lecturer is allowed to upload for ───────────────────────────
$stmtM = $pdo->prepare("
    SELECT m.module_code, m.module_name, m.year_no, m.sem_no, m.credit_unit
    FROM   lecturer_module_tb lm
    JOIN   module_tb m ON lm.module_code = m.module_code
    WHERE  lm.lecturer_ID = ?
    ORDER  BY m.module_code
");
$stmtM->execute([$lecturerID]);
$assignedModules = $stmtM->fetchAll();
$assignedCodes   = array_map(fn($m) => $m["module_code"], $assignedModules);

// ─── Grade scale, for deriving grade_point from the Total mark ────────────────
$stmtGrades = $pdo->query("SELECT min_mark, max_mark, grade_point, letter_grade FROM grade_system ORDER BY min_mark DESC");
$gradeScale = $stmtGrades->fetchAll();

function deriveGrade(array $gradeScale, int $total): array {
    foreach ($gradeScale as $row) {
        if ($total >= (int)$row["min_mark"] && $total <= (int)$row["max_mark"]) {
            return ["grade_point" => $row["grade_point"], "letter_grade" => $row["letter_grade"]];
        }
    }
    return ["grade_point" => 0.00, "letter_grade" => "0"];
}

/**
 * Normalizes a sheet's numeric student ID (e.g. 102004 or 102004.0 — Excel
 * often stores numeric-looking IDs as floats) into the DB's dash format
 * (e.g. '102-004') by inserting a dash after the 3rd digit. Falls back to
 * the raw trimmed value if it doesn't resolve to exactly 6 digits.
 */
function normalizeStudentId($rawId): string {
    // Cast through float first to strip Excel's ".0" suffix on numeric IDs,
    // e.g. 102004.0 -> 102004, before falling back to plain digit-stripping.
    if (is_numeric($rawId)) {
        $digits = (string)(int)(float)$rawId;
    } else {
        $digits = preg_replace('/\D/', '', (string)$rawId);
    }

    if (strlen($digits) === 6) {
        return substr($digits, 0, 3) . '-' . substr($digits, 3, 3);
    }
    return trim((string)$rawId);
}

// ════════════════════════════════════════════════════════════════════════
// HANDLE UPLOAD
// ════════════════════════════════════════════════════════════════════════
$uploadResult = null; // will hold summary after processing
$selectedModule = $_POST["module_code"] ?? ($assignedCodes[0] ?? null);

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["result_file"])) {

    $moduleCode = trim($_POST["module_code"] ?? "");

    if (!in_array($moduleCode, $assignedCodes, true)) {
        $uploadResult = ["error" => "You are not assigned to upload results for that module."];
    } elseif ($_FILES["result_file"]["error"] !== UPLOAD_ERR_OK) {
        $uploadResult = ["error" => "File upload failed. Please try again."];
    } else {
        $tmpPath  = $_FILES["result_file"]["tmp_name"];
        $fileName = $_FILES["result_file"]["name"];

        try {
            $spreadsheet = IOFactory::load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $allRows = $sheet->toArray(null, true, true, false);

            // ── Locate the header row dynamically (looks for "Student ID" in col B) ──
            // Expected layout from Result_sheet.xlsx:
            //   Row 1: title, Row 2: "Code:", Row 3: "Course:", Row 4: blank,
            //   Row 5: column headers, Row 6+: data
            $headerRowIndex = null;
            foreach ($allRows as $i => $row) {
                $rowValues = array_map(fn($v) => is_string($v) ? trim($v) : $v, $row);
                if (in_array("Student ID", $rowValues, true)) {
                    $headerRowIndex = $i;
                    break;
                }
            }

            if ($headerRowIndex === null) {
                throw new Exception("Couldn't find the 'Student ID' header row in this file. Please use the standard Result Sheet template.");
            }

            $headers = array_map(fn($v) => is_string($v) ? trim($v) : $v, $allRows[$headerRowIndex]);
            $colIndex = array_flip($headers); // column name => index

            $required = ["Mod. Code", "Student ID", "Student Name", "Email", "CAT1", "CAT2", "Exam", "Total"];
            foreach ($required as $col) {
                if (!isset($colIndex[$col])) {
                    throw new Exception("Missing required column: '{$col}'. Please use the standard Result Sheet template.");
                }
            }

            $dataRows = array_slice($allRows, $headerRowIndex + 1, null, true);

            $module = null;
            foreach ($assignedModules as $m) {
                if ($m["module_code"] === $moduleCode) { $module = $m; break; }
            }

            $rowsTotal    = 0;
            $rowsInserted = 0;
            $rowsUpdated  = 0;
            $rowsSkipped  = 0;
            $skippedDetail = [];

            $stmtFindStudent = $pdo->prepare("SELECT student_ID FROM student_tb WHERE student_ID = ? OR student_email = ? LIMIT 1");
            $stmtUpsert = $pdo->prepare("
                INSERT INTO results_tb
                    (module_code, student_ID, year_no, sem_no, cat1_mk, cat2_mk, exam_mk, grade_point, letter_grade, final_total, status_retake_pass, created_at)
                VALUES
                    (:module_code, :student_ID, :year_no, :sem_no, :cat1, :cat2, :exam, :gp, :letter, :total, :status, NOW())
                ON DUPLICATE KEY UPDATE
                    cat1_mk = VALUES(cat1_mk),
                    cat2_mk = VALUES(cat2_mk),
                    exam_mk = VALUES(exam_mk),
                    grade_point = VALUES(grade_point),
                    letter_grade = VALUES(letter_grade),
                    final_total = VALUES(final_total),
                    status_retake_pass = VALUES(status_retake_pass)
            ");

            $pdo->beginTransaction();

            foreach ($dataRows as $rowNum => $row) {
                // Skip fully blank rows
                $rowModCode = $row[$colIndex["Mod. Code"]] ?? null;
                if ($rowModCode === null || trim((string)$rowModCode) === "") {
                    continue;
                }

                $rowsTotal++;
                $excelRowNum = $rowNum + 1; // 1-indexed, matches what a lecturer sees in Excel

                $sheetModCode = trim((string)$rowModCode);
                if (strcasecmp($sheetModCode, $moduleCode) !== 0) {
                    $rowsSkipped++;
                    $skippedDetail[] = "Row {$excelRowNum}: module code '{$sheetModCode}' does not match selected module '{$moduleCode}'.";
                    continue;
                }

                $rawStudentId = $row[$colIndex["Student ID"]] ?? "";
                $email        = trim((string)($row[$colIndex["Email"]] ?? ""));
                $normalizedId = normalizeStudentId($rawStudentId);

                $stmtFindStudent->execute([$normalizedId, $email]);
                $studentRow = $stmtFindStudent->fetch();

                if (!$studentRow) {
                    $rowsSkipped++;
                    $skippedDetail[] = "Row {$excelRowNum}: no matching student for ID '{$rawStudentId}' (normalized: '{$normalizedId}') / email '{$email}'.";
                    continue;
                }

                $studentID = $studentRow["student_ID"];

                $cat1  = (int)($row[$colIndex["CAT1"]] ?? 0);
                $cat2  = (int)($row[$colIndex["CAT2"]] ?? 0);
                $exam  = (int)($row[$colIndex["Exam"]] ?? 0);
                $total = isset($colIndex["Total"]) && $row[$colIndex["Total"]] !== null
                    ? (int)$row[$colIndex["Total"]]
                    : ($cat1 + $cat2 + $exam);

                $derived = deriveGrade($gradeScale, $total);
                $status  = $derived["letter_grade"] === "0" ? "Retake" : "Pass";

                // Check if a result already existed (for insert vs update count)
                $existsStmt = $pdo->prepare("SELECT ID FROM results_tb WHERE module_code = ? AND student_ID = ?");
                $existsStmt->execute([$moduleCode, $studentID]);
                $existedBefore = (bool)$existsStmt->fetch();

                $stmtUpsert->execute([
                    ":module_code" => $moduleCode,
                    ":student_ID"  => $studentID,
                    ":year_no"     => $module["year_no"],
                    ":sem_no"      => $module["sem_no"],
                    ":cat1"        => $cat1,
                    ":cat2"        => $cat2,
                    ":exam"        => $exam,
                    ":gp"          => $derived["grade_point"],
                    ":letter"      => $derived["letter_grade"],
                    ":total"       => $total,
                    ":status"      => $status,
                ]);

                if ($existedBefore) {
                    $rowsUpdated++;
                } else {
                    $rowsInserted++;
                }

                // GPA and CGPA are now recalculated automatically by the
                // trg_results_after_insert / trg_results_after_update triggers
                // on results_tb (added in gpa_cgpa_automation_migration.sql).
                // No manual CALL needed here.
            }

            $pdo->commit();

            // ── Log the upload for audit purposes ──
            $pdo->prepare("
                INSERT INTO result_upload_log_tb
                    (lecturer_ID, module_code, file_name, rows_total, rows_inserted, rows_updated, rows_skipped, skipped_detail)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $lecturerID, $moduleCode, $fileName,
                $rowsTotal, $rowsInserted, $rowsUpdated, $rowsSkipped,
                implode("\n", $skippedDetail),
            ]);

            $uploadResult = [
                "success"  => true,
                "total"    => $rowsTotal,
                "inserted" => $rowsInserted,
                "updated"  => $rowsUpdated,
                "skipped"  => $rowsSkipped,
                "skippedDetail" => $skippedDetail,
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $uploadResult = ["error" => "Could not process file: " . $e->getMessage()];
        }
    }
}

// ─── Recent uploads for this lecturer (for the activity panel) ────────────────
$stmtRecent = $pdo->prepare("
    SELECT module_code, file_name, rows_total, rows_inserted, rows_updated, rows_skipped, uploaded_at
    FROM   result_upload_log_tb
    WHERE  lecturer_ID = ?
    ORDER  BY uploaded_at DESC
    LIMIT  5
");
$stmtRecent->execute([$lecturerID]);
$recentUploads = $stmtRecent->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results – Cavendish Lecturer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #fff; }

        .site-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.2em 1.5rem; background: #213769;
        }
        .site-header h4 {
            color: #fff; font-family: 'Montserrat', sans-serif; letter-spacing: 0.2rem;
            font-weight: 600; font-size: 0.85rem; text-transform: uppercase;
        }
        .site-header h1 { color: #fff; font-size: 1.1rem; font-weight: 700; margin-top: 0.2rem; }
        .logout-link { color: #cdd6ef; font-size: 0.82rem; text-decoration: none; }
        .logout-link:hover { text-decoration: underline; }

        .page-wrap { max-width: 880px; margin: 1.75rem auto; padding: 0 1.5rem 3rem; }

        .lecturer-row {
            display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem;
        }
        .lecturer-name { font-weight: 700; font-size: 1rem; }
        .lecturer-email { font-size: 0.88rem; color: #555; }

        .card { border: 1px solid #999; border-radius: 6px; margin-bottom: 1.75rem; overflow: hidden; }
        .card-header {
            background: #121e38; color: #fff; font-weight: 700; font-size: 0.92rem;
            padding: 0.65rem 1rem;
        }
        .card-body { background: #f3f4f7; padding: 1.1rem 1rem 1.4rem; }

        .alert { padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.88rem; margin-bottom: 1rem; }
        .alert-error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }
        .alert-success { background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }

        .field-group { display: flex; flex-direction: column; gap: 0.4rem; margin-bottom: 1.1rem; }
        label { font-size: 0.82rem; font-weight: 600; color: #2b3550; }
        select, input[type="file"] {
            padding: 0.55rem 0.7rem; border: 1.5px solid #c9cdda; border-radius: 6px;
            font-family: 'Inter', sans-serif; font-size: 0.88rem; background: #fff;
        }

        .module-hint { font-size: 0.78rem; color: #666; margin-top: 0.25rem; }

        button.btn-upload {
            padding: 0.6rem 1.4rem; border: none; border-radius: 20px; background: #213769;
            color: #fff; font-weight: 600; font-size: 0.88rem; cursor: pointer; margin-top: 0.5rem;
        }
        button.btn-upload:hover { background: #121e38; }

        table.summary-table { width: 100%; border-collapse: collapse; margin-top: 0.5rem; }
        table.summary-table th, table.summary-table td {
            border: 1px solid #ccc; padding: 0.45rem 0.7rem; font-size: 0.85rem; text-align: left;
        }
        table.summary-table th { background: #e3e6ee; }

        .skipped-list { margin-top: 0.8rem; font-size: 0.82rem; color: #8a4b00; background: #fff7e6; border: 1px solid #f0d49a; border-radius: 6px; padding: 0.75rem 0.9rem; max-height: 220px; overflow-y: auto; }
        .skipped-list div { padding: 0.15rem 0; }

        .empty { text-align: center; padding: 1.5rem; color: #888; font-size: 0.88rem; }

        .tab-nav { display: flex; gap: 0.35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn {
            padding: .8rem 1.1rem .7rem; border: none; background: transparent;
            font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,0.68);
            text-decoration: none; border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }
    </style>
</head>
<body>

<header class="site-header">
    <div>
        <h4>Cavendish University</h4>
        <h1>Lecturer Portal — Result Uploads</h1>
    </div>
    <a class="logout-link" href="LecturerLogout.php">Log out</a>
</header>

<nav class="tab-nav">
    <span class="tab-btn active">Upload Results</span>
    <a class="tab-btn" href="LecturerReportsStatus.php">Module Reports</a>
</nav>

<main class="page-wrap">

    <div class="lecturer-row">
        <div>
            <div class="lecturer-name"><?= htmlspecialchars($lecturer["lecturer_name"]) ?></div>
            <div class="lecturer-email"><?= htmlspecialchars($lecturer["lecturer_email"]) ?></div>
        </div>
    </div>

    <?php if (empty($assignedModules)): ?>
        <p class="empty">You are not currently assigned to any modules. Contact the academic office to be assigned a module before uploading results.</p>
    <?php else: ?>

    <!-- ── Upload result ── -->
    <div class="card">
        <div class="card-header">Upload Result Sheet</div>
        <div class="card-body">

            <?php if ($uploadResult && isset($uploadResult["error"])): ?>
                <div class="alert alert-error"><?= htmlspecialchars($uploadResult["error"]) ?></div>
            <?php elseif ($uploadResult && !empty($uploadResult["success"])): ?>
                <div class="alert alert-success">
                    Upload complete — <?= $uploadResult["inserted"] ?> new result(s) added,
                    <?= $uploadResult["updated"] ?> updated, <?= $uploadResult["skipped"] ?> skipped.
                </div>

                <table class="summary-table">
                    <tr><th>Total rows in file</th><td><?= $uploadResult["total"] ?></td></tr>
                    <tr><th>Inserted</th><td><?= $uploadResult["inserted"] ?></td></tr>
                    <tr><th>Updated</th><td><?= $uploadResult["updated"] ?></td></tr>
                    <tr><th>Skipped</th><td><?= $uploadResult["skipped"] ?></td></tr>
                </table>

                <?php if (!empty($uploadResult["skippedDetail"])): ?>
                <div class="skipped-list">
                    <strong>Skipped row details:</strong>
                    <?php foreach ($uploadResult["skippedDetail"] as $line): ?>
                        <div><?= htmlspecialchars($line) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <form action="LecturerUploadInterface.php" method="post" enctype="multipart/form-data">
                <div class="field-group">
                    <label for="module_code">Module</label>
                    <select name="module_code" id="module_code" required>
                        <?php foreach ($assignedModules as $m): ?>
                        <option value="<?= htmlspecialchars($m["module_code"]) ?>"
                            <?= $selectedModule === $m["module_code"] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($m["module_code"]) ?> — <?= htmlspecialchars($m["module_name"]) ?>
                            (Year <?= $m["year_no"] ?>, Sem <?= $m["sem_no"] ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="module-hint">Only modules assigned to you appear here.</p>
                </div>

                <div class="field-group">
                    <label for="result_file">Result sheet (.xlsx)</label>
                    <input type="file" name="result_file" id="result_file" accept=".xlsx" required>
                    <p class="module-hint">Use the standard Result Sheet template — columns: Mod. Code, Student ID, Student Name, Email, CAT1, CAT2, Exam, Total.</p>
                </div>

                <button type="submit" class="btn-upload">Upload &amp; Process</button>
            </form>
        </div>
    </div>

    <!-- ── Recent uploads ── -->
    <div class="card">
        <div class="card-header">Recent Uploads</div>
        <div class="card-body">
            <?php if (empty($recentUploads)): ?>
                <p class="empty">No uploads yet.</p>
            <?php else: ?>
            <table class="summary-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Module</th>
                        <th>File</th>
                        <th>Inserted</th>
                        <th>Updated</th>
                        <th>Skipped</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentUploads as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u["uploaded_at"]) ?></td>
                        <td><?= htmlspecialchars($u["module_code"]) ?></td>
                        <td><?= htmlspecialchars($u["file_name"]) ?></td>
                        <td><?= (int)$u["rows_inserted"] ?></td>
                        <td><?= (int)$u["rows_updated"] ?></td>
                        <td><?= (int)$u["rows_skipped"] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

</main>

</body>
</html>