<?php
session_start();

// ════════════════════════════════════════════════════════════════════════
// CONFIG + AUTH
// ════════════════════════════════════════════════════════════════════════
$db_host = "127.0.0.1"; $db_port = "3306"; $db_name = "apaagps_db";
$db_user = "root"; $db_pass = "";

if (empty($_SESSION["student_ID"])) {
    header("Location: index.php?error=session_expired");
    exit();
}
$studentID = $_SESSION["student_ID"];

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

$stmtS = $pdo->prepare("
    SELECT s.student_ID, s.student_name, s.program_code, p.program_name, p.program_faculty
    FROM   student_tb s JOIN program_tb p ON s.program_code = p.program_code
    WHERE  s.student_ID = ? LIMIT 1
");
$stmtS->execute([$studentID]);
$student = $stmtS->fetch();
if (!$student) { header("Location: index.php?error=invalid_session"); exit(); }

// ─── Career interests ─────────────────────────────────────────────────────────
$careerOptions = [
    "Software Development"        => "Building applications, web/mobile systems, and backend services.",
    "Data Science & Analytics"    => "Extracting insight from data using statistics, ML, and visualization.",
    "Network & Cybersecurity"     => "Securing systems, networks, and infrastructure against threats.",
    "Systems & Cloud Engineering" => "Designing, deploying, and maintaining distributed/cloud systems.",
    "Business & IT Consulting"    => "Bridging business needs with technical solutions; IT governance and strategy.",
    "UI/UX & Product Design"      => "Designing usable, accessible digital products and interfaces.",
];
$selectedCareers = $_POST["careers"] ?? [];
$selectedCareers = array_values(array_intersect($selectedCareers, array_keys($careerOptions)));

// ─── Past results ─────────────────────────────────────────────────────────────
$stmtR = $pdo->prepare("
    SELECT r.year_no, r.sem_no, r.module_code, m.module_name, m.credit_unit,
           r.final_total, r.letter_grade, r.grade_point, r.status_retake_pass
    FROM   results_tb r JOIN module_tb m ON r.module_code = m.module_code
    WHERE  r.student_ID = ? ORDER BY r.year_no ASC, r.sem_no ASC
");
$stmtR->execute([$studentID]);
$pastResults = $stmtR->fetchAll();

// ─── Latest completed → next semester ─────────────────────────────────────────
$latestSem = 0;
foreach ($pastResults as $r) { $latestSem = max($latestSem, (int)$r["sem_no"]); }
$nextSemGlobal = $latestSem + 1;
$nextYear      = max(1, (int)ceil($nextSemGlobal / 2));
$nextSem       = (($nextSemGlobal - 1) % 2) + 1;

$stmtNext = $pdo->prepare("
    SELECT module_code, module_name, year_no, sem_no, credit_unit
    FROM   module_tb WHERE program_code = ? AND year_no = ? AND sem_no = ?
    ORDER  BY module_code ASC
");
$stmtNext->execute([$student["program_code"], $nextYear, $nextSemGlobal]);
$nextModules = $stmtNext->fetchAll();

// ════════════════════════════════════════════════════════════════════════
// KNOWLEDGE BASE
// ════════════════════════════════════════════════════════════════════════
function getConceptLibrary(): array {
    return [
        "security"     => ["concepts" => ["Encryption","Firewalls","Risk assessment","Authentication protocols"], "roles" => ["Security Analyst","SOC Analyst","Penetration Tester","Information Security Officer"]],
        "network"      => ["concepts" => ["OSI & TCP/IP models","IP addressing & subnetting","Network protocols (HTTP, FTP, DNS)","Data transmission & bandwidth","Network security fundamentals","Cloud & distributed basics"], "roles" => ["Network Engineer","Network Administrator","Cloud Network Specialist","Telecom Engineer"]],
        "database"     => ["concepts" => ["Advanced SQL & query optimisation","Normalisation (3NF, BCNF)","Stored procedures & triggers","Transactions & ACID","NoSQL & modern paradigms","Database security"], "roles" => ["Database Administrator","Backend Developer","Data Engineer"]],
        "design"       => ["concepts" => ["Wireframing","Usability heuristics","Prototyping","Accessibility","Design systems"], "roles" => ["UI/UX Designer","Product Designer","Front-End Developer"]],
        "system"       => ["concepts" => ["Requirements gathering","UML diagrams & modelling","System lifecycle (SDLC)","Feasibility studies","Process flow & data-flow diagrams","Prototyping techniques"], "roles" => ["Software Engineer","Systems Architect","DevOps Engineer","Cloud Engineer"]],
        "audit"        => ["concepts" => ["Compliance","Governance frameworks","Risk controls","Internal audit"], "roles" => ["IT Auditor","Compliance Officer","Risk Analyst","Governance Consultant"]],
        "professional" => ["concepts" => ["Ethics","Stakeholder communication","IT law","Professional conduct"], "roles" => ["IT Consultant","Project Coordinator","Business Analyst"]],
        "data"         => ["concepts" => ["Data modeling","Statistics","Visualization","ETL pipelines"], "roles" => ["Data Analyst","Data Scientist","BI Developer"]],
        "mobile"       => ["concepts" => ["App lifecycle","Cross-platform frameworks","UI components","Push notifications"], "roles" => ["Mobile App Developer","iOS/Android Engineer"]],
        "web"          => ["concepts" => ["HTML, CSS & JavaScript","Responsive & adaptive design","Front-end frameworks","Web server configuration","Web security basics","CMS & deployment"], "roles" => ["Web Developer","Front-End Developer","Full-Stack Developer"]],
        "programming"  => ["concepts" => ["Classes & encapsulation","Inheritance & polymorphism","Abstraction & interfaces","Design patterns (MVC, Singleton)","Exception handling","OO analysis & UML"], "roles" => ["Software Engineer","Backend Developer","Mobile App Developer"]],
        "project"      => ["concepts" => ["Scope management","Agile/Scrum","Stakeholder planning","Risk management"], "roles" => ["IT Project Manager","Scrum Master","Delivery Lead"]],
        "business"     => ["concepts" => ["Business plan development","Small business finance","Market analysis","Risk management","Entrepreneurial mindset","Startup operations"], "roles" => ["Business Analyst","Product Manager","Founder / Entrepreneur"]],
        "default"      => ["concepts" => ["Problem-solving","Technical documentation","Collaboration tools","Critical thinking"], "roles" => ["IT Generalist","Technical Support Specialist","Junior Developer"]],
    ];
}

function getAreaExtras(): array {
    return [
        "programming" => ["rw" => "Object-oriented programming is the dominant paradigm in professional software development — used in Java, Python and C# to build scalable, maintainable, reusable codebases. Mastery of OOP is a non-negotiable requirement for virtually every software role.", "project" => "Build a library-management system in Java: model Books, Members and Loans as classes, then use inheritance for e-book and reference-only types and a design pattern to handle overdue notifications.", "demand" => ["Very high", 92]],
        "web"         => ["rw" => "Web development is one of the most in-demand skill sets in the software industry, powering everything from e-commerce platforms to enterprise dashboards. Proficiency here directly expands your employability as a full-stack or front-end developer.", "project" => "Build and deploy a responsive multi-page site with a working contact form, clean semantic HTML and accessible CSS.", "demand" => ["Very high", 90]],
        "database"    => ["rw" => "Almost every software application relies on a robust database backend; advanced database skills let developers design efficient data layers, optimise performance and handle large-scale data reliably. This expertise is highly valued in full-stack and back-end roles.", "project" => "Design a normalised schema for a real domain, then write optimised queries, a view and a stored procedure against sample data.", "demand" => ["Very high", 88]],
        "system"      => ["rw" => "Software developers use systems analysis to translate client requirements into structured, buildable solutions before writing a single line of code. It is a critical bridge between stakeholder needs and technical implementation on every professional team.", "project" => "Produce a full requirements-and-design package (use-case, class and sequence diagrams) for a small business system.", "demand" => ["High", 84]],
        "network"     => ["rw" => "Software developers must understand how data travels across networks to build reliable, secure, performant applications — especially when working with APIs, cloud services or distributed systems. Network knowledge is essential for debugging connectivity and designing scalable architectures.", "project" => "Map and document a small LAN, then capture and analyse traffic to explain how a web request travels end to end.", "demand" => ["High", 80]],
        "data"        => ["rw" => "Turning raw data into insight is a fast-growing, cross-industry skill spanning analytics, reporting and machine learning — increasingly expected even of general software roles.", "project" => "Take a public dataset, clean it, and build a short dashboard that answers three concrete questions about it.", "demand" => ["Very high", 88]],
        "security"    => ["rw" => "Cybersecurity skills are in critical shortage worldwide; securing systems and data is a top priority for every serious organisation, from banks to government.", "project" => "Run a basic security assessment of a sample application: identify vulnerabilities and propose concrete, prioritised mitigations.", "demand" => ["Very high", 90]],
        "design"      => ["rw" => "Product and interface design is how software wins and keeps users; strong UX skills make you valuable across product and front-end teams.", "project" => "Design and prototype a three-screen mobile flow in Figma, then test it with two users and iterate on their feedback.", "demand" => ["High", 78]],
        "audit"       => ["rw" => "IT governance, risk and audit skills bridge technology and the business — increasingly required as firms modernise operations and face compliance pressure.", "project" => "Perform a lightweight controls review of a process and produce a short findings-and-recommendations report.", "demand" => ["Steady", 64]],
        "professional"=> ["rw" => "Professional practice — ethics, communication and stakeholder management — is what turns a capable technician into a trusted colleague and consultant.", "project" => "Prepare and deliver a short stakeholder briefing that translates a technical decision into plain business terms.", "demand" => ["Steady", 66]],
        "project"     => ["rw" => "Project planning and delivery skills (Agile, scope, risk) are what actually get software shipped, and are valued in every role that touches delivery.", "project" => "Plan a two-week sprint for a small feature: backlog, estimates, a simple burndown and a risk log.", "demand" => ["High", 76]],
        "business"    => ["rw" => "Enables software developers to understand the business context of the products they build and potentially launch their own tech ventures. Foundational for evaluating client needs and project viability in a development career.", "project" => "Draft a one-page business model for a small software product idea: customers, value, costs and a first go-to-market step.", "demand" => ["Standard", 60]],
        "mobile"      => ["rw" => "Mobile development remains in high demand as businesses meet their users on phones first.", "project" => "Build a simple two-screen mobile app that stores and lists items locally.", "demand" => ["High", 82]],
        "default"     => ["rw" => "These are foundational skills that apply across almost every IT role, and a strong grade here signals reliability to future employers.", "project" => "Apply this module's core ideas to a small self-chosen practical task and document your approach and result.", "demand" => ["Steady", 62]],
    ];
}

function getCareerProfiles(): array {
    return [
        "Software Development"        => ["areas" => ["system","database","programming","web"], "support" => ["network","data","project","mobile"]],
        "Data Science & Analytics"    => ["areas" => ["data","database"], "support" => ["programming","system"]],
        "Network & Cybersecurity"     => ["areas" => ["security","network"], "support" => ["system"]],
        "Systems & Cloud Engineering" => ["areas" => ["system","network"], "support" => ["database","programming"]],
        "Business & IT Consulting"    => ["areas" => ["audit","professional","project","business"], "support" => ["data","system"]],
        "UI/UX & Product Design"      => ["areas" => ["design","web"], "support" => ["mobile"]],
    ];
}

function detectModuleAreas(string $moduleName): array {
    $nameLower = strtolower($moduleName);
    $keywords = [
        'object oriented' => 'programming', 'programming' => 'programming', 'software eng' => 'programming',
        'web' => 'web', 'internet' => 'web', 'multimedia' => 'web',
        'database' => 'database', 'sql' => 'database',
        'network' => 'network', 'data communication' => 'network',
        'security' => 'security',
        'system' => 'system', 'operating system' => 'system', 'analysis and design' => 'system', 'distributed' => 'system',
        'mobile' => 'mobile',
        'data' => 'data', 'statistic' => 'data', 'research method' => 'data',
        'audit' => 'audit', 'governance' => 'audit',
        'professional' => 'professional', 'ethic' => 'professional', 'communication skills' => 'professional',
        'project' => 'project',
        'user interface' => 'design', 'design' => 'design',
        'entrepreneur' => 'business', 'business' => 'business', 'e-commerce' => 'business',
    ];
    $matched = [];
    foreach ($keywords as $kw => $area) {
        if (str_contains($nameLower, $kw)) { $matched[$area] = true; }
    }
    return $matched ? array_keys($matched) : ["default"];
}

function priorityLabel(string $p): string {
    return match($p) { 'high' => 'High priority', 'medium' => 'Medium priority', default => 'Standard priority' };
}

/**
 * Builds one full "dossier" per upcoming module: priority + matched career for
 * the current selection, concepts, real-world use, a build-this-semester project,
 * career roles, market demand, and a rule-based personalised insight.
 */
function buildDossier(array $nextModules, array $selectedCareers, array $pastResults): array {
    $lib      = getConceptLibrary();
    $extras   = getAreaExtras();
    $profiles = getCareerProfiles();
    $out = [];

    foreach ($nextModules as $mod) {
        $areas   = detectModuleAreas($mod["module_name"]);
        $primary = $areas[0];

        $concepts = []; $roles = [];
        foreach ($areas as $a) {
            $concepts = array_merge($concepts, $lib[$a]["concepts"] ?? []);
            $roles    = array_merge($roles,    $lib[$a]["roles"] ?? []);
        }
        $concepts = array_slice(array_values(array_unique($concepts)), 0, 6);
        $roles    = array_slice(array_values(array_unique($roles)), 0, 3);

        $ex = $extras[$primary] ?? $extras["default"];

        // Priority + matched career against the selected interests
        $priority = "standard"; $matched = null;
        foreach ($selectedCareers as $c) {
            if (array_intersect($areas, $profiles[$c]["areas"] ?? [])) { $priority = "high"; $matched = $c; break; }
        }
        if ($priority !== "high") {
            foreach ($selectedCareers as $c) {
                if (array_intersect($areas, $profiles[$c]["support"] ?? [])) { $priority = "medium"; $matched = $matched ?? $c; break; }
            }
        }

        // Weakest related past module, for a grounded insight
        $relWeak = null;
        foreach ($pastResults as $r) {
            if (array_intersect(detectModuleAreas($r["module_name"]), $areas)) {
                if ($relWeak === null || (float)$r["grade_point"] < (float)$relWeak["grade_point"]) { $relWeak = $r; }
            }
        }
        $frame = $priority === "high" ? "the single most important module" : ($priority === "medium" ? "a strong supporting module" : "a foundational module");
        $insight = "This is {$frame}" . ($matched ? " for your " . $matched . " path." : " for your overall degree.");
        if ($relWeak && (float)$relWeak["grade_point"] < 4.0) {
            $insight .= " Given your weaker grade in {$relWeak['module_name']} (GP {$relWeak['grade_point']}), early deliberate practice here builds the foundation everything else stands on.";
        } elseif ($relWeak) {
            $insight .= " Your strong record in related work (e.g. {$relWeak['module_name']}, {$relWeak['letter_grade']}) means you can realistically aim for a top grade.";
        } else {
            $insight .= " Build good habits from week one — consistent effort here compounds across the rest of your programme.";
        }

        $out[] = [
            "code"        => $mod["module_code"],
            "name"        => $mod["module_name"],
            "cu"          => (float)$mod["credit_unit"],
            "priority"    => $priority,
            "matched"     => $matched,
            "concepts"    => $concepts,
            "roles"       => $roles,
            "real_world"  => $ex["rw"],
            "project"     => $ex["project"],
            "demand_lvl"  => $ex["demand"][0],
            "demand_pct"  => $ex["demand"][1],
            "insight"     => $insight,
        ];
    }

    $rank = ["high" => 0, "medium" => 1, "standard" => 2];
    usort($out, fn($a, $b) => $rank[$a["priority"]] <=> $rank[$b["priority"]]);
    return $out;
}

$dossier = buildDossier($nextModules, $selectedCareers, $pastResults);
$cuFmt   = fn($cu) => rtrim(rtrim(number_format((float)$cu, 1), '0'), '.');

// Compact per-module context sent to GoalInsight.php so Claude can explain the
// MODULE (its topics, relevance, roles) as well as tie it to the student.
$insightData = [];
foreach ($dossier as $d) {
    $insightData[$d["code"]] = [
        "concepts"   => $d["concepts"],
        "real_world" => $d["real_world"],
        "roles"      => $d["roles"],
        "matched"    => $d["matched"],
        "priority"   => $d["priority"],
        "demand"     => $d["demand_lvl"],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Career &amp; Module Planner – Cavendish Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; font-size: 14px; color: #101828; background: #eef0f5; }

        .site-header { display: flex; align-items: center; gap: .9rem; padding: 1rem 1.5rem; background: #213769; border-bottom: 1px solid #16213f; }
        .site-header .crest { width: 2.6rem; height: 2.6rem; object-fit: contain; flex: 0 0 auto; background: #fff; border-radius: 6px; padding: 2px; }
        .header-text { display: flex; flex-direction: column; line-height: 1.25; }
        .site-header .uni-name { font-weight: 600; font-size: .72rem; letter-spacing: .14em; text-transform: uppercase; color: #d9c581; }
        .site-header .portal-title { font-weight: 700; font-size: 1.05rem; color: #fff; }
        .header-right { margin-left: auto; }
        .logout-btn { color: #cdd6ef; font-size: .8rem; font-weight: 600; text-decoration: none; border: 1px solid rgba(255,255,255,.3); border-radius: 999px; padding: .35rem .9rem; white-space: nowrap; transition: background .15s, color .15s; }
        .logout-btn:hover { background: rgba(255,255,255,.12); color: #fff; }

        .tab-nav { display: flex; gap: .35rem; padding: 0 1.5rem; background: #16213f; border-bottom: 1px solid #0d1730; overflow-x: auto; }
        .tab-btn { padding: .8rem 1.1rem .7rem; border: none; background: transparent; font-size: .85rem; font-weight: 600; cursor: pointer; white-space: nowrap; color: rgba(255,255,255,.68); text-decoration: none; border-bottom: 3px solid transparent; }
        .tab-btn:hover { color: #fff; background: rgba(255,255,255,.06); }
        .tab-btn.active { color: #fff; border-bottom-color: #c9a227; }

        .page-wrap { max-width: 1080px; margin: 0 auto; padding: 0 1.5rem 3rem; }
        .student-row { display: flex; justify-content: space-between; align-items: baseline; margin: 1.4rem 0 1rem; }
        .student-name { font-size: 1rem; font-weight: 700; }
        .student-sid { font-size: .95rem; color: #475467; }
        .student-sid strong { font-weight: 700; margin-right: .35rem; color: #213769; }

        .sub-tabs { display: flex; gap: .4rem; margin-bottom: 1.5rem; border-bottom: 2px solid #d5d9e4; }
        .sub-tab { padding: .7rem 1.4rem; border: none; background: transparent; font-family: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; color: #5a6478; border-bottom: 3px solid transparent; margin-bottom: -2px; display: inline-flex; align-items: center; gap: .4rem; }
        .sub-tab:hover { color: #213769; }
        .sub-tab.active { color: #213769; border-bottom-color: #c9a227; }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        .picker { background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); overflow: hidden; margin-bottom: 1.5rem; }
        .picker-head { background: #16213f; color: #fff; padding: .9rem 1.2rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .picker-head .t { font-weight: 700; font-size: 1rem; }
        .picker-head .s { font-size: .8rem; color: #c7cede; }
        .picker-body { padding: 1.1rem 1.2rem; }
        .picker-body form { display: flex; flex-wrap: wrap; gap: .6rem; }
        .cpill { position: relative; display: inline-flex; align-items: center; border: 1.5px solid #d0d5dd; background: #fff; color: #344054; border-radius: 999px; padding: .5rem 1rem; font-size: .85rem; font-weight: 600; cursor: pointer; user-select: none; white-space: nowrap; transition: all .12s; }
        .cpill:hover { border-color: #213769; }
        .cpill.on { background: #16213f; border-color: #16213f; color: #fff; }

        .planner { display: flex; gap: 1.2rem; align-items: flex-start; }
        .pm-sidebar { flex: 0 0 270px; background: #16213f; border-radius: 14px; overflow: hidden; }
        .pm-side-head { padding: .9rem 1.1rem; color: #d9c581; font-size: .68rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; border-bottom: 1px solid rgba(255,255,255,.08); }
        .pm-side-head b { display: block; color: #fff; font-size: .95rem; letter-spacing: 0; text-transform: none; margin-top: .15rem; }
        .pm-item { width: 100%; text-align: left; background: transparent; border: none; border-left: 3px solid transparent; padding: .8rem 1.1rem; cursor: pointer; color: #cdd6ef; display: block; }
        .pm-item:hover { background: rgba(255,255,255,.05); }
        .pm-item.active { background: rgba(255,255,255,.08); border-left-color: #c9a227; }
        .pm-item .pm-name { font-weight: 600; font-size: .86rem; color: #fff; display: flex; align-items: center; gap: .5rem; }
        .pm-item .dot { width: .6rem; height: .6rem; border-radius: 50%; flex: 0 0 auto; }
        .pm-item .pm-meta { font-size: .74rem; color: #9fb0d6; margin-top: .2rem; margin-left: 1.1rem; }
        .dot.high { background: #ef4444; } .dot.medium { background: #f59e0b; } .dot.standard { background: #3b6fe0; }

        .pm-detail { flex: 1 1 auto; min-width: 0; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); padding: 1.4rem 1.6rem; }
        .pm-panel { display: none; }
        .pm-panel.active { display: block; }
        .matched-label { font-size: .68rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #b7791f; margin-bottom: .35rem; }
        .pm-title-row { display: flex; align-items: center; gap: .7rem; flex-wrap: wrap; }
        .pm-title { font-size: 1.35rem; font-weight: 800; color: #101828; }
        .pm-code { font-size: .82rem; color: #667085; margin-top: .2rem; }
        .badge { display: inline-block; font-size: .72rem; font-weight: 700; padding: .2rem .6rem; border-radius: 999px; white-space: nowrap; }
        .badge-high { background: #fde2e2; color: #b42318; }
        .badge-medium { background: #fef3c7; color: #92400e; }
        .badge-standard { background: #e0e7ff; color: #3538cd; }

        .tagset { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .9rem; }
        .tag { background: #eef2fb; border: 1px solid #d6def5; color: #274690; font-size: .78rem; padding: .25rem .6rem; border-radius: 999px; }

        .sec-label { font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #98a2b3; margin: 1.1rem 0 .35rem; }
        .rw-text { font-size: .88rem; color: #344054; line-height: 1.55; }

        .build-box { background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; padding: .9rem 1.1rem; margin-top: 1rem; }
        .build-box .sec-label { color: #92722a; margin-top: 0; }
        .build-box .txt { font-size: .86rem; color: #6b5416; line-height: 1.5; }

        .two-col { display: flex; gap: 1rem; margin-top: 1rem; flex-wrap: wrap; }
        .roles-box, .demand-box { flex: 1 1 240px; border: 1px solid #e6e8ec; border-radius: 10px; padding: .8rem 1rem; }
        .role-pill { display: inline-block; font-size: .76rem; font-weight: 600; padding: .25rem .6rem; border-radius: 8px; background: #eef1f4; color: #475467; margin: .2rem .2rem 0 0; }
        .role-pill.lead { background: #16213f; color: #fff; }
        .demand-bar { height: 8px; border-radius: 999px; background: #eef1f4; overflow: hidden; margin: .5rem 0 .35rem; }
        .demand-fill { height: 100%; background: linear-gradient(90deg,#d9a520,#c9a227); border-radius: 999px; }
        .demand-lvl { font-size: .85rem; font-weight: 700; color: #101828; }

        .insight-box { border-left: 4px solid #16213f; background: #f7f8fb; border-radius: 8px; padding: .9rem 1.1rem; margin-top: 1.1rem; }
        .insight-head { display: flex; justify-content: space-between; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .5rem; }
        .insight-head .sec-label { margin: 0; }
        .gen-btn { background: #16213f; color: #fff; border: none; border-radius: 999px; padding: .4rem .9rem; font-size: .78rem; font-weight: 600; cursor: pointer; font-family: inherit; }
        .gen-btn:hover { background: #0c1730; }
        .gen-btn:disabled { opacity: .6; cursor: not-allowed; }
        .insight-text { font-size: .86rem; color: #344054; line-height: 1.55; }
        .insight-text.err { color: #b42318; }

        .mp-intro { color: #475467; font-size: .92rem; margin-bottom: 1.2rem; }
        .mod-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1.1rem; }
        .mod-card { background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; box-shadow: 0 1px 3px rgba(16,24,40,.05); padding: 1.1rem 1.3rem; }
        .mod-card-head { display: flex; justify-content: space-between; align-items: flex-start; gap: .6rem; }
        .mod-card-title { font-size: 1rem; font-weight: 700; color: #101828; }
        .mod-card-code { font-size: .8rem; color: #667085; margin-top: .15rem; }

        .empty { text-align: center; padding: 3rem 1rem; color: #98a2b3; background: #fff; border: 1px solid #e6e8ec; border-radius: 14px; }

        @media (max-width: 820px) {
            .planner { flex-direction: column; }
            .pm-sidebar { flex-basis: auto; width: 100%; }
            .mod-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header class="site-header">
    <img class="crest" src="images/cu_logo.jpg" alt="Cavendish University crest">
    <div class="header-text">
        <span class="uni-name">Cavendish University</span>
        <span class="portal-title">Academic Performance and Career &amp; Module Planning</span>
    </div>
    <div class="header-right"><a class="logout-btn" href="logout.php">Log out</a></div>
</header>

<nav class="tab-nav">
    <a class="tab-btn" href="ExamResultInterface.php">Results</a>
    <a class="tab-btn" href="AnalysisResultInterface.php">Analysis</a>
    <span class="tab-btn active">Career &amp; Module Planner</span>
    <a class="tab-btn" href="ModuleRegistration.php">Module Registration</a>
    <a class="tab-btn" href="MyReportsStatus.php">My Reports</a>
</nav>

<main class="page-wrap">

    <div class="student-row">
        <div class="student-name">Dear, <?= htmlspecialchars(strtoupper($student["student_name"])) ?></div>
        <div class="student-sid"><strong>SID:</strong><?= htmlspecialchars($student["student_ID"]) ?></div>
    </div>

    <?php $activePanel = !empty($selectedCareers) ? 'career' : 'module'; ?>
    <div class="sub-tabs">
        <button type="button" class="sub-tab <?= $activePanel === 'module' ? 'active' : '' ?>" data-panel="module">📚 Module Planner</button>
        <button type="button" class="sub-tab <?= $activePanel === 'career' ? 'active' : '' ?>" data-panel="career">🎯 Career Planner</button>
    </div>

    <!-- ══════════ MODULE PLANNER TAB ══════════ -->
    <div class="tab-panel <?= $activePanel === 'module' ? 'active' : '' ?>" data-panel="module">
        <p class="mp-intro">Your registered modules for <strong>Year <?= $nextYear ?>, Semester <?= $nextSem ?></strong> — open the Career Planner to see how each one builds toward a role.</p>
        <?php if (empty($dossier)): ?>
            <p class="empty">No module list found for Year <?= $nextYear ?>, Sem <?= $nextSem ?> under your program.</p>
        <?php else: ?>
        <div class="mod-grid">
            <?php foreach ($dossier as $d): ?>
            <div class="mod-card">
                <div class="mod-card-head">
                    <div>
                        <div class="mod-card-title"><?= htmlspecialchars($d["name"]) ?></div>
                        <div class="mod-card-code"><?= htmlspecialchars($d["code"]) ?> · <?= $cuFmt($d["cu"]) ?> credits</div>
                    </div>
                    <span class="badge badge-<?= $d["priority"] ?>"><?= priorityLabel($d["priority"]) ?></span>
                </div>
                <div class="sec-label">Key Concepts &amp; Technologies</div>
                <div class="tagset">
                    <?php foreach ($d["concepts"] as $c): ?><span class="tag"><?= htmlspecialchars($c) ?></span><?php endforeach; ?>
                </div>
                <div class="sec-label">Real-World Application</div>
                <div class="rw-text"><?= htmlspecialchars($d["real_world"]) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ══════════ CAREER PLANNER TAB ══════════ -->
    <div class="tab-panel <?= $activePanel === 'career' ? 'active' : '' ?>" data-panel="career">

        <div class="picker">
            <div class="picker-head">
                <span class="t">Choose your career interests</span>
                <span class="s">Select one or more — your dossier updates to match</span>
            </div>
            <div class="picker-body">
                <form method="POST" action="GoalPlanning.php" id="careerForm">
                    <?php foreach ($careerOptions as $name => $desc): $on = in_array($name, $selectedCareers, true); ?>
                    <label class="cpill <?= $on ? 'on' : '' ?>">
                        <input type="checkbox" name="careers[]" value="<?= htmlspecialchars($name) ?>" <?= $on ? 'checked' : '' ?> style="position:absolute;opacity:0;width:0;height:0;">
                        <?= htmlspecialchars($name) ?>
                    </label>
                    <?php endforeach; ?>
                </form>
            </div>
        </div>

        <?php if (empty($dossier)): ?>
            <p class="empty">No module list found for Year <?= $nextYear ?>, Sem <?= $nextSem ?> under your program.</p>
        <?php else: ?>
        <div class="planner">
            <aside class="pm-sidebar">
                <div class="pm-side-head">Your Modules <b>Year <?= $nextYear ?> · Semester <?= $nextSem ?></b></div>
                <?php foreach ($dossier as $i => $d): ?>
                <button type="button" class="pm-item <?= $i === 0 ? 'active' : '' ?>" data-target="mod-<?= $i ?>">
                    <span class="pm-name"><span class="dot <?= $d["priority"] ?>"></span><?= htmlspecialchars($d["name"]) ?></span>
                    <span class="pm-meta"><?= htmlspecialchars($d["code"]) ?> · <?= priorityLabel($d["priority"]) ?></span>
                </button>
                <?php endforeach; ?>
            </aside>

            <div class="pm-detail">
                <?php foreach ($dossier as $i => $d): ?>
                <div class="pm-panel <?= $i === 0 ? 'active' : '' ?>" id="mod-<?= $i ?>">
                    <?php if ($d["matched"]): ?>
                    <div class="matched-label">Matched to <?= htmlspecialchars($d["matched"]) ?></div>
                    <?php endif; ?>
                    <div class="pm-title-row">
                        <span class="pm-title"><?= htmlspecialchars($d["name"]) ?></span>
                        <span class="badge badge-<?= $d["priority"] ?>"><?= priorityLabel($d["priority"]) ?></span>
                    </div>
                    <div class="pm-code"><?= htmlspecialchars($d["code"]) ?> · <?= $cuFmt($d["cu"]) ?> credits</div>

                    <div class="tagset">
                        <?php foreach ($d["concepts"] as $c): ?><span class="tag"><?= htmlspecialchars($c) ?></span><?php endforeach; ?>
                    </div>

                    <div class="sec-label">Real-World Application</div>
                    <div class="rw-text"><?= htmlspecialchars($d["real_world"]) ?></div>

                    <div class="build-box">
                        <div class="sec-label">Build This Semester</div>
                        <div class="txt"><?= htmlspecialchars($d["project"]) ?></div>
                    </div>

                    <div class="two-col">
                        <div class="roles-box">
                            <div class="sec-label" style="margin-top:0;">Career Roles This Builds Toward</div>
                            <?php foreach ($d["roles"] as $ri => $role): ?>
                            <span class="role-pill <?= $ri === 0 ? 'lead' : '' ?>"><?= htmlspecialchars($role) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <div class="demand-box">
                            <div class="sec-label" style="margin-top:0;">Market Demand</div>
                            <div class="demand-bar"><div class="demand-fill" style="width:<?= (int)$d["demand_pct"] ?>%;"></div></div>
                            <div class="demand-lvl"><?= htmlspecialchars($d["demand_lvl"]) ?></div>
                        </div>
                    </div>

                    <div class="insight-box">
                        <div class="insight-head">
                            <span class="sec-label">Personalised Insight</span>
                            <button type="button" class="gen-btn"
                                    data-code="<?= htmlspecialchars($d["code"]) ?>"
                                    data-name="<?= htmlspecialchars($d["name"]) ?>">✦ Generate with Claude</button>
                        </div>
                        <div class="insight-text"><?= htmlspecialchars($d["insight"]) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</main>

<script>
    document.querySelectorAll('.sub-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var t = btn.dataset.panel;
            document.querySelectorAll('.sub-tab').forEach(b => b.classList.toggle('active', b.dataset.panel === t));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === t));
        });
    });

    document.querySelectorAll('#careerForm .cpill input').forEach(function (cb) {
        cb.addEventListener('change', function () {
            cb.closest('.cpill').classList.toggle('on', cb.checked);
            document.getElementById('careerForm').submit();
        });
    });

    document.querySelectorAll('.pm-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var target = item.dataset.target;
            document.querySelectorAll('.pm-item').forEach(i => i.classList.toggle('active', i === item));
            document.querySelectorAll('.pm-panel').forEach(p => p.classList.toggle('active', p.id === target));
        });
    });

    const careers = <?= json_encode($selectedCareers) ?>;
    const dossierMap = <?= json_encode($insightData) ?>;
    document.querySelectorAll('.gen-btn').forEach(function (btn) {
        btn.addEventListener('click', async function () {
            const box = btn.closest('.insight-box').querySelector('.insight-text');
            const original = btn.textContent;
            btn.disabled = true; btn.textContent = 'Generating…';
            box.classList.remove('err');
            try {
                const extra = dossierMap[btn.dataset.code] || {};
                const res = await fetch('GoalInsight.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(Object.assign({ module_code: btn.dataset.code, module_name: btn.dataset.name, careers: careers }, extra))
                });
                const data = await res.json();
                if (data.success) { box.textContent = data.insight; }
                else { box.textContent = data.error || 'Could not generate an insight right now.'; box.classList.add('err'); }
            } catch (e) {
                box.textContent = 'Could not reach the server. Please try again.'; box.classList.add('err');
            } finally {
                btn.disabled = false; btn.textContent = original;
            }
        });
    });
</script>

</body>
</html>
