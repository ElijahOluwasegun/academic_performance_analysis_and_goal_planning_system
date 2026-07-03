<?php
session_start();

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: AdminLogin.php');
    exit();
}

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";
$db_pass = "";

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

// ─── Handle POST (assign / unassign) — PRG pattern ───────────────────────────
$flashMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']      ?? '';
    $lecturerID = trim($_POST['lecturer_id'] ?? '');
    $moduleCode = trim($_POST['module_code']  ?? '');

    if ($lecturerID && $moduleCode) {
        // Verify both records exist before touching the junction table
        $okLec = $pdo->prepare("SELECT 1 FROM lecturer_tb  WHERE lecturer_ID  = ? LIMIT 1");
        $okMod = $pdo->prepare("SELECT 1 FROM module_tb    WHERE module_code   = ? LIMIT 1");
        $okLec->execute([$lecturerID]);
        $okMod->execute([$moduleCode]);

        if ($okLec->fetch() && $okMod->fetch()) {
            if ($action === 'assign') {
                // Prevent assigning a module already held by any other lecturer
                $chk = $pdo->prepare("SELECT lecturer_ID FROM lecturer_module_tb WHERE module_code = ? LIMIT 1");
                $chk->execute([$moduleCode]);
                if ($chk->fetch()) {
                    header('Location: AdminDashboard.php?msg=module_taken&lec=' . urlencode($lecturerID));
                    exit();
                }
                $pdo->prepare("INSERT INTO lecturer_module_tb (lecturer_ID, module_code) VALUES (?, ?)")
                    ->execute([$lecturerID, $moduleCode]);
                header('Location: AdminDashboard.php?msg=assigned&lec=' . urlencode($lecturerID));
                exit();
            } elseif ($action === 'unassign') {
                $pdo->prepare("DELETE FROM lecturer_module_tb WHERE lecturer_ID = ? AND module_code = ?")
                    ->execute([$lecturerID, $moduleCode]);
                header('Location: AdminDashboard.php?msg=unassigned&lec=' . urlencode($lecturerID));
                exit();
            }
        }
    }

    header('Location: AdminDashboard.php?msg=error');
    exit();
}

// ─── Fetch flash message ──────────────────────────────────────────────────────
$msgType = null;
$msgText = null;
if (isset($_GET['msg'])) {
    match($_GET['msg']) {
        'assigned'     => [$msgType, $msgText] = ['success', 'Module assigned successfully.'],
        'unassigned'   => [$msgType, $msgText] = ['success', 'Module removed from lecturer.'],
        'module_taken' => [$msgType, $msgText] = ['error',   'That module is already assigned to another lecturer.'],
        'error'        => [$msgType, $msgText] = ['error',   'Something went wrong. Please try again.'],
        default        => null,
    };
}

