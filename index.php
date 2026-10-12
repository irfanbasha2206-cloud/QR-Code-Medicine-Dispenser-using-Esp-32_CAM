<?php
// ═══ Custom session path ═══
$sessionPath = __DIR__ . '/sessions';
if (!is_dir($sessionPath)) {
    @mkdir($sessionPath, 0777, true);
}
if (is_dir($sessionPath)) {
    session_save_path($sessionPath);
}
session_start();

include 'connection.php';

// ─── Login Handler ───
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_submit'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if ($username === 'doctor' && $password === 'doctor123') {
        $_SESSION['doctor'] = $username;
        echo "<script>window.location.href='index.php';</script>";
        exit;
    } else {
        $login_error = "Invalid username or password";
    }
}

// ─── Add Medicine Handler ───
$add_med_success = '';
$add_med_error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_medicine_submit'])) {
    $new_med = trim($_POST['new_medicine_name'] ?? '');
    
    if ($new_med === '') {
        $add_med_error = "Medicine name cannot be empty";
    } else {
        $check = mysqli_prepare($conn, "SELECT id FROM `4973_medicine` WHERE medicine_name = ?");
        mysqli_stmt_bind_param($check, 's', $new_med);
        mysqli_stmt_execute($check);
        mysqli_stmt_store_result($check);
        
        if (mysqli_stmt_num_rows($check) > 0) {
            $add_med_error = "❌ Medicine already exists";
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO `4973_medicine` (medicine_name) VALUES (?)");
            mysqli_stmt_bind_param($stmt, 's', $new_med);
            
            if (mysqli_stmt_execute($stmt)) {
                $add_med_success = "✅ Medicine '$new_med' added successfully!";
            } else {
                $add_med_error = "❌ Failed to add medicine";
            }
            mysqli_stmt_close($stmt);
        }
        mysqli_stmt_close($check);
    }
}

// ─── Edit Medicine Handler ───
$edit_med_success = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_medicine_submit'])) {
    $edit_id = (int)($_POST['edit_medicine_id'] ?? 0);
    $edit_name = trim($_POST['edit_medicine_name'] ?? '');
    
    if ($edit_id > 0 && $edit_name !== '') {
        $stmt = mysqli_prepare($conn, "UPDATE `4973_medicine` SET medicine_name = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'si', $edit_name, $edit_id);
        if (mysqli_stmt_execute($stmt)) {
            $edit_med_success = "✅ Medicine updated successfully!";
        }
        mysqli_stmt_close($stmt);
    }
}

