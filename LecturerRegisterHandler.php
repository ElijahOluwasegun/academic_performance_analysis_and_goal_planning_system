<?php
session_start();

$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";
$db_pass = "";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: LecturerRegister.php");
    exit();
}

$fullName   = trim($_POST["FullName"]       ?? "");
$staffID    = trim($_POST["StaffID"]        ?? "");
$title      = trim($_POST["Title"]          ?? "");
$email      = trim($_POST["Email"]          ?? "");
$faculty    = trim($_POST["Faculty"]        ?? "");
$department = trim($_POST["Department"]     ?? "");
$password   = $_POST["Password"]            ?? "";
$confirm    = $_POST["ConfirmPassword"]     ?? "";

if (empty($fullName) || empty($staffID) || empty($title) || empty($email)
    || empty($faculty) || empty($department) || empty($password) || empty($confirm)) {
    header("Location: LecturerRegister.php?error=missing_fields");
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header("Location: LecturerRegister.php?error=invalid_email");
    exit();
}

if ($password !== $confirm) {
    header("Location: LecturerRegister.php?error=password_mismatch");
    exit();
}

if (strlen($password) < 8) {
    header("Location: LecturerRegister.php?error=password_short");
    exit();
}

try {
    $dsn = "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    die("Database connection failed.");
}

$stmtEmail = $pdo->prepare("SELECT ID FROM lecturer_tb WHERE lecturer_email = ? LIMIT 1");
$stmtEmail->execute([$email]);
if ($stmtEmail->fetch()) {
    header("Location: LecturerRegister.php?error=email_taken");
    exit();
}

$stmtID = $pdo->prepare("SELECT ID FROM lecturer_tb WHERE lecturer_ID = ? LIMIT 1");
$stmtID->execute([$staffID]);
if ($stmtID->fetch()) {
    header("Location: LecturerRegister.php?error=id_taken");
    exit();
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->prepare("
    INSERT INTO lecturer_tb
        (lecturer_ID, lecturer_name, lecturer_title, lecturer_email, lecturer_password, lecturer_faculty, lecturer_department)
    VALUES (?, ?, ?, ?, ?, ?, ?)
")->execute([$staffID, $fullName, $title, $email, $hash, $faculty, $department]);

header("Location: LecturerLoginInterface.php?registered=1");
exit();
