<?php
// ============================================================
//  DASHBOARD.PHP - MAIN DASHBOARD WITH USER AUTH
//  Includes Promise Handler (No Email)
// ============================================================

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];
$email = $_SESSION['email'];
$today = date('Y-m-d');

// ============================================================
//  DATABASE CONNECTION - Using your swazilift.php
// ============================================================
require_once '/home2/firstsun/tesdbaccess/swazilift.php';

// Create connection using variables from swazilift.php
$conn = new mysqli($servername, $username, $password, $dbname);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ============================================================
//  HANDLE LOGOUT
// ============================================================
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit();
}

// ============================================================
//  HANDLE PROMISE COMPLETION - Mark promise as done
// ============================================================
if (isset($_POST['complete_promise'])) {
    $promise_id = isset($_POST['promise_id']) ? intval($_POST['promise_id']) : 0;
    $promise_text = isset($_POST['promise_text']) ? trim($_POST['promise_text']) : '';
    
    if ($promise_id > 0 && !empty($promise_text)) {
        // Update promise as completed
        $updateStmt = $conn->prepare("UPDATE petal_promises SET is_completed = 1, completed_at = NOW() WHERE id = ? AND user_id = ?");
        $updateStmt->bind_param("ii", $promise_id, $user_id);
        
        if ($updateStmt->execute()) {
            // Store the completed promise text in session for results page
            $_SESSION['completed_promise'] = $promise_text;
            
            // Redirect to results.php for thank you message
            header('Location: results.php?promise_completed=1');
            exit();
        }
    }
}

// ============================================================
//  HANDLE PROMISE SUBMISSION - Check if already exists today
// ============================================================
$promise_message = '';
$promise_message_type = '';

if (isset($_POST['submit_promise'])) {
    $promise_text = trim($_POST['promise_text'] ?? '');
    
    if (empty($promise_text)) {
        $promise_message = "Please enter a promise.";
        $promise_message_type = 'error';
    } else {
        // Check if user already has a promise today that is not completed
        $checkStmt = $conn->prepare("SELECT id FROM petal_promises WHERE user_id = ? AND DATE(created_at) = ? AND is_completed = 0");
        $checkStmt->bind_param("is", $user_id, $today);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        
        if ($existing) {
            $promise_message = "You already have an active promise today. Complete it first before making a new one.";
            $promise_message_type = 'warning';
        } else {
            // Analyze promise (check if it's good or bad)
            $bad_keywords = ['hurt', 'kill', 'destroy', 'hate', 'revenge', 'steal', 'cheat', 'lie', 'break', 'ruin', 'harm', 'fight', 'curse', 'blame', 'guilt', 'bad', 'wrong', 'evil', 'sin', 'crime'];
            $good_keywords = ['help', 'love', 'kind', 'care', 'support', 'share', 'give', 'honest', 'respect', 'patient', 'forgive', 'understand', 'listen', 'appreciate', 'grateful', 'good', 'nice', 'great', 'wonderful'];
            
            $text_lower = strtolower($promise_text);
            $bad_count = 0;
            $good_count = 0;
            
            foreach ($bad_keywords as $kw) {
                if (strpos($text_lower, $kw) !== false) $bad_count++;
            }
            
            foreach ($good_keywords as $kw) {
                if (strpos($text_lower, $kw) !== false) $good_count++;
            }
            
            // Determine promise type
            $promise_type = 'good';
            if ($bad_count > $good_count) {
                $promise_type = 'bad';
            } elseif ($bad_count == $good_count && $bad_count > 0) {
                $strong_bad = ['kill', 'destroy', 'hate', 'revenge', 'steal', 'hurt'];
                foreach ($strong_bad as $kw) {
                    if (strpos($text_lower, $kw) !== false) {
                        $promise_type = 'bad';
                        break;
                    }
                }
            }
            
            // Save to database (NO EMAIL)
            $stmt = $conn->prepare("INSERT INTO petal_promises (user_id, promise_text, promise_type) VALUES (?, ?, ?)");
            $stmt->bind_param("iss", $user_id, $promise_text, $promise_type);
            
            if ($stmt->execute()) {
                if ($promise_type === 'good') {
                    $promise_message = "🌸 Promise made! We'll remind you in 3 hours to keep it.";
                    $promise_message_type = 'success';
                } else {
                    $promise_message = "⚠️ That promise doesn't sound good. Petal advises against it.";
                    $promise_message_type = 'error';
                }
            } else {
                $promise_message = "❌ Failed to save promise. Please try again.";
                $promise_message_type = 'error';
            }
        }
    }
}

