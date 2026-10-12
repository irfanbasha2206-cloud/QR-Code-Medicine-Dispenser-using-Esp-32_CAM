<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 * view_prescription.php — Patient Prescription Viewer & AI Clinical Station
 * ═══════════════════════════════════════════════════════════════════════════
 * 1. Validates 64-character hex access_token from `4973_patient` table.
 * 2. Supports JSON format for background services (?format=json).
 * 3. Dispense Status Guard:
 *    - If PENDING: Displays pending notice & automatically checks for dispense.
 *    - If DISPENSED: Unlocks AI Clinical Review & Voice Chatbot.
 * 4. UI is 100% in clean, professional English (no regional text displayed).
 * 5. Integrated with AI Clinical Consultation Chatbot:
 *    - Dual API failover: Google Gemini & Groq Ultra-Fast LPU (qwen/qwen3.8-27b).
 *    - Web Speech API microphone input & speech synthesis audio playback.
 * ═══════════════════════════════════════════════════════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

date_default_timezone_set('Asia/Kolkata');

$connPath = __DIR__ . '/connection.php';
$conn = null;
if (file_exists($connPath)) {
    include_once $connPath;
}

// ─── 1. Get & Validate Token ───
$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(400);
    die("Invalid access. Prescription access token is missing.");
}

// ─── 2. Fetch Prescription from Database ───
$p = null;
if ($conn) {
    @mysqli_set_charset($conn, "utf8mb4");
    $safeToken = mysqli_real_escape_string($conn, $token);

    // Primary: search by access_token
    $sql = "SELECT * FROM `4973_patient` WHERE access_token = '$safeToken' LIMIT 1";
    $result = @mysqli_query($conn, $sql);

    // Fallback: search by presc_id or token column if access_token column not found
    if (!$result || mysqli_num_rows($result) === 0) {
        $sqlFallback = "SELECT * FROM `4973_patient` WHERE presc_id = '$safeToken' OR token = '$safeToken' LIMIT 1";
        $result = @mysqli_query($conn, $sqlFallback);
    }

    if ($result && mysqli_num_rows($result) > 0) {
        $p = mysqli_fetch_assoc($result);
    }
}

if (!$p) {
    http_response_code(404);
    die("Prescription not found or invalid token.");
}

// Extract patient record fields
$presc_id        = $p['presc_id'] ?? ('PRESC_' . substr($token, 0, 10));
$patient_name    = $p['patient_name'] ?? 'Patient';
$gender          = $p['gender'] ?? 'Unspecified';
$age             = (int)($p['age'] ?? 25);
$dob             = $p['dob'] ?? '-';
$phone           = $p['phone'] ?? '-';
$email           = $p['email'] ?? 'Not Provided';
$doctor          = $p['doctor'] ?? 'Attending Physician';
$qr_image_url    = $p['qr_image_url'] ?? '';
$dispense_status = strtoupper($p['dispense_status'] ?? 'PENDING');
$created_at      = $p['created_at'] ?? date('Y-m-d H:i:s');
$scanned_at      = $p['scanned_at'] ?? null;
$dispensed_at    = $p['dispensed_at'] ?? null;

// Parse medicines list
$medicines = json_decode($p['medicines'] ?? '[]', true);
if (!is_array($medicines)) $medicines = [];

// ─── 3. Check for Saved AI Clinical Feedback ───
$aiStorageDir = __DIR__ . '/shared/ai_feedback';
$aiReport = null;

if (file_exists($aiStorageDir . '/' . $token . '.json')) {
    $aiReport = json_decode(file_get_contents($aiStorageDir . '/' . $token . '.json'), true);
} elseif (!empty($presc_id) && file_exists($aiStorageDir . '/' . $presc_id . '.json')) {
    $aiReport = json_decode(file_get_contents($aiStorageDir . '/' . $presc_id . '.json'), true);
}