// ─── Delete Medicine Handler ───
$del_med_success = '';
if (isset($_GET['delete_med'])) {
    $del_id = (int)$_GET['delete_med'];
    if ($del_id > 0) {
        $stmt = mysqli_prepare($conn, "DELETE FROM `4973_medicine` WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $del_id);
        if (mysqli_stmt_execute($stmt)) {
            $del_med_success = "✅ Medicine deleted successfully!";
        }
        mysqli_stmt_close($stmt);
    }
}

// ─── Load Medicines List from DB ───
$medicinesList = [];
$medQuery = mysqli_query($conn, "SELECT id, medicine_name FROM `4973_medicine` ORDER BY medicine_name ASC");
if ($medQuery) {
    while ($row = mysqli_fetch_assoc($medQuery)) {
        $medicinesList[] = $row;
    }
}

if (empty($medicinesList)) {
    $defaultMeds = ["Paracetamol", "Dolo650", "VitaminC", "Amoxicillin", "Azithromycin", "Cetirizine"];
    foreach ($defaultMeds as $dm) {
        mysqli_query($conn, "INSERT IGNORE INTO `4973_medicine` (medicine_name) VALUES ('$dm')");
    }
    $medQuery = mysqli_query($conn, "SELECT id, medicine_name FROM `4973_medicine` ORDER BY medicine_name ASC");
    if ($medQuery) {
        while ($row = mysqli_fetch_assoc($medQuery)) {
            $medicinesList[] = $row;
        }
    }
}

// ─── Logout Handler ───
if (isset($_GET['logout'])) {
    session_destroy();
    echo "<script>window.location.href='index.php';</script>";
    exit;
}

$isLoggedIn = isset($_SESSION['doctor']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isLoggedIn ? 'New Prescription' : 'Doctor Login' ?> — Smart Pill Dispensing System</title>
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
    
    /* HEADER */
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
        position: relative;
        width: 100%;
        min-height: 62px;
    }
    .header-inner.logged-out { justify-content: center; }
    .header-inner.logged-in { justify-content: flex-start; }
    
    .brand-group {
        display: flex;
        align-items: center;
        gap: 14px;
        flex-shrink: 0;
        min-width: 0;
    }
    .brand-icon {
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
        flex-shrink: 0;
        overflow: hidden;
        position: relative;
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
    .brand-text {
        display: flex;
        align-items: center;
        min-width: 0;
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
    
    .header-actions {
        position: absolute;
        right: 0;
        top: 50%;
        transform: translateY(-50%);
        display: flex;
        align-items: center;
        gap: 10px;
        flex-shrink: 0;
    }
    
    .btn-add-medicine-header {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        background: linear-gradient(135deg, #10b981, #059669);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 4px 15px rgba(16, 185, 129, 0.4);
        white-space: nowrap;
        cursor: pointer;
    }
    .btn-add-medicine-header i { font-size: 1rem; }
    .btn-add-medicine-header:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16, 185, 129, 0.6);
        color: #fff;
    }
    
    .btn-view-patients {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        background: linear-gradient(135deg, #22d3ee, #06b6d4);
        color: #0f172a;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(34, 211, 238, 0.4);
        white-space: nowrap;
    }
    .btn-view-patients i { font-size: 1rem; }
    .btn-view-patients:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(34, 211, 238, 0.6);
        color: #0f172a;
    }
    
    .btn-logout-new {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 18px;
        background: linear-gradient(135deg, #f43f5e, #ec4899);
        color: #fff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.82rem;
        text-decoration: none;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(244, 63, 94, 0.4);
        white-space: nowrap;
    }
    .btn-logout-new i { font-size: 1rem; }
    .btn-logout-new:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(244, 63, 94, 0.6);
        color: #fff;
    }
    
    /* MAIN */
    .main-wrap {
        flex: 1;
        max-width: 900px;
        margin: 0 auto;
        padding: 40px 25px 60px;
        width: 100%;
        position: relative;
        z-index: 1;
    }
    
    /* LOGIN */
    .login-wrap {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        flex: 1;
        padding: 50px 20px;
        position: relative;
        z-index: 1;
    }
    .login-hero {
        text-align: center;
        margin-bottom: 35px;
        max-width: 700px;
        animation: slideUp 0.5s cubic-bezier(0.4, 0, 0.2, 1);
    }
    .login-hero h1 {
        font-weight: 800;
        font-size: 2.4rem;
        line-height: 1.15;
        letter-spacing: -1.2px;
        margin-bottom: 14px;
        background: linear-gradient(135deg, #0f172a 0%, #6366f1 50%, #ec4899 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .login-hero p {
        color: #64748b;
        font-size: 1rem;
        font-weight: 500;
        line-height: 1.5;
        margin: 0;
    }
    .login-card {
        max-width: 440px;
        width: 100%;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 28px;
        padding: 45px 40px;
        box-shadow: 0 20px 60px rgba(15,23,42,0.1);
        position: relative;
        overflow: hidden;
        animation: slideUp 0.7s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes slideUp {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .login-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 5px;
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899);
        background-size: 200% 100%;
        animation: gradientSlide 4s linear infinite;
    }
    .logo-box {
        width: 70px;
        height: 70px;
        border-radius: 18px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 2rem;
        color: #fff;
        box-shadow: 0 12px 30px rgba(99,102,241,0.4);
    }
    .login-title {
        font-weight: 800;
        font-size: 1.45rem;
        color: var(--dark);
        text-align: center;
        margin-bottom: 6px;
        letter-spacing: -0.5px;
    }
    .login-sub {
        color: var(--gray);
        font-size: 0.88rem;
        text-align: center;
        margin-bottom: 28px;
        font-weight: 500;
    }
    
    .form-label {
        font-weight: 700;
        color: var(--dark);
        font-size: 0.88rem;
        margin-bottom: 8px;
        display: block;
    }
    .form-control, .form-select {
        border-radius: 12px;
        padding: 13px 16px;
        border: 2px solid #e2e8f0;
        font-size: 0.95rem;
        transition: all 0.25s;
        font-weight: 500;
        background: #fff;
    }
    .form-control:focus, .form-select:focus {
        border-color: #6366f1;
        box-shadow: 0 0 0 4px rgba(99,102,241,0.12);
    }
    .input-icon-wrap { position: relative; }
    .input-icon-wrap i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        font-size: 1.05rem;
    }
    .input-icon-wrap .form-control { padding-left: 46px; }
    
    .btn-login {
        width: 100%;
        padding: 14px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        background-size: 200% 100%;
        color: #fff;
        border: none;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.98rem;
        transition: all 0.4s;
        box-shadow: 0 8px 24px rgba(99,102,241,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        margin-top: 8px;
    }
    .btn-login:hover {
        transform: translateY(-2px);
        box-shadow: 0 12px 32px rgba(99,102,241,0.5);
        background-position: 100% 0;
        color: #fff;
    }
    .error-alert {
        background: #fee2e2;
        border: 1px solid #fecaca;
        color: #991b1b;
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 600;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    /* PRESCRIPTION FORM */
    .page-title {
        text-align: center;
        margin-bottom: 35px;
    }
    .page-title h1 {
        font-weight: 800;
        font-size: 2rem;
        margin-bottom: 8px;
        letter-spacing: -0.8px;
        background: linear-gradient(135deg, #0f172a 0%, #6366f1 50%, #ec4899 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .page-title p { color: var(--gray); font-size: 0.95rem; margin: 0; font-weight: 500; }
    
    .form-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        border-radius: 24px;
        padding: 40px;
        box-shadow: 0 20px 60px rgba(15,23,42,0.08);
        position: relative;
        overflow: hidden;
    }
    .form-card::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899, #06b6d4, #10b981);
        background-size: 200% 100%;
        animation: gradientSlide 6s linear infinite;
    }
    
    .section-head {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 14px;
        margin-bottom: 28px;
        padding-bottom: 18px;
        border-bottom: 2px solid #f1f5f9;
    }
    .section-head .icon-box {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.2rem;
        box-shadow: 0 6px 16px rgba(99,102,241,0.35);
        flex-shrink: 0;
    }
    .section-head h3 {
        font-weight: 800;
        font-size: 1.2rem;
        color: var(--dark);
        margin: 0;
        letter-spacing: -0.3px;
    }
    
    .medicine-row {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        padding: 18px;
        border-radius: 14px;
        margin-bottom: 12px;
        border: 1.5px solid #e2e8f0;
        position: relative;
        transition: all 0.3s;
    }
    .medicine-row:hover {
        border-color: #c7d2fe;
        background: linear-gradient(135deg, #eef2ff, #f8fafc);
    }
    .medicine-number {
        position: absolute;
        top: -10px;
        left: 16px;
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: #fff;
        font-size: 0.68rem;
        font-weight: 800;
        padding: 3px 10px;
        border-radius: 10px;
        letter-spacing: 0.5px;
    }
    .remove-btn {
        position: absolute;
        top: 10px;
        right: 10px;
        background: #fee2e2;
        border: none;
        color: #dc2626;
        width: 26px;
        height: 26px;
        border-radius: 50%;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
    }
    .remove-btn:hover { background: #dc2626; color: #fff; transform: rotate(90deg); }
    
    .btn-add-med {
        background: linear-gradient(135deg, #eef2ff, #f0f9ff);
        border: 2px dashed #a5b4fc;
        color: #6366f1;
        padding: 12px 24px;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.88rem;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }
    .btn-add-med:hover {
        border-color: #6366f1;
        background: linear-gradient(135deg, #e0e7ff, #dbeafe);
        color: #4f46e5;
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(99,102,241,0.2);
    }
    .btn-add-med:disabled { opacity: 0.4; cursor: not-allowed; transform: none; }
    
    .btn-generate {
        width: 100%;
        padding: 16px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        background-size: 200% 100%;
        color: #fff;
        border: none;
        border-radius: 14px;
        font-weight: 700;
        font-size: 1rem;
        transition: all 0.4s;
        box-shadow: 0 10px 30px rgba(99,102,241,0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        margin-top: 22px;
    }
    .btn-generate:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 40px rgba(99,102,241,0.5);
        background-position: 100% 0;
        color: #fff;
    }
    
    .duplicate-warning {
        background: #fee2e2;
        border: 2px solid #fca5a5;
        color: #991b1b;
        padding: 12px 18px;
        border-radius: 12px;
        font-size: 0.88rem;
        font-weight: 700;
        display: none;
        align-items: center;
        gap: 10px;
        margin-bottom: 18px;
        animation: shake 0.4s ease;
    }
    .duplicate-warning.show { display: flex; }
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        25% { transform: translateX(-6px); }
        75% { transform: translateX(6px); }
    }
    
    /* MODAL */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.75);
        backdrop-filter: blur(8px);
        z-index: 9999;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 20px;
        animation: fadeIn 0.3s ease;
    }
    .modal-overlay.show { display: flex; }
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    .modal-box {
        background: #fff;
        border-radius: 24px;
        padding: 32px 28px;
        max-width: 500px;
        width: 100%;
        position: relative;
        overflow: hidden;
        box-shadow: 0 30px 80px rgba(0,0,0,0.4);
        animation: slideUpModal 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        max-height: 90vh;
        overflow-y: auto;
    }
    @keyframes slideUpModal {
        from { opacity: 0; transform: translateY(30px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .modal-box::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 5px;
        background: linear-gradient(90deg, #10b981, #06b6d4, #6366f1);
        background-size: 200% 100%;
        animation: gradientSlide 4s linear infinite;
    }
    .modal-close {
        position: absolute;
        top: 14px;
        right: 14px;
        background: #f1f5f9;
        border: none;
        color: #64748b;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        font-size: 1.1rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        z-index: 10;
    }
    .modal-close:hover {
        background: #dc2626;
        color: #fff;
        transform: rotate(90deg);
    }
    .modal-icon {
        width: 60px;
        height: 60px;
        border-radius: 18px;
        background: linear-gradient(135deg, #10b981, #059669);
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 1.7rem;
        margin: 0 auto 16px;
        box-shadow: 0 10px 25px rgba(16,185,129,0.4);
    }
    .modal-title {
        font-weight: 800;
        font-size: 1.3rem;
        color: var(--dark);
        text-align: center;
        margin-bottom: 6px;
        letter-spacing: -0.4px;
    }
    .modal-sub {
        color: var(--gray);
        font-size: 0.85rem;
        text-align: center;
        margin-bottom: 22px;
        font-weight: 500;
    }
    
    .modal-alert {
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 0.85rem;
        font-weight: 700;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
        animation: slideDownAlert 0.4s ease;
    }
    .modal-alert.success {
        background: #dcfce7;
        border: 1px solid #86efac;
        color: #166534;
    }
    .modal-alert.error {
        background: #fee2e2;
        border: 1px solid #fca5a5;
        color: #991b1b;
    }
    @keyframes slideDownAlert {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .modal-actions {
        display: flex;
        gap: 10px;
        margin-top: 18px;
    }
    .btn-modal-cancel {
        flex: 1;
        padding: 13px;
        background: #f1f5f9;
        border: 2px solid #e2e8f0;
        color: #475569;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s;
    }
    .btn-modal-cancel:hover {
        background: #e2e8f0;
        border-color: #cbd5e1;
    }
    .btn-modal-submit {
        flex: 1.5;
        padding: 13px;
        background: linear-gradient(135deg, #10b981, #059669);
        border: none;
        color: #fff;
        border-radius: 12px;
        font-weight: 700;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        box-shadow: 0 4px 15px rgba(16,185,129,0.4);
    }
    .btn-modal-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(16,185,129,0.5);
    }
    
    .modal-header-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
        gap: 10px;
    }
    .btn-view-med-list {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 14px;
        background: linear-gradient(135deg, #eef2ff, #e0e7ff);
        border: 2px solid #c7d2fe;
        color: #4f46e5;
        border-radius: 10px;
        font-weight: 700;
        font-size: 0.78rem;
        cursor: pointer;
        transition: all 0.3s;
        white-space: nowrap;
    }
    .btn-view-med-list:hover {
        background: linear-gradient(135deg, #e0e7ff, #c7d2fe);
        transform: translateY(-1px);
    }
    
    .med-list-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 14px;
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        margin-bottom: 8px;
        transition: all 0.25s;
    }
    .med-list-item:hover {
        border-color: #c7d2fe;
        background: linear-gradient(135deg, #eef2ff, #f8fafc);
    }
    .med-list-name {
        font-weight: 700;
        color: #334155;
        font-size: 0.92rem;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .med-list-name i {
        color: #6366f1;
        font-size: 1rem;
    }
    .med-list-actions {
        display: flex;
        gap: 6px;
    }
    .btn-med-action {
        width: 34px;
        height: 34px;
        border-radius: 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.9rem;
        border: none;
        cursor: pointer;
        transition: all 0.25s;
        text-decoration: none;
    }
    .btn-med-edit {
        background: #dbeafe;
        color: #1e40af;
    }
    .btn-med-edit:hover {
        background: #1e40af;
        color: #fff;
        transform: translateY(-2px);
    }
    .btn-med-delete {
        background: #fee2e2;
        color: #991b1b;
    }
    .btn-med-delete:hover {
        background: #991b1b;
        color: #fff;
        transform: translateY(-2px);
    }
    
    .empty-med-box {
        text-align: center;
        padding: 30px 20px;
        color: #94a3b8;
    }
    .empty-med-box i {
        font-size: 2.5rem;
        margin-bottom: 10px;
        display: block;
        color: #cbd5e1;
    }
    .empty-med-box p {
        font-size: 0.9rem;
        font-weight: 600;
        margin: 0;
    }
    
    /* FOOTER */
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
    
    /* RESPONSIVE */
    @media (max-width: 1200px) {
        .brand-title { font-size: 1.7rem; }
        .btn-add-medicine-header, .btn-view-patients, .btn-logout-new { padding: 8px 14px; font-size: 0.76rem; }
    }
    @media (max-width: 992px) {
        .brand-title { font-size: 1.3rem; }
        .brand-icon { width: 48px; height: 48px; font-size: 1.5rem; }
        .header-actions { gap: 6px; }
    }
    @media (max-width: 768px) {
        .main-header { padding: 14px 15px; }
        .brand-title { font-size: 0.85rem; }
        .brand-icon { width: 40px; height: 40px; font-size: 1.2rem; border-radius: 10px; }
        .brand-group { gap: 10px; }
        .btn-add-medicine-header span, .btn-view-patients span, .btn-logout-new span { display: none; }
        .btn-add-medicine-header, .btn-view-patients, .btn-logout-new { padding: 8px 10px; border-radius: 10px; }
        .header-actions { gap: 5px; }
        .form-card, .login-card { padding: 25px 20px; }
        .page-title h1, .login-title { font-size: 1.4rem; }
        .modal-box { padding: 24px 20px; }
    }
</style>
</head>
<body>

<div class="bg-shape bg-shape-1"></div>
<div class="bg-shape bg-shape-2"></div>
<div class="bg-shape bg-shape-3"></div>

<!-- HEADER -->
<header class="main-header">
    <div class="header-inner <?= $isLoggedIn ? 'logged-in' : 'logged-out' ?>">
        
        <div class="brand-group">
            <div class="brand-icon">
                <i class="bi bi-capsule-pill"></i>
            </div>
            <div class="brand-text">
                <span class="brand-title">AI-Integrated Smart Medication Dispensing System</span>
            </div>
        </div>
        
        <?php if ($isLoggedIn): ?>
        <div class="header-actions">
            <button type="button" class="btn-add-medicine-header" onclick="openAddMedModal()">
                <i class="bi bi-plus-circle-fill"></i> <span>Add Medicine</span>
            </button>
            <a href="patients.php" class="btn-view-patients">
                <i class="bi bi-people-fill"></i> <span>View Patients</span>
            </a>
            <a href="?logout=1" class="btn-logout-new">
                <i class="bi bi-box-arrow-right"></i> <span>Logout</span>
            </a>
        </div>
        <?php endif; ?>
        
    </div>
</header>

<?php if (!$isLoggedIn): ?>
    
    <!-- LOGIN VIEW -->
    <div class="login-wrap">
        <div class="login-hero">
            <h1>Doctor Login</h1>
            <p>Sign in to access the prescription & dispensing system</p>
        </div>
        
        <div class="login-card">
            <div class="logo-box">
                <i class="bi bi-capsule-pill"></i>
            </div>
            <h2 class="login-title">Welcome Back</h2>
            <p class="login-sub">Enter your credentials to continue</p>
            
            <?php if ($login_error): ?>
                <div class="error-alert">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <?= htmlspecialchars($login_error) ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-person"></i>
                        <input type="text" name="username" class="form-control" placeholder="Enter username" required>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Password</label>
                    <div class="input-icon-wrap">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>
                </div>
                <button type="submit" name="login_submit" class="btn-login">
                    <i class="bi bi-box-arrow-in-right"></i> Login
                </button>
            </form>
        </div>
    </div>

<?php else: ?>

    <!-- PRESCRIPTION VIEW -->
    <div class="main-wrap">
        
        <div class="page-title">
            <h1>New Prescription</h1>
            <p>Enter patient details and select medicines</p>
        </div>
        
        <div class="form-card">
            <form action="generate_qr.php" method="POST" id="prescForm">
                
                <div class="section-head">
                    <div class="icon-box">
                        <i class="bi bi-person-vcard"></i>
                    </div>
                    <h3>Patient Information</h3>
                </div>
                
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label">Patient Name</label>
                        <input type="text" name="patient_name" class="form-control" placeholder="Enter full name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select" required>
                            <option value="">Select Gender</option>
                            <option value="Male">Male</option>
                            <option value="Female">Female</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Age</label>
                        <input type="number" name="age" class="form-control" placeholder="Age" min="1" max="120" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="dob" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone</label>
                        <input type="tel" name="phone" class="form-control" placeholder="Phone number" pattern="[0-9]{10}">
                    </div>
                </div>
                
                <div class="section-head">
                    <div class="icon-box">
                        <i class="bi bi-capsule"></i>
                    </div>
                    <h3>Medicines</h3>
                </div>
                
                <div class="duplicate-warning" id="duplicateWarning">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span>Duplicate medicine detected! Please select different medicines.</span>
                </div>
                
                <div id="medicines">
                    <div class="medicine-row">
                        <span class="medicine-number">MEDICINE 1</span>
                        <div class="row g-2">
                            <div class="col-md-7">
                                <label class="form-label">Medicine</label>
                                <select name="medicines[]" class="form-select medicine-select" required onchange="checkDuplicates()">
                                    <option value="">-- Select Medicine --</option>
                                    <?php foreach ($medicinesList as $med): ?>
                                        <option value="<?= htmlspecialchars($med['medicine_name']) ?>"><?= htmlspecialchars($med['medicine_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Quantity <small style="color: #dc2626; font-weight: 800;"></small></label>
                                <input type="number" name="qty[]" class="form-control qty-input" placeholder="Qty" value="1" min="1" max="4" required>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button type="button" class="btn-add-med" id="addMedBtn" onclick="addMedicine()">
                    <i class="bi bi-plus-circle"></i> Add Another Medicine
                </button>
                
                <button type="submit" class="btn-generate" id="generateBtn">
                    <i class="bi bi-qr-code"></i> Generate QR & Save
                </button>
            </form>
        </div>
    </div>

<?php endif; ?>

<!-- ═══ ADD MEDICINE MODAL ═══ -->
<div class="modal-overlay" id="addMedModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeAddMedModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        
        <div class="modal-icon">
            <i class="bi bi-plus-circle-fill"></i>
        </div>
        <h3 class="modal-title">Add New Medicine</h3>
        <p class="modal-sub">Add a new medicine to the list</p>
        
        <?php if ($add_med_success): ?>
            <div class="modal-alert success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($add_med_success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($add_med_error): ?>
            <div class="modal-alert error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($add_med_error) ?>
            </div>
        <?php endif; ?>
        
        <div class="modal-header-row">
            <span style="font-weight: 700; color: #64748b; font-size: 0.85rem;">
                <i class="bi bi-capsule"></i> Add New
            </span>
            <button type="button" class="btn-view-med-list" onclick="openViewMedModal()">
                <i class="bi bi-list-ul"></i> View Medicines (<?= count($medicinesList) ?>)
            </button>
        </div>
        
        <form method="POST">
            <label class="form-label">Medicine Name</label>
            <input type="text" name="new_medicine_name" class="form-control" 
                   placeholder="e.g., Ibuprofen, Aspirin..." required autocomplete="off">
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeAddMedModal()">
                    Cancel
                </button>
                <button type="submit" name="add_medicine_submit" class="btn-modal-submit">
                    <i class="bi bi-check-circle-fill"></i> Add Medicine
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ═══ VIEW MEDICINES MODAL ═══ -->
<div class="modal-overlay" id="viewMedModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeViewMedModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        
        <div class="modal-icon" style="background: linear-gradient(135deg, #6366f1, #8b5cf6);">
            <i class="bi bi-list-ul"></i>
        </div>
        <h3 class="modal-title">All Medicines</h3>
        <p class="modal-sub">Total: <?= count($medicinesList) ?> medicines</p>
        
        <?php if ($edit_med_success): ?>
            <div class="modal-alert success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($edit_med_success) ?>
            </div>
        <?php endif; ?>
        
        <?php if ($del_med_success): ?>
            <div class="modal-alert success">
                <i class="bi bi-check-circle-fill"></i>
                <?= htmlspecialchars($del_med_success) ?>
            </div>
        <?php endif; ?>
        
        <div style="max-height: 400px; overflow-y: auto; padding-right: 4px;">
            <?php if (count($medicinesList) > 0): ?>
                <?php foreach ($medicinesList as $med): ?>
                    <div class="med-list-item">
                        <div class="med-list-name">
                            <i class="bi bi-capsule"></i>
                            <?= htmlspecialchars($med['medicine_name']) ?>
                        </div>
                        <div class="med-list-actions">
                            <button type="button" class="btn-med-action btn-med-edit" 
                                    onclick="editMedicine(<?= $med['id'] ?>, '<?= htmlspecialchars($med['medicine_name'], ENT_QUOTES) ?>')">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <a href="?delete_med=<?= $med['id'] ?>" 
                               class="btn-med-action btn-med-delete"
                               onclick="return confirm('Delete <?= htmlspecialchars($med['medicine_name'], ENT_QUOTES) ?>?')">
                                <i class="bi bi-trash-fill"></i>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-med-box">
                    <i class="bi bi-inbox"></i>
                    <p>No medicines added yet</p>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="modal-actions">
            <button type="button" class="btn-modal-cancel" onclick="closeViewMedModal()" style="flex: 1;">
                Close
            </button>
        </div>
    </div>
</div>

<!-- ═══ EDIT MEDICINE MODAL ═══ -->
<div class="modal-overlay" id="editMedModal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeEditMedModal()">
            <i class="bi bi-x-lg"></i>
        </button>
        
        <div class="modal-icon" style="background: linear-gradient(135deg, #3b82f6, #1e40af);">
            <i class="bi bi-pencil-fill"></i>
        </div>
        <h3 class="modal-title">Edit Medicine</h3>
        <p class="modal-sub">Update medicine name</p>
        
        <form method="POST">
            <input type="hidden" name="edit_medicine_id" id="editMedId">
            
            <label class="form-label">Medicine Name</label>
            <input type="text" name="edit_medicine_name" id="editMedName" class="form-control" required>
            
            <div class="modal-actions">
                <button type="button" class="btn-modal-cancel" onclick="closeEditMedModal()">
                    Cancel
                </button>
                <button type="submit" name="edit_medicine_submit" class="btn-modal-submit" style="background: linear-gradient(135deg, #3b82f6, #1e40af);">
                    <i class="bi bi-check-circle-fill"></i> Update
                </button>
            </div>
        </form>
    </div>
</div>

<!-- FOOTER -->
<footer class="main-footer">
    <div class="footer-inner">
        <i class="bi bi-capsule-pill"></i>
        Smart Pill Dispensing System &copy; <?= date('Y') ?>
    </div>
</footer>

<script>
    let medCount = 1;
    const MAX_MEDS = 3;
    const MAX_QTY = 4;
    
    const medicinesList = <?= json_encode(array_column($medicinesList, 'medicine_name')) ?>;
    
    function openAddMedModal() {
        document.getElementById('addMedModal').classList.add('show');
    }
    function closeAddMedModal() {
        document.getElementById('addMedModal').classList.remove('show');
    }
    
    function openViewMedModal() {
        document.getElementById('viewMedModal').classList.add('show');
    }
    function closeViewMedModal() {
        document.getElementById('viewMedModal').classList.remove('show');
    }
    
    function editMedicine(id, name) {
        document.getElementById('editMedId').value = id;
        document.getElementById('editMedName').value = name;
        closeViewMedModal();
        document.getElementById('editMedModal').classList.add('show');
    }
    function closeEditMedModal() {
        document.getElementById('editMedModal').classList.remove('show');
    }
    
    function buildMedicineOptions() {
        let options = '<option value="">-- Select Medicine --</option>';
        medicinesList.forEach(med => {
            options += `<option value="${med}">${med}</option>`;
        });
        return options;
    }
    
    function addMedicine() {
        if (medCount >= MAX_MEDS) return;
        medCount++;
        const container = document.getElementById('medicines');
        const div = document.createElement('div');
        div.className = 'medicine-row';
        div.innerHTML = `
            <span class="medicine-number">MEDICINE ${medCount}</span>
            <button type="button" class="remove-btn" onclick="removeMedicine(this)">
                <i class="bi bi-x"></i>
            </button>
            <div class="row g-2">
                <div class="col-md-7">
                    <label class="form-label">Medicine</label>
                    <select name="medicines[]" class="form-select medicine-select" required onchange="checkDuplicates()">
                        ${buildMedicineOptions()}
                    </select>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Quantity <small style="color: #dc2626; font-weight: 800;">(Max 4)</small></label>
                    <input type="number" name="qty[]" class="form-control qty-input" placeholder="Qty" value="1" min="1" max="4" required>
                </div>
            </div>
        `;
        container.appendChild(div);
        updateMedNumbers();
        updateAddButton();
        checkDuplicates();
    }
    
    function removeMedicine(btn) {
        btn.closest('.medicine-row').remove();
        updateMedNumbers();
        updateAddButton();
        checkDuplicates();
    }
    
    function updateMedNumbers() {
        const rows = document.querySelectorAll('#medicines .medicine-row');
        rows.forEach((row, i) => {
            row.querySelector('.medicine-number').textContent = `MEDICINE ${i + 1}`;
        });
        medCount = rows.length;
    }
    
    function updateAddButton() {
        const btn = document.getElementById('addMedBtn');
        if (!btn) return;
        const current = document.querySelectorAll('#medicines .medicine-row').length;
        if (current >= MAX_MEDS) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-check-circle"></i> Maximum 3 medicines';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plus-circle"></i> Add Another Medicine';
        }
    }
    
    function checkDuplicates() {
        const selects = document.querySelectorAll('.medicine-select');
        const warning = document.getElementById('duplicateWarning');
        const generateBtn = document.getElementById('generateBtn');
        const values = [];
        let hasDuplicate = false;
        
        selects.forEach(sel => {
            sel.classList.remove('is-invalid');
            if (sel.value) {
                if (values.includes(sel.value)) {
                    hasDuplicate = true;
                    sel.classList.add('is-invalid');
                    sel.style.borderColor = '#dc2626';
                    sel.style.boxShadow = '0 0 0 4px rgba(220,38,38,0.15)';
                } else {
                    sel.style.borderColor = '';
                    sel.style.boxShadow = '';
                    values.push(sel.value);
                }
            } else {
                sel.style.borderColor = '';
                sel.style.boxShadow = '';
            }
        });
        
        if (hasDuplicate) {
            warning.classList.add('show');
            generateBtn.disabled = true;
            generateBtn.style.opacity = '0.5';
            generateBtn.style.cursor = 'not-allowed';
        } else {
            warning.classList.remove('show');
            generateBtn.disabled = false;
            generateBtn.style.opacity = '1';
            generateBtn.style.cursor = 'pointer';
        }
    }
    
    // 🎯 Quantity Validation — Max 4 (Strict)
    document.addEventListener('input', function(e) {
        if (e.target.name === 'qty[]') {
            let val = parseInt(e.target.value) || 0;
            
            // If empty, skip
            if (e.target.value === '') return;
            
            // If exceeds 4, force to 4
            if (val > MAX_QTY) {
                e.target.value = MAX_QTY;
                e.target.style.borderColor = '#dc2626';
                e.target.style.boxShadow = '0 0 0 4px rgba(220,38,38,0.15)';
                setTimeout(() => {
                    e.target.style.borderColor = '';
                    e.target.style.boxShadow = '';
                }, 1000);
            }
            
            // If less than 1 (and not empty), force to 1
            if (val < 1) {
                e.target.value = 1;
            }
        }
    });
    
    // 🎯 Prevent typing 5 as first digit
    document.addEventListener('keydown', function(e) {
        if (e.target.name === 'qty[]') {
            const currentVal = e.target.value;
            
            // Allow: backspace, delete, tab, escape, enter, arrows
            if ([46, 8, 9, 27, 13, 37, 38, 39, 40].indexOf(e.keyCode) !== -1) return;
            
            // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            if ((e.ctrlKey || e.metaKey) && [65, 67, 86, 88].indexOf(e.keyCode) !== -1) return;
            
            // Block "5" if current field is empty (would create 5, 50, etc.)
            if (e.key === '5' && currentVal === '') {
                e.preventDefault();
                e.target.style.borderColor = '#dc2626';
                e.target.style.boxShadow = '0 0 0 4px rgba(220,38,38,0.15)';
                setTimeout(() => {
                    e.target.style.borderColor = '';
                    e.target.style.boxShadow = '';
                }, 1000);
                return;
            }
            
            // Block numbers > 4 when field already has "1", "2", "3", or "4"
            // This prevents "45", "54" etc.
            if (/[5-9]/.test(e.key) && currentVal.length >= 1) {
                e.preventDefault();
                return;
            }
        }
    });
    
    // Auto-open modals if there's a success/error message
    <?php if ($add_med_success || $add_med_error): ?>
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => openAddMedModal(), 100);
        });
    <?php endif; ?>
    
    <?php if ($edit_med_success || $del_med_success): ?>
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => openViewMedModal(), 100);
        });
    <?php endif; ?>
    
    // Close modal on overlay click
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('show');
            }
        });
    });
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay').forEach(m => m.classList.remove('show'));
        }
    });
</script>

</body>
</html>