// ============================================================
//  HANDLE ENTRY SUBMISSION - INSERT NEW ROW AND GO TO RESULTS
// ============================================================
if (isset($_POST['submit_entry'])) {
    $good_thing = trim($_POST['good_thing'] ?? '');
    $bad_thing = trim($_POST['bad_thing'] ?? '');
    
    if (!empty($good_thing) || !empty($bad_thing)) {
        // Store the entry text in session to display on results page
        if (!empty($good_thing)) {
            $_SESSION['user_entry_text'] = $good_thing;
            $_SESSION['user_entry_type'] = 'good';
        } else {
            $_SESSION['user_entry_text'] = $bad_thing;
            $_SESSION['user_entry_type'] = 'bad';
        }
        
        // Insert into database
        $stmt = $conn->prepare("INSERT INTO petal_entries (user_id, entry_date, good_thing, bad_thing) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isss", $user_id, $today, $good_thing, $bad_thing);
        $stmt->execute();
        
        header('Location: results.php');
        exit();
    }
}

// ============================================================
//  FETCH TODAY'S ENTRIES (ALL ENTRIES FOR TODAY)
// ============================================================
$todayEntries = [];
$stmt = $conn->prepare("SELECT id, good_thing, bad_thing, created_at FROM petal_entries WHERE user_id = ? AND entry_date = ? ORDER BY created_at DESC");
$stmt->bind_param("is", $user_id, $today);
$stmt->execute();
$todayEntriesResult = $stmt->get_result();
while ($row = $todayEntriesResult->fetch_assoc()) {
    $todayEntries[] = $row;
}

