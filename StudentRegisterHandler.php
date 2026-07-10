<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['Submit'])) {
    header('Location: StudentRegister.php');
    exit();
}

$db_host = '127.0.0.1'; $db_port = '3306'; $db_name = 'apaagps_db'; $db_user = 'root'; $db_pass = '';

// ─── Collect & basic sanitise ────────────────────────────────────────────────
$fullName      = trim($_POST['FullName']        ?? '');
$studentID     = trim($_POST['StudentID']       ?? '');
$programCode   = strtoupper(trim($_POST['ProgramCode'] ?? ''));
$email         = strtolower(trim($_POST['Email']       ?? ''));
$password      = $_POST['Password']             ?? '';
$confirmPw     = $_POST['ConfirmPassword']      ?? '';
$gender        = $_POST['Gender']               ?? '';
$nationality   = trim($_POST['Nationality']     ?? '');
$dob           = $_POST['DateOfBirth']          ?? '';
$intakeYear    = (int)($_POST['IntakeYear']     ?? 0);
$intakeSession = strtoupper(trim($_POST['IntakeSession'] ?? ''));
$modeOfEntry   = trim($_POST['ModeOfEntry']    ?? '');

// ─── Required fields ─────────────────────────────────────────────────────────
if (!$fullName || !$studentID || !$programCode || !$email
    || !$password || !$confirmPw || !$gender || !$nationality
    || !$dob || !$intakeYear || !$intakeSession || !$modeOfEntry) {
    header('Location: StudentRegister.php?error=missing_fields');
    exit();
}

// ─── Password match ───────────────────────────────────────────────────────────
if ($password !== $confirmPw) {
    header('Location: StudentRegister.php?error=password_mismatch');
    exit();
}

// ─── Validate gender (DB enum: M / F) ────────────────────────────────────────
if (!in_array($gender, ['M', 'F'], true)) {
    header('Location: StudentRegister.php?error=missing_fields');
    exit();
}

// ─── Validate intake session ──────────────────────────────────────────────────
if (!in_array($intakeSession, ['JAN', 'MAY', 'AUG'], true)) {
    header('Location: StudentRegister.php?error=invalid_session');
    exit();
}

// ─── Validate mode of entry ───────────────────────────────────────────────────
$validModes = ['Direct', 'Transfer', 'Foundation', 'Mature', 'Diploma'];
if (!in_array($modeOfEntry, $validModes, true)) {
    header('Location: StudentRegister.php?error=missing_fields');
    exit();
}

// ─── Validate intake year range ───────────────────────────────────────────────
if ($intakeYear < 2000 || $intakeYear > 2100) {
    header('Location: StudentRegister.php?error=missing_fields');
    exit();
}

// ─── DB connection ────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
        $db_user, $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

// ─── Programme must exist ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT program_code FROM program_tb WHERE program_code = ? LIMIT 1");
$stmt->execute([$programCode]);
if (!$stmt->fetch()) {
    header('Location: StudentRegister.php?error=invalid_program');
    exit();
}

// ─── Email uniqueness ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT student_ID FROM student_tb WHERE student_email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    header('Location: StudentRegister.php?error=email_taken');
    exit();
}

// ─── Student ID uniqueness ───────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT student_ID FROM student_tb WHERE student_ID = ? LIMIT 1");
$stmt->execute([$studentID]);
if ($stmt->fetch()) {
    header('Location: StudentRegister.php?error=id_taken');
    exit();
}

// ─── Insert student ──────────────────────────────────────────────────────────
// student_password is varchar(20) — stored as plain text to match the existing
// login check (ExamResultInterface.php uses !== string comparison).
// Truncate to 20 chars to avoid a DB error; the user will log in with whatever
// they typed (matching characters up to the 20-char limit).
$passwordStore = password_hash($password, PASSWORD_BCRYPT);

$ins = $pdo->prepare("
    INSERT INTO student_tb
        (student_ID, student_name, student_email, student_password,
         program_code, gender, nationality, date_of_birth,
         intake_year, intake_session, mode_of_entry)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$ins->execute([
    $studentID, $fullName, $email, $passwordStore,
    $programCode, $gender, $nationality, $dob,
    $intakeYear, $intakeSession, $modeOfEntry,
]);

// ─── Auto-login and redirect ─────────────────────────────────────────────────
$_SESSION['student_ID']   = $studentID;
$_SESSION['student_name'] = $fullName;

header('Location: ExamResultInterface.php');
exit();
