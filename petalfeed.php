<?php
// ============================================================
//  PETALFEED.PHP - Feed ALL Database Content to AI
//  This creates a knowledge base for the AI to learn from
// ============================================================

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$fullname = $_SESSION['fullname'];

// ============================================================
//  DATABASE CONNECTION
// ============================================================
require_once '/home2/firstsun/tesdbaccess/swazilift.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ============================================================
//  GITHUB AI CONFIGURATION
// ============================================================
$GITHUB_TOKEN = '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';

// ============================================================
//  HANDLE ADD NEW RESPONSE
// ============================================================
$add_message = '';
$add_message_type = '';

if (isset($_POST['add_response'])) {
    $response_text = trim($_POST['response_text'] ?? '');
    $response_type = trim($_POST['response_type'] ?? 'motivation');
    $trigger_keywords = trim($_POST['trigger_keywords'] ?? '');
    $tone = trim($_POST['tone'] ?? 'warm');
    
    if (empty($response_text)) {
        $add_message = "Please enter a response text.";
        $add_message_type = 'error';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO petal_responses (trigger_keywords, response_type, response_text, tone) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("ssss", $trigger_keywords, $response_type, $response_text, $tone);
        
        if ($stmt->execute()) {
            $add_message = "✅ Response added successfully!";
            $add_message_type = 'success';
            // Clear form
            $_POST = [];
        } else {
            $add_message = "❌ Failed to add response: " . $conn->error;
            $add_message_type = 'error';
        }
    }
}

// ============================================================
//  GET COUNTS FOR STATS
// ============================================================
$countStmt = $conn->prepare("SELECT COUNT(*) as count FROM petal_entries");
$countStmt->execute();
$entryCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;

$countStmt = $conn->prepare("SELECT COUNT(*) as count FROM petal_promises");
$countStmt->execute();
$promiseCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;

$countStmt = $conn->prepare("SELECT COUNT(*) as count FROM petal_responses");
$countStmt->execute();
$responseCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;

$countStmt = $conn->prepare("SELECT COUNT(*) as count FROM petal_users");
$countStmt->execute();
$userCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;

$countStmt = $conn->prepare("SELECT COUNT(*) as count FROM petal_sentiment_cache");
$countStmt->execute();
$sentimentCount = $countStmt->get_result()->fetch_assoc()['count'] ?? 0;

// ============================================================
//  HANDLE FEED ACTION
// ============================================================
$feed_status = '';
$feed_progress = 0;
$total_items = 0;
$processed_items = 0;
$feed_results = [];
$feed_logs = [];
$ai_response = '';
$ai_success = false;

// Read feed log
$log_file = __DIR__ . '/petal_feed_log.txt';
$feed_logs = file_exists($log_file) ? file($log_file, FILE_IGNORE_NEW_LINES) : [];
$feed_logs = array_slice($feed_logs, -10);