// ============================================================
//  FETCH TOTAL COUNTS
// ============================================================
$progressStmt = $conn->prepare("
    SELECT 
        COUNT(CASE WHEN good_thing IS NOT NULL AND good_thing != '' THEN 1 END) as total_good,
        COUNT(CASE WHEN bad_thing IS NOT NULL AND bad_thing != '' THEN 1 END) as total_bad,
        MAX(entry_date) as last_entry
    FROM petal_entries 
    WHERE user_id = ?
");
$progressStmt->bind_param("i", $user_id);
$progressStmt->execute();
$progress = $progressStmt->get_result()->fetch_assoc();

$total_good = $progress['total_good'] ?? 0;
$total_bad = $progress['total_bad'] ?? 0;
$last_entry = $progress['last_entry'] ?? 'Never';

// ============================================================
//  CALCULATE STREAK
// ============================================================
$streak = 0;
$streakStmt = $conn->prepare("
    SELECT DISTINCT entry_date 
    FROM petal_entries 
    WHERE user_id = ? 
    ORDER BY entry_date DESC
");
$streakStmt->bind_param("i", $user_id);
$streakStmt->execute();
$dates = $streakStmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (count($dates) > 0) {
    $streak = 1;
    $current = strtotime($today);
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $next = strtotime($dates[$i]['entry_date']);
        $diff = ($current - $next) / (60 * 60 * 24);
        if ($diff <= 1) {
            $streak++;
            $current = $next;
        } else {
            break;
        }
    }
}

// ============================================================
//  CALCULATE PERFORMANCE PERCENTAGE
// ============================================================
$total_entries = $total_good + $total_bad;
$performance_percent = $total_entries > 0 ? round(($total_good / $total_entries) * 100) : 0;

// ============================================================
//  FETCH HISTORY - Last 10 entries
// ============================================================
$history = [];
$historyStmt = $conn->prepare("
    SELECT entry_date, good_thing, bad_thing 
    FROM petal_entries 
    WHERE user_id = ? 
    AND (good_thing IS NOT NULL OR bad_thing IS NOT NULL)
    ORDER BY entry_date DESC 
    LIMIT 10
");
$historyStmt->bind_param("i", $user_id);
$historyStmt->execute();
$historyResult = $historyStmt->get_result();
while ($row = $historyResult->fetch_assoc()) {
    $history[] = $row;
}

// ============================================================
//  FETCH TODAY'S PROMISE - Only if not completed
// ============================================================
$promiseStmt = $conn->prepare("
    SELECT id, promise_text, promise_type, is_completed 
    FROM petal_promises 
    WHERE user_id = ? 
    AND DATE(created_at) = ? 
    AND is_completed = 0
    ORDER BY created_at DESC 
    LIMIT 1
");
$promiseStmt->bind_param("is", $user_id, $today);
$promiseStmt->execute();
$today_promise = $promiseStmt->get_result()->fetch_assoc();

$promise_id = $today_promise['id'] ?? 0;
$promise_text = $today_promise['promise_text'] ?? '';
$promise_type = $today_promise['promise_type'] ?? '';

// ============================================================
//  FETCH ACTIVE PROMISES FOR NOTIFICATIONS
// ============================================================
$activePromises = [];
$activeStmt = $conn->prepare("
    SELECT id, promise_text, promise_type 
    FROM petal_promises 
    WHERE user_id = ? 
    AND is_completed = 0 
    AND promise_type = 'good'
    ORDER BY created_at DESC
");
$activeStmt->bind_param("i", $user_id);
$activeStmt->execute();
$activeResult = $activeStmt->get_result();
while ($row = $activeResult->fetch_assoc()) {
    $activePromises[] = $row;
}

// ============================================================
//  GET MESSAGES
// ============================================================
$entry_success = $_SESSION['entry_success'] ?? '';
$entry_error = $_SESSION['entry_error'] ?? '';
unset($_SESSION['entry_success']);
unset($_SESSION['entry_error']);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <!-- ============================================================
    PWA META TAGS
    ============================================================ -->
    <link rel="manifest" href="manifest.json" />
    <meta name="theme-color" content="#e91e63" />
    <meta name="apple-mobile-web-app-capable" content="yes" />
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
    <meta name="apple-mobile-web-app-title" content="Petal" />
    <meta name="application-name" content="Petal" />
    <meta name="mobile-web-app-capable" content="yes" />
    <meta name="msapplication-TileColor" content="#e91e63" />
    <meta name="msapplication-tap-highlight" content="no" />

    <!-- PWA ICONS -->
    <link rel="icon" href="icons/icon-192.svg" sizes="192x192" />
    <link rel="apple-touch-icon" href="icons/icon-192.svg" />
    <link rel="apple-touch-icon-precomposed" href="icons/icon-192.svg" />
    <link rel="shortcut icon" href="icons/icon-192.svg" />
    
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>🌸 Petal - Your Daily Companion</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        /* ===== RESET & BASE ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Quicksand', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #fff5f9 0%, #fce4ec 50%, #f3e5f5 100%);
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }

        /* ===== CONTAINER ===== */
        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        /* ===== HEADER ===== */
        .header {
            text-align: center;
            padding: 30px 0 20px;
        }
        .header h1 {
            font-size: 2.8rem;
            font-weight: 700;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1px;
        }
        .header .user-greeting {
            color: #4a1a2c;
            font-size: 1.1rem;
            font-weight: 500;
        }
        .header .user-greeting .name {
            color: #e91e63;
            font-weight: 700;
        }
        .header .user-greeting .email {
            color: #ad1457;
            font-size: 0.85rem;
            font-weight: 400;
            opacity: 0.7;
            display: block;
        }
        .header .logout-btn {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 20px;
            background: none;
            border: 2px solid #e91e63;
            color: #e91e63;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-family: inherit;
        }
        .header .logout-btn:hover {
            background: #e91e63;
            color: white;
        }
        .header .mute-btn {
            background: none;
            border: 2px solid #9c27b0;
            color: #9c27b0;
            padding: 6px 20px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.3s;
            margin-top: 8px;
            margin-left: 8px;
            font-family: inherit;
        }
        .header .mute-btn:hover {
            background: #9c27b0;
            color: white;
        }
        .header .mute-btn.muted {
            background: #666;
            border-color: #666;
            color: white;
        }
        .header .share-btn {
            display: inline-block;
            margin-top: 8px;
            padding: 6px 20px;
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            margin-left: 8px;
        }
        .header .share-btn:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 15px rgba(37, 211, 102, 0.3);
        }
        .header .top-buttons {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 8px;
        }

        /* ===== MESSAGE ALERTS ===== */
        .alert {
            padding: 14px 20px;
            border-radius: 16px;
            margin: 10px 0;
            font-weight: 600;
            font-size: 0.95rem;
        }
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #2e7d32;
        }
        .alert-error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #c62828;
        }
        .alert-warning {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #e65100;
        }

        /* ===== STATS CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin: 24px 0;
        }
        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        .stat-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            padding: 20px 16px;
            border-radius: 24px;
            text-align: center;
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.5);
            transition: all 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(233, 30, 99, 0.15);
        }
        .stat-card .number {
            font-size: 2.2rem;
            font-weight: 700;
            color: #880e4f;
        }
        .stat-card .label {
            font-size: 0.8rem;
            color: #ad1457;
            font-weight: 600;
            opacity: 0.7;
            margin-top: 4px;
        }
        .stat-card .emoji {
            font-size: 1.8rem;
            display: block;
            margin-bottom: 4px;
        }
        .stat-card.good .number { color: #2e7d32; }
        .stat-card.bad .number { color: #c62828; }
        .stat-card.streak .number { color: #e65100; }
        .stat-card.performance .number { color: #6a1b9a; }

        /* ===== PERFORMANCE BAR CARD ===== */
        .performance-card {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 24px 28px;
            margin: 20px 0;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.06);
        }
        .performance-card h3 {
            color: #880e4f;
            margin-bottom: 12px;
            font-size: 1.1rem;
        }
        .performance-bar {
            display: flex;
            height: 30px;
            border-radius: 16px;
            overflow: hidden;
            margin: 10px 0;
            background: #fce4ec;
        }
        .performance-bar .good-bar {
            background: linear-gradient(90deg, #66bb6a, #2e7d32);
            height: 100%;
            transition: width 0.8s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .performance-bar .bad-bar {
            background: linear-gradient(90deg, #ef5350, #c62828);
            height: 100%;
            transition: width 0.8s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .performance-stats {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            color: #4a1a2c;
            font-weight: 500;
        }
        .performance-stats .good-stat { color: #2e7d32; }
        .performance-stats .bad-stat { color: #c62828; }
        .performance-stats .total-stat { color: #6a1b9a; }

        /* ===== PROMISE SECTION ===== */
        .promise-section {
            background: linear-gradient(135deg, #fce4ec, #f3e5f5);
            border-radius: 24px;
            padding: 24px 28px;
            margin: 20px 0;
            border: 2px solid #e91e63;
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.1);
        }
        .promise-section h3 {
            color: #880e4f;
            margin-bottom: 12px;
            font-size: 1.2rem;
        }
        .promise-section .promise-display {
            background: rgba(255, 255, 255, 0.6);
            border-radius: 16px;
            padding: 16px 20px;
            margin: 10px 0;
            border-left: 4px solid #e91e63;
            font-size: 1.05rem;
            color: #4a1a2c;
        }
        .promise-section .promise-display .promise-label {
            font-weight: 600;
            color: #880e4f;
        }
        .promise-section .promise-display .promise-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .promise-section .promise-display .promise-badge.good {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .promise-section .promise-display .promise-badge.bad {
            background: #ffebee;
            color: #c62828;
        }
        .promise-section .promise-display .promise-actions {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .promise-section .promise-display .promise-actions .complete-btn {
            padding: 10px 24px;
            background: linear-gradient(135deg, #2e7d32, #43a047);
            color: white;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
        }
        .promise-section .promise-display .promise-actions .complete-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(46, 125, 50, 0.3);
        }
        .promise-section .promise-input-group {
            display: flex;
            gap: 12px;
            margin-top: 12px;
        }
        .promise-section .promise-input-group input {
            flex: 1;
            padding: 14px 18px;
            border-radius: 16px;
            border: 2px solid #f8bbd0;
            font-family: inherit;
            font-size: 1rem;
            background: rgba(255, 255, 255, 0.6);
            transition: all 0.3s;
            color: #1a1a2e;
        }
        .promise-section .promise-input-group input:focus {
            outline: none;
            border-color: #e91e63;
            box-shadow: 0 0 0 4px rgba(233, 30, 99, 0.08);
        }
        .promise-section .promise-input-group input::placeholder {
            color: #ad1457;
            opacity: 0.5;
        }
        .promise-btn {
            padding: 14px 28px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            white-space: nowrap;
        }
        .promise-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(233, 30, 99, 0.3);
        }
        .promise-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .promise-btn .badge {
            background: #ff6f00;
            color: white;
            border-radius: 50%;
            padding: 2px 8px;
            font-size: 0.7rem;
            margin-left: 6px;
        }

        /* ===== PROGRESS SUMMARY ===== */
        .progress-summary {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 24px 28px;
            margin: 20px 0;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.06);
        }
        .progress-summary p {
            color: #4a1a2c;
            font-size: 0.95rem;
            line-height: 1.8;
            margin: 4px 0;
        }
        .progress-summary .highlight {
            background: linear-gradient(135deg, #fce4ec, #f3e5f5);
            padding: 12px 16px;
            border-radius: 16px;
            font-weight: 600;
            color: #880e4f;
            margin-top: 8px;
            border-left: 4px solid #e91e63;
        }

        /* ===== INPUT SECTION ===== */
        .input-section {
            background: rgba(255, 255, 255, 0.7);
            backdrop-filter: blur(12px);
            border-radius: 24px;
            padding: 28px;
            margin: 24px 0;
            border: 1px solid rgba(255, 255, 255, 0.5);
            box-shadow: 0 8px 32px rgba(233, 30, 99, 0.06);
        }
        .input-section h3 {
            color: #880e4f;
            margin-bottom: 16px;
            font-size: 1.2rem;
        }
        .input-group {
            margin-bottom: 16px;
        }
        .input-group label {
            display: block;
            font-weight: 600;
            color: #4a1a2c;
            margin-bottom: 6px;
            font-size: 0.95rem;
        }
        .input-group textarea {
            width: 100%;
            padding: 14px 18px;
            border-radius: 16px;
            border: 2px solid #f8bbd0;
            font-family: inherit;
            font-size: 1rem;
            resize: vertical;
            min-height: 60px;
            background: rgba(255, 255, 255, 0.6);
            transition: all 0.3s;
            color: #1a1a2e;
        }
        .input-group textarea:focus {
            outline: none;
            border-color: #e91e63;
            box-shadow: 0 0 0 4px rgba(233, 30, 99, 0.08);
        }
        .input-group .voice-btn {
            background: #ce93d8;
            color: #4a148c;
            border: none;
            padding: 8px 16px;
            border-radius: 40px;
            font-weight: 600;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.3s;
            margin-top: 6px;
            font-family: inherit;
        }
        .input-group .voice-btn:hover {
            background: #ba68c8;
            transform: scale(1.02);
        }
        .input-group .voice-btn.listening {
            background: #4CAF50;
            color: white;
            animation: pulse 1s infinite;
        }
        .input-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        @media (max-width: 600px) {
            .input-row {
                grid-template-columns: 1fr;
            }
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.03); }
        }

        .submit-btn {
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            padding: 16px 40px;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
            font-family: inherit;
            letter-spacing: 0.5px;
        }
        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(233, 30, 99, 0.3);
        }
        .submit-btn:active {
            transform: translateY(0);
        }
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        /* ===== TODAY'S ENTRIES ===== */
        .today-entries-section {
            margin-top: 20px;
        }
        .today-entries-section h4 {
            color: #880e4f;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        .today-entry-item {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border-radius: 12px;
            padding: 12px 16px;
            margin-bottom: 8px;
            border-left: 4px solid #e91e63;
            font-size: 0.9rem;
            color: #4a1a2c;
        }
        .today-entry-item .entry-time {
            font-size: 0.7rem;
            color: #ad1457;
            opacity: 0.6;
            margin-right: 8px;
        }
        .today-entry-item .entry-good {
            color: #2e7d32;
        }
        .today-entry-item .entry-bad {
            color: #c62828;
        }
        .today-entry-item .entry-empty {
            color: #ad1457;
            opacity: 0.5;
            font-style: italic;
        }

        /* ===== HISTORY ===== */
        .history-section {
            margin-top: 24px;
        }
        .history-item {
            background: rgba(255, 255, 255, 0.6);
            backdrop-filter: blur(8px);
            border-radius: 16px;
            padding: 16px 20px;
            margin-bottom: 10px;
            border-left: 4px solid #e91e63;
            transition: all 0.3s;
        }
        .history-item:hover {
            transform: translateX(4px);
            background: rgba(255, 255, 255, 0.8);
        }
        .history-item .date {
            font-size: 0.75rem;
            color: #ad1457;
            font-weight: 600;
            opacity: 0.6;
        }
        .history-item .good {
            color: #2e7d32;
        }
        .history-item .bad {
            color: #c62828;
        }

        /* ===== TOAST ===== */
        .toast {
            position: fixed;
            bottom: 30px;
            right: 30px;
            background: #880e4f;
            color: white;
            padding: 16px 24px;
            border-radius: 16px;
            font-weight: 600;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.5s ease;
            z-index: 1000;
            max-width: 400px;
        }
        .toast.show {
            transform: translateY(0);
            opacity: 1;
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 480px) {
            .header h1 { font-size: 2rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .stat-card .number { font-size: 1.8rem; }
            .promise-section .promise-input-group {
                flex-direction: column;
            }
            .promise-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

    <div class="container">
        <!-- ===== HEADER ===== -->
        <header class="header">
            <h1>🌸 Petal</h1>
            <p class="user-greeting">
                Welcome back, <span class="name"><?= htmlspecialchars($fullname) ?></span>!
                <span class="email"><?= htmlspecialchars($email) ?></span>
            </p>
            <div class="top-buttons">
                <button class="mute-btn" id="muteBtn" onclick="toggleMute()">🔊 Sound On</button>
                <a href="dashboard.php?logout=1" class="logout-btn">🚪 Logout</a>
                <button class="share-btn" onclick="shareWebsite()">📤 Share</button>
            </div>
        </header>

        <!-- ===== MESSAGE ALERTS ===== -->
        <?php if (!empty($promise_message)): ?>
            <div class="alert alert-<?= $promise_message_type ?>">
                <?= htmlspecialchars($promise_message) ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($entry_success)): ?>
            <div class="alert alert-success"><?= htmlspecialchars($entry_success) ?></div>
        <?php endif; ?>
        <?php if (!empty($entry_error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($entry_error) ?></div>
        <?php endif; ?>

        <!-- ===== STATS CARDS ===== -->
        <div class="stats-grid">
            <div class="stat-card good">
                <span class="emoji">✨</span>
                <div class="number"><?= $total_good ?></div>
                <div class="label">Good Things</div>
            </div>
            <div class="stat-card bad">
                <span class="emoji">🌱</span>
                <div class="number"><?= $total_bad ?></div>
                <div class="label">Lessons Learned</div>
            </div>
            <div class="stat-card streak">
                <span class="emoji">🔥</span>
                <div class="number"><?= $streak ?></div>
                <div class="label">Day Streak</div>
            </div>
            <div class="stat-card performance">
                <span class="emoji">📊</span>
                <div class="number"><?= $performance_percent ?>%</div>
                <div class="label">Performance</div>
            </div>
        </div>

        <!-- ===== PERFORMANCE BAR CARD ===== -->
        <div class="performance-card">
            <h3>📈 Performance Overview</h3>
            <?php
            $total = $total_good + $total_bad;
            $good_percent = $total > 0 ? round(($total_good / $total) * 100) : 50;
            $bad_percent = $total > 0 ? round(($total_bad / $total) * 100) : 50;
            
            if ($total == 0) {
                $message = "Start tracking your journey to see your performance!";
            } elseif ($good_percent >= 70) {
                $message = "You're doing amazing! Keep up the great work! 🌟";
            } elseif ($good_percent >= 50) {
                $message = "You're finding balance! Keep growing! 💪";
            } else {
                $message = "Every day is a learning opportunity. You're growing! 🌱";
            }
            ?>
            <div class="performance-bar">
                <div class="good-bar" style="width: <?= $good_percent ?>%;"><?= $good_percent ?>% Good</div>
                <div class="bad-bar" style="width: <?= $bad_percent ?>%;"><?= $bad_percent ?>% Lessons</div>
            </div>
            <div class="performance-stats">
                <span class="good-stat">✨ Good: <?= $total_good ?></span>
                <span class="bad-stat">🌱 Lessons: <?= $total_bad ?></span>
                <span class="total-stat">📊 Total: <?= $total ?></span>
            </div>
            <p style="margin-top:10px; color:#4a1a2c; font-weight:500; text-align:center;"><?= $message ?></p>
        </div>

        <!-- ===== PROMISE SECTION ===== -->
        <div class="promise-section">
            <h3>🤝 Your Promise</h3>
            
            <?php if (!empty($promise_text)): ?>
                <!-- Display existing promise with Complete button -->
                <div class="promise-display">
                    <span class="promise-label">📌 You promised:</span>
                    <span><?= htmlspecialchars($promise_text) ?></span>
                    <span class="promise-badge <?= $promise_type ?>"><?= ucfirst($promise_type) ?></span>
                    <br>
                    <small style="color:#ad1457; opacity:0.7;">💡 We'll remind you in 3 hours to keep your promise!</small>
                    
                    <!-- Complete Promise Button -->
                    <div class="promise-actions">
                        <form method="POST" action="dashboard.php" style="display:inline;">
                            <input type="hidden" name="promise_id" value="<?= $promise_id ?>">
                            <input type="hidden" name="promise_text" value="<?= htmlspecialchars($promise_text) ?>">
                            <button type="submit" name="complete_promise" class="complete-btn">
                                ✅ I Completed My Promise!
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Promise Input Form (only show if no active promise) -->
            <?php if (empty($promise_text)): ?>
            <form method="POST" action="dashboard.php">
                <div class="promise-input-group">
                    <input type="text" id="promiseInput" name="promise_text" placeholder="🌸 Promise me what good thing you will do today..." required />
                    <button type="submit" name="submit_promise" class="promise-btn" id="promiseBtn">
                        🤝 Promise Me
                        <?php if (!empty($activePromises)): ?>
                            <span class="badge"><?= count($activePromises) ?></span>
                        <?php endif; ?>
                    </button>
                </div>
                <?php if (!empty($activePromises)): ?>
                    <div style="margin-top:8px; font-size:0.8rem; color:#880e4f;">
                        ⏰ You have <?= count($activePromises) ?> active promise(s) being tracked.
                    </div>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>

        <!-- ===== PROGRESS SUMMARY ===== -->
        <div class="progress-summary">
            <?php
            $summary_lines = [];
            
            if ($total_good > 0 || $total_bad > 0) {
                $ratio = $total > 0 ? round(($total_good / $total) * 100) : 0;
                
                if ($ratio >= 70) {
                    $summary_lines[] = "🌟 You're absolutely glowing! Your positivity is contagious!";
                    $summary_lines[] = "You've been focusing on the good things, and it shows!";
                } elseif ($ratio >= 40) {
                    $summary_lines[] = "💫 You're finding balance! Every day is a learning experience.";
                    $summary_lines[] = "You're growing through both your wins and your challenges.";
                } else {
                    $summary_lines[] = "🌧️ Life has its ups and downs, and you're handling them with grace.";
                    $summary_lines[] = "Remember, even the hardest days are stepping stones to growth.";
                }
                
                if ($streak >= 5) {
                    $summary_lines[] = "🔥 $streak days in a row! That's incredible dedication!";
                } elseif ($streak >= 2) {
                    $summary_lines[] = "💪 $streak day streak! You're building a beautiful habit!";
                }
                
                if ($last_entry != 'Never' && $last_entry == $today) {
                    $summary_lines[] = "🌸 You've already checked in today! So proud of you!";
                } elseif ($last_entry != 'Never') {
                    $summary_lines[] = "💕 Your last check-in was " . date('M j', strtotime($last_entry)) . ". Welcome back!";
                }
            } else {
                $summary_lines[] = "🌸 Welcome to Petal! This is your safe space for growth.";
                $summary_lines[] = "Start by sharing something good that happened today.";
                $summary_lines[] = "Every journey begins with a single step. You've taken yours.";
            }
            
            $summary_lines[] = "💖 Remember: You are worthy, you are enough, and you are loved.";
            $summary_lines[] = "🌟 What good thing do you want to do today? Say it, keep it, and do it. Don't lie to yourself. 🌟";
            
            echo implode("</p><p>", array_map(function($line) {
                return "<p>" . htmlspecialchars($line) . "</p>";
            }, $summary_lines));
            ?>
        </div>

        <!-- ===== INPUT SECTION ===== -->
        <div class="input-section">
            <h3>📝 Share Your Day With Petal</h3>
            <form id="entryForm" method="POST" action="dashboard.php">
                <input type="hidden" name="submit_entry" value="1">
                <div class="input-row">
                    <div class="input-group">
                        <label for="goodInput">✨ Something Good You Did</label>
                        <textarea id="goodInput" name="good_thing" placeholder="e.g. I helped a colleague with their project..." rows="2"></textarea>
                        <button type="button" class="voice-btn" data-target="goodInput" onclick="startVoice(this, 'goodInput')">🎤 Voice Input</button>
                    </div>
                    <div class="input-group">
                        <label for="badInput">🌱 Something You Want to Improve</label>
                        <textarea id="badInput" name="bad_thing" placeholder="e.g. I felt overwhelmed today..." rows="2"></textarea>
                        <button type="button" class="voice-btn" data-target="badInput" onclick="startVoice(this, 'badInput')">🎤 Voice Input</button>
                    </div>
                </div>
                <button type="submit" class="submit-btn" id="submitBtn">💕 Share With Petal</button>
            </form>
        </div>

        <!-- ===== TODAY'S ENTRIES ===== -->
        <div class="today-entries-section">
            <h4>📋 Today's Entries</h4>
            <?php if (count($todayEntries) > 0): ?>
                <?php foreach ($todayEntries as $entry): ?>
                    <div class="today-entry-item">
                        <span class="entry-time"><?= date('M j, Y', strtotime($entry['created_at'])) ?></span>
                        <?php if (!empty($entry['good_thing'])): ?>
                            <span class="entry-good">✨ <?= htmlspecialchars($entry['good_thing']) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($entry['bad_thing'])): ?>
                            <span class="entry-bad">🌱 <?= htmlspecialchars($entry['bad_thing']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="today-entry-item">
                    <span class="entry-empty">No entries yet today. Share your day above! 🌸</span>
                </div>
            <?php endif; ?>
        </div>

        <!-- ===== HISTORY ===== -->
        <div class="history-section">
            <h3 style="color:#880e4f; margin-bottom:16px;">📖 Your Journey</h3>
            <div id="historyList">
                <?php
                if (count($history) > 0) {
                    foreach ($history as $row) {
                        echo '<div class="history-item">';
                        echo '<div class="date">' . date('M j, Y', strtotime($row['entry_date'])) . '</div>';
                        if (!empty($row['good_thing'])) {
                            echo '<div class="good">✨ ' . htmlspecialchars(substr($row['good_thing'], 0, 100)) . '</div>';
                        }
                        if (!empty($row['bad_thing'])) {
                            echo '<div class="bad">🌱 ' . htmlspecialchars(substr($row['bad_thing'], 0, 100)) . '</div>';
                        }
                        echo '</div>';
                    }
                } else {
                    echo '<p style="color:#ad1457; opacity:0.6; text-align:center; padding:20px;">No entries yet. Start your journey today! 🌸</p>';
                }
                ?>
            </div>
        </div>
    </div>

    <!-- ===== TOAST ===== -->
    <div class="toast" id="toast"></div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        // ============================================================
        //  SHARE FUNCTIONALITY
        // ============================================================
        function shareWebsite() {
            const url = window.location.href;
            const text = "🌸 Petal - Your daily companion for growth and self-love! Track your good things, lessons learned, and make promises to yourself. Join me at: ";
            
            if (navigator.share) {
                navigator.share({
                    title: '🌸 Petal - Your Daily Companion',
                    text: text,
                    url: url
                }).catch(function(err) {
                    console.log('Share cancelled:', err);
                    copyToClipboard(url);
                });
            } else {
                copyToClipboard(url);
                showToast('📤 Link copied! Paste it anywhere to share.');
                
                if (confirm('Do you want to share on WhatsApp?')) {
                    const whatsappUrl = 'https://api.whatsapp.com/send?text=' + encodeURIComponent(text + ' ' + url);
                    window.open(whatsappUrl, '_blank');
                }
            }
        }

        function copyToClipboard(text) {
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).catch(function(err) {
                    console.error('Copy failed:', err);
                    const textarea = document.createElement('textarea');
                    textarea.value = text;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    document.body.removeChild(textarea);
                });
            } else {
                const textarea = document.createElement('textarea');
                textarea.value = text;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
            }
        }

        // ============================================================
        //  MUTE FUNCTIONALITY
        // ============================================================
        let isMuted = false;

        function toggleMute() {
            isMuted = !isMuted;
            const btn = document.getElementById('muteBtn');
            if (isMuted) {
                btn.textContent = '🔕 Sound Off';
                btn.classList.add('muted');
                localStorage.setItem('petal_muted', 'true');
            } else {
                btn.textContent = '🔊 Sound On';
                btn.classList.remove('muted');
                localStorage.setItem('petal_muted', 'false');
            }
        }

        if (localStorage.getItem('petal_muted') === 'true') {
            isMuted = true;
            document.getElementById('muteBtn').textContent = '🔕 Sound Off';
            document.getElementById('muteBtn').classList.add('muted');
        }

        // ============================================================
        //  VOICE INPUT
        // ============================================================
        let recognition = null;

        function initSpeech() {
            if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
                const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
                recognition = new SpeechRecognition();
                recognition.lang = 'en-US';
                recognition.continuous = false;
                recognition.interimResults = true;
                recognition.maxAlternatives = 1;
                return true;
            }
            return false;
        }

        function startVoice(btn, targetId) {
            if (!recognition) {
                if (!initSpeech()) {
                    showToast('❌ Voice input not supported. Try Chrome or Edge.');
                    return;
                }
            }

            const textarea = document.getElementById(targetId);
            if (!textarea) return;

            if (btn.classList.contains('listening')) {
                recognition.stop();
                btn.classList.remove('listening');
                btn.textContent = '🎤 Voice Input';
                return;
            }

            btn.textContent = '🔴 Listening...';
            btn.classList.add('listening');

            recognition.onresult = function(event) {
                let transcript = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                textarea.value = transcript;
            };

            recognition.onerror = function(event) {
                console.error('Voice error:', event.error);
                btn.classList.remove('listening');
                btn.textContent = '🎤 Voice Input';
                if (event.error === 'not-allowed') {
                    showToast('❌ Please allow microphone access.');
                } else {
                    showToast('⚠️ Could not hear you. Try again.');
                }
            };

            recognition.onend = function() {
                btn.classList.remove('listening');
                btn.textContent = '🎤 Voice Input';
            };

            try {
                recognition.start();
            } catch (e) {
                btn.classList.remove('listening');
                btn.textContent = '🎤 Voice Input';
            }
        }

        // ============================================================
        //  PROMISE TIMER FOR NOTIFICATIONS
        // ============================================================
        let promiseTimer = null;
        let promiseText = '';

        function requestNotificationPermission() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().then(function(permission) {
                    if (permission === 'granted') {
                        console.log('🔔 Notification permission granted!');
                    } else {
                        console.log('🔔 Notification permission denied.');
                    }
                });
            }
        }

        function sendNotification(title, body) {
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(title, {
                    body: body,
                    icon: '🌸',
                    silent: false,
                    requireInteraction: true
                });
            }
        }

        function startPromiseTimer() {
            if (promiseTimer) {
                clearTimeout(promiseTimer);
            }

            const storedPromise = localStorage.getItem('petal_promise');
            const storedPromiseType = localStorage.getItem('petal_promise_type');
            
            if (storedPromise && storedPromiseType === 'good') {
                promiseText = storedPromise;
                
                promiseTimer = setTimeout(function() {
                    sendNotification(
                        '🌸 Petal Promise Reminder',
                        'Remember your promise: "' + promiseText + '" 🌟'
                    );
                    
                    localStorage.removeItem('petal_promise');
                    localStorage.removeItem('petal_promise_type');
                    
                    showToast('🔔 Reminder: ' + promiseText);
                    location.reload();
                }, 10800000); // 3 hours
            }
        }

        // ============================================================
        //  FORM SUBMISSION
        // ============================================================
        document.getElementById('entryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const goodInput = document.getElementById('goodInput');
            const badInput = document.getElementById('badInput');
            
            if (!goodInput.value.trim() && !badInput.value.trim()) {
                showToast('🌸 Please share at least one thing!');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = '💫 Sending to Petal...';
            this.submit();
        });

        // ============================================================
        //  TOAST
        // ============================================================
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.classList.add('show');
            clearTimeout(toast._timeout);
            toast._timeout = setTimeout(() => {
                toast.classList.remove('show');
            }, 4000);
        }

        // ============================================================
        //  AUTO-RESIZE TEXTAREAS
        // ============================================================
        document.querySelectorAll('textarea').forEach(ta => {
            ta.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 200) + 'px';
            });
        });

        // ============================================================
        //  CHECK FOR EXISTING PROMISE ON LOAD
        // ============================================================
        function checkExistingPromise() {
            const storedPromise = localStorage.getItem('petal_promise');
            const storedPromiseType = localStorage.getItem('petal_promise_type');
            
            if (storedPromise && storedPromiseType === 'good') {
                startPromiseTimer();
            }
        }

        // ============================================================
        //  INIT
        // ============================================================
        console.log('🌸 Petal is ready!');
        initSpeech();
        checkExistingPromise();

        setTimeout(() => {
            if (<?= $total_good ?> === 0 && <?= $total_bad ?> === 0) {
                showToast('🌸 Welcome to Petal! Share your day with me.');
            } else {
                showToast('💕 Welcome back! Let\'s grow together.');
            }
            
            <?php if (!empty($promise_text) && $promise_type === 'good'): ?>
            showToast('🤝 Remember your promise: "<?= htmlspecialchars($promise_text) ?>"');
            <?php endif; ?>
        }, 800);
    </script>

</body>
</html>
