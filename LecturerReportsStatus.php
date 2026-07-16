<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Auth: relies on the session set at lecturer login ───────────────────────
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

$allowedStatuses = ["Submitted", "Reviewing", "Resolved", "Rejected"];
$updateMessage = null;

// ─── Grade scale (to derive letter/GP for a proposed corrected mark) ──────────
$grades = $pdo->query("SELECT min_mark, max_mark, letter_grade, grade_point FROM grade_system")->fetchAll();
function gradeFromScore(int $score, array $grades): array {
    foreach ($grades as $g) {
        if ($score >= (int)$g["min_mark"] && $score <= (int)$g["max_mark"]) {
            return ["letter_grade" => $g["letter_grade"], "grade_point" => $g["grade_point"]];
        }
    }
    return ["letter_grade" => "0", "grade_point" => "0.00"];
}

// ─── Handle a status update submission ────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["report_ID"])) {
    $reportID  = (int)$_POST["report_ID"];
    $newStatus = $_POST["status"] ?? "";
    $note      = trim($_POST["lecturer_note"] ?? "");
    $newMarkRaw = trim($_POST["new_mark"] ?? "");

    if (!in_array($newStatus, $allowedStatuses, true)) {
        $updateMessage = ["type" => "error", "text" => "Invalid status selected."];
    } else {
        // Only allow updating reports that actually belong to this lecturer's modules
        $stmtOwns = $pdo->prepare("
            SELECT report_ID, student_ID, module_code, category
            FROM   module_report_tb
            WHERE  report_ID = ? AND lecturer_ID = ?
        ");
        $stmtOwns->execute([$reportID, $lecturerID]);
        $ownRow = $stmtOwns->fetch();

        if (!$ownRow) {
            $updateMessage = ["type" => "error", "text" => "You can only update reports assigned to your modules."];
        } else {
            // 1) Update the report's status + note (always)
            $pdo->prepare("
                UPDATE module_report_tb SET status = ?, lecturer_note = ?
                WHERE report_ID = ? AND lecturer_ID = ?
            ")->execute([$newStatus, $note !== '' ? $note : null, $reportID, $lecturerID]);

            $msgText = "Report #{$reportID} updated to \"{$newStatus}\".";

            // 2) Optionally propose a corrected COMPONENT mark for the reported
            //    category (CAT1 / CAT2 / Exam) → goes to admin for approval.
            //    The final total is recomputed as CAT1 + CAT2 + Exam.
            if ($newMarkRaw !== "" && is_numeric($newMarkRaw)) {
                $category  = $ownRow["category"];
                $catColumn = ['CAT1' => 'cat1_mk', 'CAT2' => 'cat2_mk', 'Exam' => 'exam_mk'];
                $catMax    = ['CAT1' => 20,        'CAT2' => 20,        'Exam' => 60];

                if (!isset($catColumn[$category])) {
                    $updateMessage = ["type" => "error", "text" => "This report's category can't be mapped to a mark component."];
                } else {
                    $newComp = (int)$newMarkRaw;
                    $maxC    = $catMax[$category];
                    if ($newComp < 0 || $newComp > $maxC) {
                        $updateMessage = ["type" => "error", "text" => "The corrected {$category} mark must be between 0 and {$maxC}."];
                    } else {
                        // Current recorded breakdown for this student + module
                        $rs = $pdo->prepare("SELECT cat1_mk, cat2_mk, exam_mk, final_total FROM results_tb WHERE student_ID = ? AND module_code = ? LIMIT 1");
                        $rs->execute([$ownRow["student_ID"], $ownRow["module_code"]]);
                        $curRow = $rs->fetch();

                        if (!$curRow) {
                            $updateMessage = ["type" => "error", "text" => "No existing result was found for that module, so there's nothing to correct."];
                        } else {
                            $col     = $catColumn[$category];
                            $oldComp = (int)$curRow[$col];

                            // Recompute total = CAT1 + CAT2 + Exam with the corrected component swapped in
                            $c1 = (int)$curRow["cat1_mk"]; $c2 = (int)$curRow["cat2_mk"]; $ex = (int)$curRow["exam_mk"];
                            if     ($category === 'CAT1') { $c1 = $newComp; }
                            elseif ($category === 'CAT2') { $c2 = $newComp; }
                            else                          { $ex = $newComp; }
                            $newTotal = max(0, min(100, $c1 + $c2 + $ex));
                            $oldTotal = (int)$curRow["final_total"];

                            if ($oldComp === $newComp) {
                                $updateMessage = ["type" => "success", "text" => $msgText . " ({$category} mark left unchanged.)"];
                            } else {
                                $g = gradeFromScore($newTotal, $grades);
                                // Replace any earlier pending correction for this report
                                $pdo->prepare("DELETE FROM mark_correction_tb WHERE report_ID = ? AND status = 'Pending'")->execute([$reportID]);
                                $pdo->prepare("
                                    INSERT INTO mark_correction_tb
                                        (report_ID, student_ID, module_code, lecturer_ID, category,
                                         old_component, new_component, old_total, new_total,
                                         new_grade_point, new_letter_grade, status, lecturer_note, created_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, NOW())
                                ")->execute([
                                    $reportID, $ownRow["student_ID"], $ownRow["module_code"], $lecturerID, $category,
                                    $oldComp, $newComp, $oldTotal, $newTotal,
                                    $g["grade_point"], $g["letter_grade"],
                                    $note !== '' ? $note : null,
                                ]);
                                $updateMessage = ["type" => "success", "text" =>
                                    $msgText . " Proposed {$category} change {$oldComp} → {$newComp} (total {$oldTotal}% → {$newTotal}%, {$g['letter_grade']}) sent to the admin for approval."];
                            }
                        }
                    }
                }
            } else {
                $updateMessage = ["type" => "success", "text" => $msgText];
            }
        }
    }
}

