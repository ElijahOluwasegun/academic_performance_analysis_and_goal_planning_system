<?php
session_start();

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Auth: relies on the session set at student login ────────────────────────
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

// ─── Student info ──────────────────────────────────────────────────────────────
$stmtS = $pdo->prepare("SELECT student_ID, student_name FROM student_tb WHERE student_ID = ? LIMIT 1");
$stmtS->execute([$studentID]);
$student = $stmtS->fetch();

if (!$student) {
    header("Location: index.php?error=invalid_session");
    exit();
}

// ─── Fetch this student's reports, newest first ───────────────────────────────
$stmtReports = $pdo->prepare("
    SELECT mr.report_ID, mr.module_code, m.module_name, mr.category, mr.message,
           mr.status, mr.lecturer_note, mr.created_at, mr.updated_at,
           l.lecturer_name
    FROM   module_report_tb mr
    JOIN   module_tb m ON mr.module_code = m.module_code
    LEFT JOIN lecturer_tb l ON mr.lecturer_ID = l.lecturer_ID
    WHERE  mr.student_ID = ?
    ORDER  BY mr.created_at DESC
");
$stmtReports->execute([$studentID]);
$reports = $stmtReports->fetchAll();

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
    <title>My Reports – Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #fff; }

        .site-header {
            display: flex; align-items: center; gap: 0.9rem;
            padding: 1rem 1.5rem; background: #213769; border-bottom: 1px solid #16213f;
        }
        .site-header .crest { width: 2.6rem; height: 2.6rem; object-fit: contain; flex: 0 0 auto; background: #fff; border-radius: 6px; padding: 2px; }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name { font-weight: 600; font-size: 0.72rem; letter-spacing: .14em; text-transform: uppercase; color: #d9c581; }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }
        .header-right { margin-left: auto; width: 1px; }

        .tab-nav { display: flex; gap: 0.35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn {
            padding: .8rem 1.1rem .7rem; border: none; background: transparent;
            font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,0.68);
            text-decoration: none; border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        .page-wrap { max-width: 860px; margin: 1.5rem auto; padding: 0 1.5rem 3rem; }

        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid { font-size: .95rem; font-weight: 400; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; }

        .empty { text-align: center; padding: 3rem 1rem; color: #888; }

        .report-card {
            background: #fff; border: 1px solid #999; border-radius: 8px;
            padding: 1.1rem 1.3rem; margin-bottom: 1rem;
        }
        .report-head {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: .75rem; flex-wrap: wrap; margin-bottom: .6rem;
        }
        .report-module { font-weight: 700; font-size: .95rem; color: #16213f; }
        .report-meta { font-size: .8rem; color: #666; margin-top: .15rem; }

        .status-pill {
            font-size: .76rem; font-weight: 700; padding: .25rem .7rem; border-radius: 14px;
            white-space: nowrap;
        }
        .status-submitted { background: #e0e7ff; color: #3730a3; }
        .status-reviewing { background: #fef3c7; color: #92400e; }
        .status-resolved  { background: #d1fae5; color: #065f46; }
        .status-rejected  { background: #fde2e2; color: #991b1b; }

        .report-message {
            font-size: .85rem; color: #222; background: #f7f8fb;
            border-radius: 6px; padding: .7rem .9rem; margin-bottom: .6rem; line-height: 1.5;
        }
        .report-message .label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #555; display: block; margin-bottom: .3rem; }

        .lecturer-note {
            font-size: .85rem; color: #16213f; background: #eef1fa;
            border: 1px solid #c7cfe8; border-radius: 6px; padding: .7rem .9rem; line-height: 1.5;
        }
        .lecturer-note .label { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #213769; display: block; margin-bottom: .3rem; }

        @media (max-width: 640px) {
            .report-head { flex-direction: column; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Career & Module Planning</span>
    </div>
    <div class="header-right"></div>
</header>

<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <a class="tab-btn" href="GoalPlanning.php">Career & Module Planner</a>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <span class="tab-btn active">My Reports</span>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <?php if (empty($reports)): ?>
        <p class="empty">You haven't reported any module issues yet. Use the "Report Module" button on the Results page if something looks wrong with a mark.</p>
    <?php else: ?>
        <?php foreach ($reports as $r): ?>
        <div class="report-card">
            <div class="report-head">
                <div>
                    <div class="report-module"><?= htmlspecialchars($r["module_name"]) ?> (<?= htmlspecialchars($r["module_code"]) ?>)</div>
                    <div class="report-meta">
                        <?= htmlspecialchars($r["category"]) ?> ·
                        Submitted <?= htmlspecialchars(date('d M Y, H:i', strtotime($r["created_at"]))) ?>
                        <?php if ($r["lecturer_name"]): ?>
                            · Assigned to <?= htmlspecialchars($r["lecturer_name"]) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <span class="status-pill <?= statusClass($r["status"]) ?>"><?= htmlspecialchars($r["status"]) ?></span>
            </div>

            <div class="report-message">
                <span class="label">Your message</span>
                <?= nl2br(htmlspecialchars($r["message"])) ?>
            </div>

            <?php if (!empty($r["lecturer_note"])): ?>
            <div class="lecturer-note">
                <span class="label">Lecturer's response</span>
                <?= nl2br(htmlspecialchars($r["lecturer_note"])) ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

</main>

</body>
</html>