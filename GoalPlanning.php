<?php
session_start();

// ════════════════════════════════════════════════════════════════════════
// CONFIG
// ════════════════════════════════════════════════════════════════════════

// ─── Database ──────────────────────────────────────────────────────────────
$db_host = "127.0.0.1";
$db_port = "3306";
$db_name = "apaagps_db";
$db_user = "root";   // ← change if needed
$db_pass = "";       // ← change if needed

// ─── Recommendation source ──────────────────────────────────────────────────
// 'api'      → call the Anthropic API at runtime (requires ANTHROPIC_API_KEY below)
// 'fallback' → use built-in rule-based logic, no external calls (default, works now)
// 'db'       → use the optional career_paths_tb / module_career_tags_tb tables
//              (run career_knowledge_base_migration.sql first, then switch this)
define('RECOMMENDATION_SOURCE', 'fallback');

// ─── Anthropic API key (only needed if RECOMMENDATION_SOURCE = 'api') ───────
// Get one at https://console.anthropic.com/ — never commit real keys to source control.
define('ANTHROPIC_API_KEY', ''); // ← paste your key here when you have one

// ════════════════════════════════════════════════════════════════════════
// AUTH — this page is reached via the navbar, so it relies on the session
// set by ExamResultInterface.php at login, not a fresh POST.
// ════════════════════════════════════════════════════════════════════════
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

// ─── Student + program info ───────────────────────────────────────────────────
$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code,
           p.program_name, p.program_faculty
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

// ════════════════════════════════════════════════════════════════════════
// FIXED CAREER INTEREST LIST
// (Mirrors career_paths_tb in the optional migration — kept in sync manually
// since the 'fallback' and 'api' sources don't require the DB table to exist.)
// ════════════════════════════════════════════════════════════════════════
$careerOptions = [
    "Software Development"        => "Building applications, web/mobile systems, and backend services.",
    "Data Science & Analytics"    => "Extracting insight from data using statistics, ML, and visualization.",
    "Network & Cybersecurity"     => "Securing systems, networks, and infrastructure against threats.",
    "Systems & Cloud Engineering" => "Designing, deploying, and maintaining distributed/cloud systems.",
    "Business & IT Consulting"    => "Bridging business needs with technical solutions; IT governance and strategy.",
    "UI/UX & Product Design"      => "Designing usable, accessible digital products and interfaces.",
];

// ─── Read selected interests from form submission (if any) ───────────────────
$selectedCareers = $_POST["careers"] ?? [];
$selectedCareers = array_intersect($selectedCareers, array_keys($careerOptions)); // sanitize
$hasSubmitted     = $_SERVER["REQUEST_METHOD"] === "POST" && !empty($selectedCareers);