// ─── Fetch all reports for this lecturer's modules, newest first ─────────────
$stmtReports = $pdo->prepare("
    SELECT mr.report_ID, mr.module_code, m.module_name, mr.category, mr.message,
           mr.status, mr.lecturer_note, mr.created_at, mr.updated_at,
           s.student_name, s.student_ID,
           res.final_total AS current_total, res.letter_grade AS current_grade,
           res.cat1_mk AS c1, res.cat2_mk AS c2, res.exam_mk AS ex
    FROM   module_report_tb mr
    JOIN   module_tb  m   ON mr.module_code = m.module_code
    JOIN   student_tb s   ON mr.student_ID  = s.student_ID
    LEFT JOIN results_tb res ON res.student_ID = mr.student_ID AND res.module_code = mr.module_code
    WHERE  mr.lecturer_ID = ?
    ORDER  BY
        CASE mr.status WHEN 'Submitted' THEN 0 WHEN 'Reviewing' THEN 1 ELSE 2 END,
        mr.created_at DESC
");
$stmtReports->execute([$lecturerID]);
$reports = $stmtReports->fetchAll();

// ─── Latest mark-correction per report (to show its approval state) ──────────
$corrByReport = [];
$reportIDs = array_column($reports, 'report_ID');
if (!empty($reportIDs)) {
    $in = implode(',', array_fill(0, count($reportIDs), '?'));
    $cs = $pdo->prepare("SELECT * FROM mark_correction_tb WHERE report_ID IN ($in) ORDER BY created_at DESC");
    $cs->execute($reportIDs);
    foreach ($cs->fetchAll() as $c) {
        if (!isset($corrByReport[$c['report_ID']])) { $corrByReport[$c['report_ID']] = $c; } // keep latest
    }
}

function statusClass(string $status): string {
    return match($status) {
        'Submitted' => 'status-submitted',
        'Reviewing' => 'status-reviewing',
        'Resolved'  => 'status-resolved',
        'Rejected'  => 'status-rejected',
        default     => 'status-submitted',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Reports – Cavendish Lecturer Portal</title>
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

        .tab-nav { display: flex; gap: 0.35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn {
            padding: .8rem 1.1rem .7rem; border: none; background: transparent;
            font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,0.68);
            text-decoration: none; border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        .page-wrap { max-width: 900px; margin: 1.75rem auto; padding: 0 1.5rem 3rem; }

        .lecturer-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem; }
        .lecturer-name { font-weight: 700; font-size: 1rem; }
        .lecturer-email { font-size: 0.88rem; color: #555; }

        .alert { padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.88rem; margin-bottom: 1.2rem; }
        .alert-error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }
        .alert-success { background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }

        .empty { text-align: center; padding: 2.5rem 1rem; color: #888; }

        .report-card { background: #f3f4f7; border: 1px solid #999; border-radius: 8px; padding: 1.1rem 1.3rem; margin-bottom: 1rem; }
        .report-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; flex-wrap: wrap; margin-bottom: .6rem; }
        .report-module { font-weight: 700; font-size: .95rem; color: #16213f; }
        .report-meta { font-size: .8rem; color: #666; margin-top: .15rem; }

        .status-pill { font-size: .76rem; font-weight: 700; padding: .25rem .7rem; border-radius: 14px; white-space: nowrap; }
        .status-submitted { background: #e0e7ff; color: #3730a3; }
        .status-reviewing { background: #fef3c7; color: #92400e; }
        .status-resolved  { background: #d1fae5; color: #065f46; }
        .status-rejected  { background: #fde2e2; color: #991b1b; }

        .report-message {
            font-size: .85rem; color: #222; background: #fff;
            border-radius: 6px; padding: .7rem .9rem; margin-bottom: .9rem; line-height: 1.5;
        }
        .report-message .label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #555; display: block; margin-bottom: .3rem; }

        .update-form { display: flex; flex-wrap: wrap; gap: .7rem; align-items: flex-start; }
        .update-form .field { display: flex; flex-direction: column; gap: .35rem; }
        .update-form select, .update-form textarea {
            padding: .5rem .65rem; border: 1.5px solid #c9cdda; border-radius: 6px;
            font-family: 'Inter', sans-serif; font-size: .85rem;
        }
        .update-form textarea { min-height: 2.6rem; resize: vertical; width: 100%; }
        .update-form .note-field { flex: 1 1 220px; }
        .update-form label { font-size: .76rem; font-weight: 600; color: #2b3550; }

        .btn-update {
            padding: .5rem 1.2rem; border: none; border-radius: 18px; background: #213769;
            color: #fff; font-weight: 600; font-size: .82rem; cursor: pointer; align-self: flex-end;
        }
        .btn-update:hover { background: #121e38; }

        .update-form input.mark-input {
            padding: .5rem .65rem; border: 1.5px solid #c9cdda; border-radius: 6px;
            font-family: 'Inter', sans-serif; font-size: .85rem; width: 6rem;
        }

        .mark-row { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .9rem; font-size: .82rem; color: #444; }
        .mark-current strong { color: #16213f; }
        .corr-badge { font-size: .74rem; font-weight: 700; padding: .2rem .6rem; border-radius: 12px; }
        .corr-pending  { background: #fef3c7; color: #92400e; }
        .corr-approved { background: #d1fae5; color: #065f46; }
        .corr-rejected { background: #fde2e2; color: #991b1b; }
    </style>
</head>
<body>

<header class="site-header">
    <div>
        <h4>Cavendish University</h4>
        <h1>Lecturer Portal — Module Reports</h1>
    </div>
    <a class="logout-link" href="LecturerLogout.php">Log out</a>
</header>

<nav class="tab-nav">
    <a class="tab-btn" href="LecturerUploadInterface.php">Upload Results</a>
    <span class="tab-btn active">Module Reports</span>
</nav>

<main class="page-wrap">

    <div class="lecturer-row">
        <div>
            <div class="lecturer-name"><?= htmlspecialchars($lecturer["lecturer_name"]) ?></div>
            <div class="lecturer-email"><?= htmlspecialchars($lecturer["lecturer_email"]) ?></div>
        </div>
    </div>

    <?php if ($updateMessage): ?>
        <div class="alert alert-<?= $updateMessage["type"] ?>"><?= htmlspecialchars($updateMessage["text"]) ?></div>
    <?php endif; ?>

    <?php if (empty($reports)): ?>
        <p class="empty">No module issue reports have been submitted for your modules yet.</p>
    <?php else: ?>
        <?php foreach ($reports as $r):
            $corr = $corrByReport[$r['report_ID']] ?? null;
            $catColMap = ['CAT1' => 'c1', 'CAT2' => 'c2', 'Exam' => 'ex'];
            $catMaxMap = ['CAT1' => 20, 'CAT2' => 20, 'Exam' => 60];
            $reportCat = $r['category'];
            $curComp   = (isset($catColMap[$reportCat]) && $r['current_total'] !== null) ? (int)$r[$catColMap[$reportCat]] : null;
            $compMax   = $catMaxMap[$reportCat] ?? 100;
        ?>
        <div class="report-card">
            <div class="report-head">
                <div>
                    <div class="report-module"><?= htmlspecialchars($r["module_name"]) ?> (<?= htmlspecialchars($r["module_code"]) ?>)</div>
                    <div class="report-meta">
                        <?= htmlspecialchars($r["category"]) ?> ·
                        <?= htmlspecialchars($r["student_name"]) ?> (<?= htmlspecialchars($r["student_ID"]) ?>) ·
                        Submitted <?= htmlspecialchars(date('d M Y, H:i', strtotime($r["created_at"]))) ?>
                    </div>
                </div>
                <span class="status-pill <?= statusClass($r["status"]) ?>"><?= htmlspecialchars($r["status"]) ?></span>
            </div>

            <div class="report-message">
                <span class="label">Student's message</span>
                <?= nl2br(htmlspecialchars($r["message"])) ?>
            </div>

            <div class="mark-row">
                <span class="mark-current">Current:
                    <?php if ($r["current_total"] !== null): ?>
                        <strong>CAT1 <?= (int)$r['c1'] ?> &middot; CAT2 <?= (int)$r['c2'] ?> &middot; Exam <?= (int)$r['ex'] ?>
                        &middot; Total <?= (int)$r["current_total"] ?>% (<?= htmlspecialchars($r["current_grade"]) ?>)</strong>
                    <?php else: ?><strong>—</strong><?php endif; ?>
                </span>
                <?php if ($corr): ?>
                    <span class="corr-badge corr-<?= strtolower($corr["status"]) ?>">
                        <?php if (!empty($corr["category"]) && $corr["new_component"] !== null): ?>
                            <?= htmlspecialchars($corr["category"]) ?> <?= (int)$corr["old_component"] ?> &rarr; <?= (int)$corr["new_component"] ?>
                            (total <?= (int)$corr["old_total"] ?>% &rarr; <?= (int)$corr["new_total"] ?>%, <?= htmlspecialchars($corr["new_letter_grade"]) ?>)
                        <?php else: ?>
                            <?= (int)$corr["old_total"] ?>% &rarr; <?= (int)$corr["new_total"] ?>% (<?= htmlspecialchars($corr["new_letter_grade"]) ?>)
                        <?php endif; ?>
                        &middot; <?= htmlspecialchars($corr["status"]) ?><?= $corr["status"] === "Pending" ? " — awaiting admin" : "" ?>
                    </span>
                <?php endif; ?>
            </div>

            <form class="update-form" method="POST" action="LecturerReportsStatus.php">
                <input type="hidden" name="report_ID" value="<?= (int)$r["report_ID"] ?>">

                <div class="field">
                    <label for="status_<?= $r['report_ID'] ?>">Status</label>
                    <select name="status" id="status_<?= $r['report_ID'] ?>">
                        <?php foreach ($allowedStatuses as $s): ?>
                        <option value="<?= $s ?>" <?= $r["status"] === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label for="mark_<?= $r['report_ID'] ?>">Corrected <?= htmlspecialchars($reportCat) ?> mark (max <?= (int)$compMax ?>)</label>
                    <input type="number" name="new_mark" id="mark_<?= $r['report_ID'] ?>" min="0" max="<?= (int)$compMax ?>"
                           class="mark-input"
                           placeholder="<?= $curComp !== null ? (int)$curComp : '—' ?>"
                           value="<?= ($corr && $corr["status"] === "Pending" && $corr["new_component"] !== null) ? (int)$corr["new_component"] : '' ?>">
                </div>

                <div class="field note-field">
                    <label for="note_<?= $r['report_ID'] ?>">Note to student (optional)</label>
                    <textarea name="lecturer_note" id="note_<?= $r['report_ID'] ?>" placeholder="e.g. I've rechecked your script — the mark has been corrected."><?= htmlspecialchars($r["lecturer_note"] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn-update">Update</button>
            </form>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

</body>
</html>