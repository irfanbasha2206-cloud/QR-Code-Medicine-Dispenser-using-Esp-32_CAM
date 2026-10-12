<?php
/**
 * ═══════════════════════════════════════════════════════════
 * chat_ai.php — Cloud AI Medical Consultation Chatbot API
 * ═══════════════════════════════════════════════════════════
 * Direct failover bridge:
 * 1. Primary: Google Gemini API (gemini-1.5-flash / gemini-flash-latest)
 * 2. Failover: Groq High-Speed C-Engine (qwen-2.5-32b-instruct / llama-3.3-70b)
 * 
 * Clinical Pharmacology Persona (AI Clinical Consultant)
 * Professional Clinical Assistant (Voice and Text)
 * ═══════════════════════════════════════════════════════════
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$GEMINI_API_KEY = "AQ.Ab8RN6KF5WbJQe5EuQ_y0lrD4SItD2rmyYOMGg1SrjvxY1uPaA";
$GROQ_API_KEY   = "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";

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !is_array($payload)) {
    $payload = $_POST;
}

$userMessage = trim($payload['message'] ?? $payload['query'] ?? '');
$patient     = $payload['patient'] ?? [];
$history     = $payload['history'] ?? [];

if (empty($userMessage)) {
    echo json_encode([
        "status"  => "error",
        "message" => "Empty message provided"
    ]);
    exit;
}

$patientName = $patient['name'] ?? 'Patient';
$patientAge  = $patient['age'] ?? '25';
$patientGender = $patient['gender'] ?? 'Unspecified';
$meds = $patient['medicines'] ?? [];

$medListStr = "";
if (is_array($meds)) {
    $parts = [];
    foreach ($meds as $m) {
        if (is_array($m)) {
            $parts[] = ($m['name'] ?? 'Medicine') . ' (Qty: ' . ($m['qty'] ?? 1) . ')';
        } else {
            $parts[] = strval($m);
        }
    }
    $medListStr = implode(", ", $parts);
}

$systemPrompt = "You are an AI Clinical Consultant and Expert Clinical Pharmacologist specializing in medication therapy, patient safety, and drug interactions.\n"
    . "You are consulting regarding this patient profile:\n"
    . "- Patient Details: Age {$patientAge} years, Gender: {$patientGender}\n"
    . "- Prescribed Medicines: " . ($medListStr ?: "Standard clinical prescription") . "\n\n"
    . "CLINICAL MULTILINGUAL DIRECTIVES (LIKE CHATGPT & GEMINI LIVE):\n"
    . "1. DYNAMIC LANGUAGE MATCHING (CRITICAL):\n"
    . "   - If the patient speaks or asks in TAMIL (தமிழ்) or TANGLISH (Tamil words written in English letters, e.g. 'eppadi saapdanum', 'intha tablet', 'thookam varuma'): You MUST respond in fluent, polite, natural TAMIL (தமிழ்), keeping medicine names clear (e.g., அமாக்சிசிலின் / Amoxicillin).\n"
    . "   - If the patient speaks or asks in ENGLISH: You MUST respond in clear, reassuring, professional clinical ENGLISH.\n"
    . "2. CRITICAL RULE: Do NOT repeat or address the patient by name in your responses. The initial greeting already introduced the patient. Directly answer the question.\n"
    . "3. Provide practical advice on meal timing (before/after food, hydration), potential side effects, and missed doses.\n"
    . "4. Keep answers concise (under 75 words) suitable for both reading on screen and listening via text-to-speech audio!";

function callGemini($apiKey, $systemPrompt, $userMessage, $history) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=" . urlencode($apiKey);

    $contents = [];
    $contents[] = [
        "role" => "user",
        "parts" => [["text" => "System Instruction:\n" . $systemPrompt]]
    ];
    $contents[] = [
        "role" => "model",
        "parts" => [["text" => "Understood. I am your AI Clinical Consultant. How can I assist with your medication query?"]]
    ];

    if (is_array($history)) {
        foreach (array_slice($history, -4) as $turn) {
            $role = ($turn['role'] ?? '') === 'user' ? 'user' : 'model';
            $contents[] = [
                "role" => $role,
                "parts" => [["text" => $turn['content'] ?? '']]
            ];
        }
    }

    $contents[] = [
        "role" => "user",
        "parts" => [["text" => $userMessage]]
    ];

    $postData = json_encode([
        "contents" => $contents,
        "generationConfig" => [
            "temperature" => 0.3,
            "maxOutputTokens" => 600
        ]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($text) return trim($text);
    }
    return null;
}

function callGroq($apiKey, $systemPrompt, $userMessage, $history) {
    $url = "https://api.groq.com/openai/v1/chat/completions";

    $messages = [
        ["role" => "system", "content" => $systemPrompt]
    ];

    if (is_array($history)) {
        foreach (array_slice($history, -4) as $turn) {
            $messages[] = [
                "role" => ($turn['role'] ?? '') === 'user' ? 'user' : 'assistant',
                "content" => $turn['content'] ?? ''
            ];
        }
    }

    $messages[] = ["role" => "user", "content" => $userMessage];

    $postData = json_encode([
        "model" => "qwen/qwen3.8-27b",
        "messages" => $messages,
        "temperature" => 0.3,
        "max_tokens" => 500
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        $text = $json['choices'][0]['message']['content'] ?? null;
        if ($text) return trim($text);
    }
    return null;
}

// ─── Execute with Automatic Failover ───
$reply = null;
$engineUsed = "";

// 1. Try Gemini
$geminiRes = callGemini($GEMINI_API_KEY, $systemPrompt, $userMessage, $history);
if ($geminiRes) {
    $reply = $geminiRes;
    $engineUsed = "Gemini Flash (Google DeepMind)";
} else {
    // 2. Failover to Groq
    $groqRes = callGroq($GROQ_API_KEY, $systemPrompt, $userMessage, $history);
    if ($groqRes) {
        $reply = $groqRes;
        $engineUsed = "Groq LPU Engine (Qwen 2.5)";
    }
}

// Fallback message if both network APIs are unreachable
if (!$reply) {
    $reply = "Please adhere to your prescribed dosage schedule and take medications with meals as directed. If you experience severe allergic symptoms or acute discomfort, please consult your healthcare provider immediately.";
    $engineUsed = "Clinical Protocol Fallback";
}

echo json_encode([
    "status"    => "success",
    "reply"     => $reply,
    "engine"    => $engineUsed,
    "timestamp" => date("H:i:s")
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
