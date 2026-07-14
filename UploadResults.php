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

// ─── Student + program ────────────────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code, p.program_name
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
$programCode = $student["program_code"];

require __DIR__ . "/vendor/autoload.php";

// ══════════════════════════════════════════════════════════════════════════════
// HELPERS
// ══════════════════════════════════════════════════════════════════════════════

/** Normalise a module name for tolerant matching (case/space-insensitive). */
function normName(string $s): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
}

/**
 * Extract (module_name, score) pairs from the faculty PDF text.
 * The statement's text layer is column-ordered: the SCORE column and the
 * MODULE NAME column both run in the same term order (Sem 1 → Sem 6), so we
 * zip them positionally. Module CODES are in a different order and are only
 * used later as a cross-check.
 */
function extractPairsFromPdf(string $path): array {
    $text = (new \Smalot\PdfParser\Parser())->parseFile($path)->getText();

    // Scores, in reading order
    preg_match_all('/(\d{1,3})%/', $text, $sc);
    $scores = array_map('intval', $sc[1]);

    // Module names live between the "GRADE" header and the "SCORE" header
    $region = $text;
    if (($a = strpos($region, 'GRADE')) !== false) { $region = substr($region, $a + 5); }
    if (($b = strpos($region, 'SCORE')) !== false) { $region = substr($region, 0, $b); }

    $names = [];
    foreach (preg_split('/\R/', $region) as $line) {
        $line = trim($line);
        if ($line === '' || stripos($line, 'Term:') === 0) { continue; }
        // Strip a trailing letter grade (A, B+, B, C+, C, D+, D, 0)
        $name = preg_replace('/\s+(A|B\+|B|C\+|C|D\+|D|0)$/u', '', $line);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name !== '') { $names[] = $name; }
    }

    $pairs = [];
    foreach ($names as $i => $nm) {
        $pairs[] = ["name" => $nm, "score" => $scores[$i] ?? null];
    }
    return $pairs;
}

/** Look up letter grade + grade point for a score from grade_system. */
function gradeFromScore(int $score, array $grades): array {
    foreach ($grades as $g) {
        if ($score >= (int)$g["min_mark"] && $score <= (int)$g["max_mark"]) {
            return ["letter_grade" => $g["letter_grade"], "grade_point" => $g["grade_point"]];
        }
    }
    return ["letter_grade" => "0", "grade_point" => "0.00"];
}