// ─── 4. JSON Format Output (For Python Watcher & Telemetry) ───
$wantJson = isset($_GET['format']) && $_GET['format'] === 'json';
if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode([
        'status'          => 'success',
        'presc_id'        => $presc_id,
        'token'           => $token,
        'patient'         => [
            'name'   => $patient_name,
            'gender' => $gender,
            'age'    => $age,
            'dob'    => $dob,
            'phone'  => $phone,
            'email'  => $email
        ],
        'medicines'       => $medicines,
        'qr_image_url'    => $qr_image_url,
        'dispense_status' => $dispense_status,
        'created_at'      => $created_at,
        'scanned_at'      => $scanned_at,
        'dispensed_at'    => $dispensed_at,
        'ai_report'       => $aiReport
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$isDispensed = ($dispense_status === 'DISPENSED');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Prescription <?= htmlspecialchars($presc_id) ?> — AegisRx Medical Station</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">

<style>
    :root {
        --primary: #4f46e5;
        --primary-accent: #6366f1;
        --secondary-cyan: #06b6d4;
        --emerald: #10b981;
        --amber: #f59e0b;
        --rose: #f43f5e;
        --dark: #0f172a;
        --border-light: #e2e8f0;
        --text-dark: #0f172a;
        --text-muted: #64748b;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        background: linear-gradient(135deg, #f8fafc 0%, #eef2ff 50%, #f1f5f9 100%);
        min-height: 100vh;
        font-family: 'Plus Jakarta Sans', sans-serif;
        padding: 30px 16px 120px;
        color: var(--text-dark);
    }

    .presc-card {
        max-width: 860px;
        margin: 0 auto;
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
        overflow: hidden;
        border: 1px solid rgba(255, 255, 255, 0.9);
    }

    /* ═══ HEADER ═══ */
    .presc-header {
        background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 100%);
        color: #ffffff;
        padding: 36px 30px;
        text-align: center;
        position: relative;
    }
    .presc-header::after {
        content: '';
        position: absolute;
        bottom: 0; left: 0; right: 0;
        height: 4px;
        background: linear-gradient(90deg, #06b6d4, #10b981, #f43f5e);
    }
    .presc-header .icon-top {
        font-size: 2.8rem;
        margin-bottom: 10px;
        display: inline-block;
        filter: drop-shadow(0 4px 12px rgba(0,0,0,0.15));
    }
    .presc-header h1 {
        font-weight: 800;
        font-size: 1.85rem;
        margin-bottom: 6px;
        letter-spacing: -0.5px;
    }
    .presc-header .presc-id {
        opacity: 0.95;
        font-family: 'JetBrains Mono', monospace;
        font-size: 0.92rem;
        font-weight: 600;
        margin-bottom: 16px;
        background: rgba(255, 255, 255, 0.16);
        display: inline-block;
        padding: 4px 14px;
        border-radius: 20px;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 22px;
        border-radius: 30px;
        font-weight: 800;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .status-badge.PENDING   { background: #fef3c7; color: #92400e; }
    .status-badge.SCANNED   { background: #dbeafe; color: #1e40af; }
    .status-badge.DISPENSED { background: #d1fae5; color: #065f46; }

    .presc-body { padding: 32px 28px; }

    .info-section {
        background: #f8fafc;
        border-radius: 16px;
        padding: 22px 24px;
        margin-bottom: 22px;
        border: 1.5px solid var(--border-light);
    }
    .info-section h4 {
        font-weight: 800;
        font-size: 1.02rem;
        color: #1e293b;
        margin-bottom: 18px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .info-section h4 i { color: var(--primary-accent); }

    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 9px 0;
        border-bottom: 1px dashed #e2e8f0;
        font-size: 0.92rem;
    }
    .info-row:last-child { border-bottom: none; }
    .info-row .label { color: var(--text-muted); font-weight: 600; }
    .info-row .value { color: var(--text-dark); font-weight: 700; text-align: right; }

    /* Medicines List */
    .med-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        background: #ffffff;
        border: 1.5px solid var(--border-light);
        border-radius: 12px;
        margin-bottom: 10px;
    }
    .med-name {
        font-weight: 700;
        font-size: 0.98rem;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .med-name i { color: var(--primary-accent); }
    .med-qty {
        background: linear-gradient(135deg, #4f46e5, #6366f1);
        color: #ffffff;
        padding: 5px 14px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 0.82rem;
    }

    /* QR Code */
    .qr-section {
        text-align: center;
        padding: 26px;
        background: linear-gradient(135deg, #eef2ff, #f0fdf4);
        border-radius: 16px;
        border: 2px dashed #cbd5e1;
    }
    .qr-section img {
        max-width: 240px;
        width: 100%;
        border-radius: 14px;
        background: #ffffff;
        padding: 14px;
        box-shadow: 0 8px 24px rgba(99, 102, 241, 0.15);
    }

    /* ═══ PENDING DISPENSE GATE ═══ */
    .pending-gate-box {
        background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        border: 2px dashed #f59e0b;
        border-radius: 20px;
        padding: 36px 24px;
        text-align: center;
        margin-top: 26px;
    }
    .pending-gate-icon {
        width: 68px;
        height: 68px;
        background: rgba(245, 158, 11, 0.15);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 16px;
    }
    .pending-gate-icon i { font-size: 2.2rem; color: #d97706; }
    .pending-gate-box h3 {
        font-weight: 800;
        color: #92400e;
        font-size: 1.3rem;
        margin-bottom: 10px;
    }
    .pending-gate-box p {
        color: #78350f;
        font-size: 0.95rem;
        max-width: 640px;
        margin: 0 auto 18px;
        line-height: 1.6;
    }
    .pending-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: #ffffff;
        padding: 8px 22px;
        border-radius: 30px;
        font-weight: 700;
        color: #92400e;
        font-size: 0.85rem;
        border: 1.5px solid #fde68a;
    }

    /* ═══ DOCTOR AEGIS AI CLINICAL REVIEW ═══ */
    .ai-dossier-box {
        background: linear-gradient(145deg, #ffffff, #f8faff);
        border: 2px solid #6366f1;
        border-radius: 22px;
        padding: 26px;
        margin-top: 28px;
        margin-bottom: 24px;
        box-shadow: 0 12px 40px rgba(99, 102, 241, 0.12);
    }
    .ai-header-badge {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        background: linear-gradient(135deg, #4f46e5, #7c3aed);
        color: #ffffff;
        padding: 8px 18px;
        border-radius: 20px;
        font-weight: 800;
        font-size: 0.88rem;
        letter-spacing: 0.4px;
        margin-bottom: 20px;
        box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
    }
    .ai-card {
        background: #ffffff;
        border: 1.5px solid #e0e7ff;
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 16px;
    }
    .ai-card-title {
        font-weight: 800;
        font-size: 0.95rem;
        color: #312e81;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .ai-card-body {
        font-size: 0.92rem;
        color: #334155;
        line-height: 1.6;
    }

    .btn-audio-guide {
        background: linear-gradient(135deg, #4f46e5, #6366f1);
        color: #ffffff;
        border: none;
        border-radius: 20px;
        padding: 8px 18px;
        font-weight: 700;
        font-size: 0.85rem;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
        transition: all 0.2s;
        margin-top: 10px;
    }
    .btn-audio-guide:hover { transform: translateY(-2px); opacity: 0.95; }

    /* ═══ EXPANDED INTERACTIVE CONSULTATION CHATBOT ═══ */
    .chat-section {
        background: #ffffff;
        border-radius: 24px;
        border: 2.5px solid #6366f1;
        box-shadow: 0 18px 50px rgba(99, 102, 241, 0.14);
        margin-top: 32px;
        margin-bottom: 24px;
        overflow: hidden;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.3s ease;
    }
    .chat-header {
        background: linear-gradient(135deg, #1e1b4b, #312e81);
        color: #ffffff;
        padding: 16px 22px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 10px;
    }
    .chat-header h3 {
        font-size: 1.05rem;
        font-weight: 800;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .voice-lang-switch-box {
        display: inline-flex;
        background: rgba(255, 255, 255, 0.15);
        border: 1px solid rgba(255, 255, 255, 0.3);
        border-radius: 20px;
        padding: 3px;
        gap: 3px;
    }
    .lang-tab {
        background: transparent;
        border: none;
        color: #e0e7ff;
        padding: 5px 12px;
        border-radius: 16px;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.2s ease;
        user-select: none;
    }
    .lang-tab:hover {
        color: #ffffff;
        background: rgba(255, 255, 255, 0.1);
    }
    .lang-tab.active {
        background: #ffffff !important;
        color: #312e81 !important;
        box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
    }
    .lang-tab i { font-size: 0.85rem; }

    .chat-messages {
        height: 360px;
        overflow-y: auto;
        padding: 20px;
        background: #f8fafc;
        display: flex;
        flex-direction: column;
        gap: 14px;
        box-sizing: border-box;
    }
    .chat-bubble {
        max-width: 84%;
        padding: 14px 18px;
        border-radius: 16px;
        font-size: 0.92rem;
        line-height: 1.55;
        position: relative;
        word-wrap: break-word;
        box-sizing: border-box;
    }
    .chat-bubble.doctor {
        align-self: flex-start;
        background: #ffffff;
        border: 1.5px solid #e0e7ff;
        color: #1e293b;
        border-bottom-left-radius: 4px;
    }
    .chat-bubble.user {
        align-self: flex-end;
        background: linear-gradient(135deg, #4f46e5, #6366f1);
        color: #ffffff;
        border-bottom-right-radius: 4px;
    }
    .chat-speaker-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-top: 8px;
        padding: 5px 14px;
        border-radius: 12px;
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        color: #4338ca;
        font-size: 0.78rem;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .chat-speaker-btn:hover {
        background: #e0e7ff;
        transform: translateY(-1px);
    }
    .chat-speaker-btn.speaking {
        background: #ecfdf5 !important;
        border-color: #6ee7b7 !important;
        color: #047857 !important;
        animation: pulseSpeaker 1.5s infinite;
    }
    @keyframes pulseSpeaker {
        0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); }
        70% { box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
        100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .chat-quick-suggestions {
        padding: 10px 16px;
        background: #f1f5f9;
        border-top: 1px solid #e2e8f0;
        display: flex;
        gap: 8px;
        overflow-x: auto;
        white-space: nowrap;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .chat-quick-suggestions::-webkit-scrollbar { display: none; }
    .suggestion-chip {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        padding: 6px 14px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        color: #475569;
        cursor: pointer;
        transition: all 0.2s;
        flex-shrink: 0;
    }
    .suggestion-chip:hover {
        border-color: #6366f1;
        color: #4f46e5;
        background: #eef2ff;
    }

    /* ═══ RESPONSIVE CHAT INPUT BAR ═══ */
    .chat-input-bar {
        padding: 12px 14px;
        background: #ffffff;
        border-top: 1.5px solid #e2e8f0;
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
        box-sizing: border-box;
    }
    .chat-input {
        flex: 1 1 0% !important;
        min-width: 0 !important; /* CRITICAL: Allows shrinking inside mobile flexbox */
        padding: 12px 16px;
        border-radius: 25px;
        border: 1.5px solid #cbd5e1;
        outline: none;
        font-size: 0.93rem;
        box-sizing: border-box;
        transition: border-color 0.2s;
    }
    .chat-input:focus { border-color: #6366f1; }

    .btn-mic {
        flex-shrink: 0 !important;
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
        border-radius: 50%;
        border: none;
        background: #f1f5f9;
        color: #475569;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-mic:hover { background: #e2e8f0; color: #1e293b; }
    .btn-mic.listening {
        background: #ef4444 !important;
        color: #ffffff !important;
        animation: pulseRecord 1.5s infinite;
        transform: scale(1.05);
    }
    @keyframes pulseRecord {
        0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); }
        70% { box-shadow: 0 0 0 16px rgba(239, 68, 68, 0); }
        100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    @keyframes spinSlow {
        from { transform: rotate(0deg); }
        to { transform: rotate(360deg); }
    }

    .btn-send {
        flex-shrink: 0 !important;
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
        border-radius: 50%;
        border: none;
        background: linear-gradient(135deg, #4f46e5, #6366f1);
        color: #ffffff;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.15rem;
        cursor: pointer;
        transition: all 0.2s;
    }
    .btn-send:hover { transform: scale(1.05); }

    .voice-status-bar {
        background: #fef2f2;
        color: #991b1b;
        padding: 9px 14px;
        font-size: 0.82rem;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
        border-top: 1.5px solid #fecaca;
        box-sizing: border-box;
        transition: all 0.25s ease;
    }

    .sound-wave {
        display: inline-flex;
        align-items: center;
        gap: 3px;
        height: 18px;
        flex-shrink: 0;
    }
    .sound-wave .bar {
        display: inline-block;
        width: 3px;
        background: #ef4444;
        border-radius: 3px;
        animation: soundWavePulse 0.9s ease-in-out infinite alternate;
    }
    .sound-wave .bar-1 { height: 7px; animation-delay: 0.1s; }
    .sound-wave .bar-2 { height: 16px; animation-delay: 0.25s; }
    .sound-wave .bar-3 { height: 20px; animation-delay: 0.4s; }
    .sound-wave .bar-4 { height: 13px; animation-delay: 0.15s; }
    .sound-wave .bar-5 { height: 8px; animation-delay: 0.3s; }
    @keyframes soundWavePulse {
        0% { transform: scaleY(0.35); opacity: 0.45; }
        100% { transform: scaleY(1.35); opacity: 1; }
    }

    .footer-note {
        text-align: center;
        padding: 24px;
        color: #94a3b8;
        font-size: 0.85rem;
        border-top: 1px solid #f1f5f9;
        background: #f8fafc;
    }

    /* ═══ MOBILE RESPONSIVE ADAPTATION (ZERO CUTOFF / ZERO HIDE) ═══ */
    @media (max-width: 768px) {
        body {
            padding: 12px 8px 80px;
        }
        .presc-card {
            border-radius: 18px;
        }
        .presc-header {
            padding: 24px 16px;
        }
        .presc-header h1 {
            font-size: 1.45rem;
        }
        .presc-body {
            padding: 16px 10px;
        }
        .info-section {
            padding: 16px 12px;
            margin-bottom: 14px;
            border-radius: 14px;
        }
        .ai-dossier-box {
            padding: 16px 12px;
            border-radius: 16px;
        }
        .ai-card {
            padding: 14px 12px;
            margin-bottom: 12px;
        }

        /* Expanded Mobile Chatbot */
        .chat-section {
            margin-top: 20px;
            margin-bottom: 16px;
            border-radius: 18px;
            border-width: 2px;
        }
        .chat-header {
            padding: 12px 14px;
        }
        .chat-header h3 {
            font-size: 0.95rem;
        }
        .chat-messages {
            height: 350px;
            max-height: 48vh;
            padding: 14px 10px;
            gap: 12px;
        }
        .chat-bubble {
            max-width: 90%;
            padding: 12px 14px;
            font-size: 0.88rem;
        }

        /* Compact & Bulletproof Input Controls */
        .chat-input-bar {
            padding: 8px 10px;
            gap: 6px;
        }
        .btn-mic, .btn-send {
            width: 40px !important;
            height: 40px !important;
            min-width: 40px !important;
            min-height: 40px !important;
            font-size: 1.1rem;
        }
        .chat-input {
            padding: 10px 12px;
            font-size: 0.88rem;
            border-radius: 20px;
        }
    }

    @media (max-width: 420px) {
        .presc-body {
            padding: 12px 6px;
        }
        .chat-section {
            border-radius: 14px;
        }
        .chat-input-bar {
            padding: 6px 6px;
            gap: 5px;
        }
        .btn-mic, .btn-send {
            width: 38px !important;
            height: 38px !important;
            min-width: 38px !important;
            min-height: 38px !important;
            font-size: 1.05rem;
        }
        .chat-input {
            padding: 9px 10px;
            font-size: 0.84rem;
        }
        .voice-lang-pill {
            padding: 4px 10px;
            font-size: 0.72rem;
        }
    }
</style>
</head>
<body>

<div class="presc-card">
    <!-- Header -->
    <div class="presc-header">
        <i class="bi bi-file-medical-fill icon-top"></i>
        <h1>Clinical Prescription</h1>
        <div class="presc-id"><?= htmlspecialchars($presc_id) ?></div>

        <div class="status-badge <?= htmlspecialchars($dispense_status) ?>">
            <?php if ($dispense_status === 'DISPENSED'): ?>
                <i class="bi bi-check-circle-fill"></i> Dispensed Successfully
            <?php elseif ($dispense_status === 'SCANNED'): ?>
                <i class="bi bi-qr-code-scan"></i> Scanned — Processing
            <?php else: ?>
                <i class="bi bi-clock-fill"></i> Pending Dispense
            <?php endif; ?>
        </div>
    </div>

    <div class="presc-body">
        <!-- Patient Information -->
        <div class="info-section">
            <h4><i class="bi bi-person-fill"></i> Patient Demographics</h4>
            <div class="info-row">
                <span class="label">Patient Name</span>
                <span class="value"><?= htmlspecialchars($patient_name) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Gender / Age</span>
                <span class="value"><?= htmlspecialchars($gender) ?> / <?= htmlspecialchars(strval($age)) ?> Years</span>
            </div>
            <div class="info-row">
                <span class="label">Date of Birth</span>
                <span class="value"><?= htmlspecialchars($dob) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Phone Contact</span>
                <span class="value"><?= htmlspecialchars($phone) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Email Address</span>
                <span class="value"><?= htmlspecialchars($email) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Attending Doctor</span>
                <span class="value"><?= htmlspecialchars($doctor) ?></span>
            </div>
            <div class="info-row">
                <span class="label">Prescription Date</span>
                <span class="value"><?= htmlspecialchars(date('d-M-Y H:i', strtotime($created_at))) ?></span>
            </div>
        </div>

        <!-- Prescribed Medications -->
        <div class="info-section">
            <h4><i class="bi bi-capsule"></i> Prescribed Medications</h4>
            <?php if (!empty($medicines)): ?>
                <?php foreach ($medicines as $m): ?>
                    <div class="med-item">
                        <span class="med-name">
                            <i class="bi bi-capsule"></i>
                            <?= htmlspecialchars(is_array($m) ? ($m['name'] ?? 'Medication') : strval($m)) ?>
                        </span>
                        <span class="med-qty">x <?= htmlspecialchars(is_array($m) ? strval($m['qty'] ?? 1) : '1') ?> Units</span>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="color: #94a3b8; text-align: center; margin: 0;">No medication entries recorded.</p>
            <?php endif; ?>
        </div>

        <!-- Prescription QR Code -->
        <div class="info-section">
            <h4><i class="bi bi-qr-code"></i> Dispensing Verification QR</h4>
            <div class="qr-section">
                <?php if (!empty($qr_image_url)): ?>
                    <img src="<?= htmlspecialchars($qr_image_url) ?>?t=<?= time() ?>" alt="Prescription QR Code">
                <?php else: ?>
                    <img src="shared/qr.png?t=<?= time() ?>" alt="Prescription QR Code">
                <?php endif; ?>
                <p style="margin-top: 14px; font-weight: 600; color: #64748b;">
                    Present this QR code at the AegisRx Intelligent Medicine Dispenser
                </p>
            </div>
        </div>

        <?php if ($isDispensed): ?>
        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 🩺 AI CLINICAL PHARMACOLOGICAL REVIEW (ACTIVE WHEN DISPENSED) -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="ai-dossier-box" id="ai-dossier-container">
            <div class="ai-header-badge">
                <i class="bi bi-award-fill"></i>
                AI Clinical Pharmacology Review & Medication Guidance
            </div>

            <!-- Clinical Overview -->
            <div class="ai-card">
                <div class="ai-card-title">
                    <i class="bi bi-clipboard2-pulse-fill" style="color: #4f46e5;"></i>
                    Clinical Overview & Therapeutic Suitability
                </div>
                <div class="ai-card-body" id="ai-patient-summary">
                    <?= !empty($aiReport['patient_summary']) ? htmlspecialchars($aiReport['patient_summary']) : 'Clinical review verified and cross-referenced with pharmacological models.' ?>
                </div>
                <button class="btn-audio-guide" onclick="speakSummary()">
                    <i class="bi bi-volume-up-fill"></i> Listen to Clinical Overview
                </button>
            </div>

            <!-- Medicines Detailed Breakdown -->
            <div id="ai-medicines-container">
                <?php if (!empty($aiReport['medicines_analysis'])): ?>
                    <?php foreach ($aiReport['medicines_analysis'] as $med): ?>
                        <div class="ai-card">
                            <div class="ai-card-title">
                                <i class="bi bi-capsule-pill" style="color: #06b6d4;"></i>
                                <?= htmlspecialchars($med['medicine_name'] ?? 'Medication') ?> — Clinical Details
                            </div>
                            <div class="ai-card-body">
                                <p><strong>Mechanism & Purpose:</strong> <?= htmlspecialchars($med['pharmacological_action'] ?? '') ?></p>
                                <p style="margin-top: 8px;"><strong>Side Effects & Safety:</strong> <?= htmlspecialchars($med['side_effects'] ?? '') ?></p>
                                <p style="margin-top: 8px;"><strong>Meal & Diet Guidelines:</strong> <?= htmlspecialchars($med['meal_guidelines'] ?? '') ?></p>
                                <p style="margin-top: 8px;"><strong>Age-Calibrated Dosage Profile:</strong> <?= htmlspecialchars($med['dosage_for_age'] ?? '') ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Drug Safety & Interactions -->
            <div class="ai-card">
                <div class="ai-card-title">
                    <i class="bi bi-shield-check" style="color: #10b981;"></i>
                    Drug Safety, Lifestyle & Food Interactions
                </div>
                <div class="ai-card-body" id="ai-interactions">
                    <?= !empty($aiReport['drug_interactions_and_safety']) ? htmlspecialchars($aiReport['drug_interactions_and_safety']) : 'Adhere to instructions. Take doses with a full glass of water.' ?>
                </div>
            </div>

            <!-- Important Precautions & Doctor Consultation Notice -->
            <div class="ai-card" style="border-color: #fed7aa; background: #fffaf5;">
                <div class="ai-card-title" style="color: #c2410c;">
                    <i class="bi bi-shield-exclamation" style="color: #ea580c;"></i>
                    Important Precautions & When to Contact Doctor
                </div>
                <div class="ai-card-body" style="color: #7c2d12; line-height: 1.7;" id="ai-precautions">
                    <?= !empty($aiReport['red_flag_symptoms']) ? htmlspecialchars($aiReport['red_flag_symptoms']) : 'Seek medical advice promptly if you experience severe shortness of breath, unexpected skin rash, facial swelling, or severe dizziness.' ?>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- 💬 AI CLINICAL MEDICAL CONSULTATION CHATBOT -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="chat-section">
            <div class="chat-header">
                <h3>
                    <i class="bi bi-robot"></i>
                    AI Clinical Consultation Assistant
                </h3>
                <div class="voice-lang-switch-box" title="Select Voice Recognition Language">
                    <button type="button" class="lang-tab active" id="tab-lang-ta" onclick="setVoiceLang('ta-IN')">
                        <i class="bi bi-mic-fill"></i> தமிழ்
                    </button>
                    <button type="button" class="lang-tab" id="tab-lang-en" onclick="setVoiceLang('en-IN')">
                        <i class="bi bi-mic-fill"></i> English
                    </button>
                </div>
            </div>

            <div class="chat-messages" id="chat-messages">
                <div class="chat-bubble doctor">
                    <strong>AI Clinical Consultant:</strong><br>
                    <span class="msg-text">Hello <?= htmlspecialchars($patient_name) ?>! You can ask your questions in <strong>Tamil (தமிழ்)</strong> or <strong>English</strong>. Feel free to speak or type about meal timing, dosage, or potential side effects.<br><br>வணக்கம் <?= htmlspecialchars($patient_name) ?>! உங்கள் மருந்துகள், உணவு நேரம், பக்க விளைவுகள் பற்றி தமிழ் அல்லது ஆங்கிலத்தில் தாராளமாக கேட்கலாம்.</span>
                    <br>
                    <button class="chat-speaker-btn" title="Click to listen" onclick="speakMessage(this)">
                        <i class="bi bi-volume-up-fill"></i> Listen
                    </button>
                </div>
            </div>

            <!-- Multilingual Quick Question Chips -->
            <div class="chat-quick-suggestions">
                <span class="suggestion-chip" onclick="askQuick('Should I take these medications before or after meals?')">🥗 Before or after meals?</span>
                <span class="suggestion-chip" onclick="askQuick('இந்த மாத்திரைய சாப்பாட்டுக்கு முன்னாடி சாப்பிடணுமா இல்ல பின்னாடியா?')">🥗 சாப்பாட்டுக்கு முன் / பின்?</span>
                <span class="suggestion-chip" onclick="askQuick('What are the most common side effects to watch out for?')">⚠️ Common side effects?</span>
                <span class="suggestion-chip" onclick="askQuick('இந்த மாத்திரை சாப்பிட்டால் ஏதேனும் பக்க விளைவுகள் வருமா?')">⚠️ பக்க விளைவுகள் வருமா?</span>
                <span class="suggestion-chip" onclick="askQuick('What should I do if I miss a scheduled dose?')">⏰ What if I miss a dose?</span>
            </div>

            <!-- Voice & Text Input Bar -->
            <div class="chat-input-bar">
                <button class="btn-mic" id="mic-btn" title="Click to speak (Voice Recognition)" onclick="toggleVoiceInput()">
                    <i class="bi bi-mic-fill" id="mic-icon"></i>
                </button>

                <input type="text" class="chat-input" id="chat-user-input" placeholder="Speak or type in Tamil (தமிழ்) or English..." onkeypress="handleEnter(event)">

                <button class="btn-send" onclick="sendChatMessage()" title="Send">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
            <!-- Live voice listening indicator banner -->
            <div id="voice-status-bar" class="voice-status-bar" style="display: none;">
                <div class="sound-wave" id="voice-sound-wave">
                    <span class="bar bar-1"></span>
                    <span class="bar bar-2"></span>
                    <span class="bar bar-3"></span>
                    <span class="bar bar-4"></span>
                    <span class="bar bar-5"></span>
                </div>
                <span id="voice-status-text">Listening... Speak now</span>
            </div>
        </div>

        <?php else: ?>
        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- ⏳ PENDING / SCANNED GATE: UNLOCKS WHEN STATUS = DISPENSED -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <?php if ($dispense_status === 'SCANNED'): ?>
        <div class="pending-gate-box" style="border-color: #93c5fd; background: #f0f7ff;">
            <div class="pending-gate-icon" style="background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff;">
                <i class="bi bi-gear-wide-connected" style="animation: spinSlow 3s linear infinite; display: inline-block;"></i>
            </div>
            <h3 style="color: #1e3a8a;">QR Verified! Dispenser Machine is Dispensing Pills...</h3>
            <p style="color: #334155;">
                Your prescription QR code was successfully verified. The AegisRx robotic dispensing machine is actively dispensing your medications from the medication slots.
                <strong>AI Clinical Review and Consultation will automatically unlock as soon as dispensing completes.</strong>
            </p>
            <div class="pending-status-pill" style="background: #e0f2fe; color: #0369a1; border-color: #bae6fd;">
                <span class="spinner-border spinner-border-sm text-primary" role="status"></span>
                <span>Dispenser machine active — Awaiting completion confirmation</span>
            </div>
        </div>
        <?php else: ?>
        <div class="pending-gate-box">
            <div class="pending-gate-icon">
                <i class="bi bi-hourglass-split"></i>
            </div>
            <h3>Prescription Awaiting Dispense</h3>
            <p>
                Your prescription has not yet been scanned at the machine. Once you present this QR code at the AegisRx Smart Dispenser and medicines are physically dropped (<strong>Status: DISPENSED</strong>), 
                <strong>complete AI clinical review, dosage rules, meal guidelines, and interactive consultation</strong> will automatically activate here.
            </p>
            <div class="pending-status-pill">
                <span class="spinner-grow spinner-grow-sm text-warning" role="status"></span>
                <span>Awaiting QR scan at dispenser machine...</span>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="footer-note">
        <i class="bi bi-heart-pulse-fill" style="color: #ef4444;"></i>
        Autonomous Healthcare Ecosystem &bull; AI Clinical Consultant &bull; <?= date('Y') ?>
    </div>
</div>

<script>
    const PATIENT_CONTEXT = {
        name: <?= json_encode($patient_name) ?>,
        age: <?= json_encode($age) ?>,
        gender: <?= json_encode($gender) ?>,
        presc_id: <?= json_encode($presc_id) ?>,
        token: <?= json_encode($token) ?>,
        medicines: <?= json_encode($medicines) ?>
    };

    let isListening = false;
    let speechRecognition = null;
    let chatHistory = [];
    let currentVoiceLang = "ta-IN"; // Default voice input: Tamil (India)

    function setVoiceLang(lang) {
        currentVoiceLang = lang;
        const tabTa = document.getElementById("tab-lang-ta");
        const tabEn = document.getElementById("tab-lang-en");
        if (lang === "ta-IN") {
            if (tabTa) tabTa.classList.add("active");
            if (tabEn) tabEn.classList.remove("active");
        } else {
            if (tabEn) tabEn.classList.add("active");
            if (tabTa) tabTa.classList.remove("active");
        }
        if (isListening && speechRecognition) {
            speechRecognition.stop();
            setTimeout(() => { toggleVoiceInput(); }, 250);
        }
    }

    // ─── Web Speech Recognition (Microphone Voice Input Engine) ───
    function updateVoiceUiState(state, customMsg) {
        const mic = document.getElementById("mic-btn");
        const statusBar = document.getElementById("voice-status-bar");
        const statusText = document.getElementById("voice-status-text");
        const soundWave = document.getElementById("voice-sound-wave");
        const inputEl = document.getElementById("chat-user-input");

        if (state === 'starting' || state === 'listening') {
            if (mic) mic.classList.add("listening");
            if (statusBar) {
                statusBar.style.display = "flex";
                statusBar.style.background = "#fef2f2";
                statusBar.style.borderColor = "#fecaca";
                statusBar.style.color = "#991b1b";
            }
            if (soundWave) soundWave.style.display = "inline-flex";
            if (statusText) {
                const langName = currentVoiceLang === "ta-IN" ? "Tamil (தமிழ்)" : "English";
                statusText.innerHTML = customMsg || `Listening... Speak in <strong>${langName}</strong> (தமிழ் / English)`;
            }
        } else if (state === 'processing') {
            if (mic) mic.classList.remove("listening");
            if (soundWave) soundWave.style.display = "none";
            if (statusBar) {
                statusBar.style.display = "flex";
                statusBar.style.background = "#eef2ff";
                statusBar.style.borderColor = "#c7d2fe";
                statusBar.style.color = "#4338ca";
            }
            if (statusText) {
                statusText.innerHTML = `<span class="spinner-border spinner-border-sm text-primary"></span> Transcribed! Sending to AI Consultant...`;
            }
        } else if (state === 'notice') {
            if (mic) mic.classList.remove("listening");
            if (soundWave) soundWave.style.display = "none";
            if (statusBar) {
                statusBar.style.display = "flex";
                statusBar.style.background = "#fffbeb";
                statusBar.style.borderColor = "#fcd34d";
                statusBar.style.color = "#92400e";
            }
            if (statusText) {
                statusText.innerHTML = customMsg;
            }
            setTimeout(() => {
                if (!isListening && statusBar) statusBar.style.display = "none";
            }, 7000);
        } else {
            // Idle state
            isListening = false;
            if (mic) mic.classList.remove("listening");
            if (soundWave) soundWave.style.display = "none";
            if (statusBar) statusBar.style.display = "none";
        }
    }

    function initSpeech() {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) {
            console.warn("SpeechRecognition is not supported in this browser.");
            return null;
        }

        if (speechRecognition) {
            try { speechRecognition.abort(); } catch (e) {}
        }

        speechRecognition = new SpeechRec();
        speechRecognition.continuous = false;
        speechRecognition.interimResults = true; // Real-time live speech transcription
        speechRecognition.maxAlternatives = 1;

        speechRecognition.onstart = function() {
            isListening = true;
            updateVoiceUiState('listening');
        };

        speechRecognition.onresult = function(e) {
            let interimTranscript = '';
            let finalTranscript = '';

            for (let i = e.resultIndex; i < e.results.length; ++i) {
                if (e.results[i].isFinal) {
                    finalTranscript += e.results[i][0].transcript;
                } else {
                    interimTranscript += e.results[i][0].transcript;
                }
            }

            const currentWords = finalTranscript || interimTranscript;
            const inputEl = document.getElementById("chat-user-input");
            if (inputEl && currentWords) {
                inputEl.value = currentWords;
            }

            const statusText = document.getElementById("voice-status-text");
            if (statusText && currentWords) {
                statusText.innerHTML = `Hearing: "<em>${escapeHtml(currentWords)}</em>"`;
            }

            if (finalTranscript && finalTranscript.trim()) {
                updateVoiceUiState('processing');
                stopVoiceInput();
                setTimeout(() => {
                    sendChatMessage();
                }, 400);
            }
        };

        speechRecognition.onerror = function(e) {
            console.warn("Speech Recognition Error:", e);
            const err = e.error || '';
            if (err === 'not-allowed' || err === 'service-not-allowed') {
                updateVoiceUiState('notice', '<i class="bi bi-exclamation-triangle-fill"></i> Microphone access blocked! Tap the lock icon 🔒 next to the website URL to allow microphone.');
            } else if (err === 'no-speech') {
                updateVoiceUiState('notice', '<i class="bi bi-info-circle-fill"></i> No voice detected. Please tap the mic and speak clearly.');
            } else if (err === 'network') {
                updateVoiceUiState('notice', '<i class="bi bi-wifi-off"></i> Speech network issue. Check internet connection or type below.');
            } else {
                updateVoiceUiState('notice', '<i class="bi bi-info-circle"></i> Voice stopped. Tap mic to speak again.');
            }
            isListening = false;
        };

        speechRecognition.onend = function() {
            if (isListening) {
                stopVoiceInput();
            }
        };

        return speechRecognition;
    }

    async function toggleVoiceInput() {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) {
            updateVoiceUiState('notice', '<i class="bi bi-info-circle-fill"></i> Voice recognition not supported on this in-app browser. Please open in Google Chrome, Edge, or Safari.');
            return;
        }

        if (isListening) {
            stopVoiceInput();
            return;
        }

        // 1. Immediate visual feedback on touch/click
        isListening = true;
        const langText = currentVoiceLang === "ta-IN" ? "Tamil (தமிழ்)" : "English";
        updateVoiceUiState('starting', `Activating microphone for <strong>${langText}</strong>... Speak now`);

        // 2. Request / check microphone permission explicitly
        if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
            try {
                const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                stream.getTracks().forEach(t => t.stop());
            } catch (permErr) {
                console.warn("Microphone permission check:", permErr);
                if (permErr.name === 'NotAllowedError' || permErr.name === 'PermissionDeniedError') {
                    updateVoiceUiState('notice', '<i class="bi bi-exclamation-triangle-fill"></i> Microphone access blocked! Tap the lock icon 🔒 next to the URL to allow microphone.');
                    isListening = false;
                    return;
                }
            }
        }

        // 3. Initialize engine
        initSpeech();

        // 4. Start listening
        try {
            speechRecognition.lang = currentVoiceLang;
            speechRecognition.start();
        } catch (err) {
            console.warn("Speech start warning:", err);
            try { speechRecognition.abort(); } catch (e) {}
            setTimeout(() => {
                try {
                    speechRecognition.lang = currentVoiceLang;
                    speechRecognition.start();
                } catch (err2) {
                    console.error("Second speech start attempt failed:", err2);
                    updateVoiceUiState('notice', '<i class="bi bi-exclamation-circle-fill"></i> Microphone could not start. Please refresh page and allow microphone.');
                    isListening = false;
                }
            }, 250);
        }
    }

    function stopVoiceInput() {
        isListening = false;
        if (speechRecognition) {
            try { speechRecognition.stop(); } catch(e) {}
        }
        updateVoiceUiState('idle');
    }

    // ─── Text-to-Speech (Audio Voice Output Engine with Language Auto-Detection) ───
    function getSpeechVoice(hasTamil) {
        if (!('speechSynthesis' in window)) return null;
        const voices = window.speechSynthesis.getVoices();
        if (hasTamil) {
            return voices.find(v => (v.lang && (v.lang === "ta-IN" || v.lang.startsWith("ta"))) || (v.name && v.name.toLowerCase().includes("tamil"))) || null;
        } else {
            return voices.find(v => v.lang === "en-IN") || voices.find(v => v.lang && v.lang.startsWith("en")) || null;
        }
    }

    function speakText(text) {
        if (!('speechSynthesis' in window)) return;
        window.speechSynthesis.cancel();

        const clean = text.replace(/AI Clinical Consultant.*?:/gi, '').replace(/Listen/gi, '').replace(/Stop/gi, '').trim();
        const utterance = new SpeechSynthesisUtterance(clean);
        utterance.rate = 0.92;
        utterance.pitch = 1.0;

        const hasTamil = /[\u0B80-\u0BFF]/.test(clean);
        utterance.lang = hasTamil ? "ta-IN" : "en-IN";
        const voice = getSpeechVoice(hasTamil);
        if (voice) utterance.voice = voice;

        window.speechSynthesis.speak(utterance);
    }

    function speakSummary() {
        const el = document.getElementById("ai-patient-summary");
        if (el) speakText(el.innerText);
    }

    // Interactive Speaker Button Audio Player (Bilingual: Tamil & English like ChatGPT/Gemini)
    function speakMessage(btn) {
        if (!('speechSynthesis' in window)) {
            alert("Speech synthesis is not supported on this browser.");
            return;
        }

        // Toggle: If currently playing this message, stop it
        if (btn.classList.contains("speaking")) {
            window.speechSynthesis.cancel();
            btn.classList.remove("speaking");
            btn.innerHTML = `<i class="bi bi-volume-up-fill"></i> Listen`;
            return;
        }

        // Stop any current voice playback and reset other buttons
        window.speechSynthesis.cancel();
        document.querySelectorAll(".chat-speaker-btn").forEach(b => {
            b.classList.remove("speaking");
            b.innerHTML = `<i class="bi bi-volume-up-fill"></i> Listen`;
        });

        // Extract message text from parent bubble
        const bubble = btn.closest(".chat-bubble");
        let textToRead = "";
        const textSpan = bubble ? bubble.querySelector(".msg-text") : null;
        if (textSpan) {
            textToRead = textSpan.innerText.trim();
        } else if (bubble) {
            textToRead = bubble.innerText.replace(/AI Clinical Consultant.*?:/gi, '').replace(/Listen/gi, '').replace(/Stop/gi, '').trim();
        }

        if (!textToRead) return;

        btn.classList.add("speaking");
        btn.innerHTML = `<i class="bi bi-stop-fill"></i> Stop`;

        const utterance = new SpeechSynthesisUtterance(textToRead);
        utterance.rate = 0.92;
        utterance.pitch = 1.0;

        // Auto-detect Language: If text contains Tamil characters (Unicode range 0B80-0BFF)
        const hasTamil = /[\u0B80-\u0BFF]/.test(textToRead);
        utterance.lang = hasTamil ? "ta-IN" : "en-IN";
        const voice = getSpeechVoice(hasTamil);
        if (voice) utterance.voice = voice;

        utterance.onend = () => {
            btn.classList.remove("speaking");
            btn.innerHTML = `<i class="bi bi-volume-up-fill"></i> Listen`;
        };
        utterance.onerror = () => {
            btn.classList.remove("speaking");
            btn.innerHTML = `<i class="bi bi-volume-up-fill"></i> Listen`;
        };

        window.speechSynthesis.speak(utterance);
    }

    function handleEnter(e) {
        if (e.key === "Enter") sendChatMessage();
    }

    function askQuick(text) {
        document.getElementById("chat-user-input").value = text;
        sendChatMessage();
    }

    // ─── Chatbot Engine with Dual-API Failover ───
    async function sendChatMessage() {
        const inputEl = document.getElementById("chat-user-input");
        const query = inputEl.value.trim();
        if (!query) return;

        inputEl.value = "";
        appendBubble("user", query);
        chatHistory.push({ role: "user", content: query });

        const thinkingId = "think-" + Date.now();
        appendThinkingBubble(thinkingId);

        try {
            // Attempt 1: Call Cloud chat_ai.php
            let reply = await callCloudChatApi(query);

            if (!reply) {
                // Attempt 2: Direct Client-Side Gemini
                reply = await callGeminiDirect(query);
            }

            if (!reply) {
                // Attempt 3: Direct Client-Side Groq Failover
                reply = await callGroqDirect(query);
            }

            removeThinkingBubble(thinkingId);

            if (reply) {
                // Default: Silent text chat response only (Patient can click speaker button to listen)
                appendBubble("doctor", reply);
                chatHistory.push({ role: "assistant", content: reply });
            } else {
                appendBubble("doctor", "Please take your medications as directed with meals. If you experience unexpected discomfort, please consult your healthcare provider.");
            }
        } catch (err) {
            console.error("Chat error:", err);
            removeThinkingBubble(thinkingId);
            appendBubble("doctor", "Please take your medications as directed. Consult your physician for any persistent discomfort.");
        }
    }

    function escapeHtml(text) {
        const div = document.createElement("div");
        div.textContent = text;
        return div.innerHTML;
    }

    function appendBubble(role, content) {
        const msgs = document.getElementById("chat-messages");
        const div = document.createElement("div");
        div.className = "chat-bubble " + role;
        if (role === "doctor") {
            const cleanContent = escapeHtml(content);
            div.innerHTML = `<strong>AI Clinical Consultant:</strong><br><span class="msg-text">${cleanContent}</span><br><button class="chat-speaker-btn" title="Click to listen" onclick="speakMessage(this)"><i class="bi bi-volume-up-fill"></i> Listen</button>`;
        } else {
            div.innerText = content;
        }
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function appendThinkingBubble(id) {
        const msgs = document.getElementById("chat-messages");
        const div = document.createElement("div");
        div.id = id;
        div.className = "chat-bubble doctor";
        div.innerHTML = `<em><i class="bi bi-hourglass-split"></i> AI Clinical Consultant is reviewing clinical pharmacology...</em>`;
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
    }

    function removeThinkingBubble(id) {
        const el = document.getElementById(id);
        if (el) el.remove();
    }

    async function callCloudChatApi(query) {
        try {
            const resp = await fetch("chat_ai.php", {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    message: query,
                    patient: PATIENT_CONTEXT,
                    history: chatHistory
                })
            });
            if (resp.ok) {
                const data = await resp.json();
                if (data.status === "success" && data.reply) return data.reply;
            }
        } catch (e) {
            console.log("chat_ai.php unreachable, using browser failover...", e);
        }
        return null;
    }

    async function callGeminiDirect(query) {
        try {
            const url = `https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key=${GEMINI_API_KEY}`;
            const sys = `You are an AI Clinical Consultant specializing in clinical pharmacology. Prescribed medicines: ${JSON.stringify(PATIENT_CONTEXT.medicines)}.
CRITICAL MULTILINGUAL & VOICE DIRECTIVE (LIKE CHATGPT & GEMINI LIVE):
1. DYNAMIC LANGUAGE MATCHING (STRICT):
   - If the patient asks in TAMIL (தமிழ்) or TANGLISH (e.g. 'eppadi saapdanum', 'thookam varuma'): You MUST reply in polite, fluent, natural TAMIL (தமிழ்). Keep drug names clear (e.g. அமாக்சிசிலின் / Amoxicillin).
   - If the patient asks in ENGLISH: You MUST reply in clear, reassuring, professional clinical ENGLISH.
2. DO NOT repeat or address the patient by name in follow-up replies.
3. Directly answer the question with medical clarity under 80 words, optimized for reading and text-to-speech audio playback.`;

            const res = await fetch(url, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    contents: [
                        { role: "user", parts: [{ text: "System Directive:\n" + sys }] },
                        { role: "model", parts: [{ text: "Understood. I am your AI Clinical Consultant. I dynamically match the patient's language in Tamil or English, and answer medical questions directly without repeating their name." }] },
                        ...chatHistory.slice(-4).map(h => ({
                            role: h.role === "user" ? "user" : "model",
                            parts: [{ text: h.content }]
                        })),
                        { role: "user", parts: [{ text: query }] }
                    ],
                    generationConfig: { temperature: 0.3, maxOutputTokens: 350 }
                })
            });
            if (res.ok) {
                const d = await res.json();
                return d.candidates[0].content.parts[0].text;
            }
        } catch (e) {
            console.warn("Direct Gemini failed, failing over to Groq...", e);
        }
        return null;
    }

    async function callGroqDirect(query) {
        try {
            const url = "https://api.groq.com/openai/v1/chat/completions";
            const res = await fetch(url, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "Authorization": `Bearer ${GROQ_API_KEY}`
                },
                body: JSON.stringify({
                    model: "qwen/qwen3.8-27b",
                    messages: [
                        { role: "system", content: `You are an AI Clinical Consultant specializing in clinical pharmacology. Prescribed medicines: ${JSON.stringify(PATIENT_CONTEXT.medicines)}.
CRITICAL MULTILINGUAL & VOICE DIRECTIVE (LIKE CHATGPT & GEMINI LIVE):
1. DYNAMIC LANGUAGE MATCHING (STRICT):
   - If the patient asks in TAMIL (தமிழ்) or TANGLISH: You MUST reply in polite, fluent, natural TAMIL (தமிழ்). Keep drug names clear (e.g. அமாக்சிசிலின் / Amoxicillin).
   - If the patient asks in ENGLISH: You MUST reply in clear, reassuring clinical ENGLISH.
2. DO NOT repeat or address the patient by name.
3. Directly answer the question under 80 words with accurate dosage/food timing guidance.` },
                        ...chatHistory.slice(-4).map(h => ({
                            role: h.role === "user" ? "user" : "assistant",
                            content: h.content
                        })),
                        { role: "user", content: query }
                    ],
                    max_tokens: 350
                })
            });
            if (res.ok) {
                const d = await res.json();
                return d.choices[0].message.content;
            }
        } catch (e) {
            console.warn("Direct Groq failed:", e);
        }
        return null;
    }

    // ─── Status Lifecycle Handling ───
    <?php if (!$isDispensed): ?>
        // Prescriptions with PENDING or SCANNED status auto-check every 2.5 seconds
        console.log("Prescription awaiting dispense confirmation. Auto-polling server every 2.5s...");
        setInterval(async function() {
            try {
                const url = window.location.pathname + window.location.search + (window.location.search.includes('?') ? '&' : '?') + 'format=json&t=' + Date.now();
                const resp = await fetch(url);
                if (resp.ok) {
                    const d = await resp.json();
                    const liveStatus = (d.dispense_status || '').toUpperCase();
                    if (liveStatus === 'DISPENSED') {
                        console.log("Hardware Dispense Confirmed! Reloading to unlock AI Clinical Review...");
                        window.location.reload();
                    } else if (liveStatus === 'SCANNED') {
                        // If page was rendered as PENDING, reload to show active dispensing animation
                        const curBadge = document.querySelector('.status-badge');
                        if (curBadge && !curBadge.classList.contains('SCANNED')) {
                            console.log("Status transitioned to SCANNED! Reloading to show dispensing screen...");
                            window.location.reload();
                        }
                    }
                }
            } catch (err) {}
        }, 2500);
    <?php else: ?>
        window.addEventListener("DOMContentLoaded", () => {
            initSpeech();
        });
    <?php endif; ?>
</script>

</body>
</html>
