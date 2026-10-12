<?php
// ═══ Session Check ═══
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}
if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}
session_start();

if (!isset($_SESSION['doctor'])) {
    echo "<script>window.location.href='index.php';</script>";
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

date_default_timezone_set('Asia/Kolkata');
include 'connection.php';

$mode = $_POST['mode'] ?? 'manual';
$qrText = "";
$medicines = [];

$baseUrl = "https://iotcloud22.in/4973";

// ─── MANUAL MODE ───
if ($mode == 'manual') {
    $selectedNames = [];
    
    if (!empty($_POST['medicines'])) {
        foreach ($_POST['medicines'] as $i => $medName) {
            $medName = trim($medName);
            if ($medName === '') continue;
            
            if (in_array($medName, $selectedNames)) {
                die("❌ Duplicate medicine detected: $medName");
            }
            $selectedNames[] = $medName;
            
            $qty = (int)($_POST['qty'][$i] ?? 1);
            if ($qty < 1) $qty = 1;
            if ($qty > 4) $qty = 4;
            
            $medicines[] = [
                "name" => $medName,
                "qty"  => $qty
            ];
        }
    }
    
    $qrParts = [];
    foreach ($medicines as $med) {
        $qrParts[] = $med['name'] . ":" . $med['qty'];
    }
    $qrText = implode("|", $qrParts);
    
    if (empty($qrText)) $qrText = "EMPTY";
}

// ─── Patient Data ───
$patient_name = trim($_POST['patient_name'] ?? 'Unknown');
$gender       = trim($_POST['gender'] ?? '-');
$age          = (int)($_POST['age'] ?? 0);
$dob          = trim($_POST['dob'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
$doctor       = $_SESSION['doctor'] ?? 'doctor';

// Prescription ID
$presc_id = 'PRESC_' . uniqid();

// ─── 🎯 Patient-wise QR Image Save ───
$qrImagesDir = 'shared/qr_images';
if (!is_dir($qrImagesDir)) {
    mkdir($qrImagesDir, 0777, true);
}

// Patient-specific filename
$qrFilename = $presc_id . '.png';
$qrFilePath = $qrImagesDir . '/' . $qrFilename;
$qrImageUrl = $baseUrl . '/' . $qrFilePath;

// Generate patient-specific QR
include 'phpqrcode/qrlib.php';
QRcode::png($qrText, $qrFilePath, QR_ECLEVEL_L, 10);

// Also update main shared/qr.png (for result.php display)
QRcode::png($qrText, "shared/qr.png", QR_ECLEVEL_L, 10);

// Medicines JSON string
$medicinesJson = json_encode($medicines);

// ─── Insert into Database (with qr_image_url) ───
$sql = "INSERT INTO `4973_patient` 
        (presc_id, patient_name, gender, age, dob, phone, medicines, qr_text, qr_image_url, doctor) 
        VALUES 
        (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = mysqli_prepare($conn, $sql);

if ($stmt) {
    mysqli_stmt_bind_param(
        $stmt, 
        'sssissssss',
        $presc_id, $patient_name, $gender, $age, $dob, $phone, 
        $medicinesJson, $qrText, $qrImageUrl, $doctor
    );
    
    if (!mysqli_stmt_execute($stmt)) {
        error_log("DB Insert failed: " . mysqli_stmt_error($stmt));
    }
    
    mysqli_stmt_close($stmt);
}

header("Location: result.php");
exit;
?>