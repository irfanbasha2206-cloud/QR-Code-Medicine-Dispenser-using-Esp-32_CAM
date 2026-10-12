<?php
/**
 * ═══════════════════════════════════════════════════════════
 * save_ai_feedback.php — Real-Time Cloud AI Dossier Storage
 * ═══════════════════════════════════════════════════════════
 * Receives doctoral-level AI analysis for prescriptions
 * and caches them for zero-latency retrieval by view_prescription.php
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

date_default_timezone_set('Asia/Kolkata');

$storageDir = __DIR__ . '/shared/ai_feedback';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0777, true);
}

// ─── GET: Retrieve cached feedback ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim($_GET['token'] ?? '');
    $presc_id = trim($_GET['presc_id'] ?? '');

    $fileToRead = null;
    if (!empty($token)) {
        $safeToken = preg_replace('/[^a-zA-Z0-9_\-]/', '', $token);
        $candidate = $storageDir . '/' . $safeToken . '.json';
        if (file_exists($candidate)) {
            $fileToRead = $candidate;
        }
    }
    if (!$fileToRead && !empty($presc_id)) {
        $safePresc = preg_replace('/[^a-zA-Z0-9_\-]/', '', $presc_id);
        $candidate = $storageDir . '/' . $safePresc . '.json';
        if (file_exists($candidate)) {
            $fileToRead = $candidate;
        }
    }

    if ($fileToRead && file_exists($fileToRead)) {
        $data = json_decode(file_get_contents($fileToRead), true);
        echo json_encode([
            "status" => "success",
            "source" => "cache",
            "data"   => $data
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        echo json_encode([
            "status"  => "not_found",
            "message" => "No AI feedback cached for this token or presc_id"
        ]);
        exit;
    }
}

// ─── POST: Save AI feedback ───
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!$payload || !is_array($payload)) {
    $payload = $_POST;
}

$token = trim($payload['token'] ?? '');
$presc_id = trim($payload['presc_id'] ?? '');
$ai_report = $payload['ai_report'] ?? $payload['report'] ?? null;

if (!$ai_report) {
    echo json_encode([
        "status" => "error",
        "message" => "Missing ai_report payload"
    ]);
    exit;
}

$savedFiles = [];

// Save by token
if (!empty($token)) {
    $safeToken = preg_replace('/[^a-zA-Z0-9_\-]/', '', $token);
    $path = $storageDir . '/' . $safeToken . '.json';
    file_put_contents($path, json_encode($ai_report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $savedFiles[] = $path;
}

// Save by presc_id
if (!empty($presc_id)) {
    $safePresc = preg_replace('/[^a-zA-Z0-9_\-]/', '', $presc_id);
    $path = $storageDir . '/' . $safePresc . '.json';
    file_put_contents($path, json_encode($ai_report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $savedFiles[] = $path;
}

echo json_encode([
    "status" => "success",
    "message" => "AI feedback saved successfully",
    "saved_files" => count($savedFiles),
    "timestamp" => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