// ─── Fetch all lecturers ──────────────────────────────────────────────────────
$stmtL = $pdo->query("
    SELECT lecturer_ID, lecturer_name, lecturer_title, lecturer_email,
           lecturer_faculty, lecturer_department
    FROM   lecturer_tb
    ORDER  BY lecturer_name
");
$lecturers = $stmtL->fetchAll();

// ─── Fetch all assignments in one query ──────────────────────────────────────
$stmtA = $pdo->query("
    SELECT lm.lecturer_ID, lm.module_code, m.module_name, m.year_no, m.sem_no
    FROM   lecturer_module_tb lm
    JOIN   module_tb m ON lm.module_code = m.module_code
    ORDER  BY lm.lecturer_ID, m.year_no, m.sem_no, m.module_code
");
$assignedByLecturer = [];
foreach ($stmtA->fetchAll() as $a) {
    $assignedByLecturer[$a['lecturer_ID']][] = $a;
}

// ─── Fetch all modules ────────────────────────────────────────────────────────
$stmtM = $pdo->query("
    SELECT module_code, module_name, year_no, sem_no
    FROM   module_tb
    ORDER  BY year_no, sem_no, module_code
");
$allModules = $stmtM->fetchAll();

// ─── Summary stats ────────────────────────────────────────────────────────────
$totalModules      = count($allModules);
$totalLecturers    = count($lecturers);
$assignedModCodes  = $pdo->query("SELECT DISTINCT module_code FROM lecturer_module_tb")->fetchAll(PDO::FETCH_COLUMN);
$totalAssigned     = count($assignedModCodes);
$totalUnassigned   = $totalModules - $totalAssigned;

// Highlight the lecturer card that was just modified
$highlightLec = $_GET['lec'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Module Assignment — Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Montserrat:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #111; background: #f4f5f8; }

        /* ── Header ── */
        .site-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.1rem 1.5rem; background: #213769;
        }
        .site-header h4 {
            color: #d9c581; font-family: 'Montserrat', sans-serif;
            letter-spacing: 0.2rem; font-weight: 600; font-size: 0.72rem;
            text-transform: uppercase; margin-bottom: 0.15rem;
        }
        .site-header h1 { color: #fff; font-size: 1.05rem; font-weight: 700; }
        .logout-link { color: #cdd6ef; font-size: 0.82rem; text-decoration: none; }
        .logout-link:hover { text-decoration: underline; }

        /* ── Nav ── */
        .tab-nav {
            display: flex; gap: 0.35rem; padding: 0 1.5rem;
            background: #16213f; border-bottom: 1px solid #0d1730;
        }
        .tab-btn {
            padding: .75rem 1.1rem .65rem; border: none; background: transparent;
            font-size: .84rem; font-weight: 600; cursor: default;
            color: #fff; border-bottom: 3px solid #c9a227;
            text-decoration: none;
        }

        /* ── Page wrap ── */
        .page-wrap { max-width: 960px; margin: 0 auto; padding: 1.6rem 1.5rem 3rem; }

        /* ── Alert ── */
        .alert {
            padding: .75rem 1rem; border-radius: 7px;
            font-size: .86rem; margin-bottom: 1.25rem;
        }
        .alert-success { background: #e8f6ef; border: 1px solid #a7e0c4; color: #0f6b41; }
        .alert-error   { background: #fdecec; border: 1px solid #f3b9b9; color: #9b2c2c; }

        /* ── Summary stats ── */
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.85rem;
            margin-bottom: 1.6rem;
        }
        .stat-card {
            background: #fff;
            border: 1px solid #d9dce8;
            border-radius: 8px;
            padding: 1rem 1.1rem;
        }
        .stat-label { font-size: .76rem; font-weight: 600; color: #7b8294; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .3rem; }
        .stat-value { font-size: 1.7rem; font-weight: 700; color: #16213f; line-height: 1; }
        .stat-card.warn .stat-value { color: #b45309; }

        /* ── Section heading ── */
        .section-heading {
            font-size: .9rem; font-weight: 700; color: #16213f;
            margin-bottom: 1rem; padding-bottom: .5rem;
            border-bottom: 2px solid #d9dce8;
        }

        /* ── Lecturer card ── */
        .lec-card {
            background: #fff;
            border: 1px solid #c8ccd8;
            border-radius: 9px;
            margin-bottom: 1.1rem;
            overflow: hidden;
            transition: box-shadow .15s;
        }
        .lec-card.highlight { border-color: #c9a227; box-shadow: 0 0 0 2px rgba(201,162,39,0.25); }

        .lec-header {
            background: #121e38;
            padding: .7rem 1.1rem;
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .lec-name {
            font-weight: 700; font-size: .95rem; color: #fff;
        }
        .lec-name span { font-weight: 400; font-size: .82rem; color: rgba(255,255,255,.6); margin-left: .5rem; }
        .lec-email { font-size: .8rem; color: #adb9d6; }
        .lec-dept  { font-size: .78rem; color: rgba(255,255,255,.5); }

        .lec-body { padding: .95rem 1.1rem 1.1rem; }

        /* ── Module pills ── */
        .pills-label {
            font-size: .74rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .05em; color: #7b8294; margin-bottom: .55rem;
        }
        .pills-wrap { display: flex; flex-wrap: wrap; gap: .45rem; margin-bottom: .95rem; min-height: 1.8rem; }

        .module-pill {
            display: inline-flex; align-items: center; gap: .3rem;
            background: #e8ecf8; border: 1px solid #c2caea;
            border-radius: 14px;
            padding: .22rem .55rem .22rem .7rem;
            font-size: .8rem; font-weight: 600; color: #16213f;
        }
        .pill-code { font-family: 'Montserrat', sans-serif; letter-spacing: .02em; }
        .pill-sem  { font-size: .72rem; font-weight: 400; color: #5a6378; }

        .btn-remove {
            display: inline-flex; align-items: center; justify-content: center;
            width: 16px; height: 16px;
            background: none; border: 1px solid #b0bae8; border-radius: 50%;
            font-size: .8rem; line-height: 1; color: #5a6378;
            cursor: pointer; padding: 0; flex-shrink: 0;
            transition: background .12s, color .12s;
        }
        .btn-remove:hover { background: #c7314a; border-color: #c7314a; color: #fff; }
        .no-modules { font-size: .84rem; color: #aaa; font-style: italic; }

        /* ── Assign form ── */
        .assign-wrap {
            display: flex; align-items: center; gap: .6rem;
            flex-wrap: wrap;
            padding-top: .85rem;
            border-top: 1px solid #e8eaf2;
        }
        .assign-label { font-size: .78rem; font-weight: 600; color: #2b3550; white-space: nowrap; }

        .assign-select {
            flex: 1; min-width: 220px;
            padding: .48rem .7rem;
            border: 1.5px solid #c9cdda; border-radius: 7px;
            font-family: 'Inter', sans-serif; font-size: .86rem;
            background: #fff; color: #1c2433;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='11' height='7' viewBox='0 0 11 7'%3E%3Cpath d='M1 1l4.5 4.5L10 1' stroke='%235a6378' stroke-width='1.4' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            padding-right: 2.2rem;
        }
        .assign-select:focus { outline: none; border-color: #213769; box-shadow: 0 0 0 2px rgba(33,55,105,0.12); }

        .btn-assign {
            padding: .48rem 1.15rem; border: none; border-radius: 18px;
            background: #213769; color: #fff;
            font-weight: 700; font-size: .82rem; cursor: pointer;
            white-space: nowrap;
        }
        .btn-assign:hover { background: #121e38; }
        .btn-assign:disabled { background: #b0b5c0; cursor: not-allowed; }

        .all-assigned { font-size: .8rem; color: #7b8294; font-style: italic; }

        @media (max-width: 560px) {
            .summary-row { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <div>
        <h4>Cavendish University</h4>
        <h1>Admin Panel — Module Assignment</h1>
    </div>
    <a class="logout-link" href="AdminLogout.php">Log out</a>
</header>

<nav class="tab-nav">
    <span class="tab-btn">Module Assignment</span>
</nav>

<main class="page-wrap">

    <?php if ($msgText): ?>
        <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($msgText) ?></div>
    <?php endif; ?>

    <!-- ── Summary stats ── -->
    <div class="summary-row">
        <div class="stat-card">
            <div class="stat-label">Lecturers</div>
            <div class="stat-value"><?= $totalLecturers ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Modules</div>
            <div class="stat-value"><?= $totalModules ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Modules Assigned</div>
            <div class="stat-value"><?= $totalAssigned ?></div>
        </div>
        <div class="stat-card <?= $totalUnassigned > 0 ? 'warn' : '' ?>">
            <div class="stat-label">Without Lecturer</div>
            <div class="stat-value"><?= $totalUnassigned ?></div>
        </div>
    </div>

    <!-- ── Lecturer cards ── -->
    <div class="section-heading">Lecturers &amp; Module Assignments</div>

    <?php foreach ($lecturers as $lec):
        $lid          = $lec['lecturer_ID'];
        $assigned     = $assignedByLecturer[$lid] ?? [];
        $assignedCodes = array_column($assigned, 'module_code');
        $available    = array_filter($allModules, fn($m) => !in_array($m['module_code'], $assignedModCodes));
        $isHighlight  = ($highlightLec === $lid);
    ?>
    <div class="lec-card <?= $isHighlight ? 'highlight' : '' ?>" id="lec-<?= htmlspecialchars($lid) ?>">

        <!-- Card header -->
        <div class="lec-header">
            <div>
                <div class="lec-name">
                    <?= htmlspecialchars(trim(($lec['lecturer_title'] ? $lec['lecturer_title'] . ' ' : '') . $lec['lecturer_name'])) ?>
                    <span>(<?= htmlspecialchars($lid) ?>)</span>
                </div>
                <?php if ($lec['lecturer_faculty'] || $lec['lecturer_department']): ?>
                <div class="lec-dept">
                    <?= htmlspecialchars(implode(' · ', array_filter([$lec['lecturer_faculty'], $lec['lecturer_department']]))) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="lec-email"><?= htmlspecialchars($lec['lecturer_email']) ?></div>
        </div>

        <!-- Card body -->
        <div class="lec-body">

            <!-- Assigned modules -->
            <div class="pills-label">Assigned modules (<?= count($assigned) ?>)</div>
            <div class="pills-wrap">
                <?php if (empty($assigned)): ?>
                    <span class="no-modules">No modules assigned yet.</span>
                <?php else: ?>
                    <?php foreach ($assigned as $mod): ?>
                    <span class="module-pill">
                        <span class="pill-code"><?= htmlspecialchars($mod['module_code']) ?></span>
                        <span class="pill-sem">Y<?= $mod['year_no'] ?>S<?= $mod['sem_no'] ?></span>
                        <form method="post" action="AdminDashboard.php" style="display:inline;margin:0;">
                            <input type="hidden" name="action"      value="unassign">
                            <input type="hidden" name="lecturer_id" value="<?= htmlspecialchars($lid) ?>">
                            <input type="hidden" name="module_code" value="<?= htmlspecialchars($mod['module_code']) ?>">
                            <button type="submit" class="btn-remove"
                                    title="Remove <?= htmlspecialchars($mod['module_code']) ?> from <?= htmlspecialchars($lec['lecturer_name']) ?>"
                                    onclick="return confirm('Remove <?= htmlspecialchars(addslashes($mod['module_code'])) ?> from <?= htmlspecialchars(addslashes($lec['lecturer_name'])) ?>?')">
                                &times;
                            </button>
                        </form>
                    </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Assign form -->
            <div class="assign-wrap">
                <span class="assign-label">Assign module:</span>
                <?php if (empty($available)): ?>
                    <span class="all-assigned">All modules are already assigned to this lecturer.</span>
                <?php else: ?>
                <form method="post" action="AdminDashboard.php" style="display:contents;">
                    <input type="hidden" name="action"      value="assign">
                    <input type="hidden" name="lecturer_id" value="<?= htmlspecialchars($lid) ?>">

                    <select name="module_code" class="assign-select" required>
                        <option value="" disabled selected>Select a module…</option>
                        <?php
                        $currentYear = null;
                        foreach ($available as $mod):
                            if ($mod['year_no'] !== $currentYear):
                                if ($currentYear !== null) echo '</optgroup>';
                                echo '<optgroup label="Year ' . (int)$mod['year_no'] . '">';
                                $currentYear = $mod['year_no'];
                            endif;
                        ?>
                            <option value="<?= htmlspecialchars($mod['module_code']) ?>">
                                <?= htmlspecialchars($mod['module_code']) ?> — <?= htmlspecialchars($mod['module_name']) ?>
                                (Sem <?= (int)$mod['sem_no'] ?>)
                            </option>
                        <?php endforeach; ?>
                        <?php if ($currentYear !== null) echo '</optgroup>'; ?>
                    </select>

                    <button type="submit" class="btn-assign">Assign</button>
                </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

</main>

<?php if ($highlightLec): ?>
<script>
    // Scroll to and briefly flash the recently changed card
    const el = document.getElementById('lec-<?= htmlspecialchars($highlightLec) ?>');
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => el.classList.remove('highlight'), 3000);
    }
</script>
<?php endif; ?>

</body>
</html>
