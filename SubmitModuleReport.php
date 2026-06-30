<?php
session_start();
header('Content-Type: application/json');

// ─── Database Configuration ───────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

function respond(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode($payload);
    exit();
}

// ─── Auth: relies on the session set at student login ────────────────────────
if (empty($_SESSION["student_ID"])) {
    respond(["success" => false, "error" => "Your session expired. Please log in again."], 401);
}
$studentID = $_SESSION["student_ID"];

// ─── Validate input ────────────────────────────────────────────────────────────
$moduleCode = trim($_POST["module_code"] ?? "");
$category   = trim($_POST["category"] ?? "");
$message    = trim($_POST["message"] ?? "");

$allowedCategories = ["CAT1", "CAT2", "Exam"];

if ($moduleCode === "" || $message === "") {
    respond(["success" => false, "error" => "Please fill in all fields."], 422);
}
if (!in_array($category, $allowedCategories, true)) {
    respond(["success" => false, "error" => "Invalid category selected."], 422);
}
if (mb_strlen($message) > 2000) {
    respond(["success" => false, "error" => "Message is too long (max 2000 characters)."], 422);
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
    respond(["success" => false, "error" => "Database connection failed."], 500);
}

// ─── Confirm the student actually has a result for this module
//     (prevents reporting on a module they were never enrolled in) ────────────
$stmtCheck = $pdo->prepare("
    SELECT r.module_code, m.module_name
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = ? AND r.module_code = ?
    LIMIT  1
");
$stmtCheck->execute([$studentID, $moduleCode]);
$moduleRow = $stmtCheck->fetch();

if (!$moduleRow) {
    respond(["success" => false, "error" => "We couldn't match that module to your results."], 422);
}

// ─── Find the lecturer assigned to this module (if any) ───────────────────────
$stmtLecturer = $pdo->prepare("
    SELECT l.lecturer_ID, l.lecturer_name, l.lecturer_email
    FROM   lecturer_module_tb lm
    JOIN   lecturer_tb l ON lm.lecturer_ID = l.lecturer_ID
    WHERE  lm.module_code = ?
    LIMIT  1
");
$stmtLecturer->execute([$moduleCode]);
$lecturer = $stmtLecturer->fetch();

// ─── Insert the report ─────────────────────────────────────────────────────────
$stmtInsert = $pdo->prepare("
    INSERT INTO module_report_tb (student_ID, module_code, lecturer_ID, category, message, status)
    VALUES (:student_ID, :module_code, :lecturer_ID, :category, :message, 'Submitted')
");
$stmtInsert->execute([
    ":student_ID"  => $studentID,
    ":module_code" => $moduleCode,
    ":lecturer_ID" => $lecturer["lecturer_ID"] ?? null,
    ":category"    => $category,
    ":message"     => $message,
]);

// ─── Best-effort email to the lecturer — failure here does NOT fail the request ─
if ($lecturer && !empty($lecturer["lecturer_email"])) {
    $stmtStudentName = $pdo->prepare("SELECT student_name FROM student_tb WHERE student_ID = ?");
    $stmtStudentName->execute([$studentID]);
    $studentName = $stmtStudentName->fetchColumn() ?: $studentID;

    $subject = "Module Issue Report — {$moduleRow['module_code']} ({$category})";
    $body  = "A student has reported an issue regarding one of your modules.\n\n";
    $body .= "Student: {$studentName} ({$studentID})\n";
    $body .= "Module: {$moduleRow['module_name']} ({$moduleRow['module_code']})\n";
    $body .= "Category: {$category}\n\n";
    $body .= "Message:\n{$message}\n\n";
    $body .= "Please log in to the Lecturer Portal to review and update the status of this report.\n";

    $headers = "From: Cavendish Student Portal <no-reply@cavendish.ac.ug>\r\n";
    $headers .= "Reply-To: no-reply@cavendish.ac.ug\r\n";

    // @ suppresses warnings if the local mail server isn't configured —
    // the report is already safely stored in the DB regardless of this outcome.
    @mail($lecturer["lecturer_email"], $subject, $body, $headers);
}

respond(["success" => true]);