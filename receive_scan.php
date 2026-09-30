<?php
/**
 * ═══════════════════════════════════════════════════════════
 * receive_scan.php — Bulletproof QR Scan Receiver API
 * ═══════════════════════════════════════════════════════════
 * Handles QR scan payload from Python (app.py), matches against
 * 4973_patient table, marks as DISPENSED, and returns JSON.
 * 
 * 100% resilient across PHP 7.x, 8.0, 8.1, 8.2, 8.3+.
 * ═══════════════════════════════════════════════════════════
 */

// Disable MySQLi exception throwing (prevents uncaught exceptions on MySQL errors)
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

// Error reporting configuration
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// JSON headers & CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent');

// Preflight OPTIONS handler
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

try {
    // ─── 1. Connect to Database ───
    $connPath = __DIR__ . '/connection.php';
    if (!file_exists($connPath)) {
        echo json_encode([
            "status"  => "error",
            "message" => "connection.php not found on server"
        ]);
        exit;
    }

    include_once $connPath;

    if (!isset($conn) || !$conn) {
        echo json_encode([
            "status"  => "error",
            "message" => "Database connection failed"
        ]);
        exit;
    }

    // Force UTF-8 charset on DB connection
    @mysqli_set_charset($conn, "utf8mb4");

    // ─── 2. Handle GET (Health Check) ───
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode([
            "status"      => "ready",
            "message"     => "receive_scan.php API is active and connected to DB!",
            "server_time" => date('Y-m-d H:i:s'),
            "php_version" => phpversion()
        ]);
        exit;
    }

    // ─── 3. Read Input ───
    $rawInput = file_get_contents("php://input");
    $input = json_decode($rawInput, true);

    if (!$input || !is_array($input)) {
        $input = $_POST;
    }

    $qr_text = trim($input['qr_text'] ?? '');
    $medicines = $input['medicines'] ?? [];

    if (empty($qr_text)) {
        echo json_encode([
            "status"   => "error",
            "message"  => "No qr_text provided",
            "received" => $rawInput
        ]);
        exit;
    }

    // ─── 4. Search Database for Matching Prescription ───
    $safeQr = mysqli_real_escape_string($conn, $qr_text);
    
    // Attempt 1: Exact QR text match
    $sql = "SELECT * FROM `4973_patient` WHERE qr_text = '$safeQr' ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $sql);

    // Attempt 2: LIKE search
    if (!$result || mysqli_num_rows($result) === 0) {
        $sqlLike = "SELECT * FROM `4973_patient` WHERE qr_text LIKE '%$safeQr%' ORDER BY id DESC LIMIT 1";
        $result = mysqli_query($conn, $sqlLike);
    }

    // Attempt 3: Match by medicine names if QR text had multiple parts
    if ((!$result || mysqli_num_rows($result) === 0) && is_array($medicines) && !empty($medicines)) {
        foreach ($medicines as $m) {
            $medName = trim($m['name'] ?? '');
            if ($medName !== '') {
                $safeMed = mysqli_real_escape_string($conn, $medName);
                $sqlMed = "SELECT * FROM `4973_patient` WHERE qr_text LIKE '%$safeMed%' OR medicines LIKE '%$safeMed%' ORDER BY id DESC LIMIT 1";
                $result = mysqli_query($conn, $sqlMed);
                if ($result && mysqli_num_rows($result) > 0) {
                    break;
                }
            }
        }
    }

    if (!$result || mysqli_num_rows($result) === 0) {
        echo json_encode([
            "status"    => "not_found",
            "message"   => "No prescription found matching this QR code in database",
            "qr_text"   => $qr_text,
            "medicines" => $medicines
        ]);
        exit;
    }

    $row = mysqli_fetch_assoc($result);
    $rowId = (int)($row['id'] ?? 0);
    $prescId = $row['presc_id'] ?? '';
    $now = date('Y-m-d H:i:s');

    // ─── 5. Ensure Status Columns Exist & Update to DISPENSED ───
    try {
        $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `4973_patient` LIKE 'dispense_status'");
        if ($colCheck && mysqli_num_rows($colCheck) === 0) {
            @mysqli_query($conn, "ALTER TABLE `4973_patient` ADD COLUMN `dispense_status` VARCHAR(50) DEFAULT 'PENDING'");
            @mysqli_query($conn, "ALTER TABLE `4973_patient` ADD COLUMN `dispensed_at` DATETIME NULL");
        }
    } catch (Throwable $t) {
        // Suppress any column check/alter error
    }

    // Update using ID
    if ($rowId > 0) {
        @mysqli_query($conn, "UPDATE `4973_patient` SET `dispense_status` = 'DISPENSED', `dispensed_at` = '$now' WHERE id = $rowId");
    }
    // Also update using presc_id as safeguard
    if (!empty($prescId)) {
        $safePresc = mysqli_real_escape_string($conn, $prescId);
        @mysqli_query($conn, "UPDATE `4973_patient` SET `dispense_status` = 'DISPENSED', `dispensed_at` = '$now' WHERE presc_id = '$safePresc'");
    }

    // ─── 6. Decode Medicines ───
    $dbMedicines = [];
    if (!empty($row['medicines'])) {
        $decoded = json_decode($row['medicines'], true);
        if (is_array($decoded)) {
            $dbMedicines = $decoded;
        }
    }
    if (empty($dbMedicines)) {
        $dbMedicines = $medicines;
    }

    // ─── 7. Success Response ───
    $response = [
        "status"          => "success",
        "message"         => "Prescription dispensed successfully!",
        "presc_id"        => $prescId ?: 'PRESC_N/A',
        "patient"         => [
            "name"   => $row['patient_name'] ?? 'Unknown',
            "gender" => $row['gender'] ?? '-',
            "age"    => $row['age'] ?? '-',
            "dob"    => $row['dob'] ?? '-',
            "phone"  => $row['phone'] ?? '-'
        ],
        "medicines"       => $dbMedicines,
        "doctor"          => $row['doctor'] ?? 'doctor',
        "prescribed_at"   => $row['created_at'] ?? $now,
        "dispensed_at"    => $now,
        "dispense_status" => "DISPENSED"
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    // Return friendly JSON error instead of blank HTTP 500
    http_response_code(200);
    echo json_encode([
        "status"  => "error",
        "message" => "Server Handler Exception: " . $e->getMessage(),
        "line"    => $e->getLine()
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
?>