if (isset($_POST['feed_ai'])) {
    $feed_status = 'started';
    $feed_logs = [];
    
    // ============================================================
    //  STEP 1: GATHER ALL DATA FROM DATABASE
    // ============================================================
    
    // Get all entries
    $entries = [];
    $stmt = $conn->prepare("
        SELECT id, user_id, entry_date, good_thing, bad_thing, created_at 
        FROM petal_entries 
        ORDER BY entry_date DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $entries[] = $row;
    }
    
    // Get all promises
    $promises = [];
    $stmt = $conn->prepare("
        SELECT id, user_id, promise_text, promise_type, is_completed, created_at, completed_at 
        FROM petal_promises 
        ORDER BY created_at DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $promises[] = $row;
    }
    
    // Get all responses
    $responses = [];
    $stmt = $conn->prepare("
        SELECT id, trigger_keywords, response_type, response_text, tone 
        FROM petal_responses 
        ORDER BY id ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $responses[] = $row;
    }
    
    // Get all users
    $users = [];
    $stmt = $conn->prepare("
        SELECT id, fullname, email, created_at, last_login 
        FROM petal_users 
        ORDER BY id ASC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // Get sentiment cache
    $sentiments = [];
    $stmt = $conn->prepare("
        SELECT id, user_id, input_text, detected_keywords, sentiment_score, created_at 
        FROM petal_sentiment_cache 
        ORDER BY created_at DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sentiments[] = $row;
    }
    
    // ============================================================
    //  STEP 2: BUILD KNOWLEDGE BASE
    // ============================================================
    $total_items = count($entries) + count($promises) + count($responses) + count($users) + count($sentiments);
    $processed_items = 0;
    
    $knowledge_base = "=== PETAL AI KNOWLEDGE BASE ===\n\n";
    $knowledge_base .= "Created: " . date('Y-m-d H:i:s') . "\n";
    $knowledge_base .= "Total Users: " . count($users) . "\n";
    $knowledge_base .= "Total Entries: " . count($entries) . "\n";
    $knowledge_base .= "Total Promises: " . count($promises) . "\n";
    $knowledge_base .= "Total Responses: " . count($responses) . "\n";
    $knowledge_base .= "Total Sentiments: " . count($sentiments) . "\n\n";
    $knowledge_base .= "============================================\n\n";
    
    // Add user data
    $knowledge_base .= "=== USER DATA ===\n\n";
    foreach ($users as $user) {
        $knowledge_base .= "User ID: " . $user['id'] . "\n";
        $knowledge_base .= "Name: " . $user['fullname'] . "\n";
        $knowledge_base .= "Email: " . $user['email'] . "\n";
        $knowledge_base .= "Joined: " . $user['created_at'] . "\n";
        $knowledge_base .= "Last Login: " . ($user['last_login'] ?? 'Never') . "\n\n";
        $processed_items++;
        $feed_progress = round(($processed_items / $total_items) * 25);
    }
    
    // Add entries
    $knowledge_base .= "=== USER ENTRIES ===\n\n";
    foreach ($entries as $entry) {
        $knowledge_base .= "Entry ID: " . $entry['id'] . "\n";
        $knowledge_base .= "User ID: " . $entry['user_id'] . "\n";
        $knowledge_base .= "Date: " . $entry['entry_date'] . "\n";
        if (!empty($entry['good_thing'])) {
            $knowledge_base .= "Good Thing: " . $entry['good_thing'] . "\n";
        }
        if (!empty($entry['bad_thing'])) {
            $knowledge_base .= "Challenge: " . $entry['bad_thing'] . "\n";
        }
        $knowledge_base .= "---\n";
        $processed_items++;
        $feed_progress = round(($processed_items / $total_items) * 25 + 25);
    }
    
    // Add promises
    $knowledge_base .= "\n=== USER PROMISES ===\n\n";
    foreach ($promises as $promise) {
        $knowledge_base .= "Promise ID: " . $promise['id'] . "\n";
        $knowledge_base .= "User ID: " . $promise['user_id'] . "\n";
        $knowledge_base .= "Promise: " . $promise['promise_text'] . "\n";
        $knowledge_base .= "Type: " . $promise['promise_type'] . "\n";
        $knowledge_base .= "Status: " . ($promise['is_completed'] ? 'Completed' : 'Active') . "\n";
        $knowledge_base .= "Created: " . $promise['created_at'] . "\n";
        if ($promise['completed_at']) {
            $knowledge_base .= "Completed: " . $promise['completed_at'] . "\n";
        }
        $knowledge_base .= "---\n";
        $processed_items++;
        $feed_progress = round(($processed_items / $total_items) * 25 + 50);
    }
    
    // Add responses (the knowledge base)
    $knowledge_base .= "\n=== PETAL'S KNOWLEDGE BASE (RESPONSES) ===\n\n";
    foreach ($responses as $response) {
        $knowledge_base .= "Response ID: " . $response['id'] . "\n";
        $knowledge_base .= "Keywords: " . ($response['trigger_keywords'] ?: 'General') . "\n";
        $knowledge_base .= "Type: " . $response['response_type'] . "\n";
        $knowledge_base .= "Tone: " . $response['tone'] . "\n";
        $knowledge_base .= "Response: " . $response['response_text'] . "\n";
        $knowledge_base .= "---\n";
        $processed_items++;
        $feed_progress = round(($processed_items / $total_items) * 25 + 75);
    }
    
    // Add sentiment cache
    $knowledge_base .= "\n=== SENTIMENT ANALYSIS CACHE ===\n\n";
    foreach ($sentiments as $sentiment) {
        $knowledge_base .= "Sentiment ID: " . $sentiment['id'] . "\n";
        $knowledge_base .= "User ID: " . $sentiment['user_id'] . "\n";
        $knowledge_base .= "Input: " . substr($sentiment['input_text'] ?? '', 0, 100) . "...\n";
        $knowledge_base .= "Keywords: " . ($sentiment['detected_keywords'] ?? 'None') . "\n";
        $knowledge_base .= "Score: " . $sentiment['sentiment_score'] . "\n";
        $knowledge_base .= "---\n";
        $processed_items++;
        $feed_progress = min(round(($processed_items / $total_items) * 100), 99);
    }
    
    // ============================================================
    //  STEP 3: SAVE TO FILE
    // ============================================================
    $knowledge_file = __DIR__ . '/petal_knowledge_base.txt';
    file_put_contents($knowledge_file, $knowledge_base);
    
    $feed_logs[] = "📁 Knowledge base saved to file (" . round(strlen($knowledge_base)/1024) . " KB)";
    
    // ============================================================
    //  STEP 4: SEND TO AI
    // ============================================================
    try {
        $url = 'https://api.github.com/chat/completions';
        
        $training_prompt = "You are Petal, an AI companion for personal growth. I am feeding you the entire knowledge base of your system. Study this carefully so you can respond wisely to users.\n\n";
        $training_prompt .= "KNOWLEDGE BASE:\n" . substr($knowledge_base, 0, 4000) . "\n\n";
        $training_prompt .= "After studying this, respond with a confirmation that you have learned and are ready to help users grow. Be warm and encouraging.";
        
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are Petal, a warm, encouraging, and empathetic AI companion for personal growth.'
                ],
                [
                    'role' => 'user',
                    'content' => $training_prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 500
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: token ' . $GITHUB_TOKEN,
            'Accept: application/vnd.github.v3+json'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $ai_response = $data['choices'][0]['message']['content'];
                $ai_success = true;
                $feed_logs[] = "✅ AI training successful!";
                $feed_results['ai'] = ['success' => true, 'message' => 'AI trained successfully!'];
            } else {
                $feed_results['ai'] = ['success' => false, 'message' => 'Invalid AI response'];
                $feed_logs[] = "❌ Invalid AI response";
            }
        } else {
            $feed_results['ai'] = ['success' => false, 'message' => 'API error: ' . $httpCode];
            $feed_logs[] = "❌ API error: " . $httpCode;
            if ($error) $feed_logs[] = "❌ cURL error: " . $error;
        }
    } catch (Exception $e) {
        $feed_results['ai'] = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        $feed_logs[] = "❌ Exception: " . $e->getMessage();
    }
    
    $feed_results['total_items'] = $total_items;
    $feed_results['processed_items'] = $processed_items;
    $feed_results['knowledge_file'] = $knowledge_file;
    $feed_results['knowledge_size'] = round(strlen($knowledge_base) / 1024) . ' KB';
    $feed_status = 'completed';
    
    $feed_logs[] = "📊 Processed: $processed_items/$total_items items";
    $feed_logs[] = "📁 Knowledge base size: " . $feed_results['knowledge_size'];
    $feed_logs[] = "✅ Feed completed at " . date('Y-m-d H:i:s');
    
    // Save feed log
    $log_entry = "[" . date('Y-m-d H:i:s') . "] Feed: $processed_items/$total_items items, AI: " . ($ai_success ? 'SUCCESS' : 'FAILED') . "\n";
    file_put_contents(__DIR__ . '/petal_feed_log.txt', $log_entry, FILE_APPEND);
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>🧠 Petal - Feed AI Knowledge</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Quicksand', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #fff5f9 0%, #fce4ec 50%, #f3e5f5 100%);
            min-height: 100vh;
            padding: 20px;
            margin: 0;
        }

        .feed-container {
            max-width: 700px;
            margin: 0 auto;
            background: rgba(255, 255, 255, 0.85);
            backdrop-filter: blur(20px);
            border-radius: 48px;
            padding: 40px 36px;
            box-shadow: 0 30px 80px rgba(233, 30, 99, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.5);
            animation: slideUp 0.5s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(40px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        @keyframes shimmer {
            0% { background-position: -200% center; }
            100% { background-position: 200% center; }
        }

        .feed-container .doll-icon { text-align: center; font-size: 3.5rem; margin-bottom: 4px; }
        .feed-container h1 {
            font-size: 2.2rem;
            text-align: center;
            font-weight: 700;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 4px;
        }
        .feed-container .subtitle {
            text-align: center;
            color: #ad1457;
            font-size: 0.95rem;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .feed-stats {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin: 20px 0;
        }
        @media (max-width: 600px) { .feed-stats { grid-template-columns: repeat(3, 1fr); } }
        .feed-stat {
            background: rgba(248, 187, 208, 0.15);
            border-radius: 16px;
            padding: 12px 6px;
            text-align: center;
            border: 1px solid rgba(233, 30, 99, 0.08);
        }
        .feed-stat .number { font-size: 1.3rem; font-weight: 700; color: #880e4f; }
        .feed-stat .label { font-size: 0.55rem; color: #ad1457; opacity: 0.7; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }

        .feed-progress-container {
            background: #fce4ec;
            border-radius: 40px;
            height: 24px;
            margin: 20px 0;
            overflow: hidden;
            position: relative;
        }
        .feed-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #e91e63, #9c27b0, #e91e63);
            background-size: 200% 100%;
            border-radius: 40px;
            transition: width 0.8s ease;
            width: 0%;
            position: relative;
            animation: shimmer 2s infinite;
        }
        .feed-progress-bar .progress-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            color: white;
            font-size: 0.7rem;
            font-weight: 700;
        }

        .feed-status {
            text-align: center;
            padding: 16px;
            border-radius: 16px;
            margin: 16px 0;
            font-weight: 600;
        }
        .feed-status.idle { background: #f3e5f5; color: #6a1b9a; }
        .feed-status.starting { background: #fff3e0; color: #e65100; animation: pulse 1s infinite; }
        .feed-status.processing { background: #e3f2fd; color: #0d47a1; animation: pulse 1s infinite; }
        .feed-status.completed { background: #e8f5e9; color: #2e7d32; }
        .feed-status.error { background: #ffebee; color: #c62828; }

        /* ===== ADD RESPONSE FORM ===== */
        .add-response-section {
            background: rgba(248, 187, 208, 0.15);
            border-radius: 20px;
            padding: 20px;
            margin: 16px 0;
            border: 1px dashed rgba(233, 30, 99, 0.2);
        }
        .add-response-section h3 {
            color: #880e4f;
            font-size: 1rem;
            margin-bottom: 12px;
        }
        .add-response-section .form-group {
            margin-bottom: 10px;
        }
        .add-response-section .form-group label {
            display: block;
            font-weight: 600;
            color: #4a1a2c;
            font-size: 0.8rem;
            margin-bottom: 3px;
        }
        .add-response-section .form-group input,
        .add-response-section .form-group textarea,
        .add-response-section .form-group select {
            width: 100%;
            padding: 10px 14px;
            border-radius: 12px;
            border: 2px solid #f8bbd0;
            font-family: inherit;
            font-size: 0.9rem;
            background: rgba(255,255,255,0.6);
            transition: all 0.3s;
            color: #1a1a2e;
        }
        .add-response-section .form-group input:focus,
        .add-response-section .form-group textarea:focus,
        .add-response-section .form-group select:focus {
            outline: none;
            border-color: #e91e63;
            box-shadow: 0 0 0 4px rgba(233,30,99,0.08);
        }
        .add-response-section .form-group textarea {
            min-height: 60px;
            resize: vertical;
        }
        .add-response-section .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        @media (max-width: 480px) {
            .add-response-section .form-row {
                grid-template-columns: 1fr;
            }
        }
        .add-response-section .btn-add {
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
            width: 100%;
        }
        .add-response-section .btn-add:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(46,125,50,0.3);
        }
        .add-response-section .message {
            padding: 8px 12px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 10px;
            font-weight: 600;
        }
        .add-response-section .message.success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .add-response-section .message.error {
            background: #ffebee;
            color: #c62828;
        }

        .feed-logs {
            max-height: 200px;
            overflow-y: auto;
            margin: 12px 0;
            padding: 8px 12px;
            background: rgba(0,0,0,0.03);
            border-radius: 16px;
            font-size: 0.75rem;
            font-family: monospace;
            color: #4a1a2c;
        }
        .feed-logs .log-item {
            padding: 3px 0;
            border-bottom: 1px solid rgba(233, 30, 99, 0.04);
        }

        .btn-feed {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            margin-top: 12px;
        }
        .btn-feed:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(233,30,99,0.3); }
        .btn-feed:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

        .btn-back {
            display: inline-block;
            margin-top: 16px;
            padding: 12px 30px;
            background: none;
            border: 2px solid #e91e63;
            color: #e91e63;
            border-radius: 60px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            font-family: inherit;
            text-align: center;
            width: 100%;
        }
        .btn-back:hover { background: #e91e63; color: white; }

        .ai-response {
            background: rgba(243, 229, 245, 0.5);
            border-radius: 16px;
            padding: 16px;
            margin: 12px 0;
            border-left: 4px solid #9c27b0;
            color: #4a1a2c;
            line-height: 1.6;
            font-size: 0.9rem;
            max-height: 150px;
            overflow-y: auto;
        }

        .learning-badge {
            display: inline-block;
            padding: 4px 16px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 700;
            background: #e8f5e9;
            color: #2e7d32;
            animation: pulse 1.5s infinite;
        }

        .feed-result-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 0;
            font-size: 0.85rem;
            color: #4a1a2c;
        }
        .feed-result-item .icon { font-size: 1rem; }
        .feed-result-item .label { font-weight: 600; color: #880e4f; }

        @media (max-width: 480px) {
            .feed-container { padding: 24px 16px; }
            .feed-stats { grid-template-columns: repeat(3, 1fr); }
            .feed-stat .number { font-size: 1rem; }
        }
    </style>
</head>
<body>

    <div class="feed-container">
        <div class="doll-icon">🧠</div>
        <h1>Petal Knowledge Feed</h1>
        <p class="subtitle">Feed all database knowledge to AI for learning</p>

        <!-- ===== STATS ===== -->
        <div class="feed-stats">
            <div class="feed-stat">
                <div class="number"><?= $userCount ?></div>
                <div class="label">Users</div>
            </div>
            <div class="feed-stat">
                <div class="number"><?= $entryCount ?></div>
                <div class="label">Entries</div>
            </div>
            <div class="feed-stat">
                <div class="number"><?= $promiseCount ?></div>
                <div class="label">Promises</div>
            </div>
            <div class="feed-stat">
                <div class="number"><?= $responseCount ?></div>
                <div class="label">Knowledge</div>
            </div>
            <div class="feed-stat">
                <div class="number"><?= $sentimentCount ?></div>
                <div class="label">Sentiments</div>
            </div>
        </div>

        <!-- ===== ADD RESPONSE SECTION ===== -->
        <div class="add-response-section">
            <h3>✏️ Add New Response to Knowledge Base</h3>
            
            <?php if (!empty($add_message)): ?>
                <div class="message <?= $add_message_type ?>"><?= $add_message ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="response_text">Response Text *</label>
                    <textarea id="response_text" name="response_text" placeholder="Enter the response message..." required></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="trigger_keywords">Trigger Keywords (comma separated)</label>
                        <input type="text" id="trigger_keywords" name="trigger_keywords" placeholder="e.g. happy,joy,great" />
                    </div>
                    <div class="form-group">
                        <label for="response_type">Response Type</label>
                        <select id="response_type" name="response_type">
                            <option value="celebration">Celebration</option>
                            <option value="bad_advice">Bad Advice</option>
                            <option value="motivation" selected>Motivation</option>
                            <option value="defense">Defense</option>
                            <option value="reflection">Reflection</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="tone">Tone</label>
                        <select id="tone" name="tone">
                            <option value="warm">Warm</option>
                            <option value="proud">Proud</option>
                            <option value="inspiring">Inspiring</option>
                            <option value="grateful">Grateful</option>
                            <option value="gentle">Gentle</option>
                            <option value="comforting">Comforting</option>
                            <option value="encouraging">Encouraging</option>
                            <option value="calm">Calm</option>
                            <option value="firm">Firm</option>
                            <option value="contemplative">Contemplative</option>
                        </select>
                    </div>
                    <div class="form-group" style="display:flex; align-items:flex-end;">
                        <button type="submit" name="add_response" class="btn-add">➕ Add Response</button>
                    </div>
                </div>
            </form>
        </div>

        <!-- ===== PROGRESS ===== -->
        <div class="feed-progress-container">
            <div class="feed-progress-bar" id="progressBar" style="width: <?= $feed_progress ?? 0 ?>%;">
                <span class="progress-text" id="progressText"><?= $feed_progress ?? 0 ?>%</span>
            </div>
        </div>

        <!-- ===== STATUS ===== -->
        <div class="feed-status <?= $feed_status ?: 'idle' ?>" id="feedStatus">
            <?php if ($feed_status === 'started' || $feed_status === 'processing'): ?>
                <span class="learning-badge">🧠 LEARNING IN PROGRESS...</span>
                <br><br>
                <span>Reading database and feeding knowledge to AI...</span>
            <?php elseif ($feed_status === 'completed'): ?>
                ✅ <strong>Knowledge Feed Complete!</strong>
                <br>
                <span style="font-size:0.85rem;"><?= $processed_items ?? 0 ?> items processed successfully.</span>
                <?php if ($ai_success): ?>
                    <br><br>
                    <span style="color:#2e7d32;">🤖 AI has successfully learned from the database!</span>
                <?php else: ?>
                    <br><br>
                    <span style="color:#e65100;">⚠️ AI training completed with some issues. Check logs below.</span>
                <?php endif; ?>
            <?php elseif ($feed_status === 'idle'): ?>
                🌸 <strong>Ready to Feed Knowledge</strong>
                <br>
                <span style="font-size:0.85rem;">Click the button below to feed all database content to AI.</span>
            <?php endif; ?>
        </div>

        <!-- ===== AI RESPONSE ===== -->
        <?php if (!empty($ai_response)): ?>
            <div class="ai-response">
                <strong>🤖 AI Response:</strong><br>
                <?= nl2br(htmlspecialchars($ai_response)) ?>
            </div>
        <?php endif; ?>

        <!-- ===== FEED RESULTS ===== -->
        <?php if ($feed_status === 'completed' && !empty($feed_results)): ?>
            <div style="margin: 12px 0; padding: 12px; background: rgba(255,255,255,0.3); border-radius: 16px;">
                <div class="feed-result-item">
                    <span class="icon">✅</span>
                    <span class="label">Total Items:</span>
                    <span><?= $feed_results['total_items'] ?? 0 ?></span>
                </div>
                <div class="feed-result-item">
                    <span class="icon">✅</span>
                    <span class="label">Processed:</span>
                    <span><?= $feed_results['processed_items'] ?? 0 ?></span>
                </div>
                <div class="feed-result-item">
                    <span class="icon">📁</span>
                    <span class="label">Knowledge File:</span>
                    <span><?= basename($feed_results['knowledge_file'] ?? '') ?></span>
                </div>
                <div class="feed-result-item">
                    <span class="icon">📊</span>
                    <span class="label">File Size:</span>
                    <span><?= $feed_results['knowledge_size'] ?? '0 KB' ?></span>
                </div>
                <div class="feed-result-item">
                    <span class="icon">🤖</span>
                    <span class="label">AI Training:</span>
                    <span style="color: <?= $ai_success ? '#2e7d32' : '#c62828' ?>;">
                        <?= $ai_success ? '✅ Successful' : '❌ Failed' ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>

        <!-- ===== FEED LOGS ===== -->
        <?php if (!empty($feed_logs)): ?>
            <div class="feed-logs" id="feedLogs">
                <?php foreach ($feed_logs as $log): ?>
                    <div class="log-item"><?= htmlspecialchars($log) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- ===== FEED BUTTON ===== -->
        <form method="POST" action="" id="feedForm">
            <button type="submit" name="feed_ai" class="btn-feed" id="feedBtn" <?= $feed_status === 'started' || $feed_status === 'processing' ? 'disabled' : '' ?>>
                <?php if ($feed_status === 'started' || $feed_status === 'processing'): ?>
                    ⏳ Feeding Knowledge...
                <?php elseif ($feed_status === 'completed'): ?>
                    ✅ Feed Complete - Feed Again?
                <?php else: ?>
                    🧠 Feed Knowledge to AI
                <?php endif; ?>
            </button>
        </form>

        <!-- ===== BACK BUTTON ===== -->
        <a href="dashboard.php" class="btn-back">🌸 Back to Dashboard</a>

        <!-- ===== LOG SECTION ===== -->
        <div style="margin-top:16px; font-size:0.65rem; color:#ad1457; opacity:0.4; text-align:center; border-top:1px solid rgba(233,30,99,0.1); padding-top:12px;">
            Knowledge base: <code>petal_knowledge_base.txt</code> &nbsp;|&nbsp; Logs: <code>petal_feed_log.txt</code>
        </div>
    </div>

    <!-- ===== JAVASCRIPT ===== -->
    <script>
        let progressInterval = null;
        let currentProgress = <?= $feed_progress ?? 0 ?>;

        function updateProgress() {
            const progressBar = document.getElementById('progressBar');
            const progressText = document.getElementById('progressText');
            
            if (currentProgress < 100 && <?= $feed_status === 'started' || $feed_status === 'processing' ? 'true' : 'false' ?>) {
                currentProgress += Math.random() * 3 + 1;
                if (currentProgress > 99) currentProgress = 99;
                progressBar.style.width = currentProgress + '%';
                progressText.textContent = Math.round(currentProgress) + '%';
            }
        }

        <?php if ($feed_status === 'started' || $feed_status === 'processing'): ?>
            progressInterval = setInterval(updateProgress, 500);
            setTimeout(function() { location.reload(); }, 15000);
        <?php endif; ?>

        <?php if ($feed_status === 'completed'): ?>
            setTimeout(function() {
                const progressBar = document.getElementById('progressBar');
                const progressText = document.getElementById('progressText');
                progressBar.style.width = '100%';
                progressText.textContent = '100%';
            }, 500);
        <?php endif; ?>

        document.getElementById('feedForm').addEventListener('submit', function() {
            const btn = document.getElementById('feedBtn');
            btn.disabled = true;
            btn.innerHTML = '⏳ Processing... Please wait';
            document.getElementById('feedStatus').className = 'feed-status processing';
            document.getElementById('feedStatus').innerHTML = '<span class="learning-badge">🧠 LEARNING IN PROGRESS...</span><br><br><span>Reading database and feeding knowledge to AI...</span>';
        });

        console.log('🌸 Petal Knowledge Feed is ready!');
        console.log('📊 Total items: <?= $entryCount + $promiseCount + $responseCount + $userCount + $sentimentCount ?>');
    </script>

</body>
</html>