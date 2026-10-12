<?php
/**
 * confirm_dispense.php — Embedded Team Confirmation API
 * 
 * 🎯 STRICT RULE:
 * - Only SCANNED prescriptions can be marked DISPENSED
 * - PENDING → must scan first
 * - DISPENSED → already locked, no re-dispense
 * 
 * 🆕 STOCK FEATURE (Early Deduct Approach):
 * - DISPENSED → Stock already deducted at QR generate (no action)
 * - FAILED → Stock RETURN (add back to inventory)
 * - Low stock -> stock_alert.json write (for embedded hardware team)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

// ─── Connect DB ───
$connPath = __DIR__ . '/connection.php';
if (!file_exists($connPath)) {
    echo json_encode(["status" => "error", "message" => "connection.php not found"]);
    exit;
}

include_once $connPath;

if (!isset($conn) || !$conn) {
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

// ─── Health Check ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode([
        "status"      => "ready",
        "message"     => "confirm_dispense.php API active!",
        "server_time" => date('Y-m-d H:i:s')
    ]);
    exit;
}

// ─── Read Input ───
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!$input || !is_array($input)) {
    $input = $_POST;
}

$presc_id = trim($input['presc_id'] ?? '');
$status   = strtoupper(trim($input['status'] ?? 'DISPENSED'));

if (empty($presc_id)) {
    echo json_encode([
        "status"  => "error",
        "message" => "Missing presc_id",
        "received" => $rawInput
    ]);
    exit;
}

// Validate status
if (!in_array($status, ['DISPENSED', 'FAILED'])) {
    $status = 'DISPENSED';
}

$now = date('Y-m-d H:i:s');
$safeId = mysqli_real_escape_string($conn, $presc_id);

// ─── 🎯 STEP 1: Check current status ───
$checkSql = "SELECT patient_name, dispense_status, scanned_at, medicines 
             FROM `4973_patient` 
             WHERE `presc_id` = '$safeId' 
             LIMIT 1";
$checkResult = mysqli_query($conn, $checkSql);

if (!$checkResult || mysqli_num_rows($checkResult) === 0) {
    echo json_encode([
        "status"  => "error",
        "message" => "Prescription not found",
        "presc_id" => $presc_id
    ]);
    exit;
}

$currentRow = mysqli_fetch_assoc($checkResult);
$currentStatus = strtoupper($currentRow['dispense_status'] ?? 'PENDING');

// ─── 🎯 STEP 2: STRICT GUARD ───
if ($currentStatus === 'DISPENSED') {
    echo json_encode([
        "status"          => "already_dispensed",
        "message"         => "Already dispensed. Cannot dispense again.",
        "presc_id"        => $presc_id,
        "patient_name"    => $currentRow['patient_name'] ?? 'Unknown',
        "dispense_status" => 'DISPENSED'
    ]);
    exit;
}

if ($currentStatus === 'PENDING') {
    echo json_encode([
        "status"          => "error",
        "message"         => "Cannot dispense. Prescription not scanned yet. Please scan QR first.",
        "presc_id"        => $presc_id,
        "current_status"  => 'PENDING'
    ]);
    exit;
}

if ($currentStatus === 'FAILED') {
    echo json_encode([
        "status"          => "error",
        "message"         => "Prescription FAILED. Cannot dispense.",
        "presc_id"        => $presc_id,
        "current_status"  => 'FAILED'
    ]);
    exit;
}

if ($currentStatus !== 'SCANNED') {
    echo json_encode([
        "status"          => "error",
        "message"         => "Invalid status transition. Current: $currentStatus",
        "presc_id"        => $presc_id,
        "current_status"  => $currentStatus
    ]);
    exit;
}

// ─── STEP 4: Update DB (SCANNED → DISPENSED/FAILED only) ───
$safeStatus = mysqli_real_escape_string($conn, $status);

$sql = "UPDATE `4973_patient` 
        SET `dispense_status` = '$safeStatus', `dispensed_at` = '$now' 
        WHERE `presc_id` = '$safeId' AND `dispense_status` = 'SCANNED'";

if (mysqli_query($conn, $sql)) {
    // Verify update
    $verifySql = "SELECT patient_name, dispense_status, dispensed_at 
                  FROM `4973_patient` 
                  WHERE `presc_id` = '$safeId' 
                  LIMIT 1";
    $verifyResult = mysqli_query($conn, $verifySql);
    $updatedRow = mysqli_fetch_assoc($verifyResult);
    
    // ═══════════════════════════════════════════════════════
    // 🎯 STEP 5: STOCK HANDLING (Early Deduct Approach)
    // - DISPENSED: Stock already deducted at QR generate (no action)
    // - FAILED: Stock RETURN (add back to inventory)
    // ═══════════════════════════════════════════════════════
    $stockDeducted = false;
    $stockReturned = false;
    $deductedItems = [];
    $returnedItems = [];
    
    // Get patient medicines
    $medQuery = mysqli_query($conn, 
        "SELECT medicines FROM `4973_patient` 
         WHERE `presc_id` = '$safeId' LIMIT 1");
    $medRow = mysqli_fetch_assoc($medQuery);
    
    if ($medRow && !empty($medRow['medicines'])) {
        $medList = json_decode($medRow['medicines'], true);
        
        if (is_array($medList)) {
            foreach ($medList as $med) {
                $medName = trim($med['name'] ?? '');
                $qty = (int)($med['qty'] ?? 0);
                
                if ($medName === '' || $qty <= 0) continue;
                
                $safeMedName = mysqli_real_escape_string($conn, $medName);
                
                // ═══ IF DISPENSED: Stock already deducted — Just log ═══
                if ($status === 'DISPENSED') {
                    $currQ = mysqli_query($conn, 
                        "SELECT id, stock_qty FROM `4973_medicine` 
                         WHERE medicine_name = '$safeMedName' LIMIT 1");
                    $currRow = mysqli_fetch_assoc($currQ);
                    
                    if ($currRow) {
                        $deductedItems[] = [
                            'medicine'  => $medName,
                            'deducted'  => $qty,
                            'remaining' => (int)$currRow['stock_qty']
                        ];
                        $stockDeducted = true;
                    }
                }
                
                // ═══ IF FAILED: Stock RETURN (add back) ═══
                if ($status === 'FAILED') {
                    $currQ = mysqli_query($conn, 
                        "SELECT id, stock_qty FROM `4973_medicine` 
                         WHERE medicine_name = '$safeMedName' LIMIT 1");
                    $currRow = mysqli_fetch_assoc($currQ);
                    
                    if ($currRow) {
                        $medId = (int)$currRow['id'];
                        $oldStock = (int)$currRow['stock_qty'];
                        $newStock = $oldStock + $qty;
                        
                        $returnSql = "UPDATE `4973_medicine` 
                                      SET stock_qty = $newStock 
                                      WHERE id = $medId";
                        
                        if (mysqli_query($conn, $returnSql)) {
                            $returnedItems[] = [
                                'medicine'  => $medName,
                                'returned'  => $qty,
                                'new_stock' => $newStock
                            ];
                            $stockReturned = true;
                        }
                    }
                }
            }
        }
    }
    
    // ═══════════════════════════════════════════════════════
    // 🆕 STEP 6: LOW STOCK JSON WRITE (For BOTH DISPENSED & FAILED)
    // ═══════════════════════════════════════════════════════
    $lowStockNames = [];
    $alertWritten = false;
    
    // Check low stock from DB (after any stock change)
    $allLowQuery = mysqli_query($conn, 
        "SELECT medicine_name, stock_qty, min_stock 
         FROM `4973_medicine` 
         WHERE stock_qty <= min_stock 
         ORDER BY stock_qty ASC");
    
    if ($allLowQuery) {
        while ($row = mysqli_fetch_assoc($allLowQuery)) {
            $lowStockNames[] = $row['medicine_name'];
        }
    }
    
    // JSON file write (both DISPENSED & FAILED)
    $sharedDir = __DIR__ . '/shared';
    if (!is_dir($sharedDir)) {
        @mkdir($sharedDir, 0777, true);
    }
    
    $alertFile = $sharedDir . '/stock_alert.json';
    
    if (!empty($lowStockNames)) {
        // LOW STOCK alert
        $alertData = [
            'alert_type'    => 'LOW_STOCK',
            'medicine_name' => $lowStockNames,
            'alert_time'    => $now
        ];
        $alertWritten = file_put_contents(
            $alertFile, 
            json_encode($alertData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    } else {
        // CLEAR alert (all stock OK)
        $alertData = [
            'alert_type'    => 'CLEAR',
            'medicine_name' => [],
            'alert_time'    => $now
        ];
        $alertWritten = file_put_contents(
            $alertFile, 
            json_encode($alertData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }
    
    // ─── Update light2.json status (existing) ───
    $light2Path = __DIR__ . '/shared/light2.json';
    if (file_exists($light2Path)) {
        $light2 = json_decode(file_get_contents($light2Path), true);
        if (is_array($light2) && ($light2['presc_id'] ?? '') === $presc_id) {
            $light2['status'] = 'done';
            $light2['confirmed_at'] = $now;
            $light2['final_status'] = $status;
            file_put_contents($light2Path, json_encode($light2, JSON_PRETTY_PRINT));
        }
    }
    
    // ─── Response ───
    echo json_encode([
        "status"          => "success",
        "message"         => "Dispense confirmed!",
        "presc_id"        => $presc_id,
        "patient_name"    => $updatedRow['patient_name'] ?? 'Unknown',
        "dispense_status" => $updatedRow['dispense_status'] ?? $status,
        "dispensed_at"    => $updatedRow['dispensed_at'] ?? $now,
        "stock_deducted"  => $stockDeducted,
        "stock_returned"  => $stockReturned,
        "deducted_items"  => $deductedItems,
        "returned_items"  => $returnedItems,
        "low_stock_alert" => [
            "written"   => $alertWritten,
            "items"     => $lowStockNames,
            "json_url"  => "https://iotcloud22.in/4973/shared/stock_alert.json"
        ]
    ]);
} else {
    echo json_encode([
        "status"  => "error",
        "message" => "DB update failed: " . mysqli_error($conn)
    ]);
}
exit;
?>