// Curriculum for this program: name → module row, and code → module row
$stmtCur = $pdo->prepare("
    SELECT module_code, module_name, year_no, sem_no, credit_unit
    FROM   module_tb WHERE program_code = ?
");
$stmtCur->execute([$programCode]);
$moduleByName = [];
$moduleByCode = [];
foreach ($stmtCur->fetchAll() as $m) {
    $moduleByName[normName($m["module_name"])] = $m;
    $moduleByCode[$m["module_code"]] = $m;
}

// Grade bands
$grades = $pdo->query("SELECT min_mark, max_mark, letter_grade, grade_point FROM grade_system")->fetchAll();

// ══════════════════════════════════════════════════════════════════════════════
// PHASE ROUTING
// ══════════════════════════════════════════════════════════════════════════════
$phase       = "upload";   // upload | preview | done
$error       = "";
$previewRows = [];         // [ ['code'=>, 'name'=>, 'score'=>, 'year'=>, 'sem'=>, 'grade'=>, 'gp'=>, 'matched'=>bool ] ]
$pdfStudentNo = "";
$summary     = null;

// ── PHASE 3: SAVE confirmed rows ──────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "save") {
    $codes  = $_POST["module_code"] ?? [];
    $scores = $_POST["score"]       ?? [];

    $toSave = [];
    foreach ($codes as $i => $code) {
        $code  = trim($code);
        $score = (int)($scores[$i] ?? -1);
        if ($code === "" || !isset($moduleByCode[$code])) { continue; } // unmatched/blank → skip
        if ($score < 0 || $score > 100)                   { continue; } // invalid score → skip
        // Last write wins if a code appears twice
        $toSave[$code] = $score;
    }

    if (empty($toSave)) {
        $error = "No valid rows to save. Please check the scores and module selections.";
        $phase = "upload";
    } else {
        $inserted = 0; $updated = 0;
        $ins = $pdo->prepare("
            INSERT INTO results_tb
                (module_code, student_ID, year_no, sem_no, cat1_mk, cat2_mk, exam_mk,
                 grade_point, letter_grade, final_total, status_retake_pass, created_at)
            VALUES (?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                year_no            = VALUES(year_no),
                sem_no             = VALUES(sem_no),
                grade_point        = VALUES(grade_point),
                letter_grade       = VALUES(letter_grade),
                final_total        = VALUES(final_total),
                status_retake_pass = VALUES(status_retake_pass)
        ");

        try {
            $pdo->beginTransaction();
            foreach ($toSave as $code => $score) {
                $m     = $moduleByCode[$code];
                $g     = gradeFromScore($score, $grades);
                $status = $score >= 50 ? "Pass" : "Retake";
                $ins->execute([
                    $code, $studentID, (int)$m["year_no"], (int)$m["sem_no"],
                    $g["grade_point"], $g["letter_grade"], $score, $status,
                ]);
                // MySQL: rowCount() is 1 for a fresh insert, 2 for an update
                if ($ins->rowCount() === 1) { $inserted++; } else { $updated++; }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = "Could not save results: " . $e->getMessage();
            $phase = "upload";
        }

        if ($error === "") {
            $summary = ["inserted" => $inserted, "updated" => $updated, "total" => count($toSave)];
            $phase   = "done";
        }
    }
}

// ── PHASE 2: PARSE uploaded PDF → editable preview ────────────────────────────
elseif ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_FILES["statement"])) {
    $f = $_FILES["statement"];
    if ($f["error"] !== UPLOAD_ERR_OK) {
        $error = "Upload failed (error code {$f['error']}). Please try again.";
    } elseif ($f["size"] > 8 * 1024 * 1024) {
        $error = "That file is larger than 8 MB. Please upload the original statement PDF.";
    } elseif (strtolower(pathinfo($f["name"], PATHINFO_EXTENSION)) !== "pdf") {
        $error = "Please upload a PDF file (the statement you received from the faculty).";
    } else {
        try {
            $text  = (new \Smalot\PdfParser\Parser())->parseFile($f["tmp_name"])->getText();
            if (preg_match('/\b(\d{3}-\d{3})\b/', $text, $mn)) { $pdfStudentNo = $mn[1]; }

            $pairs = extractPairsFromPdf($f["tmp_name"]);
            foreach ($pairs as $p) {
                if ($p["score"] === null) { continue; }
                $m = $moduleByName[normName($p["name"])] ?? null;
                $g = gradeFromScore((int)$p["score"], $grades);
                $previewRows[] = [
                    "code"    => $m["module_code"]  ?? "",
                    "name"    => $p["name"],
                    "score"   => (int)$p["score"],
                    "year"    => $m["year_no"]      ?? null,
                    "sem"     => $m["sem_no"]        ?? null,
                    "grade"   => $g["letter_grade"],
                    "gp"      => $g["grade_point"],
                    "matched" => $m !== null,
                ];
            }

            if (empty($previewRows)) {
                $error = "Couldn't read any results from that PDF. It may be a scanned image rather than a text document — try the original digital copy from the faculty.";
            } else {
                $phase = "preview";
            }
        } catch (Throwable $e) {
            $error = "Couldn't read that PDF: " . $e->getMessage();
        }
    }
}

// Module list for the manual "pick a code" dropdown on unmatched rows
$allModules = array_values($moduleByCode);
usort($allModules, fn($a, $b) => [$a["sem_no"], $a["module_code"]] <=> [$b["sem_no"], $b["module_code"]]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Results – Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #fff; }

        .site-header { display: flex; align-items: center; gap: .9rem; padding: 1rem 1.5rem; background: #213769; border-bottom: 1px solid #16213f; }
        .site-header .crest { width: 2.6rem; height: 2.6rem; object-fit: contain; background: #fff; border-radius: 6px; padding: 2px; }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name { font-weight: 600; font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; color: #d9c581; }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }

        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn { padding: .8rem 1.1rem .7rem; border: none; background: transparent; font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,.68); text-decoration: none; border-bottom: 3px solid transparent; }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        .page-wrap { max-width: 920px; margin: 1.5rem auto; padding: 0 1.5rem 3rem; }
        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.25rem; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; }

        .card { border: 1px solid #999; border-radius: 6px; margin-bottom: 1.5rem; overflow: hidden; }
        .card-header { background: #121e38; color: #fff; font-weight: 700; font-size: .92rem; padding: .65rem 1rem; display: flex; justify-content: space-between; flex-wrap: wrap; gap: .5rem; }
        .card-sub { font-size: .8rem; font-weight: 400; opacity: .85; }
        .card-body { background: #eef0f4; padding: 1.2rem 1rem; }

        .drop-zone { background: #fff; border: 2px dashed #a9b1c6; border-radius: 8px; padding: 2rem 1rem; text-align: center; }
        .drop-zone input[type=file] { margin: .8rem 0; font-size: .9rem; }
        .hint { font-size: .82rem; color: #555; line-height: 1.5; }

        .btn { padding: .55rem 1.4rem; border: none; border-radius: 20px; font-weight: 600; font-size: .88rem; cursor: pointer; font-family: inherit; }
        .btn-primary { background: #213769; color: #fff; }
        .btn-primary:hover { background: #1a2c54; }
        .btn-ghost { background: #f0f0f0; color: #333; border: 1px solid #bbb; }
        .btn-ghost:hover { background: #e2e2e2; }

        .banner { padding: .75rem 1rem; border-radius: 6px; font-size: .85rem; line-height: 1.5; margin-bottom: 1rem; }
        .banner-error { background: #fef2f2; border: 1px solid #fca5a5; border-left: 4px solid #dc2626; color: #7f1d1d; }
        .banner-warn  { background: #fffbeb; border: 1px solid #fde68a; border-left: 4px solid #f59e0b; color: #92400e; }
        .banner-ok    { background: #ecfdf5; border: 1px solid #a7f3d0; border-left: 4px solid #10b981; color: #065f46; }

        table { width: 100%; border-collapse: collapse; background: #fff; }
        thead th { background: #121e38; color: #fff; font-weight: 700; font-size: .78rem; padding: .5rem .6rem; text-align: left; }
        tbody td { padding: .4rem .6rem; border-bottom: 1px solid #d9d9d9; font-size: .84rem; }
        tbody tr.row-unmatched td { background: #fff7ed; }
        .score-input { width: 4.5rem; padding: .3rem .4rem; border: 1.5px solid #c9cdda; border-radius: 5px; font-family: inherit; font-size: .84rem; text-align: center; }
        .code-select { padding: .3rem .4rem; border: 1.5px solid #f0b072; border-radius: 5px; font-family: inherit; font-size: .82rem; }
        .code-fixed { font-weight: 700; }
        .tag { display: inline-block; font-size: .72rem; font-weight: 700; padding: .1rem .5rem; border-radius: 10px; }
        .tag-ok { background: #e0e7ff; color: #3730a3; }
        .tag-warn { background: #fde68a; color: #92400e; }
        .actions { display: flex; gap: .7rem; justify-content: flex-end; margin-top: 1rem; flex-wrap: wrap; }
        .muted { font-size: .8rem; color: #666; margin-top: .3rem; }
    </style>
</head>
<body>

<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Goal Planning</span>
    </div>
</header>

<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Career &amp; Module Planner</a>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <span class="tab-btn active">Upload Results</span>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <?php if ($error !== ""): ?>
        <div class="banner banner-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($phase === "upload"): ?>
    <!-- ═══════════ PHASE 1: UPLOAD ═══════════ -->
    <div class="card">
        <div class="card-header">
            Upload Your Provisional Results Statement
            <span class="card-sub"><?= htmlspecialchars($student["program_name"]) ?></span>
        </div>
        <div class="card-body">
            <p class="hint" style="margin-bottom:1rem;">
                Upload the <strong>PDF provisional results statement</strong> you received from the faculty.
                We'll read the module scores from it and let you review everything before saving.
                Your GPA and CGPA are recalculated automatically once you save.
            </p>
            <form method="POST" enctype="multipart/form-data">
                <div class="drop-zone">
                    <div style="font-size:2rem;">📄</div>
                    <input type="file" name="statement" accept="application/pdf,.pdf" required>
                    <div class="hint">PDF only · up to 8&nbsp;MB</div>
                </div>
                <div class="actions">
                    <button type="submit" class="btn btn-primary">Read Statement &rarr;</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($phase === "preview"):
        $matchedCount   = count(array_filter($previewRows, fn($r) => $r["matched"]));
        $unmatchedCount = count($previewRows) - $matchedCount;
    ?>
    <!-- ═══════════ PHASE 2: PREVIEW / CONFIRM ═══════════ -->
    <?php if ($pdfStudentNo !== "" && $pdfStudentNo !== $student["student_ID"]): ?>
        <div class="banner banner-warn">
            Heads up: this statement shows student number <strong><?= htmlspecialchars($pdfStudentNo) ?></strong>,
            but you are signed in as <strong><?= htmlspecialchars($student["student_ID"]) ?></strong>.
            These results will be saved to <strong>your</strong> account. Make sure this is your own statement.
        </div>
    <?php endif; ?>

    <?php if ($unmatchedCount > 0): ?>
        <div class="banner banner-warn">
            <?= $unmatchedCount ?> module<?= $unmatchedCount === 1 ? "" : "s" ?> couldn't be matched automatically.
            Pick the correct module code for the highlighted row<?= $unmatchedCount === 1 ? "" : "s" ?> (or leave blank to skip).
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            Review &amp; Confirm — <?= $matchedCount ?> of <?= count($previewRows) ?> modules matched
            <span class="card-sub">Edit any score, then save</span>
        </div>
        <div class="card-body">
            <form method="POST" onsubmit="return confirm('Save these results to your record? Your GPA/CGPA will be recalculated.');">
                <input type="hidden" name="action" value="save">
                <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width:130px">Module Code</th>
                            <th>Module (from statement)</th>
                            <th style="width:110px">Score (%)</th>
                            <th style="width:70px">Grade</th>
                            <th style="width:70px">GP</th>
                            <th style="width:90px">Yr / Sem</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewRows as $i => $r): ?>
                        <tr class="<?= $r["matched"] ? "" : "row-unmatched" ?>">
                            <td>
                                <?php if ($r["matched"]): ?>
                                    <span class="code-fixed"><?= htmlspecialchars($r["code"]) ?></span>
                                    <input type="hidden" name="module_code[]" value="<?= htmlspecialchars($r["code"]) ?>">
                                <?php else: ?>
                                    <select class="code-select" name="module_code[]">
                                        <option value="">— skip —</option>
                                        <?php foreach ($allModules as $mo): ?>
                                        <option value="<?= htmlspecialchars($mo["module_code"]) ?>">
                                            <?= htmlspecialchars($mo["module_code"]) ?> — <?= htmlspecialchars($mo["module_name"]) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($r["name"]) ?>
                                <?php if ($r["matched"]): ?>
                                    <span class="tag tag-ok">matched</span>
                                <?php else: ?>
                                    <span class="tag tag-warn">needs a code</span>
                                <?php endif; ?>
                            </td>
                            <td><input class="score-input" type="number" name="score[]" min="0" max="100" value="<?= (int)$r["score"] ?>" required></td>
                            <td><?= htmlspecialchars($r["grade"]) ?></td>
                            <td><?= htmlspecialchars($r["gp"]) ?></td>
                            <td><?= $r["matched"] ? "Y{$r['year']} · S{$r['sem']}" : "—" ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <p class="muted">Grade &amp; grade point are derived automatically from the score. Modules left as “skip” won't be saved.</p>
                <div class="actions">
                    <a class="btn btn-ghost" href="UploadResults.php">Cancel</a>
                    <button type="submit" class="btn btn-primary">Confirm &amp; Save Results</button>
                </div>
            </form>
        </div>
    </div>

    <?php elseif ($phase === "done"): ?>
    <!-- ═══════════ PHASE 3: DONE ═══════════ -->
    <div class="banner banner-ok">
        <strong>Results saved.</strong>
        <?= $summary["inserted"] ?> added<?php if ($summary["updated"] > 0): ?>, <?= $summary["updated"] ?> updated<?php endif; ?>.
        Your GPA and CGPA have been recalculated.
    </div>
    <div class="card">
        <div class="card-header">What next?</div>
        <div class="card-body">
            <div class="actions" style="justify-content:flex-start;">
                <a class="btn btn-primary" href="ExamResultInterface.php">View My Results</a>
                <a class="btn btn-ghost" href="UploadResults.php">Upload Another Statement</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

</main>

</body>
</html>
