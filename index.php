<?php
// ============================================================
//  INDEX.PHP - LOGIN PAGE WITH REGISTER OPTION
//  Users can login or create an account on the same page
//  PWA Enabled - Full Progressive Web App
//  WITH PETAL INTRO CARD (floating from top)
// ============================================================

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

// ============================================================
//  DATABASE CONNECTION
// ============================================================
require_once '/home2/firstsun/tesdbaccess/swazilift.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$error = '';
$success = '';
$show_register = isset($_GET['register']) ? true : false;

// ============================================================
//  HANDLE LOGIN
// ============================================================
if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Email and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT id, fullname, email, password FROM petal_users WHERE email = ? AND is_active = 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($user = $result->fetch_assoc()) {
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['fullname'] = $user['fullname'];
                $_SESSION['email'] = $user['email'];
                
                $updateStmt = $conn->prepare("UPDATE petal_users SET last_login = NOW() WHERE id = ?");
                $updateStmt->bind_param("i", $user['id']);
                $updateStmt->execute();
                
                header('Location: dashboard.php');
                exit();
            } else {
                $error = "Invalid password.";
            }
        } else {
            $error = "Account not found. Please create an account.";
        }
    }
}

// ============================================================
//  HANDLE REGISTRATION
// ============================================================
if (isset($_POST['register'])) {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    
    $errors = [];
    
    if (empty($fullname) || strlen($fullname) < 2) {
        $errors[] = "Full name must be at least 2 characters.";
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }
    if (strlen($password) < 4) {
        $errors[] = "Password must be at least 4 characters.";
    }
    if ($password !== $confirm) {
        $errors[] = "Passwords do not match.";
    }
    
    if (empty($errors)) {
        $checkStmt = $conn->prepare("SELECT id FROM petal_users WHERE email = ?");
        $checkStmt->bind_param("s", $email);
        $checkStmt->execute();
        if ($checkStmt->get_result()->num_rows > 0) {
            $errors[] = "Email already registered. Please login.";
        }
    }
    
    if (empty($errors)) {
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $insertStmt = $conn->prepare("INSERT INTO petal_users (fullname, email, password) VALUES (?, ?, ?)");
        $insertStmt->bind_param("sss", $fullname, $email, $hashedPassword);
        
        if ($insertStmt->execute()) {
            $success = "Account created successfully! Please login.";
            $show_register = false;
            $_POST = [];
        } else {
            $errors[] = "Registration failed. Please try again.";
        }
    }
    
    if (!empty($errors)) {
        $error = implode("<br>", $errors);
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes" />
    <title>🌸 Petal - Login / Register</title>
    
    <!-- PWA META TAGS -->
    <link rel="manifest" href="manifest.json" />
    <meta name="theme-color" content="#e91e63" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Petal" />
    <meta name="application-name" content="Petal" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="msapplication-TileColor" content="#e91e63" />
    <meta name="msapplication-tap-highlight" content="no" />
    
    <link rel="icon" href="icons/icon-192.svg" sizes="192x192" />
    <link rel="apple-touch-icon" href="icons/icon-192.svg" />
    <link rel="apple-touch-icon-precomposed" href="icons/icon-192.svg" />
    <link rel="shortcut icon" href="icons/icon-192.svg" />
    
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Quicksand', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #fff5f9 0%, #fce4ec 50%, #f3e5f5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            margin: 0;
            overscroll-behavior: none;
            position: relative;
            overflow-x: hidden;
        }

        /* ============================================================
           PETAL INTRO CARD - FLOATING FROM TOP
        ============================================================ */
        .petal-intro-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            pointer-events: none;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }
        .petal-intro-card {
            max-width: 440px;
            width: 100%;
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 32px;
            padding: 28px 24px 24px;
            box-shadow: 0 20px 60px rgba(233, 30, 99, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.5);
            pointer-events: auto;
            transform: translateY(-120%) scale(0.8);
            opacity: 0;
            animation: petalFall 1.2s cubic-bezier(0.34, 1.56, 0.64, 1) 0.6s forwards;
        }
        @keyframes petalFall {
            0% {
                transform: translateY(-120%) scale(0.6);
                opacity: 0;
            }
            50% {
                transform: translateY(8px) scale(1.02);
                opacity: 1;
            }
            70% {
                transform: translateY(-4px) scale(0.99);
            }
            100% {
                transform: translateY(0) scale(1);
                opacity: 1;
            }
        }

        .petal-intro-card .close-intro {
            position: absolute;
            top: 12px;
            right: 16px;
            background: none;
            border: none;
            font-size: 1.4rem;
            color: #ad1457;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 50%;
            transition: all 0.2s;
            -webkit-tap-highlight-color: transparent;
        }
        .petal-intro-card .close-intro:hover {
            background: #fce4ec;
        }
        .petal-intro-card .intro-icon {
            text-align: center;
            font-size: 3.2rem;
            margin-bottom: 6px;
            animation: floatPetal 3s ease-in-out infinite;
        }
        @keyframes floatPetal {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        .petal-intro-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #880e4f;
            text-align: center;
            margin-bottom: 4px;
        }
        .petal-intro-card .intro-sub {
            text-align: center;
            color: #ad1457;
            font-size: 0.85rem;
            font-weight: 500;
            margin-bottom: 14px;
        }
        .petal-intro-card .intro-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 14px;
        }
        .petal-intro-card .intro-item {
            background: rgba(248, 187, 208, 0.15);
            border-radius: 16px;
            padding: 12px 10px;
            text-align: center;
        }
        .petal-intro-card .intro-item .emoji {
            font-size: 1.6rem;
            display: block;
        }
        .petal-intro-card .intro-item .label {
            font-size: 0.7rem;
            font-weight: 600;
            color: #4a1a2c;
            margin-top: 2px;
        }
        .petal-intro-card .intro-item .desc {
            font-size: 0.65rem;
            color: #6a4a5a;
            margin-top: 1px;
        }
        .petal-intro-card .intro-footer {
            background: linear-gradient(135deg, #fce4ec, #f3e5f5);
            border-radius: 16px;
            padding: 12px 16px;
            text-align: center;
            font-size: 0.8rem;
            color: #4a1a2c;
            font-weight: 500;
            border-left: 4px solid #e91e63;
        }
        .petal-intro-card .intro-footer .highlight {
            color: #e91e63;
            font-weight: 700;
        }
        .petal-intro-card .intro-cta {
            margin-top: 14px;
            display: flex;
            gap: 10px;
        }
        .petal-intro-card .intro-cta .btn-gotit {
            flex: 1;
            padding: 12px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }
        .petal-intro-card .intro-cta .btn-gotit:hover {
            transform: scale(1.02);
            box-shadow: 0 8px 24px rgba(233, 30, 99, 0.3);
        }
        .petal-intro-card .intro-cta .btn-skip {
            padding: 12px 20px;
            background: none;
            border: 2px solid #e91e63;
            color: #e91e63;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            -webkit-tap-highlight-color: transparent;
        }
        .petal-intro-card .intro-cta .btn-skip:hover {
            background: #e91e63;
            color: white;
        }

        /* ============================================================
           AUTH CONTAINER (UNCHANGED)
        ============================================================ */
        .auth-container {
            max-width: 440px;
            width: 100%;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 48px;
            padding: 40px 36px;
            box-shadow: 0 30px 80px rgba(233, 30, 99, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.5);
            animation: slideUp 0.5s ease;
            position: relative;
            z-index: 1;
        }
        @keyframes slideUp {
            from { transform: translateY(40px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }

        .auth-container .doll-icon {
            text-align: center;
            font-size: 3.5rem;
            margin-bottom: 4px;
        }
        .auth-container h1 {
            font-size: 2.2rem;
            text-align: center;
            font-weight: 700;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }
        .auth-container .subtitle {
            text-align: center;
            color: #ad1457;
            font-size: 0.9rem;
            margin-bottom: 24px;
            font-weight: 500;
        }
        .auth-container .form-group {
            margin-bottom: 16px;
        }
        .auth-container .form-group label {
            display: block;
            font-weight: 600;
            color: #4a1a2c;
            font-size: 0.9rem;
            margin-bottom: 4px;
        }
        .auth-container .form-group input {
            width: 100%;
            padding: 14px 18px;
            border-radius: 16px;
            border: 2px solid #f8bbd0;
            font-family: inherit;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.6);
            transition: all 0.3s;
            color: #1a1a2e;
            -webkit-appearance: none;
            appearance: none;
        }
        .auth-container .form-group input:focus {
            outline: none;
            border-color: #e91e63;
            box-shadow: 0 0 0 4px rgba(233, 30, 99, 0.08);
        }
        .auth-container .form-group input::placeholder {
            color: #ad1457;
            opacity: 0.5;
        }
        .auth-container .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1.05rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            margin-top: 4px;
            -webkit-tap-highlight-color: transparent;
        }
        .auth-container .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(233, 30, 99, 0.3);
        }
        .auth-container .btn-primary:active {
            transform: scale(0.98);
        }
        .auth-container .btn-secondary {
            width: 100%;
            padding: 14px;
            background: none;
            color: #e91e63;
            border: 2px solid #e91e63;
            border-radius: 60px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            margin-top: 8px;
            -webkit-tap-highlight-color: transparent;
        }
        .auth-container .btn-secondary:hover {
            background: #e91e63;
            color: white;
        }
        .auth-container .link-text {
            text-align: center;
            margin-top: 16px;
            font-size: 0.9rem;
            color: #4a1a2c;
        }
        .auth-container .link-text a {
            color: #e91e63;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
        }
        .auth-container .link-text a:hover {
            text-decoration: underline;
        }
        .auth-container .error {
            background: #ffebee;
            color: #c62828;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 14px;
            border-left: 4px solid #c62828;
        }
        .auth-container .success {
            background: #e8f5e9;
            color: #2e7d32;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            margin-bottom: 14px;
            border-left: 4px solid #2e7d32;
        }
        .auth-container .register-fields {
            display: <?= $show_register ? 'block' : 'none' ?>;
        }
        .auth-container .login-fields {
            display: <?= $show_register ? 'none' : 'block' ?>;
        }

        /* ============================================================
           PWA INSTALL BANNER (UNCHANGED)
        ============================================================ */
        .pwa-install-banner {
            position: fixed;
            bottom: 20px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            padding: 16px 24px;
            border-radius: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            display: none;
            align-items: center;
            gap: 16px;
            z-index: 10000;
            max-width: 90%;
            border: 1px solid rgba(233, 30, 99, 0.2);
            animation: slideUp 0.5s ease;
        }
        .pwa-install-banner.show {
            display: flex;
        }
        .pwa-install-banner .icon {
            font-size: 2rem;
        }
        .pwa-install-banner .text {
            flex: 1;
            font-size: 0.9rem;
            color: #4a1a2c;
        }
        .pwa-install-banner .text strong {
            color: #e91e63;
        }
        .pwa-install-banner .install-btn {
            padding: 8px 20px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            white-space: nowrap;
            -webkit-tap-highlight-color: transparent;
        }
        .pwa-install-banner .install-btn:hover {
            transform: scale(1.05);
        }
        .pwa-install-banner .close-btn {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: #999;
            padding: 4px;
            -webkit-tap-highlight-color: transparent;
        }

        @media (max-width: 480px) {
            .auth-container { padding: 28px 20px; }
            .auth-container h1 { font-size: 1.8rem; }
            .pwa-install-banner { padding: 12px 16px; bottom: 12px; }
            .pwa-install-banner .text { font-size: 0.8rem; }
            .petal-intro-card { padding: 20px 16px; }
            .petal-intro-card .intro-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .petal-intro-card h2 { font-size: 1.2rem; }
            .petal-intro-card .intro-cta { flex-direction: column; }
            .petal-intro-card .intro-cta .btn-skip { text-align: center; }
        }
        @media (max-width: 380px) {
            .petal-intro-card .intro-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- ============================================================
    PETAL INTRO CARD - FLOATING FROM TOP
    ============================================================ -->
    <div class="petal-intro-overlay" id="petalIntroOverlay">
        <div class="petal-intro-card" id="petalIntroCard">
            <button class="close-intro" id="closeIntroBtn" aria-label="Close intro">✕</button>
            
            <div class="intro-icon">🌸</div>
            <h2>Welcome to Petal 🌸</h2>
            <p class="intro-sub">Your daily companion for growth and self-love</p>
            
            <div class="intro-grid">
                <div class="intro-item">
                    <span class="emoji">✨</span>
                    <div class="label">Track Good Things</div>
                    <div class="desc">Celebrate every win</div>
                </div>
                <div class="intro-item">
                    <span class="emoji">🌱</span>
                    <div class="label">Learn from Challenges</div>
                    <div class="desc">Grow through struggles</div>
                </div>
                <div class="intro-item">
                    <span class="emoji">💕</span>
                    <div class="label">Appreciation</div>
                    <div class="desc">You are enough</div>
                </div>
                <div class="intro-item">
                    <span class="emoji">📈</span>
                    <div class="label">Track Progress</div>
                    <div class="desc">Aim for 100% good</div>
                </div>
            </div>
            
            <div class="intro-footer">
                🌟 Petal helps you track what's <span class="highlight">good</span> and what you want to <span class="highlight">improve</span> — 
                giving you appreciation, motivation, and pushing you to do better every day. 
                <span class="highlight">💪 You can do this!</span>
            </div>
            
            <div class="intro-cta">
                <button class="btn-gotit" id="gotItBtn">🌸 Got It, Let's Go!</button>
                <button class="btn-skip" id="skipIntroBtn">Skip</button>
            </div>
        </div>
    </div>

    <!-- ============================================================
    AUTH CONTAINER (UNCHANGED)
    ============================================================ -->
    <div class="auth-container">
        <div class="doll-icon">🌸</div>
        <h1 id="formTitle"><?= $show_register ? 'Create Account' : 'Welcome Back' ?></h1>
        <p class="subtitle" id="formSubtitle"><?= $show_register ? 'Start your journey with Petal' : 'Login to continue your journey' ?></p>

        <?php if ($error): ?>
            <div class="error"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success"><?= $success ?></div>
        <?php endif; ?>

        <!-- LOGIN FORM -->
        <div class="login-fields">
            <form method="POST" action="index.php">
                <div class="form-group">
                    <label for="loginEmail">Email Address</label>
                    <input type="email" id="loginEmail" name="email" placeholder="Enter your email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
                </div>
                <div class="form-group">
                    <label for="loginPassword">Password</label>
                    <input type="password" id="loginPassword" name="password" placeholder="Enter your password" required />
                </div>
                <button type="submit" name="login" class="btn-primary">Login</button>
            </form>
            <div class="link-text">
                Don't have an account? <a onclick="showRegister()">Create one here</a>
            </div>
        </div>

        <!-- REGISTER FORM -->
        <div class="register-fields">
            <form method="POST" action="index.php?register=1">
                <div class="form-group">
                    <label for="regFullname">Full Name</label>
                    <input type="text" id="regFullname" name="fullname" placeholder="Enter your full name" required value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" />
                </div>
                <div class="form-group">
                    <label for="regEmail">Email Address</label>
                    <input type="email" id="regEmail" name="email" placeholder="Enter your email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
                </div>
                <div class="form-group">
                    <label for="regPassword">Password</label>
                    <input type="password" id="regPassword" name="password" placeholder="Minimum 4 characters" required />
                </div>
                <div class="form-group">
                    <label for="regConfirm">Confirm Password</label>
                    <input type="password" id="regConfirm" name="confirm" placeholder="Re-enter your password" required />
                </div>
                <button type="submit" name="register" class="btn-primary">Create Account</button>
            </form>
            <div class="link-text">
                Already have an account? <a onclick="showLogin()">Login here</a>
            </div>
        </div>
    </div>

    <!-- ============================================================
    PWA INSTALL BANNER (UNCHANGED)
    ============================================================ -->
    <div class="pwa-install-banner" id="pwaInstallBanner">
        <span class="icon">🌸</span>
        <div class="text">
            <strong>Install Petal</strong><br>
            Add to your home screen for the best experience
        </div>
        <button class="install-btn" id="pwaInstallBtn">Install</button>
        <button class="close-btn" id="pwaInstallClose">✕</button>
    </div>

    <script>
        // ============================================================
        //  PETAL INTRO CARD
        // ============================================================
        (function() {
            const overlay = document.getElementById('petalIntroOverlay');
            const card = document.getElementById('petalIntroCard');
            const gotItBtn = document.getElementById('gotItBtn');
            const skipBtn = document.getElementById('skipIntroBtn');
            const closeBtn = document.getElementById('closeIntroBtn');

            function dismissIntro() {
                // Smooth dismiss
                card.style.transition = 'transform 0.4s ease, opacity 0.4s ease';
                card.style.transform = 'translateY(-80%) scale(0.6)';
                card.style.opacity = '0';
                setTimeout(function() {
                    overlay.style.display = 'none';
                }, 450);
                // Remember that user saw it
                try {
                    localStorage.setItem('petal_intro_seen', 'true');
                } catch(e) {}
            }

            // Check if intro was already seen
            try {
                if (localStorage.getItem('petal_intro_seen') === 'true') {
                    overlay.style.display = 'none';
                    return;
                }
            } catch(e) {}

            // Event listeners
            gotItBtn.addEventListener('click', dismissIntro);
            skipBtn.addEventListener('click', dismissIntro);
            closeBtn.addEventListener('click', dismissIntro);

            // Also dismiss on click outside (but not on the card itself)
            overlay.addEventListener('click', function(e) {
                if (e.target === overlay) {
                    dismissIntro();
                }
            });

            // Ensure the card stays visible if user interacts with it
            card.addEventListener('click', function(e) {
                e.stopPropagation();
            });

            // If user taps outside the card, it dismisses
            // This is handled above
        })();

        // ============================================================
        //  TOGGLE LOGIN / REGISTER (UNCHANGED)
        // ============================================================
        function showRegister() {
            document.querySelector('.login-fields').style.display = 'none';
            document.querySelector('.register-fields').style.display = 'block';
            document.getElementById('formTitle').textContent = 'Create Account';
            document.getElementById('formSubtitle').textContent = 'Start your journey with Petal';
            window.location.hash = 'register';
        }

        function showLogin() {
            document.querySelector('.login-fields').style.display = 'block';
            document.querySelector('.register-fields').style.display = 'none';
            document.getElementById('formTitle').textContent = 'Welcome Back';
            document.getElementById('formSubtitle').textContent = 'Login to continue your journey';
            window.location.hash = 'login';
        }

        if (window.location.hash === '#register') {
            showRegister();
        }

        // ============================================================
        //  PWA INSTALL PROMPT (UNCHANGED)
        // ============================================================
        let deferredPrompt = null;
        const installBanner = document.getElementById('pwaInstallBanner');
        const installBtn = document.getElementById('pwaInstallBtn');
        const installClose = document.getElementById('pwaInstallClose');

        function isPWAInstalled() {
            return window.matchMedia('(display-mode: standalone)').matches || 
                   window.navigator.standalone === true;
        }

        function showInstallBanner() {
            if (!isPWAInstalled()) {
                const dismissed = localStorage.getItem('petal_install_dismissed');
                if (!dismissed || Date.now() - parseInt(dismissed) > 7 * 24 * 60 * 60 * 1000) {
                    setTimeout(function() {
                        installBanner.classList.add('show');
                    }, 3000);
                }
            }
        }

        window.addEventListener('beforeinstallprompt', function(event) {
            event.preventDefault();
            deferredPrompt = event;
            if (!isPWAInstalled()) {
                installBanner.classList.add('show');
            }
        });

        installBtn.addEventListener('click', function() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choiceResult) {
                    if (choiceResult.outcome === 'accepted') {
                        console.log('✅ User accepted the install prompt');
                        installBanner.classList.remove('show');
                        localStorage.setItem('petal_installed', 'true');
                    } else {
                        console.log('❌ User dismissed the install prompt');
                    }
                    deferredPrompt = null;
                });
            } else {
                alert('🌸 To install Petal:\n\n1. Tap the Share button\n2. Scroll down and tap "Add to Home Screen"\n3. Tap "Add"');
                installBanner.classList.remove('show');
            }
        });

        installClose.addEventListener('click', function() {
            installBanner.classList.remove('show');
            localStorage.setItem('petal_install_dismissed', Date.now().toString());
        });

        document.addEventListener('DOMContentLoaded', function() {
            if (isPWAInstalled()) {
                installBanner.style.display = 'none';
            } else {
                showInstallBanner();
            }
            
            if ('serviceWorker' in navigator) {
                navigator.serviceWorker.register('sw.js')
                    .then(function(registration) {
                        console.log('📦 Service Worker registered successfully!');
                        registration.addEventListener('updatefound', function() {
                            const newWorker = registration.installing;
                            newWorker.addEventListener('statechange', function() {
                                if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                                    console.log('🔄 New version available! Reload to update.');
                                }
                            });
                        });
                    })
                    .catch(function(error) {
                        console.log('❌ Service Worker registration failed:', error);
                    });
            }
        });
    </script>

</body>
</html>