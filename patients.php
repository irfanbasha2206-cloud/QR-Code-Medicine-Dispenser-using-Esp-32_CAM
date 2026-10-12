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

date_default_timezone_set('Asia/Kolkata');
include 'connection.php';

// ─── Fetch from Database ───
$patients = [];
$sql = "SELECT * FROM `4973_patient` ORDER BY id DESC";
$result = mysqli_query($conn, $sql);

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $medicines = json_decode($row['medicines'], true);
        if (!is_array($medicines)) $medicines = [];
        
        $patients[] = [
            'id'            => $row['presc_id'],
            'patient'       => [
                'name'   => $row['patient_name'],
                'gender' => $row['gender'],
                'age'    => $row['age'],
                'dob'    => $row['dob'],
                'phone'  => $row['phone']
            ],
            'medicines'     => $medicines,
            'qr_image_url'  => $row['qr_image_url'],   // 🆕
            'timestamp'     => $row['created_at']
        ];
    }
}

$totalRecords = count($patients);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patients List — Smart Pill Dispensing System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #6366f1;
        --accent: #06b6d4;
        --success: #10b981;
        --dark: #0f172a;
        --gray: #64748b;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        background: #f8fafc;
        min-height: 100vh;
        font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
        display: flex;
        flex-direction: column;
        position: relative;
        overflow-x: hidden;
    }
    .bg-shape {
        position: fixed;
        border-radius: 50%;
        filter: blur(80px);
        opacity: 0.5;
        z-index: 0;
        pointer-events: none;
        animation: float 20s infinite ease-in-out;
    }
    .bg-shape-1 {
        width: 500px; height: 500px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        top: -200px; left: -200px;
    }
    .bg-shape-2 {
        width: 400px; height: 400px;
        background: linear-gradient(135deg, #06b6d4, #10b981);
        top: 40%; right: -150px;
        animation-delay: -5s;
    }
    .bg-shape-3 {
        width: 350px; height: 350px;
        background: linear-gradient(135deg, #ec4899, #f43f5e);
        bottom: -100px; left: 30%;
        animation-delay: -10s;
    }
    @keyframes float {
        0%, 100% { transform: translate(0, 0) scale(1); }
        33% { transform: translate(30px, -30px) scale(1.05); }
        66% { transform: translate(-20px, 20px) scale(0.95); }
    }
    @keyframes gradientSlide {
        0% { background-position: 0% 50%; }
        100% { background-position: 200% 50%; }
    }
    
    .main-header {
        background: rgba(15, 23, 42, 0.98);
        backdrop-filter: blur(20px);
        padding: 18px 30px;
        box-shadow: 0 4px 30px rgba(0,0,0,0.25);
        position: sticky;
        top: 0;
        z-index: 100;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .main-header::after {
        content: '';
        position: absolute;
        bottom: -2px; left: 0; right: 0;
        height: 3px;
        background: linear-gradient(90deg, transparent, #6366f1, #06b6d4, #ec4899, transparent);
        background-size: 200% 100%;
        animation: gradientSlide 4s linear infinite;
    }
    .header-inner {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        width: 100%;
        min-height: 62px;
    }
    .brand-center {
        text-align: center;
        width: 100%;
        padding: 0 400px;
    }
    .brand-title {
        font-weight: 800;
        font-size: 2.2rem;
        background: linear-gradient(90deg, #a5b4fc 0%, #f0abfc 50%, #fcd34d 100%);
        background-size: 200% 100%;
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -1px;
        line-height: 1.1;
        animation: gradientSlide 6s linear infinite;
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .brand-icon {
        position: absolute;
        left: 300px;
        top: 50%;
        transform: translateY(-50%);
        width: 58px;
        height: 58px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.85rem;
        color: #fff;
        box-shadow: 0 10px 28px rgba(99,102,241,0.5);
        overflow: hidden;
    }
    .brand-icon::before {
        content: '';
        position: absolute;
        top: -50%; left: -50%;
        width: 200%; height: 200%;
        background: linear-gradient(45deg, transparent, rgba(255,255,255,0.3), transparent);
        animation: shine 3s infinite;
    }
    @keyframes shine {
        0% { transform: translateX(-100%) translateY(-100%) rotate(45deg); }
        100% { transform: translateX(100%) translateY(100%) rotate(45deg); }
    }
    .header-actions {
        position: absolute;
        right: 30px;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .btn-back-home {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(99,102,241,0.4);
        white-space: nowrap;
    }
    .btn-back-home:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(99,102,241,0.6);
        color: #fff;
    }
    .btn-logout-new {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        background: linear-gradient(135deg, #f43f5e, #ec4899);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.85rem;
        text-decoration: none;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(244, 63, 94, 0.4);
        white-space: nowrap;
    }
    .btn-logout-new:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(244, 63, 94, 0.6);
        color: #fff;
    }
    
    .main-wrap {
        flex: 1;
        max-width: 1400px;
        margin: 0 auto;
        padding: 40px 25px 60px;
        width: 100%;
        position: relative;
        z-index: 1;
    }
    
    .section-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-bottom: 22px;
        padding-bottom: 14px;
        border-bottom: 2px solid #e2e8f0;
        flex-wrap: wrap;
    }
    .section-title-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .section-title .icon-box {
        width: 42px;
        height: 42px;
        border-radius: 12px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.15rem;
        box-shadow: 0 6px 16px rgba(99,102,241,0.35);
    }
    .section-title h3 {
        font-weight: 800;
        font-size: 1.15rem;
        color: var(--dark);
        margin: 0;
    }
    .record-count {
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        color: #4f46e5;
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 800;
        border: 1px solid #c7d2fe;
    }
    
    /* TABLE */
    .table-wrapper {
        background: #fff;
        border-radius: 20px;
        box-shadow: 0 10px 40px rgba(15,23,42,0.08);
        border: 1.5px solid #e2e8f0;
        overflow: hidden;
        position: relative;
    }
    .table-wrapper::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 5px;
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899);
        background-size: 200% 100%;
        animation: gradientSlide 6s linear infinite;
    }
    .table-responsive { overflow-x: auto; }
    .patient-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.88rem;
    }
    .patient-table thead {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border-bottom: 2px solid #e2e8f0;
    }
    .patient-table thead th {
        padding: 18px 16px;
        text-align: left;
        font-weight: 800;
        color: #475569;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        white-space: nowrap;
    }
    .patient-table thead th i {
        color: #6366f1;
        margin-right: 6px;
        font-size: 0.9rem;
    }
    .patient-table tbody tr {
        border-bottom: 1.5px solid #f1f5f9;
        transition: all 0.25s;
    }
    .patient-table tbody tr:last-child { border-bottom: none; }
    .patient-table tbody tr:hover {
        background: linear-gradient(135deg, #f0f7ff, #f8fafc);
    }
    .patient-table tbody td {
        padding: 18px 16px;
        color: #334155;
        font-weight: 600;
        vertical-align: middle;
    }
    
    .patient-name-cell {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 180px;
    }
    .patient-avatar {
        width: 40px;
        height: 40px;
        border-radius: 11px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.1rem;
        flex-shrink: 0;
        box-shadow: 0 4px 12px rgba(99,102,241,0.3);
    }
    .patient-name-text {
        font-weight: 800;
        color: var(--dark);
        font-size: 0.95rem;
    }
    .patient-id-text {
        font-size: 0.68rem;
        color: #64748b;
        font-weight: 700;
        margin-top: 3px;
    }
    
    .badge-cell {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 5px 12px;
        border-radius: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        white-space: nowrap;
    }
    .badge-gender-male { background: #dbeafe; color: #1e40af; }
    .badge-gender-female { background: #fce7f3; color: #be185d; }
    .badge-gender-other { background: #e0e7ff; color: #4338ca; }
    .badge-age { background: #d1fae5; color: #065f46; }
    
    .med-chips-cell {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        max-width: 300px;
    }
    .med-chip-sm {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        background: linear-gradient(135deg, #eef2ff, #f0f9ff);
        border: 1px solid #c7d2fe;
        border-radius: 8px;
        font-size: 0.72rem;
        font-weight: 700;
        color: #4338ca;
        white-space: nowrap;
    }
    .med-chip-sm .qty-badge {
        background: #6366f1;
        color: #fff;
        padding: 1px 6px;
        border-radius: 6px;
        font-size: 0.65rem;
        font-weight: 800;
    }
    
    /* 🎯 QR Button */
    .btn-qr-view {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border: 1.5px solid #c7d2fe;
        color: #4f46e5;
        text-decoration: none;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.75rem;
        transition: all 0.25s;
        white-space: nowrap;
    }
    .btn-qr-view:hover {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(99,102,241,0.4);
    }
    .btn-qr-view i { font-size: 0.9rem; }
    
    .timestamp-cell {
        font-size: 0.78rem;
        color: #64748b;
        font-weight: 600;
        white-space: nowrap;
    }
    .timestamp-cell .date {
        color: #334155;
        font-weight: 700;
    }
    .timestamp-cell .time {
        display: block;
        font-size: 0.72rem;
        margin-top: 2px;
    }
    
    .empty-box {
        text-align: center;
        padding: 80px 25px;
        background: #fff;
        border-radius: 24px;
        border: 2px dashed #cbd5e1;
        box-shadow: 0 4px 20px rgba(15,23,42,0.04);
    }
    .empty-box i {
        font-size: 4.5rem;
        background: linear-gradient(135deg, #94a3b8, #cbd5e1);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        margin-bottom: 20px;
        display: block;
    }
    .empty-box h3 {
        color: #475569;
        font-weight: 800;
        margin-bottom: 8px;
    }
    .empty-box p {
        color: #94a3b8;
        font-size: 0.92rem;
        margin-bottom: 22px;
    }
    .btn-empty-action {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 26px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 700;
        font-size: 0.9rem;
        box-shadow: 0 8px 20px rgba(99,102,241,0.4);
    }
    .btn-empty-action:hover { color: #fff; }
    
    .main-footer {
        background: rgba(15, 23, 42, 0.98);
        backdrop-filter: blur(20px);
        padding: 30px 25px 24px;
        margin-top: auto;
        color: #94a3b8;
        position: relative;
        z-index: 1;
    }
    .main-footer::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, #6366f1, #06b6d4, #ec4899, transparent);
        background-size: 200% 100%;
        animation: gradientSlide 4s linear infinite;
    }
    .footer-inner {
        max-width: 1400px;
        margin: 0 auto;
        text-align: center;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    @media (max-width: 1200px) {
        .brand-center { padding: 0 300px; }
        .brand-title { font-size: 1.7rem; }
        .brand-icon { left: 200px; }
    }
    @media (max-width: 992px) {
        .brand-center { padding: 0 250px; }
        .brand-title { font-size: 1.3rem; }
        .brand-icon { left: 150px; width: 48px; height: 48px; font-size: 1.5rem; }
        .btn-back-home, .btn-logout-new { padding: 8px 14px; font-size: 0.78rem; }
    }
    @media (max-width: 768px) {
        .main-header { padding: 14px 15px; }
        .brand-center { padding: 0 130px; }
        .brand-title { font-size: 0.85rem; }
        .brand-icon { left: 15px; width: 40px; height: 40px; font-size: 1.2rem; border-radius: 10px; }
        .btn-back-home span, .btn-logout-new span { display: none; }
        .btn-back-home, .btn-logout-new { padding: 8px 12px; border-radius: 10px; }
        .header-actions { gap: 6px; right: 15px; }
        .main-wrap { padding: 25px 15px 40px; }
    }
</style>
</head>
<body>

<div class="bg-shape bg-shape-1"></div>
<div class="bg-shape bg-shape-2"></div>
<div class="bg-shape bg-shape-3"></div>

<header class="main-header">
    <div class="header-inner">
        <div class="brand-icon">
            <i class="bi bi-capsule-pill"></i>
        </div>
        <div class="brand-center">
            <span class="brand-title">AI-Integrated Smart Medication Dispensing System</span>
        </div>
        <div class="header-actions">
            <a href="index.php" class="btn-back-home">
                <i class="bi bi-plus-circle"></i> <span>New Prescription</span>
            </a>
            <a href="index.php?logout=1" class="btn-logout-new">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </div>
    </div>
</header>

<div class="main-wrap">
    
    <div class="section-title">
        <div class="section-title-left">
            <div class="icon-box">
                <i class="bi bi-people-fill"></i>
            </div>
            <h3>Patients List</h3>
        </div>
        <span class="record-count">
            <i class="bi bi-database-fill"></i> <?= $totalRecords ?> Records
        </span>
    </div>
    
    <?php if (count($patients) > 0): ?>
        
        <div class="table-wrapper">
            <div class="table-responsive">
                <table class="patient-table">
                    <thead>
                        <tr>
                            <th><i class="bi bi-person-fill"></i> Patient</th>
                            <th><i class="bi bi-gender-ambiguous"></i> Gender</th>
                            <th><i class="bi bi-calendar-event"></i> Age</th>
                            <th><i class="bi bi-calendar-heart"></i> DOB</th>
                            <th><i class="bi bi-telephone"></i> Mobile</th>
                            <th><i class="bi bi-capsule"></i> Medicines</th>
                            <th><i class="bi bi-qr-code"></i> QR</th>
                            <th><i class="bi bi-clock"></i> Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $p): 
                            $gender = $p['patient']['gender'] ?? '-';
                            $genderClass = 'badge-gender-other';
                            if ($gender === 'Male') $genderClass = 'badge-gender-male';
                            elseif ($gender === 'Female') $genderClass = 'badge-gender-female';
                            
                            $dob = $p['patient']['dob'] ?? '';
                            $dobFormatted = '-';
                            if ($dob && $dob !== '0000-00-00' && $dob !== '-') {
                                $dobFormatted = date('d-M-Y', strtotime($dob));
                            }
                            
                            $ts = $p['timestamp'] ?? '-';
                            $datePart = '-';
                            $timePart = '';
                            if ($ts !== '-') {
                                $parts = explode(' ', $ts);
                                $datePart = date('d-M-Y', strtotime($parts[0]));
                                $timePart = $parts[1] ?? '';
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="patient-name-cell">
                                    <div class="patient-avatar">
                                        <i class="bi bi-person-fill"></i>
                                    </div>
                                    <div>
                                        <div class="patient-name-text">
                                            <?= htmlspecialchars($p['patient']['name'] ?? 'Unknown') ?>
                                        </div>
                                        <div class="patient-id-text">
                                            <?= htmlspecialchars($p['id'] ?? 'N/A') ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge-cell <?= $genderClass ?>">
                                    <?= htmlspecialchars($gender) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge-cell badge-age">
                                    <?= (int)($p['patient']['age'] ?? 0) ?> yrs
                                </span>
                            </td>
                            <td style="font-weight: 700; color: #334155;">
                                <?= htmlspecialchars($dobFormatted) ?>
                            </td>
                            <td style="font-weight: 700; color: #334155; font-family: 'Courier New', monospace;">
                                <?= htmlspecialchars($p['patient']['phone'] ?? '-') ?>
                            </td>
                            <td>
                                <div class="med-chips-cell">
                                    <?php foreach (($p['medicines'] ?? []) as $m): ?>
                                        <span class="med-chip-sm">
                                            <i class="bi bi-capsule"></i>
                                            <?= htmlspecialchars($m['name'] ?? 'Unknown') ?>
                                            <span class="qty-badge">×<?= (int)($m['qty'] ?? 1) ?></span>
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($p['qr_image_url'])): ?>
                                    <a href="<?= htmlspecialchars($p['qr_image_url']) ?>" 
                                       target="_blank" 
                                       class="btn-qr-view"
                                       title="View QR Image">
                                        <i class="bi bi-qr-code"></i> View
                                    </a>
                                <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 0.8rem;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="timestamp-cell">
                                    <span class="date"><?= htmlspecialchars($datePart) ?></span>
                                    <span class="time"><?= htmlspecialchars($timePart) ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
    <?php else: ?>
        
        <div class="empty-box">
            <i class="bi bi-inbox"></i>
            <h3>No Prescriptions Yet</h3>
            <p>Create your first prescription to see patients here</p>
            <a href="index.php" class="btn-empty-action">
                <i class="bi bi-plus-circle"></i> Create Prescription
            </a>
        </div>
        
    <?php endif; ?>
    
</div>

<footer class="main-footer">
    <div class="footer-inner">
        <i class="bi bi-capsule-pill"></i>
        Smart Pill Dispensing System &copy; <?= date('Y') ?>
    </div>
</footer>

</body>
</html>