<?php
$data = json_decode(file_get_contents("shared/light.json"), true);
$medicines = $data['medicines'] ?? [];
$qrUrl = $data['qr_image'] ?? 'shared/qr.png';
$mode = $data['mode'] ?? 'manual';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Generated — Smart Prescription</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #6366f1;
        --primary-dark: #4f46e5;
        --accent: #06b6d4;
        --accent-pink: #ec4899;
        --success: #10b981;
        --dark: #0f172a;
        --darker: #020617;
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
    
    /* ═══ ANIMATED BACKGROUND SHAPES ═══ */
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
    
    /* ═══ HEADER ═══ */
    .main-header {
        background: rgba(15, 23, 42, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        padding: 22px 25px;
        box-shadow: 0 4px 30px rgba(0,0,0,0.2);
        position: sticky;
        top: 0;
        z-index: 100;
        border-bottom: 1px solid rgba(255,255,255,0.05);
    }
    .main-header::after {
        content: '';
        position: absolute;
        bottom: -2px; left: 0; right: 0;
        height: 2px;
        background: linear-gradient(90deg, transparent, #6366f1, #06b6d4, #ec4899, transparent);
        background-size: 200% 100%;
        animation: gradientSlide 4s linear infinite;
    }
    @keyframes gradientSlide {
        0% { background-position: 0% 50%; }
        100% { background-position: 200% 50%; }
    }
    .header-inner {
        max-width: 1250px;
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .brand {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 18px;
        color: #fff;
        text-decoration: none;
    }
    .brand-icon {
        width: 56px;
        height: 56px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        border-radius: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.7rem;
        color: #fff;
        box-shadow: 0 8px 24px rgba(99,102,241,0.5);
        position: relative;
        overflow: hidden;
        flex-shrink: 0;
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
        flex-direction: column;
        align-items: flex-start;
        line-height: 1.2;
    }
    .brand-title {
        font-weight: 800;
        font-size: 1.6rem;
        background: linear-gradient(135deg, #ffffff 0%, #a5b4fc 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        letter-spacing: -0.5px;
    }
    .brand-sub {
        font-size: 0.7rem;
        color: #94a3b8;
        font-weight: 600;
        letter-spacing: 2.5px;
        margin-top: 2px;
    }
    
    /* ═══ MAIN ═══ */
    .main-wrap {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 60px 20px;
        position: relative;
        z-index: 1;
    }
    .card-box {
        max-width: 520px;
        width: 100%;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-radius: 28px;
        padding: 50px 42px;
        text-align: center;
        box-shadow: 0 20px 60px rgba(15,23,42,0.1), 0 1px 3px rgba(15,23,42,0.05);
        border: 1px solid rgba(255,255,255,0.8);
        position: relative;
        overflow: hidden;
        min-height: 560px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
    }
    .card-box::before {
        content: '';
        position: absolute;
        top: 0; left: 0; right: 0;
        height: 5px;
        background: linear-gradient(90deg, #10b981, #06b6d4, #6366f1, #8b5cf6, #ec4899);
        background-size: 200% 100%;
        animation: gradientSlide 6s linear infinite;
    }
    
    /* ═══ LOADING STATE ═══ */
    .loading-state {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 100%;
        animation: fadeIn 0.4s ease;
    }
    .loading-state.hide {
        display: none;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    /* Spinner */
    .spinner-box {
        position: relative;
        width: 130px;
        height: 130px;
        margin-bottom: 30px;
    }
    .spinner-ring {
        position: absolute;
        inset: 0;
        border-radius: 50%;
        border: 4px solid transparent;
    }
    .spinner-ring:nth-child(1) {
        border-top-color: #6366f1;
        border-right-color: #8b5cf6;
        animation: spin 1s linear infinite;
    }
    .spinner-ring:nth-child(2) {
        inset: 14px;
        border-top-color: #06b6d4;
        border-right-color: #10b981;
        animation: spin 1.5s linear infinite reverse;
    }
    .spinner-ring:nth-child(3) {
        inset: 28px;
        border-top-color: #ec4899;
        border-right-color: #f43f5e;
        animation: spin 2s linear infinite;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .spinner-icon {
        position: absolute;
        inset: 42px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 2rem;
        background: linear-gradient(135deg, #6366f1, #ec4899);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        animation: pulse 1.5s ease-in-out infinite;
    }
    @keyframes pulse {
        0%, 100% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.2); opacity: 0.7; }
    }
    
    .loading-title {
        font-weight: 800;
        font-size: 1.5rem;
        color: var(--dark);
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }
    .loading-subtitle {
        color: var(--gray);
        font-size: 0.9rem;
        margin-bottom: 28px;
        font-weight: 500;
    }
    
    /* Progress Bar */
    .progress-wrapper {
        width: 260px;
        height: 6px;
        background: #e2e8f0;
        border-radius: 10px;
        overflow: hidden;
        margin-bottom: 14px;
    }
    .progress-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #6366f1, #8b5cf6, #ec4899);
        background-size: 200% 100%;
        border-radius: 10px;
        width: 0;
        animation: progressAnim 3s ease-in-out forwards, gradientSlide 2s linear infinite;
    }
    @keyframes progressAnim {
        0% { width: 0%; }
        25% { width: 25%; }
        50% { width: 55%; }
        75% { width: 80%; }
        100% { width: 100%; }
    }
    
    .progress-text {
        font-size: 0.78rem;
        color: var(--gray);
        font-weight: 600;
        letter-spacing: 0.5px;
        margin-bottom: 25px;
    }
    
    /* Loading Steps */
    .loading-steps {
        display: flex;
        flex-direction: column;
        gap: 10px;
        width: 260px;
        text-align: left;
    }
    .step-item {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.8rem;
        color: #94a3b8;
        font-weight: 500;
        opacity: 0.4;
        transition: all 0.3s;
    }
    .step-item.active {
        color: #6366f1;
        opacity: 1;
        font-weight: 700;
    }
    .step-item.done {
        color: #10b981;
        opacity: 1;
        font-weight: 600;
    }
    .step-item i {
        font-size: 1rem;
        width: 16px;
        text-align: center;
    }
    
    /* ═══ SUCCESS STATE ═══ */
    .success-state {
        display: none;
        flex-direction: column;
        align-items: center;
        width: 100%;
    }
    .success-state.show {
        display: flex;
        animation: successFadeIn 0.6s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes successFadeIn {
        from { opacity: 0; transform: translateY(20px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    
    /* Success Badge */
    .success-badge {
        width: 90px;
        height: 90px;
        border-radius: 50%;
        background: linear-gradient(135deg, #10b981 0%, #06b6d4 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
        font-size: 2.8rem;
        color: #fff;
        box-shadow: 0 15px 40px rgba(16,185,129,0.4), 0 0 0 8px rgba(16,185,129,0.1);
        animation: pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) 0.2s both;
        position: relative;
    }
    .success-badge::after {
        content: '';
        position: absolute;
        inset: -8px;
        border-radius: 50%;
        background: linear-gradient(135deg, #10b981, #06b6d4);
        opacity: 0.3;
        filter: blur(20px);
        z-index: -1;
        animation: pulse 2s infinite;
    }
    @keyframes pop {
        0% { transform: scale(0.3); opacity: 0; }
        70% { transform: scale(1.15); }
        100% { transform: scale(1); opacity: 1; }
    }
    
    /* Typography */
    h3 {
        font-weight: 800;
        font-size: 1.6rem;
        margin-bottom: 10px;
        letter-spacing: -0.5px;
        background: linear-gradient(135deg, #0f172a 0%, #6366f1 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    .subtitle {
        color: #64748b;
        font-size: 0.92rem;
        margin-bottom: 32px;
        font-weight: 500;
    }
    
    /* QR Wrapper */
    .qr-wrapper {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        border: 2.5px dashed #c7d2fe;
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 26px;
        display: inline-block;
        transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        animation: qrReveal 0.7s cubic-bezier(0.4, 0, 0.2, 1) 0.3s both;
    }
    @keyframes qrReveal {
        from { opacity: 0; transform: scale(0.85) rotate(-3deg); }
        to { opacity: 1; transform: scale(1) rotate(0deg); }
    }
    .qr-wrapper::before {
        content: '';
        position: absolute;
        inset: 0;
        border-radius: 24px;
        background: linear-gradient(135deg, #6366f1, #06b6d4);
        opacity: 0;
        transition: opacity 0.35s;
        z-index: -1;
        filter: blur(15px);
    }
    .qr-wrapper:hover {
        border-color: #6366f1;
        background: linear-gradient(135deg, #eef2ff, #ecfeff);
        transform: translateY(-4px) scale(1.02);
    }
    .qr-wrapper:hover::before { opacity: 0.4; }
    .qr-img {
        border-radius: 14px;
        display: block;
    }
    
    /* Button */
    .btn-home {
        display: inline-flex;
        align-items: center;
        gap: 10px;
        padding: 15px 42px;
        background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #ec4899 100%);
        background-size: 200% 100%;
        color: #fff;
        border-radius: 14px;
        font-weight: 700;
        text-decoration: none;
        transition: all 0.4s;
        border: none;
        box-shadow: 0 10px 30px rgba(99,102,241,0.4);
        font-size: 0.98rem;
        letter-spacing: 0.3px;
    }
    .btn-home:hover {
        transform: translateY(-3px);
        box-shadow: 0 16px 40px rgba(99,102,241,0.5);
        color: #fff;
        background-position: 100% 0;
    }
    .btn-home i {
        transition: transform 0.3s;
    }
    .btn-home:hover i {
        transform: translateX(-4px);
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
    
    @media (max-width: 768px) {
        .card-box { padding: 35px 25px; min-height: 500px; }
        h3 { font-size: 1.3rem; }
        .loading-title { font-size: 1.25rem; }
        .spinner-box { width: 105px; height: 105px; }
        .spinner-icon { inset: 32px; font-size: 1.6rem; }
        .progress-wrapper, .loading-steps { width: 220px; }
        .brand-title { font-size: 1.15rem; }
        .brand-sub { font-size: 0.6rem; letter-spacing: 1.8px; }
        .brand-icon { width: 44px; height: 44px; font-size: 1.35rem; }
        .brand { gap: 12px; }
        .bg-shape { opacity: 0.3; }
        .main-header { padding: 18px 15px; }
    }
</style>
</head>
<body>

<!-- Animated Background Shapes -->
<div class="bg-shape bg-shape-1"></div>
<div class="bg-shape bg-shape-2"></div>
<div class="bg-shape bg-shape-3"></div>

<!-- ═══ HEADER ═══ -->
<header class="main-header">
    <div class="header-inner">
        <a href="index.php" class="brand">
            <div class="brand-icon">
                <i class="bi bi-prescription2"></i>
            </div>
            <div class="brand-text">
                <span class="brand-title">Smart Prescription</span>
                <span class="brand-sub">PROCESSING & QR GENERATION</span>
            </div>
        </a>
    </div>
</header>

<!-- ═══ MAIN ═══ -->
<div class="main-wrap">
    <div class="card-box">

        <!-- ═══ LOADING STATE ═══ -->
        <div class="loading-state" id="loadingState">
            <div class="spinner-box">
                <div class="spinner-ring"></div>
                <div class="spinner-ring"></div>
                <div class="spinner-ring"></div>
                <div class="spinner-icon">
                    <i class="bi bi-qr-code"></i>
                </div>
            </div>

            <h3 class="loading-title">Generating QR Code</h3>
            <p class="loading-subtitle">Please wait while we process your prescription</p>

            <div class="progress-wrapper">
                <div class="progress-bar-fill"></div>
            </div>
            <div class="progress-text" id="progressText">Initializing...</div>

            <div class="loading-steps">
                <div class="step-item" id="step1">
                    <i class="bi bi-circle"></i>
                    <span>Reading prescription</span>
                </div>
                <div class="step-item" id="step2">
                    <i class="bi bi-circle"></i>
                    <span>Encoding medicines</span>
                </div>
                <div class="step-item" id="step3">
                    <i class="bi bi-circle"></i>
                    <span>Generating QR code</span>
                </div>
                <div class="step-item" id="step4">
                    <i class="bi bi-circle"></i>
                    <span>Saving data</span>
                </div>
            </div>
        </div>

        <!-- ═══ SUCCESS STATE ═══ -->
        <div class="success-state" id="successState">
            <div class="success-badge">
                <i class="bi bi-check-lg"></i>
            </div>

            <h3>QR Generated Successfully!</h3>
            <p class="subtitle">Ready for processing</p>

            <div class="qr-wrapper">
                <img src="shared/qr.png?t=<?= time() ?>" class="qr-img" width="230" height="230" alt="QR">
            </div>

            <div>
                <a href="index.php" class="btn-home">
                    <i class="bi bi-arrow-left"></i> Back to Home
                </a>
            </div>
        </div>

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
    document.addEventListener('DOMContentLoaded', function() {
        
        const loadingState = document.getElementById('loadingState');
        const successState = document.getElementById('successState');
        const progressText = document.getElementById('progressText');
        
        // Steps sequence
        const steps = [
            { id: 'step1', text: 'Reading prescription...', delay: 700, time: 0 },
            { id: 'step2', text: 'Encoding medicines...', delay: 700, time: 700 },
            { id: 'step3', text: 'Generating QR code...', delay: 700, time: 1400 },
            { id: 'step4', text: 'Saving data...', delay: 700, time: 2100 }
        ];
        
        // Animate steps
        steps.forEach((step, index) => {
            setTimeout(() => {
                // Mark previous as done
                if (index > 0) {
                    const prevStep = document.getElementById(steps[index - 1].id);
                    prevStep.classList.remove('active');
                    prevStep.classList.add('done');
                    prevStep.querySelector('i').className = 'bi bi-check-circle-fill';
                }
                
                // Mark current as active
                const currentStep = document.getElementById(step.id);
                currentStep.classList.add('active');
                currentStep.querySelector('i').className = 'bi bi-arrow-right-circle-fill';
                
                // Update progress text
                progressText.textContent = step.text;
                
            }, step.time);
        });
        
        // Show success state after loading
        setTimeout(() => {
            // Mark last step as done
            const lastStep = document.getElementById('step4');
            lastStep.classList.remove('active');
            lastStep.classList.add('done');
            lastStep.querySelector('i').className = 'bi bi-check-circle-fill';
            progressText.textContent = 'Complete!';
            
            // Fade out loading, show success
            setTimeout(() => {
                loadingState.classList.add('hide');
                successState.classList.add('show');
            }, 400);
            
        }, 3000);
    });
</script>

</body>
</html>