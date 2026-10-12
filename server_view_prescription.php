<?php
/**
 * view_prescription.php
 * Patient Prescription Viewer (Token-based)
 * 
 * URL: view_prescription.php?token=xxx
 * JSON: view_prescription.php?token=xxx&format=json
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

date_default_timezone_set('Asia/Kolkata');
include 'connection.php';

// ─── Get Token ───
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    die("❌ Invalid access. Token missing.");
}

// ─── Validate Format (64 hex chars) ───
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    die("❌ Invalid token format.");
}

// ─── Fetch Prescription ───
$safeToken = mysqli_real_escape_string($conn, $token);
$sql = "SELECT * FROM `4973_patient` 
        WHERE access_token = '$safeToken' 
        LIMIT 1";
$result = mysqli_query($conn, $sql);

if (!$result || mysqli_num_rows($result) === 0) {
    http_response_code(404);
    die("❌ Prescription not found.");
}

$p = mysqli_fetch_assoc($result);

// ─── JSON API (Python team) ───
$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';

// ─── Parse medicines ───
$medicines = json_decode($p['medicines'] ?? '[]', true);
if (!is_array($medicines)) $medicines = [];

// ─── JSON Output ───
if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'status' => 'success',
        'presc_id' => $p['presc_id'],
        'patient' => [
            'name'   => $p['patient_name'],
            'gender' => $p['gender'],
            'age'    => (int)$p['age'],
            'dob'    => $p['dob'],
            'phone'  => $p['phone'],
            'email'  => $p['email'] ?? ''
        ],
        'medicines'       => $medicines,
        'qr_image_url'    => $p['qr_image_url'],
        'dispense_status' => $p['dispense_status'],
        'created_at'      => $p['created_at'],
        'scanned_at'      => $p['scanned_at'],
        'dispensed_at'    => $p['dispensed_at']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── Determine status style ───
$status = strtoupper($p['dispense_status'] ?? 'PENDING');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescription <?= htmlspecialchars($p['presc_id']) ?> — Smart Pill System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 100%);
        min-height: 100vh;
        font-family: 'Plus Jakarta Sans', sans-serif;
        padding: 30px 15px;
    }
    .presc-card {
        max-width: 800px;
        margin: 0 auto;
        background: #fff;
        border-radius: 24px;
        box-shadow: 0 20px 60px rgba(15,23,42,0.1);
        overflow: hidden;
    }
    .presc-header {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        padding: 35px 30px;
        text-align: center;
        position: relative;
    }
    .presc-header .icon-top {
        font-size: 2.5rem;
        margin-bottom: 12px;
        display: block;
    }
    .presc-header h1 {
        font-weight: 800;
        font-size: 1.7rem;
        margin: 0 0 8px;
        letter-spacing: -0.5px;
    }
    .presc-header .presc-id {
        opacity: 0.9;
        font-size: 0.88rem;
        font-weight: 600;
        margin-bottom: 14px;
    }
    
    /* Status Badge */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 20px;
        border-radius: 20px;
        font-weight: 800;
        font-size: 0.78rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .status-badge.PENDING  { background: #fef3c7; color: #92400e; }
    .status-badge.SCANNED  { background: #dbeafe; color: #1e40af; }
    .status-badge.DISPENSED { background: #d1fae5; color: #065f46; }
    .status-badge.FAILED   { background: #fee2e2; color: #991b1b; }
    
    .presc-body { padding: 30px; }
    
    .info-section {
        background: #f8fafc;
        border-radius: 14px;
        padding: 20px 22px;
        margin-bottom: 20px;
        border: 1.5px solid #e2e8f0;
    }
    .info-section h4 {
        font-weight: 800;
        font-size: 0.95rem;
        color: #334155;
        margin: 0 0 16px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .info-section h4 i { color: #6366f1; }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .label {
        color: #64748b;
        font-weight: 600;
        font-size: 0.85rem;
    }
    .info-row .value {
        color: #0f172a;
        font-weight: 700;
        font-size: 0.88rem;
        text-align: right;
        word-break: break-word;
    }
    
    .med-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 16px;
        background: #fff;
        border: 1.5px solid #e2e8f0;
        border-radius: 10px;
        margin-bottom: 8px;
        transition: all 0.2s;
    }
    .med-item:hover {
        border-color: #c7d2fe;
        background: #f8fafc;
    }
    .med-name {
        font-weight: 700;
        color: #334155;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .med-name i { color: #6366f1; }
    .med-qty {
        background: #6366f1;
        color: #fff;
        padding: 4px 12px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 0.78rem;
        white-space: nowrap;
    }
    
    .qr-section {
        text-align: center;
        padding: 24px;
        background: linear-gradient(135deg, #eef2ff, #f0f9ff);
        border-radius: 14px;
        border: 2px dashed #c7d2fe;
    }
    .qr-section img {
        max-width: 250px;
        width: 100%;
        border-radius: 12px;
        background: #fff;
        padding: 12px;
        box-shadow: 0 8px 24px rgba(99,102,241,0.2);
    }
    .qr-section p {
        color: #64748b;
        font-size: 0.85rem;
        margin-top: 14px;
        font-weight: 600;
    }
    
    .footer-note {
        text-align: center;
        padding: 22px;
        color: #94a3b8;
        font-size: 0.8rem;
        border-top: 1px solid #f1f5f9;
        background: #f8fafc;
    }
    
    .status-info-box {
        background: linear-gradient(135deg, #f0f9ff, #eef2ff);
        border: 1.5px solid #bae6fd;
        border-radius: 12px;
        padding: 16px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .status-info-box i {
        font-size: 1.5rem;
        color: #0284c7;
    }
    .status-info-box p {
        margin: 0;
        font-size: 0.88rem;
        color: #334155;
        font-weight: 600;
        line-height: 1.4;
    }
</style>
</head>
<body>

<div class="presc-card">
    <div class="presc-header">
        <i class="bi bi-file-medical-fill icon-top"></i>
        <h1>Prescription</h1>
        <div class="presc-id"><?= htmlspecialchars($p['presc_id']) ?></div>
        
        <div class="status-badge <?= $status ?>">
            <?php if ($status === 'DISPENSED'): ?>
                <i class="bi bi-check-circle-fill"></i> Dispensed
            <?php elseif ($status === 'SCANNED'): ?>
                <i class="bi bi-upc-scan"></i> Scanned
            <?php elseif ($status === 'FAILED'): ?>
                <i class="bi bi-x-circle-fill"></i> Failed
            <?php else: ?>
                <i class="bi bi-clock-fill"></i> Pending
            <?php endif; ?>
        </div>
    </div>
    
    <div class="presc-body">
        
        <!-- Live Status Message -->
        <div class="status-info-box">
            <?php if ($status === 'DISPENSED'): ?>
                <i class="bi bi-check-circle-fill" style="color: #10b981;"></i>
                <p>✅ Your medicine has been dispensed successfully!</p>
            <?php elseif ($status === 'SCANNED'): ?>
                <i class="bi bi-hourglass-split" style="color: #3b82f6;"></i>
                <p>⏳ Your QR has been scanned. Dispensing in progress...</p>
            <?php elseif ($status === 'FAILED'): ?>
                <i class="bi bi-exclamation-triangle-fill" style="color: #dc2626;"></i>
                <p>⚠️ Dispensing failed. Please contact your doctor.</p>
            <?php else: ?>
                <i class="bi bi-clock-fill" style="color: #f59e0b;"></i>
                <p>⏳ Your prescription is being processed. Please wait...</p>
            <?php endif; ?>
        </div>
        
        <!-- Patient Info -->
        <div class="info-section">
            <h4><i class="bi bi-person-fill"></i> Patient Information</h4>
            <div class="info-row">
                <span class="label">Name</span>
                <span class="value"><?= htmlspecialchars($p['patient_name']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Gender</span>
                <span class="value"><?= htmlspecialchars($p['gender']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Age</span>
                <span class="value"><?= (int)$p['age'] ?> years</span>
            </div>
            <div class="info-row">
                <span class="label">Date of Birth</span>
                <span class="value"><?= htmlspecialchars($p['dob']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Phone</span>
                <span class="value"><?= htmlspecialchars($p['phone']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Email</span>
                <span class="value"><?= htmlspecialchars($p['email'] ?? 'N/A') ?></span>
            </div>
            <div class="info-row">
                <span class="label">Doctor</span>
                <span class="value"><?= htmlspecialchars($p['doctor']) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Created</span>
                <span class="value"><?= date('d-M-Y H:i', strtotime($p['created_at'])) ?></span>
            </div>
        </div>
        
        <!-- Medicines -->
        <div class="info-section">
            <h4><i class="bi bi-capsule"></i> Prescribed Medicines</h4>
            <?php if (!empty($medicines)): ?>
                <?php foreach ($medicines as $m): ?>
                    <div class="med-item">
                        <span class="med-name">
                            <i class="bi bi-capsule"></i>
                            <?= htmlspecialchars($m['name'] ?? 'Unknown') ?>
                        </span>
                        <span class="med-qty">× <?= (int)($m['qty'] ?? 1) ?></span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #94a3b8; text-align: center; margin: 0;">No medicines found</p>
            <?php endif; ?>
        </div>
        
        <!-- QR Code -->
        <?php if (!empty($p['qr_image_url'])): ?>
        <div class="info-section">
            <h4><i class="bi bi-qr-code"></i> Prescription QR</h4>
            <div class="qr-section">
                <img src="<?= htmlspecialchars($p['qr_image_url']) ?>?t=<?= time() ?>" alt="QR Code">
                <p>Scan this QR at the dispensing machine</p>
            </div>
        </div>
        <?php endif; ?>
        
    </div>
    
    <div class="footer-note">
        <i class="bi bi-capsule-pill"></i>
        Smart Pill Dispensing System &copy; <?= date('Y') ?>
    </div>
</div>

<script>
    // Auto-refresh every 30 seconds (live status)
    setTimeout(function() {
        location.reload();
    }, 30000);
</script>

</body>
</html>