<?php
session_start();
header('Content-Type: application/json');

// ─── Anthropic API key ────────────────────────────────────────────────────────
// Paste your key here on ONE line, no line breaks. Leave blank to disable the
// "Generate with Claude" button (the rule-based insight on the page still shows).
define('ANTHROPIC_API_KEY', '');

function respond(array $p, int $code = 200): void { http_response_code($code); echo json_encode($p); exit(); }

if (empty($_SESSION["student_ID"])) {
    respond(["success" => false, "error" => "Your session expired. Please log in again."], 401);
}
$studentID = $_SESSION["student_ID"];

// ─── Read JSON body ───────────────────────────────────────────────────────────
$in         = json_decode(file_get_contents("php://input"), true) ?: [];
$moduleCode = trim($in["module_code"] ?? "");
$moduleName = trim($in["module_name"] ?? "");
$careers    = array_values(array_filter(array_map('strval', (array)($in["careers"] ?? []))));
// Module context from the page's dossier, so Claude can explain the module itself
$concepts   = array_values(array_filter(array_map('strval', (array)($in["concepts"] ?? []))));
$roles      = array_values(array_filter(array_map('strval', (array)($in["roles"] ?? []))));
$realWorld  = trim($in["real_world"] ?? "");
$matched    = trim($in["matched"] ?? "");
$priority   = trim($in["priority"] ?? "");
$demand     = trim($in["demand"] ?? "");
if ($moduleCode === "") { respond(["success" => false, "error" => "No module specified."], 422); }

if (trim(ANTHROPIC_API_KEY) === "") {
    respond(["success" => false, "error" => "AI insight isn't configured yet — set ANTHROPIC_API_KEY in GoalInsight.php. The rule-based insight above still applies."], 200);
}

// ─── DB: pull the student's grade history for grounding ───────────────────────
try {
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=apaagps_db;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    respond(["success" => false, "error" => "Database connection failed."], 500);
}
$st = $pdo->prepare("
    SELECT m.module_name, r.final_total, r.letter_grade, r.grade_point
    FROM   results_tb r JOIN module_tb m ON r.module_code = m.module_code
    WHERE  r.student_ID = ? ORDER BY r.grade_point ASC
");
$st->execute([$studentID]);
$history = $st->fetchAll();
$nameSt  = $pdo->prepare("SELECT student_name FROM student_tb WHERE student_ID = ?");
$nameSt->execute([$studentID]);
$studentName = $nameSt->fetchColumn() ?: $studentID;

$histLines = array_map(fn($r) => "  {$r['module_name']}: {$r['final_total']}% ({$r['letter_grade']}, GP {$r['grade_point']})", $history);
$histText  = $histLines ? implode("\n", $histLines) : "  No results recorded yet.";

$system = "You are an academic advisor at Cavendish University advising an IT/computing student on ONE "
        . "upcoming module. Write a single cohesive paragraph of about 5 to 7 sentences in plain text "
        . "(no markdown, no headings, no preamble, no lists). Do THREE things in this order: "
        . "(1) explain what this module actually teaches — name a few of its key topics and what a student "
        . "will be able to do after it; (2) explain why it matters in professional practice and specifically "
        . "for the student's chosen career and the roles it builds toward; (3) connect it to the student's own "
        . "record by citing a specific past grade, and finish with one concrete, actionable study tip for "
        . "excelling in it. Be specific and encouraging — avoid generic filler.";

$user = "STUDENT: {$studentName}\n"
      . "CAREER INTERESTS: " . ($careers ? implode(", ", $careers) : "none selected yet") . "\n"
      . "UPCOMING MODULE: {$moduleName} ({$moduleCode})\n"
      . ($priority ? "PRIORITY FOR THIS STUDENT: {$priority}" . ($matched ? " (matched to {$matched})" : "") . "\n" : "")
      . ($concepts ? "KEY TOPICS COVERED: " . implode(", ", $concepts) . "\n" : "")
      . ($realWorld ? "WHY IT MATTERS (context): {$realWorld}\n" : "")
      . ($roles ? "CAREER ROLES IT BUILDS TOWARD: " . implode(", ", $roles) . "\n" : "")
      . ($demand ? "MARKET DEMAND: {$demand}\n" : "")
      . "\nGRADE HISTORY (weakest first):\n{$histText}\n\n"
      . "Write the insight now.";

$payload = [
    "model" => "claude-sonnet-4-6", "max_tokens" => 600,
    "system" => $system,
    "messages" => [["role" => "user", "content" => $user]],
];

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json", "x-api-key: " . trim(ANTHROPIC_API_KEY), "anthropic-version: 2023-06-01"],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => false,  // WAMP/Windows CA-bundle workaround
    CURLOPT_SSL_VERIFYHOST => 0,
]);
$response  = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) { respond(["success" => false, "error" => "cURL failed: {$curlError}"], 200); }
if ($httpCode !== 200) {
    if ($httpCode === 529 || $httpCode === 429) {
        respond(["success" => false, "error" => "Claude is busy right now — click Generate again in a moment."], 200);
    }
    $body = json_decode($response, true);
    respond(["success" => false, "error" => "Anthropic HTTP {$httpCode}: " . ($body['error']['message'] ?? substr($response, 0, 160))], 200);
}
$data = json_decode($response, true);
$text = trim($data["content"][0]["text"] ?? "");
if ($text === "") { respond(["success" => false, "error" => "Anthropic returned an empty response."], 200); }

respond(["success" => true, "insight" => $text]);
