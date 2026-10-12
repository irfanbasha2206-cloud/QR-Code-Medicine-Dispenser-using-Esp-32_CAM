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
    
    // Attempt 1: Exact QR text match or presc_id match
    $sql = "SELECT * FROM `4973_patient` WHERE qr_text = '$safeQr' OR presc_id = '$safeQr' ORDER BY id DESC LIMIT 1";
    $result = mysqli_query($conn, $sql);

    // Attempt 1b: Extract presc_id if QR text is a URL or contains PRESC_...
    if (!$result || mysqli_num_rows($result) === 0) {
        if (preg_match('/(PRESC_[a-zA-Z0-9]+)/', $qr_text, $mMatch)) {
            $foundPresc = mysqli_real_escape_string($conn, $mMatch[1]);
            $sqlPresc = "SELECT * FROM `4973_patient` WHERE presc_id = '$foundPresc' ORDER BY id DESC LIMIT 1";
            $result = mysqli_query($conn, $sqlPresc);
        }
    }

    // Attempt 1c: Extract token if QR text is a view_prescription URL
    if (!$result || mysqli_num_rows($result) === 0) {
        if (preg_match('/token=([a-zA-Z0-9]+)/', $qr_text, $tMatch)) {
            $foundToken = mysqli_real_escape_string($conn, $tMatch[1]);
            $sqlToken = "SELECT * FROM `4973_patient` WHERE token = '$foundToken' ORDER BY id DESC LIMIT 1";
            $result = mysqli_query($conn, $sqlToken);
        }
    }

    // Attempt 2: LIKE search on qr_text
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

    // ─── 5. Check if already DISPENSED ───
    $currentStatus = strtoupper($row['dispense_status'] ?? 'PENDING');
    
    // Decode Medicines first
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

    if ($currentStatus === 'DISPENSED') {
        echo json_encode([
            "status"          => "already_dispensed",
            "message"         => "This prescription has already been dispensed.",
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
            "dispensed_at"    => $row['dispensed_at'] ?? $now,
            "dispense_status" => "DISPENSED"
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // ─── 6. Ensure Columns Exist & Update Status to SCANNED ───
    try {
        $colCheck = mysqli_query($conn, "SHOW COLUMNS FROM `4973_patient` LIKE 'scanned_at'");
        if ($colCheck && mysqli_num_rows($colCheck) === 0) {
            @mysqli_query($conn, "ALTER TABLE `4973_patient` ADD COLUMN `scanned_at` DATETIME NULL");
        }
        $colCheckStatus = mysqli_query($conn, "SHOW COLUMNS FROM `4973_patient` LIKE 'dispense_status'");
        if ($colCheckStatus && mysqli_num_rows($colCheckStatus) === 0) {
            @mysqli_query($conn, "ALTER TABLE `4973_patient` ADD COLUMN `dispense_status` VARCHAR(50) DEFAULT 'PENDING'");
            @mysqli_query($conn, "ALTER TABLE `4973_patient` ADD COLUMN `dispensed_at` DATETIME NULL");
        }
    } catch (Throwable $t) {
        // Suppress any column check/alter error
    }

    // Mark as SCANNED in Database (Hardware confirm_dispense.php will set DISPENSED)
    if ($rowId > 0) {
        @mysqli_query($conn, "UPDATE `4973_patient` SET `dispense_status` = 'SCANNED', `scanned_at` = '$now' WHERE id = $rowId");
    }
    if (!empty($prescId)) {
        $safePresc = mysqli_real_escape_string($conn, $prescId);
        @mysqli_query($conn, "UPDATE `4973_patient` SET `dispense_status` = 'SCANNED', `scanned_at` = '$now' WHERE presc_id = '$safePresc'");
    }

    // ─── 7. Write Hardware Command to shared/light2.json ───
    $slots = [];
    $slotNum = 1;
    $totalPills = 0;
    foreach ($dbMedicines as $med) {
        $mName = trim($med['name'] ?? '');
        $mQty = (int)($med['qty'] ?? 1);
        if ($mName === '' || $mQty <= 0) continue;
        if ($slotNum > 3) break; // Hardware supports 3 slots (SLOT1, SLOT2, SLOT3)
        $slots[] = [
            "slot_id" => $slotNum,
            "name"    => $mName,
            "count"   => $mQty
        ];
        $totalPills += $mQty;
        $slotNum++;
    }

    $sharedDir = __DIR__ . '/shared';
    if (!is_dir($sharedDir)) {
        @mkdir($sharedDir, 0777, true);
    }
    $light2Data = [
        "command"     => "dispense",
        "presc_id"    => $prescId,
        "patient"     => $row['patient_name'] ?? 'Patient',
        "total_slots" => count($slots),
        "total_pills" => $totalPills,
        "slots"       => $slots,
        "scanned_at"  => $now,
        "status"      => "ready" // 🎯 Triggers hardware: if (command == "dispense" && status == "ready")
    ];
    $light2File = $sharedDir . '/light2.json';
    @file_put_contents($light2File, json_encode($light2Data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // ─── 8. Success Response (SCANNED — Dispensing Active) ───
    $response = [
        "status"          => "success",
        "message"         => "QR verified! Dispense sequence queued on hardware dispenser.",
        "presc_id"        => $prescId ?: 'PRESC_N/A',
        "patient"         => [
            "name"   => $row['patient_name'] ?? 'Unknown',
            "gender" => $row['gender'] ?? '-',
            "age"    => $row['age'] ?? '-',
            "dob"    => $row['dob'] ?? '-',
            "phone"  => $row['phone'] ?? '-'
        ],
        "medicines"        => $dbMedicines,
        "doctor"           => $row['doctor'] ?? 'doctor',
        "prescribed_at"    => $row['created_at'] ?? $now,
        "scanned_at"       => $now,
        "dispense_status"  => "SCANNED",
        "hardware_command" => "ready",
        "slots"            => $slots
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