// ════════════════════════════════════════════════════════════════════════
// FETCH PAST RESULTS (for performance analysis)
// ════════════════════════════════════════════════════════════════════════
$stmtR = $pdo->prepare("
    SELECT r.year_no, r.sem_no, r.module_code, m.module_name, m.credit_unit,
           r.cat1_mk, r.cat2_mk, r.exam_mk, r.final_total,
           r.letter_grade, r.grade_point, r.status_retake_pass
    FROM   results_tb r
    JOIN   module_tb  m ON r.module_code = m.module_code
    WHERE  r.student_ID = ?
    ORDER  BY r.year_no ASC, r.sem_no ASC
");
$stmtR->execute([$studentID]);
$pastResults = $stmtR->fetchAll();

$hasResults = !empty($pastResults);

// ─── Determine the student's latest completed (year, sem) ────────────────────
$latestYear = 0;
$latestSem  = 0; // global sequential sem_no matching module_tb (Y1S1=1, Y1S2=2, Y2S1=3 …)
if ($hasResults) {
    foreach ($pastResults as $r) {
        if ((int)$r["year_no"] > $latestYear ||
            ((int)$r["year_no"] === $latestYear && (int)$r["sem_no"] > $latestSem)) {
            $latestYear = (int)$r["year_no"];
            $latestSem  = (int)$r["sem_no"];
        }
    }
}

// ─── Compute next semester ────────────────────────────────────────────────────
// module_tb uses a global sem_no (1-6 across all three years), not a per-year
// 1/2. Simply incrementing gives the correct row to query.
$nextSemGlobal = $latestSem + 1;
$nextYear      = max(1, (int)ceil($nextSemGlobal / 2));
$nextSem       = (($nextSemGlobal - 1) % 2) + 1; // 1 or 2 within the year (display only)

// Per-year label for "Based on results through Year X, Sem Y" display
$latestSemDisplay = $latestSem > 0 ? (($latestSem - 1) % 2) + 1 : 1;

// ════════════════════════════════════════════════════════════════════════
// FETCH NEXT SEMESTER'S MODULES (from module_tb, scoped to the student's program)
// ════════════════════════════════════════════════════════════════════════
$stmtNext = $pdo->prepare("
    SELECT module_code, module_name, year_no, sem_no, credit_unit
    FROM   module_tb
    WHERE  program_code = ?
      AND  year_no = ?
      AND  sem_no  = ?
    ORDER  BY module_code ASC
");
$stmtNext->execute([$student["program_code"], $nextYear, $nextSemGlobal]);
$nextModules = $stmtNext->fetchAll();

// ════════════════════════════════════════════════════════════════════════
// PERFORMANCE ANALYSIS — strongest/weakest areas by grade point
// ════════════════════════════════════════════════════════════════════════
$performanceSummary = [
    "overall_avg_gp" => null,
    "strongest"      => [], // top modules by grade_point
    "weakest"        => [], // bottom modules by grade_point
    "retakes"        => [], // modules currently on retake
];

if ($hasResults) {
    $totalGP = 0;
    $count   = 0;
    foreach ($pastResults as $r) {
        $totalGP += (float)$r["grade_point"];
        $count++;
        if (strcasecmp($r["status_retake_pass"], "Pass") !== 0) {
            $performanceSummary["retakes"][] = $r;
        }
    }
    $performanceSummary["overall_avg_gp"] = $count ? round($totalGP / $count, 2) : null;

    $sorted = $pastResults;
    usort($sorted, fn($a, $b) => $b["grade_point"] <=> $a["grade_point"]);
    $performanceSummary["strongest"] = array_slice($sorted, 0, 3);
    $performanceSummary["weakest"]   = array_slice(array_reverse($sorted), 0, 3);
}

// ════════════════════════════════════════════════════════════════════════
// RECOMMENDATION ENGINE
// Produces, for each next-semester module:
//   - priority ("high" | "medium" | "standard") based on career overlap + past performance
//   - key_concepts (string)
//   - real_world_use (string)
//   - rationale (string) — why this matters given the student's chosen careers/performance
// ════════════════════════════════════════════════════════════════════════

// ════════════════════════════════════════════════════════════════════════
// SHARED SUBJECT-AREA LIBRARY
// Used both by the always-visible "Your Upcoming Modules" section and by
// the career-matched recommendation engine below, so module → subject-area
// detection only happens in one place.
// ════════════════════════════════════════════════════════════════════════
function getConceptLibrary(): array {
    return [
        "security"     => [
            "concepts" => ["Encryption", "Firewalls", "Risk assessment", "Authentication protocols"],
            "roles"    => ["Security Analyst", "SOC Analyst", "Penetration Tester", "Information Security Officer"],
        ],
        "network"      => [
            "concepts" => ["TCP/IP", "Routing & switching", "Network protocols", "Bandwidth management"],
            "roles"    => ["Network Engineer", "Network Administrator", "Cloud Network Specialist", "Telecom Engineer"],
        ],
        "database"     => [
            "concepts" => ["SQL", "Normalization", "Indexing", "Transactions"],
            "roles"    => ["Database Administrator", "Backend Developer", "Data Engineer"],
        ],
        "design"       => [
            "concepts" => ["Wireframing", "Usability heuristics", "Prototyping", "Accessibility"],
            "roles"    => ["UI/UX Designer", "Product Designer", "Front-End Developer"],
        ],
        "system"       => [
            "concepts" => ["Distributed systems", "APIs", "Scalability", "Fault tolerance"],
            "roles"    => ["Software Engineer", "Systems Architect", "DevOps Engineer", "Cloud Engineer"],
        ],
        "audit"        => [
            "concepts" => ["Compliance", "Governance frameworks", "Risk controls", "Internal audit"],
            "roles"    => ["IT Auditor", "Compliance Officer", "Risk Analyst", "Governance Consultant"],
        ],
        "professional" => [
            "concepts" => ["Ethics", "Stakeholder communication", "IT law", "Professional conduct"],
            "roles"    => ["IT Consultant", "Project Coordinator", "Business Analyst"],
        ],
        "data"         => [
            "concepts" => ["Data modeling", "Statistics", "Visualization", "ETL pipelines"],
            "roles"    => ["Data Analyst", "Data Scientist", "BI Developer"],
        ],
        "mobile"       => [
            "concepts" => ["App lifecycle", "Cross-platform frameworks", "UI components", "Push notifications"],
            "roles"    => ["Mobile App Developer", "iOS/Android Engineer"],
        ],
        "web"          => [
            "concepts" => ["HTML/CSS", "Client-server model", "Responsive design", "Web frameworks"],
            "roles"    => ["Web Developer", "Front-End Developer", "Full-Stack Developer"],
        ],
        "programming"  => [
            "concepts" => ["Algorithms", "Data structures", "OOP principles", "Version control (Git)"],
            "roles"    => ["Software Developer", "Systems Programmer"],
        ],
        "project"      => [
            "concepts" => ["Scope management", "Agile/Scrum", "Stakeholder planning", "Risk management"],
            "roles"    => ["IT Project Manager", "Scrum Master", "Delivery Lead"],
        ],
        "default"      => [
            "concepts" => ["Problem-solving", "Technical documentation", "Collaboration tools", "Critical thinking"],
            "roles"    => ["IT Generalist", "Technical Support Specialist", "Junior Developer"],
        ],
    ];
}

/**
 * Detects which subject area(s) a module belongs to, based on keywords in
 * its name. Shared by both the always-visible module details and the
 * career-matched recommendation engine.
 */
function detectModuleAreas(string $moduleName, array $conceptLibrary): array {
    $nameLower = strtolower($moduleName);
    $matched = [];
    foreach ($conceptLibrary as $area => $info) {
        if ($area !== "default" && str_contains($nameLower, $area)) {
            $matched[] = $area;
        }
    }
    return $matched ?: ["default"];
}

/**
 * Builds always-visible module detail cards (credit units + CGPA framing,
 * key concepts, real-world relevance, career prospects) — independent of
 * any career interest selection. This is what makes the page useful the
 * moment a student lands on it, before they've chosen anything.
 */
function buildModuleDetails(array $nextModules, array $allNextModulesForCgpaContext): array {
    $conceptLibrary = getConceptLibrary();

    // Total credit units across the whole upcoming semester, so we can frame
    // each module's relative "weight" in the semester's GPA calculation.
    $semesterTotalCU = array_sum(array_map(fn($m) => (float)$m["credit_unit"], $allNextModulesForCgpaContext));

    $details = [];
    foreach ($nextModules as $mod) {
        $cu = (float)$mod["credit_unit"];
        $shareOfSemester = $semesterTotalCU > 0 ? round(($cu / $semesterTotalCU) * 100) : 0;

        $areas = detectModuleAreas($mod["module_name"], $conceptLibrary);

        $concepts = [];
        $roles    = [];
        foreach ($areas as $area) {
            $concepts = array_merge($concepts, $conceptLibrary[$area]["concepts"]);
            $roles    = array_merge($roles, $conceptLibrary[$area]["roles"]);
        }
        $concepts = array_slice(array_unique($concepts), 0, 6);
        $roles    = array_slice(array_unique($roles), 0, 4);

        $areaLabel = $areas === ["default"] ? "general IT problem-solving" : strtolower(implode(", ", $areas));

        // Plain-language CGPA-weight framing based on relative credit units.
        if ($cu >= 4) {
            $cgpaNote = "This is one of your heavier modules ({$cu} credit units — about {$shareOfSemester}% of this semester's total). Performing well here moves your GPA more than a lighter module would.";
        } elseif ($cu <= 3 && $cu > 0) {
            $cgpaNote = "This module carries {$cu} credit units (about {$shareOfSemester}% of this semester's total) — lighter weight, but it still counts toward your GPA, so don't write it off.";
        } else {
            $cgpaNote = "Credit unit weighting for this module wasn't available — check with the academic office to confirm how it factors into your GPA.";
        }

        $details[] = [
            "module_code"   => $mod["module_code"],
            "module_name"   => $mod["module_name"],
            "credit_unit"   => $cu,
            "share_pct"     => $shareOfSemester,
            "cgpa_note"     => $cgpaNote,
            "key_concepts"  => $concepts,
            "real_world_use"=> "These concepts are commonly applied in roles involving {$areaLabel}.",
            "career_roles"  => $roles,
        ];
    }

    return $details;
}

/**
 * Built-in rule-based fallback. No external calls, no DB knowledge-base
 * required — works immediately with zero setup. Intentionally simple;
 * swap RECOMMENDATION_SOURCE to 'api' or 'db' for richer output.
 */
function getFallbackRecommendations(array $nextModules, array $selectedCareers, array $performanceSummary): array {
    $conceptLibrary = getConceptLibrary();

    $careerWeights = [
        "Software Development"        => ["system", "database", "programming", "web", "default"],
        "Data Science & Analytics"    => ["data", "database", "default"],
        "Network & Cybersecurity"     => ["security", "network", "default"],
        "Systems & Cloud Engineering" => ["system", "network", "default"],
        "Business & IT Consulting"    => ["audit", "professional", "project", "default"],
        "UI/UX & Product Design"      => ["design", "default"],
    ];

    $results = [];
    foreach ($nextModules as $mod) {
        $matchedAreas = detectModuleAreas($mod["module_name"], $conceptLibrary);

        // Career overlap: does this module's matched area align with chosen careers?
        $overlapCareers = [];
        foreach ($selectedCareers as $career) {
            $careerAreas = $careerWeights[$career] ?? ["default"];
            if (array_intersect($matchedAreas, $careerAreas)) {
                $overlapCareers[] = $career;
            }
        }

        $priority = !empty($overlapCareers) ? "high" : "standard";

        $concepts = [];
        foreach ($matchedAreas as $area) {
            $concepts = array_merge($concepts, $conceptLibrary[$area]["concepts"]);
        }
        $concepts = array_slice(array_unique($concepts), 0, 6);

        $rationale = !empty($overlapCareers)
            ? "Directly relevant to your interest in " . implode(" / ", $overlapCareers) . "."
            : "General-purpose skills useful across most IT career paths.";

        $areaLabel = $matchedAreas === ["default"] ? "general IT problem-solving" : strtolower(implode(", ", $matchedAreas));

        $results[] = [
            "module_code"    => $mod["module_code"],
            "module_name"    => $mod["module_name"],
            "credit_unit"    => $mod["credit_unit"],
            "priority"       => $priority,
            "key_concepts"   => $concepts,
            "real_world_use" => "These concepts are commonly applied in roles involving {$areaLabel}.",
            "rationale"      => $rationale,
        ];
    }

    // Sort: high priority first
    usort($results, function ($a, $b) {
        $order = ["high" => 0, "medium" => 1, "standard" => 2];
        return $order[$a["priority"]] <=> $order[$b["priority"]];
    });

    return $results;
}

/**
 * Calls the Anthropic API to generate richer, more specific recommendations.
 * Only used when RECOMMENDATION_SOURCE === 'api' and a key is set.
 * Returns null on any failure so the caller can fall back gracefully.
 */
function getApiRecommendations(array $nextModules, array $selectedCareers, array $performanceSummary, string $studentName): ?array {
    if (empty(ANTHROPIC_API_KEY)) {
        return null; // no key configured — caller should fall back
    }

    $modulesPayload = array_map(fn($m) => [
        "code"        => $m["module_code"],
        "name"        => $m["module_name"],
        "credit_unit" => $m["credit_unit"],
    ], $nextModules);

    $strongest = array_map(fn($r) => $r["module_name"] . " (GP {$r['grade_point']})", $performanceSummary["strongest"] ?? []);
    $weakest   = array_map(fn($r) => $r["module_name"] . " (GP {$r['grade_point']})", $performanceSummary["weakest"] ?? []);

    $systemPrompt = "You are an academic advisor for a university IT/computing program. " .
        "Given a student's career interests, past academic performance, and their upcoming " .
        "semester's modules, return ONLY valid JSON (no markdown, no preamble) — an array where " .
        "each object has: module_code, module_name, priority ('high'|'medium'|'standard'), " .
        "key_concepts (array of 4-6 short strings), real_world_use (1-2 sentences), " .
        "rationale (1 sentence tying it to the student's chosen careers/performance).";

    $userPrompt = json_encode([
        "student_name"     => $studentName,
        "career_interests" => $selectedCareers,
        "strongest_areas"  => $strongest,
        "weakest_areas"    => $weakest,
        "upcoming_modules" => $modulesPayload,
    ]);

    $payload = [
        "model"      => "claude-sonnet-4-6",
        "max_tokens" => 2000,
        "system"     => $systemPrompt,
        "messages"   => [
            ["role" => "user", "content" => $userPrompt],
        ],
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "x-api-key: " . ANTHROPIC_API_KEY,
            "anthropic-version: 2023-06-01",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT    => 20,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null; // network/API error — caller falls back
    }

    $data = json_decode($response, true);
    $text = $data["content"][0]["text"] ?? null;
    if (!$text) {
        return null;
    }

    // Strip accidental markdown fences if the model adds them
    $clean = trim(preg_replace('/^```json|```$/m', '', $text));
    $parsed = json_decode($clean, true);

    return is_array($parsed) ? $parsed : null;
}

// ─── Run the recommendation engine (only after the student submits interests) ─
$recommendations = [];
if ($hasSubmitted && !empty($nextModules)) {
    switch (RECOMMENDATION_SOURCE) {
        case 'api':
            $recommendations = getApiRecommendations($nextModules, $selectedCareers, $performanceSummary, $student["student_name"]);
            if ($recommendations === null) {
                // API not configured or failed — gracefully fall back
                $recommendations = getFallbackRecommendations($nextModules, $selectedCareers, $performanceSummary);
            }
            break;

        case 'db':
            // ── OPTIONAL DB-BACKED PATH (commented out) ──────────────────────
            // Uncomment this block after running career_knowledge_base_migration.sql
            // and populating module_career_tags_tb.
            /*
            $placeholders = implode(',', array_fill(0, count($nextModules), '?'));
            $stmtTags = $pdo->prepare("
                SELECT t.module_code, t.relevance, t.key_concepts, t.real_world_use, c.career_name
                FROM   module_career_tags_tb t
                JOIN   career_paths_tb c ON t.career_ID = c.career_ID
                WHERE  t.module_code IN ($placeholders)
            ");
            $stmtTags->execute(array_map(fn($m) => $m['module_code'], $nextModules));
            $tagRows = $stmtTags->fetchAll();
            // ... build $recommendations from $tagRows, matching against $selectedCareers ...
            */
            $recommendations = getFallbackRecommendations($nextModules, $selectedCareers, $performanceSummary);
            break;

        case 'fallback':
        default:
            $recommendations = getFallbackRecommendations($nextModules, $selectedCareers, $performanceSummary);
            break;
    }
}

// ─── Priority badge CSS class helper ──────────────────────────────────────────
function priorityClass(string $p): string {
    return match($p) {
        'high'   => 'badge-high',
        'medium' => 'badge-medium',
        default  => 'badge-standard',
    };
}

// ─── Always-visible module details (credit unit/CGPA weight, concepts,
//     real-world relevance, career prospects) — no career selection needed ───
$moduleDetails = buildModuleDetails($nextModules, $nextModules);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Goal Planning – Cavendish Portal</title>
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
        .site-header .crest {
            width: 2.6rem; height: 2.6rem; object-fit: contain; flex: 0 0 auto;
            background: #fff; border-radius: 6px; padding: 2px;
        }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name { font-weight: 600; font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; color: #d9c581; }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }
        .header-right { margin-left: auto; width: 1px; }

        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; }
        .tab-btn {
            padding: .8rem 1.1rem .7rem; border: none; background: transparent;
            font-size: .85rem; font-weight: 600; cursor: pointer; color: rgba(255,255,255,0.68);
            text-decoration: none; border-bottom: 3px solid transparent;
            transition: color .15s, border-color .15s, background .15s;
        }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,0.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        .page-wrap { max-width: 1000px; margin: 1.5rem auto; padding: 0 1.5rem 3rem; }

        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1.5rem; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid  { font-size: .95rem; font-weight: 400; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; }

        .card { border: 1px solid #999; border-radius: 6px; margin-bottom: 1.75rem; overflow: hidden; }
        .card-header {
            background: #121e38; color: #fff; font-weight: 700; font-size: .92rem;
            padding: .65rem 1rem; display: flex; align-items: center; justify-content: space-between;
            flex-wrap: wrap; gap: .5rem;
        }
        .card-sub { font-size: .8rem; font-weight: 400; opacity: .85; }
        .card-body { background: #e8e8e8; padding: 1.1rem 1rem 1.4rem; }

        .career-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: .75rem; }
        .career-option {
            background: #fff; border: 1px solid #aaa; border-radius: 6px; padding: .75rem .9rem;
            cursor: pointer; transition: border-color .15s, background .15s; display: block;
        }
        .career-option:hover { border-color: #213769; }
        .career-option input { margin-right: .5rem; }
        .career-option .career-title { font-weight: 600; font-size: .9rem; }
        .career-option .career-desc { font-size: .78rem; color: #555; margin-top: .25rem; line-height: 1.35; }
        .career-option.checked { border-color: #213769; background: #eef1fa; }

        .btn-submit {
            margin-top: 1rem; padding: .55rem 1.4rem; border: none; border-radius: 20px;
            background: #213769; color: #fff; font-weight: 600; font-size: .88rem; cursor: pointer;
        }
        .btn-submit:hover { background: #1a2c54; }

        .stat-row { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: .25rem; }
        .stat-box {
            flex: 1; min-width: 150px; background: #fff; border: 1px solid #999;
            border-radius: 6px; padding: .8rem 1rem;
        }
        .stat-box .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: #555; margin-bottom: .25rem; }
        .stat-box .value { font-size: 1.3rem; font-weight: 700; color: #121e38; }
        .mini-list { list-style: none; margin-top: .4rem; font-size: .82rem; }
        .mini-list li { padding: .15rem 0; }

        .module-rec {
            background: #fff; border: 1px solid #999; border-radius: 6px;
            padding: .9rem 1.1rem; margin-bottom: .9rem;
        }
        .module-rec:last-child { margin-bottom: 0; }
        .module-rec-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .75rem; flex-wrap: wrap; }
        .module-rec-title { font-weight: 700; font-size: .95rem; }
        .module-rec-code  { font-size: .8rem; color: #555; font-weight: 500; }
        .module-rec-cu    { font-size: .78rem; color: #555; margin-top: .15rem; }

        .badge {
            display: inline-block; font-size: .72rem; font-weight: 700; padding: .2rem .6rem;
            border-radius: 12px; white-space: nowrap;
        }
        .badge-high     { background: #fde2e2; color: #991b1b; }
        .badge-medium   { background: #fef3c7; color: #92400e; }
        .badge-standard { background: #e0e7ff; color: #3730a3; }

        .rec-section { margin-top: .6rem; }
        .rec-label { font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #444; margin-bottom: .25rem; }
        .concept-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
        .concept-tag { background: #eef1fa; border: 1px solid #c7cfe8; color: #213769; font-size: .78rem; padding: .2rem .55rem; border-radius: 10px; }
        .rec-text { font-size: .85rem; color: #222; line-height: 1.45; }
        .rationale { font-size: .82rem; font-style: italic; color: #444; margin-top: .5rem; }

        .empty { text-align: center; padding: 2.5rem 1rem; color: #888; }

        /* ── Upcoming module detail cards (always visible) ── */
        .upcoming-grid { display: flex; flex-direction: column; gap: 1rem; }
        .upcoming-card {
            background: #fff; border: 1px solid #999; border-radius: 8px;
            padding: 1.1rem 1.3rem; position: relative;
        }
        .upcoming-card-head {
            display: flex; justify-content: space-between; align-items: flex-start;
            gap: .75rem; flex-wrap: wrap; margin-bottom: .7rem;
        }
        .upcoming-title { font-weight: 700; font-size: 1rem; color: #16213f; }
        .upcoming-code  { font-size: .8rem; color: #555; font-weight: 500; }
        .cu-pill {
            background: #213769; color: #fff; font-size: .76rem; font-weight: 700;
            padding: .3rem .7rem; border-radius: 14px; white-space: nowrap;
        }

        .cgpa-note {
            background: #fff7e6; border: 1px solid #f0d49a; color: #7a4b00;
            font-size: .82rem; padding: .6rem .8rem; border-radius: 6px; margin-bottom: .8rem;
            line-height: 1.45;
        }

        .upcoming-section { margin-top: .7rem; }
        .upcoming-label {
            font-size: .74rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .04em; color: #444; margin-bottom: .35rem;
        }
        .role-tags { display: flex; flex-wrap: wrap; gap: .35rem; }
        .role-tag {
            background: #eef1fa; border: 1px solid #c7cfe8; color: #213769;
            font-size: .78rem; padding: .2rem .55rem; border-radius: 10px;
        }
        .upcoming-text { font-size: .85rem; color: #222; line-height: 1.45; }

        @media (max-width: 640px) {
            .stat-row { flex-direction: column; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Goal Planning</span>
    </div>
    <div class="header-right"></div>
</header>

<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <span class="tab-btn active">Career & Module Planner</span>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <!-- ── Always-visible: upcoming semester's module details ── -->
    <div class="card">
        <div class="card-header">
            Your Upcoming Modules — Year <?= $nextYear ?>, Sem <?= $nextSem ?>
            <span class="card-sub">Credit units, key concepts, and where each one leads</span>
        </div>
        <div class="card-body">
            <?php if (empty($moduleDetails)): ?>
                <p class="empty">No module list found for Year <?= $nextYear ?>, Sem <?= $nextSem ?> under your program. Check that <code>module_tb</code> has entries for this program/year/semester.</p>
            <?php else: ?>
                <div class="upcoming-grid">
                    <?php foreach ($moduleDetails as $md): ?>
                    <div class="upcoming-card">
                        <div class="upcoming-card-head">
                            <div>
                                <div class="upcoming-title"><?= htmlspecialchars($md["module_name"]) ?></div>
                                <div class="upcoming-code"><?= htmlspecialchars($md["module_code"]) ?></div>
                            </div>
                            <span class="cu-pill"><?= htmlspecialchars($md["credit_unit"]) ?> credit units</span>
                        </div>

                        <div class="cgpa-note">📊 <?= htmlspecialchars($md["cgpa_note"]) ?></div>

                        <div class="upcoming-section">
                            <div class="upcoming-label">Key Concepts &amp; Terms</div>
                            <div class="role-tags">
                                <?php foreach ($md["key_concepts"] as $concept): ?>
                                <span class="role-tag"><?= htmlspecialchars($concept) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="upcoming-section">
                            <div class="upcoming-label">Industry Relevance &amp; Real-Life Application</div>
                            <div class="upcoming-text"><?= htmlspecialchars($md["real_world_use"]) ?></div>
                        </div>

                        <div class="upcoming-section">
                            <div class="upcoming-label">Career Prospects</div>
                            <div class="role-tags">
                                <?php foreach ($md["career_roles"] as $role): ?>
                                <span class="role-tag"><?= htmlspecialchars($role) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$hasResults): ?>
        <p class="empty">No past results found yet — goal planning works best once you have at least one semester recorded.</p>
    <?php endif; ?>

    <?php if ($hasResults): ?>
    <div class="card">
        <div class="card-header">
            Your Performance So Far
            <span class="card-sub">Based on results through Year <?= $latestYear ?>, Sem <?= $latestSemDisplay ?></span>
        </div>
        <div class="card-body">
            <div class="stat-row">
                <div class="stat-box">
                    <div class="label">Average Grade Point</div>
                    <div class="value"><?= $performanceSummary["overall_avg_gp"] ?? '—' ?></div>
                </div>
                <div class="stat-box">
                    <div class="label">Strongest Modules</div>
                    <ul class="mini-list">
                        <?php foreach ($performanceSummary["strongest"] as $s): ?>
                        <li>✅ <?= htmlspecialchars($s["module_name"]) ?> (<?= htmlspecialchars($s["letter_grade"]) ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="stat-box">
                    <div class="label">Modules to Strengthen</div>
                    <ul class="mini-list">
                        <?php foreach ($performanceSummary["weakest"] as $w): ?>
                        <li>⚠️ <?= htmlspecialchars($w["module_name"]) ?> (<?= htmlspecialchars($w["letter_grade"]) ?>)</li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            Choose Your Career Interests
            <span class="card-sub">Select one or more — we'll show you the overlap with your upcoming modules</span>
        </div>
        <div class="card-body">
            <form method="POST" action="GoalPlanning.php">
                <div class="career-grid">
                    <?php foreach ($careerOptions as $name => $desc): ?>
                    <label class="career-option <?= in_array($name, $selectedCareers) ? 'checked' : '' ?>">
                        <input type="checkbox" name="careers[]" value="<?= htmlspecialchars($name) ?>"
                               <?= in_array($name, $selectedCareers) ? 'checked' : '' ?>>
                        <span class="career-title"><?= htmlspecialchars($name) ?></span>
                        <div class="career-desc"><?= htmlspecialchars($desc) ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit" class="btn-submit">Get My Recommendations</button>
            </form>
        </div>
    </div>

    <?php if ($hasSubmitted): ?>
        <div class="card">
            <div class="card-header">
                Personalized Priority for Year <?= $nextYear ?>, Sem <?= $nextSem ?>
                <span class="card-sub">
                    <?= RECOMMENDATION_SOURCE === 'api' ? 'AI-generated' : 'Rule-based' ?> · which modules matter most for <?= implode(', ', $selectedCareers) ?>
                </span>
            </div>
            <div class="card-body">

                <?php if (empty($nextModules)): ?>
                    <p class="empty">No module list found for Year <?= $nextYear ?>, Sem <?= $nextSem ?> under your program. Check that <code>module_tb</code> has entries for this program/year/semester.</p>
                <?php elseif (empty($recommendations)): ?>
                    <p class="empty">Couldn't generate recommendations right now. Please try again.</p>
                <?php else: ?>
                    <?php foreach ($recommendations as $rec): ?>
                    <div class="module-rec">
                        <div class="module-rec-head">
                            <div>
                                <div class="module-rec-title"><?= htmlspecialchars($rec["module_name"] ?? $rec["module_code"]) ?></div>
                                <div class="module-rec-code"><?= htmlspecialchars($rec["module_code"]) ?></div>
                                <?php if (isset($rec["credit_unit"])): ?>
                                <div class="module-rec-cu"><?= htmlspecialchars($rec["credit_unit"]) ?> credit units</div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= priorityClass($rec["priority"] ?? 'standard') ?>">
                                <?= ucfirst($rec["priority"] ?? 'standard') ?> priority
                            </span>
                        </div>

                        <?php if (!empty($rec["key_concepts"])): ?>
                        <div class="rec-section">
                            <div class="rec-label">Key Concepts &amp; Technologies</div>
                            <div class="concept-tags">
                                <?php foreach ((array)$rec["key_concepts"] as $concept): ?>
                                <span class="concept-tag"><?= htmlspecialchars($concept) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($rec["real_world_use"])): ?>
                        <div class="rec-section">
                            <div class="rec-label">Real-World Application</div>
                            <div class="rec-text"><?= htmlspecialchars($rec["real_world_use"]) ?></div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($rec["rationale"])): ?>
                        <div class="rationale">💡 <?= htmlspecialchars($rec["rationale"]) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>
    <?php endif; ?>

</main>

</body>
</html>