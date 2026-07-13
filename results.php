<?php
// ============================================================
//  RESULTS.PHP - RESPONSE PAGE WITH AI CHAT & TALKING DOLL
//  FIXED: Displays the user's good/bad thing AND completed promise
//  "I understood" text is COMPLETELY REMOVED (hidden from both view and speech)
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
//  GET THE USER'S ENTRY TEXT FROM SESSION
// ============================================================
$user_entry_text = $_SESSION['user_entry_text'] ?? '';
$user_entry_type = $_SESSION['user_entry_type'] ?? ''; // 'good' or 'bad'

// Clear the session variables so they don't persist
unset($_SESSION['user_entry_text']);
unset($_SESSION['user_entry_type']);

// ============================================================
//  CHECK IF PROMISE WAS COMPLETED
// ============================================================
$promise_completed = isset($_GET['promise_completed']) ? true : false;
$completed_promise_text = '';

if ($promise_completed) {
    if (isset($_SESSION['completed_promise'])) {
        $completed_promise_text = $_SESSION['completed_promise'];
        unset($_SESSION['completed_promise']);
    } else {
        require_once '/home2/firstsun/tesdbaccess/swazilift.php';
        $conn = new mysqli($servername, $username, $password, $dbname);
        if (!$conn->connect_error) {
            $stmt = $conn->prepare("SELECT promise_text FROM petal_promises WHERE user_id = ? AND is_completed = 1 ORDER BY completed_at DESC LIMIT 1");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $completed_promise_text = $row['promise_text'];
            }
            $conn->close();
        }
    }
}

// ============================================================
//  DATABASE CONNECTION
// ============================================================
require_once '/home2/firstsun/tesdbaccess/swazilift.php';

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$today = date('Y-m-d');
$good_thing = isset($_POST['good_thing']) ? trim($_POST['good_thing']) : '';
$bad_thing = isset($_POST['bad_thing']) ? trim($_POST['bad_thing']) : '';

// Get user message from session
$user_message = $_SESSION['user_message'] ?? '';
unset($_SESSION['user_message']);

// Use user_message if available, otherwise use good_thing
$text_to_analyze = !empty($user_message) ? $user_message : $good_thing;

// If no text to analyze, fallback to the session entry text
if (empty($text_to_analyze)) {
    $text_to_analyze = $user_entry_text;
}

// ============================================================
//  EMOJI MAPPING
// ============================================================
function getEmojiForResponse($type, $tone) {
    $emojis = [
        'celebration' => ['warm' => '🌟', 'proud' => '🎉', 'inspiring' => '💫', 'grateful' => '🙏', 'gentle' => '🌸'],
        'bad_advice' => ['comforting' => '🌧️', 'encouraging' => '💪', 'gentle' => '🌙', 'calm' => '🧘', 'warm' => '🌸'],
        'motivation' => ['inspiring' => '🌟', 'warm' => '💕', 'gentle' => '🌱', 'encouraging' => '💪', 'contemplative' => '🌅'],
        'defense' => ['firm' => '🛑', 'calm' => '💫', 'gentle' => '🌸', 'encouraging' => '💪', 'comforting' => '💕'],
        'reflection' => ['contemplative' => '🌅', 'inspiring' => '💫', 'gentle' => '🌙', 'warm' => '🌸', 'comforting' => '💕']
    ];
    if (!isset($emojis[$type])) return '💕';
    if (!isset($emojis[$type][$tone])) {
        $values = array_values($emojis[$type]);
        return $values[0] ?? '💕';
    }
    return $emojis[$type][$tone];
}

// ============================================================
//  SAVE TO DATABASE - SIMPLE INSERT
// ============================================================
if (!empty($good_thing) || !empty($bad_thing)) {
    $stmt = $conn->prepare("INSERT INTO petal_entries (user_id, entry_date, good_thing, bad_thing) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $today, $good_thing, $bad_thing);
    $stmt->execute();
}

// ============================================================
//  ANALYZE TEXT & GENERATE RESPONSES FROM DATABASE
// ============================================================
function analyzeText($text, $conn) {
    if (empty($text)) return null;
    
    $text_lower = strtolower($text);
    $matched_responses = [];
    $matched_keywords = [];
    $sentiment_score = 0;
    $fallback_responses = [];
    
    $result = $conn->query("SELECT * FROM petal_responses ORDER BY priority ASC, id ASC");
    
    while ($row = $result->fetch_assoc()) {
        $keywords = array_map('trim', explode(',', $row['trigger_keywords']));
        $keywords = array_filter($keywords, function($k) { return !empty($k); });
        
        $match_found = false;
        
        if (!empty($keywords)) {
            foreach ($keywords as $keyword) {
                if (strpos($text_lower, $keyword) !== false) {
                    $match_found = true;
                    $matched_keywords[] = $keyword;
                    $sentiment_score += 1;
                    break;
                }
            }
        } else {
            $fallback_responses[] = $row;
            continue;
        }
        
        if ($match_found) {
            $emoji = getEmojiForResponse($row['response_type'], $row['tone']);
            $matched_responses[] = [
                'text' => $row['response_text'],
                'emoji' => $emoji,
                'type' => $row['response_type'],
                'tone' => $row['tone'],
                'priority' => $row['priority']
            ];
        }
    }
    
    if (!empty($matched_responses)) {
        usort($matched_responses, function($a, $b) {
            return ($a['priority'] ?? 2) - ($b['priority'] ?? 2);
        });
        $selected = array_slice($matched_responses, 0, 4);
        return [
            'responses' => $selected,
            'matched_keywords' => $matched_keywords,
            'sentiment_score' => $sentiment_score
        ];
    }
    
    if (!empty($fallback_responses)) {
        shuffle($fallback_responses);
        $selected = array_slice($fallback_responses, 0, 3);
        $fallback_selected = [];
        foreach ($selected as $row) {
            $emoji = getEmojiForResponse($row['response_type'], $row['tone']);
            $fallback_selected[] = [
                'text' => $row['response_text'],
                'emoji' => $emoji,
                'type' => $row['response_type'],
                'tone' => $row['tone']
            ];
        }
        return [
            'responses' => $fallback_selected,
            'matched_keywords' => [],
            'sentiment_score' => 0
        ];
    }
    
    $defaults = [
        ['text' => 'I hear you, and I am so proud of you for sharing.', 'emoji' => '🌸', 'type' => 'motivation', 'tone' => 'warm'],
        ['text' => 'Your honesty is a beautiful gift to yourself. Keep growing.', 'emoji' => '💕', 'type' => 'motivation', 'tone' => 'warm'],
        ['text' => 'Thank you for trusting me. You are doing wonderfully.', 'emoji' => '🌟', 'type' => 'motivation', 'tone' => 'inspiring']
    ];
    return [
        'responses' => [$defaults[array_rand($defaults)]],
        'matched_keywords' => [],
        'sentiment_score' => 0
    ];
}

$analysis = analyzeText($text_to_analyze, $conn);

$all_responses = [];
if ($analysis && !empty($analysis['responses'])) {
    foreach ($analysis['responses'] as $r) {
        $all_responses[] = $r;
    }
}

if (empty($all_responses)) {
    $motivation_result = $conn->query("SELECT response_text, response_type, tone FROM petal_responses WHERE response_type = 'motivation' ORDER BY RAND() LIMIT 2");
    while ($row = $motivation_result->fetch_assoc()) {
        $emoji = getEmojiForResponse($row['response_type'], $row['tone']);
        $all_responses[] = [
            'text' => $row['response_text'],
            'emoji' => $emoji,
            'type' => $row['response_type'],
            'tone' => $row['tone']
        ];
    }
}

shuffle($all_responses);
$selected_responses = array_slice($all_responses, 0, 4);

$response_parts = [];
foreach ($selected_responses as $r) {
    $response_parts[] = $r['emoji'] . ' ' . $r['text'];
}
$final_response = implode(' ', $response_parts);

// ============================================================
//  GENERATE WISDOM
// ============================================================
function generateWisdom($text, $type) {
    if (empty($text)) return null;
    
    $wisdoms = [
        'good' => [
            'Your goodness is a light that shines brighter than you realize.',
            'Every act of kindness creates ripples that extend far beyond what you can see.',
            'You are the author of your own joy. Keep writing beautiful chapters.',
            'What you did today matters. It truly matters.',
            'Small wins are still wins. Celebrate every victory.'
        ],
        'bad' => [
            'Even the strongest trees bend in the wind. You are still standing.',
            'This challenging moment is not your forever. Brighter days are ahead.',
            'Growth happens in the struggle. You are becoming stronger.',
            'Be gentle with yourself today. You are doing the best you can.',
            'The night is always darkest before the dawn. Trust that light is coming.'
        ]
    ];
    
    $list = isset($wisdoms[$type]) ? $wisdoms[$type] : $wisdoms['good'];
    return $list[array_rand($list)];
}

$wisdom = generateWisdom($text_to_analyze, 'good');

$full_response = $final_response;
if ($wisdom) {
    $full_response .= ' 💫 ' . $wisdom;
}

// ============================================================
//  BUILD FINAL RESPONSE WITH USER'S ENTRY TEXT
// ============================================================
$user_entry_display = '';

if (!empty($user_entry_text)) {
    $type_label = ($user_entry_type === 'good') ? '✨ Something Good You Did' : '🌱 Something You Want to Improve';
    $user_entry_display = '<p class="user-entry"><span class="entry-label">' . $type_label . ':</span> "' . htmlspecialchars($user_entry_text) . '"</p>';
}

if ($promise_completed && !empty($completed_promise_text)) {
    $promise_thanks = "🌟 " . $fullname . ", you completed your promise: \"" . $completed_promise_text . "\"! That is absolutely amazing! You are a person of your word and that is incredibly admirable. Keep this momentum going! 🌟";
    $final_question = "What other good thing can you promise yourself today? 🌸";
    $full_response = $promise_thanks . ' 💪 ' . $full_response;
} else {
    $final_question = "What good thing do you want to do today? Say it, commit to it, and follow through. 🌟";
}

$full_response .= ' 🌟 ' . $final_question;

// Save to cache
$keywords_str = implode(', ', $analysis['matched_keywords'] ?? []);
$sentiment = $analysis['sentiment_score'] ?? 0;

$cache_stmt = $conn->prepare("INSERT INTO petal_sentiment_cache (user_id, input_text, detected_keywords, sentiment_score) VALUES (?, ?, ?, ?)");
$cache_stmt->bind_param("issi", $user_id, $text_to_analyze, $keywords_str, $sentiment);
$cache_stmt->execute();

$conn->close();

// Store the full response in a variable for JavaScript to read
$main_response_text = $full_response;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>🌸 Petal's Response</title>
    <link href="https://fonts.googleapis.com/css2?family=Quicksand:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Quicksand', 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #fff5f9 0%, #fce4ec 50%, #f3e5f5 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            margin: 0;
        }
        .response-container {
            max-width: 620px;
            width: 100%;
            background: rgba(255, 255, 255, 0.75);
            backdrop-filter: blur(16px);
            border-radius: 48px;
            padding: 40px 32px;
            box-shadow: 0 20px 60px rgba(233, 30, 99, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.5);
            animation: fadeUp 0.8s ease;
            text-align: center;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(40px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-12px); }
        }
        @keyframes talk {
            0%, 100% { transform: scale(1) rotate(0deg); }
            25% { transform: scale(1.03) rotate(-2deg); }
            75% { transform: scale(1.03) rotate(2deg); }
        }
        @keyframes sparkle {
            0%, 100% { opacity: 0; transform: scale(0.5); }
            50% { opacity: 1; transform: scale(1.2); }
        }
        .doll-container { 
            margin: 10px 0 24px; 
            animation: float 3s ease-in-out infinite; 
            position: relative;
        }
        .doll { 
            position: relative; 
            display: inline-block; 
            cursor: pointer; 
            transition: all 0.3s; 
        }
        .doll:hover { transform: scale(1.05); }
        .doll.talking { animation: talk 0.4s ease-in-out 4; }
        .doll-speech-bubble {
            position: absolute;
            top: -60px;
            left: 50%;
            transform: translateX(-50%);
            background: white;
            padding: 8px 16px;
            border-radius: 20px 20px 20px 4px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            font-size: 0.8rem;
            color: #4a1a2c;
            font-weight: 600;
            white-space: nowrap;
            border: 1px solid rgba(248, 187, 208, 0.3);
            opacity: 0;
            transform: translateX(-50%) scale(0.8);
            transition: all 0.3s ease;
            pointer-events: none;
        }
        .doll-speech-bubble.show {
            opacity: 1;
            transform: translateX(-50%) scale(1);
        }
        .doll-speech-bubble::after {
            content: '';
            position: absolute;
            bottom: -8px;
            left: 50%;
            transform: translateX(-50%);
            border-left: 8px solid transparent;
            border-right: 8px solid transparent;
            border-top: 8px solid white;
        }
        .doll svg {
            width: 160px;
            height: 160px;
            filter: drop-shadow(0 8px 24px rgba(233, 30, 99, 0.15));
            transition: all 0.3s;
        }
        .doll .mouth { transition: all 0.2s; }
        .doll.talking .mouth {
            animation: talkMouth 0.3s ease-in-out 6;
        }
        @keyframes talkMouth {
            0%, 100% { d: path("M90 98 Q100 108 110 98"); }
            50% { d: path("M90 95 Q100 115 110 95"); }
        }
        .sparkle {
            position: absolute;
            font-size: 1.2rem;
            animation: sparkle 1.5s ease-in-out infinite;
            pointer-events: none;
        }
        .sparkle:nth-child(1) { top: -10px; left: -20px; animation-delay: 0s; }
        .sparkle:nth-child(2) { top: -5px; right: -15px; animation-delay: 0.5s; }
        .sparkle:nth-child(3) { bottom: 5px; left: -25px; animation-delay: 1s; }
        .sparkle:nth-child(4) { bottom: 10px; right: -20px; animation-delay: 0.3s; }

        /* ===== RESPONSE TEXT ===== */
        .response-text {
            font-size: 1.05rem;
            line-height: 1.9;
            color: #4a1a2c;
            padding: 20px 0;
            min-height: 100px;
            text-align: left;
        }
        .response-text p {
            margin: 8px 0;
            padding: 8px 14px;
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.3);
            animation: fadeUp 0.6s ease forwards;
        }
        .response-text .user-entry {
            background: rgba(255, 255, 255, 0.8);
            border-left: 4px solid #e91e63;
            padding: 12px 16px;
            border-radius: 12px;
            font-style: italic;
            color: #1a1a2e;
        }
        .response-text .user-entry .entry-label {
            font-weight: 700;
            color: #880e4f;
            font-style: normal;
        }
        .response-text .highlight {
            background: linear-gradient(135deg, #fce4ec, #f3e5f5);
            padding: 14px 18px;
            border-radius: 16px;
            font-weight: 600;
            color: #880e4f;
            margin-top: 12px;
            border-left: 4px solid #e91e63;
        }
        .response-text .promise-completed {
            background: linear-gradient(135deg, #e8f5e9, #c8e6c9);
            padding: 16px 20px;
            border-radius: 16px;
            font-weight: 600;
            color: #2e7d32;
            margin-top: 12px;
            border-left: 4px solid #2e7d32;
        }
        .response-text .final-question {
            font-size: 1.15rem;
            font-weight: 700;
            color: #880e4f;
            border-top: 2px dashed rgba(233,30,99,0.15);
            padding-top: 16px;
            margin-top: 12px;
            background: rgba(248, 187, 208, 0.15);
            border-radius: 16px;
            padding: 16px 20px;
        }

        /* ===== AI CHAT ===== */
        .ai-chat-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px dashed rgba(233, 30, 99, 0.15);
            text-align: left;
        }
        .ai-chat-section h4 {
            color: #880e4f;
            margin-bottom: 12px;
            font-size: 1rem;
        }
        .ai-chat-section .ai-status {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }
        .ai-chat-section .ai-status.online { background: #e8f5e9; color: #2e7d32; }
        .ai-chat-section .ai-status.offline { background: #ffebee; color: #c62828; }
        .ai-chat-section .ai-status.testing { background: #fff3e0; color: #e65100; animation: pulse 1s infinite; }
        
        .ai-chat-messages {
            max-height: 250px;
            overflow-y: auto;
            background: rgba(255, 255, 255, 0.4);
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 10px;
            min-height: 80px;
        }
        .ai-chat-messages .ai-msg {
            padding: 8px 12px;
            border-radius: 12px;
            margin-bottom: 6px;
            font-size: 0.9rem;
            animation: fadeUp 0.3s ease forwards;
        }
        .ai-chat-messages .ai-msg.user {
            background: #fce4ec;
            color: #4a1a2c;
            text-align: right;
        }
        .ai-chat-messages .ai-msg.bot {
            background: #f3e5f5;
            color: #4a1a2c;
            text-align: left;
        }
        .ai-chat-messages .ai-msg .time {
            font-size: 0.6rem;
            opacity: 0.5;
            margin-left: 8px;
        }
        
        .ai-chat-input-group {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .ai-chat-input-group input {
            flex: 1;
            padding: 10px 14px;
            border-radius: 40px;
            border: 2px solid #f8bbd0;
            font-family: inherit;
            font-size: 0.9rem;
            background: rgba(255, 255, 255, 0.6);
            transition: all 0.3s;
            color: #1a1a2e;
        }
        .ai-chat-input-group input:focus {
            outline: none;
            border-color: #e91e63;
            box-shadow: 0 0 0 4px rgba(233, 30, 99, 0.08);
        }
        .ai-chat-input-group input::placeholder {
            color: #ad1457;
            opacity: 0.5;
        }
        .voice-input-btn {
            padding: 10px 16px;
            background: #ce93d8;
            color: #4a148c;
            border: none;
            border-radius: 40px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.3s;
            font-family: inherit;
            white-space: nowrap;
        }
        .voice-input-btn:hover { background: #ba68c8; transform: scale(1.02); }
        .voice-input-btn.listening {
            background: #4CAF50;
            color: white;
            animation: pulse 1s infinite;
        }
        .send-btn {
            padding: 10px 20px;
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
        }
        .send-btn:hover { transform: scale(1.02); box-shadow: 0 4px 15px rgba(233,30,99,0.3); }
        .send-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .back-btn {
            display: inline-block;
            margin-top: 20px;
            padding: 14px 40px;
            background: linear-gradient(135deg, #e91e63, #9c27b0);
            color: white;
            border: none;
            border-radius: 60px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            font-family: inherit;
        }
        .back-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(233, 30, 99, 0.3);
        }
        .mute-btn {
            background: none;
            border: 2px solid #e91e63;
            color: #e91e63;
            padding: 6px 16px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 0.8rem;
            font-weight: 600;
            transition: all 0.3s;
            font-family: inherit;
            margin-top: 8px;
        }
        .mute-btn:hover { background: #e91e63; color: white; }
        .mute-btn.muted { background: #666; border-color: #666; color: white; }
        .keywords-tag {
            display: inline-block;
            background: rgba(233, 30, 99, 0.08);
            color: #880e4f;
            padding: 2px 12px;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
            margin: 2px;
        }
        .user-name { color: #e91e63; font-weight: 600; }
        .typing-indicator {
            display: none;
            padding: 8px 12px;
            background: #f3e5f5;
            border-radius: 12px;
            margin-bottom: 6px;
            font-size: 0.85rem;
            color: #4a1a2c;
        }
        .typing-indicator.active { display: block; }
        .typing-indicator .dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #9c27b0;
            border-radius: 50%;
            margin: 0 2px;
            animation: typingDot 1.4s infinite both;
        }
        .typing-indicator .dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-indicator .dot:nth-child(3) { animation-delay: 0.4s; }
        @keyframes typingDot {
            0%, 60%, 100% { transform: translateY(0); opacity: 0.4; }
            30% { transform: translateY(-6px); opacity: 1; }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        @media (max-width: 480px) {
            .response-container { padding: 28px 16px; }
            .doll svg { width: 120px; height: 120px; }
            .response-text { font-size: 0.95rem; }
            .ai-chat-input-group { flex-direction: column; }
            .ai-chat-input-group input { width: 100%; }
            .voice-input-btn, .send-btn { width: 100%; }
            .doll-speech-bubble { font-size: 0.7rem; top: -45px; padding: 4px 12px; }
        }
    </style>
</head>
<body>
    <div class="response-container">
        <!-- DOLL -->
        <div class="doll-container">
            <div class="doll" id="doll" onclick="toggleTalk()">
                <span class="sparkle">✨</span>
                <span class="sparkle">🌟</span>
                <span class="sparkle">💫</span>
                <span class="sparkle">🌸</span>
                <div class="doll-speech-bubble" id="dollSpeechBubble">💕 Listen to me!</div>
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                    <ellipse cx="100" cy="140" rx="60" ry="50" fill="#f8bbd0" />
                    <circle cx="100" cy="80" r="55" fill="#fce4ec" />
                    <path d="M45 70 Q50 30 70 20 Q80 15 90 18 Q100 15 110 18 Q120 15 130 20 Q150 30 155 70" fill="#e91e63" opacity="0.7" />
                    <path d="M50 75 Q55 40 75 30 Q85 25 100 28 Q115 25 125 30 Q145 40 150 75" fill="#e91e63" opacity="0.5" />
                    <ellipse cx="80" cy="80" rx="10" ry="12" fill="white" />
                    <ellipse cx="120" cy="80" rx="10" ry="12" fill="white" />
                    <circle cx="83" cy="82" r="6" fill="#4a1a2c" />
                    <circle cx="123" cy="82" r="6" fill="#4a1a2c" />
                    <circle cx="85" cy="79" r="2" fill="white" />
                    <circle cx="125" cy="79" r="2" fill="white" />
                    <ellipse cx="70" cy="92" rx="12" ry="6" fill="#f48fb1" opacity="0.4" />
                    <ellipse cx="130" cy="92" rx="12" ry="6" fill="#f48fb1" opacity="0.4" />
                    <path d="M90 98 Q100 108 110 98" stroke="#e91e63" stroke-width="2.5" fill="none" stroke-linecap="round" class="mouth" />
                    <circle cx="88" cy="97" r="2" fill="#f48fb1" opacity="0.3" />
                    <circle cx="112" cy="97" r="2" fill="#f48fb1" opacity="0.3" />
                    <text x="100" y="175" font-family="Quicksand, sans-serif" font-size="14" fill="#880e4f" font-weight="600" text-anchor="middle"><?= htmlspecialchars($fullname) ?></text>
                </svg>
            </div>
        </div>

        <button class="mute-btn" id="muteBtn" onclick="toggleMute()">🔊 Sound On</button>

        <!-- ===== RESPONSE TEXT ===== -->
        <div class="response-text" id="responseText">
            <?php
            $html = '';

            // DISPLAY THE USER'S ENTRY TEXT
            if (!empty($user_entry_display)) {
                $html .= $user_entry_display;
            }

            if ($promise_completed && !empty($completed_promise_text)) {
                $html .= '<p style="text-align:center; font-size:2rem; margin-bottom:8px;">🎉🎊🌟🎈🎉</p>';
                $html .= '<p class="promise-completed">✅ ' . htmlspecialchars($fullname) . ', you completed your promise: "' . htmlspecialchars($completed_promise_text) . '"! That is absolutely amazing! 🌟</p>';
            }

            // "I understood" section is COMPLETELY REMOVED here

            $html .= '<p>Hello <span class="user-name">' . htmlspecialchars($fullname) . '</span>! ' . htmlspecialchars($full_response) . '</p>';

            if (!empty($wisdom)) {
                $html .= '<p class="highlight">💫 ' . htmlspecialchars($wisdom) . '</p>';
            }

            $html .= '<p class="final-question">🌟 ' . htmlspecialchars($final_question) . '</p>';

            echo $html;
            ?>
        </div>

        <!-- ===== AI CHAT ===== -->
        <div class="ai-chat-section">
            <h4>🌸 Chat with Petal AI <span class="ai-status testing" id="aiStatus">Connecting...</span></h4>
            <div class="ai-chat-messages" id="aiChatMessages">
                <div class="ai-msg bot">🌸 Hi <?= htmlspecialchars($fullname) ?>! I'm Petal's AI assistant. What's on your mind today? 💕 <span class="time"><?= date('h:i A') ?></span></div>
            </div>
            <div class="typing-indicator" id="typingIndicator">
                <span>🌸 Petal is thinking</span>
                <span class="dot"></span>
                <span class="dot"></span>
                <span class="dot"></span>
            </div>
            <div class="ai-chat-input-group">
                <input type="text" id="aiChatInput" placeholder="Ask Petal AI anything..." />
                <button class="voice-input-btn" id="voiceInputBtn" onclick="startVoiceInput()">🎤 Speak</button>
                <button class="send-btn" id="aiSendBtn">💬 Send</button>
            </div>
        </div>

        <a href="dashboard.php" class="back-btn">🌸 Back to Dashboard</a>
    </div>

    <script>
        let isMuted = false;
        let isAIReady = false;
        let entryText = '<?= addslashes($text_to_analyze) ?>';
        let isSpeaking = false;
        let recognition = null;
        let mainResponseText = '<?= addslashes($main_response_text) ?>';

        function initVoiceInput() {
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

        function startVoiceInput() {
            const btn = document.getElementById('voiceInputBtn');
            const input = document.getElementById('aiChatInput');
            if (!recognition) {
                if (!initVoiceInput()) { alert('❌ Voice input not supported. Try Chrome or Edge.'); return; }
            }
            if (btn.classList.contains('listening')) {
                recognition.stop();
                btn.classList.remove('listening');
                btn.textContent = '🎤 Speak';
                return;
            }
            btn.textContent = '🔴 Listening...';
            btn.classList.add('listening');
            recognition.onresult = function(event) {
                let transcript = '';
                for (let i = event.resultIndex; i < event.results.length; i++) {
                    transcript += event.results[i][0].transcript;
                }
                input.value = transcript;
            };
            recognition.onerror = function(event) {
                console.error('Voice error:', event.error);
                btn.classList.remove('listening');
                btn.textContent = '🎤 Speak';
                if (event.error === 'not-allowed') {
                    alert('❌ Please allow microphone access.');
                } else {
                    showDollBubble('⚠️ Could not hear you. Try again.');
                }
            };
            recognition.onend = function() {
                btn.classList.remove('listening');
                btn.textContent = '🎤 Speak';
                const message = input.value.trim();
                if (message) { setTimeout(() => { sendAIMessage(); }, 500); }
            };
            try {
                recognition.start();
                showDollBubble('🎤 Listening... Speak now!');
            } catch (e) {
                btn.classList.remove('listening');
                btn.textContent = '🎤 Speak';
                console.error('Recognition start error:', e);
            }
        }

        function showDollBubble(text) {
            const bubble = document.getElementById('dollSpeechBubble');
            bubble.textContent = text || '💕 Listen!';
            bubble.classList.add('show');
            setTimeout(() => { bubble.classList.remove('show'); }, 3000);
        }

        function makeDollTalk() {
            const doll = document.getElementById('doll');
            doll.classList.add('talking');
            setTimeout(() => { doll.classList.remove('talking'); }, 1200);
        }

        function toggleMute() {
            isMuted = !isMuted;
            const btn = document.getElementById('muteBtn');
            if (isMuted) {
                btn.textContent = '🔕 Sound Off';
                btn.classList.add('muted');
                localStorage.setItem('petal_muted', 'true');
                if (window.speechSynthesis) window.speechSynthesis.cancel();
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

        function stripEmojis(text) {
            return text.replace(/[\u{1F600}-\u{1F9FF}]|[\u{2600}-\u{27BF}]|[\u{2300}-\u{23FF}]|[\u{2B50}]|[\u{2705}]|[\u{2714}]|[\u{274C}]|[\u{1F300}-\u{1F5FF}]/gu, '').trim();
        }

        // ============================================================
        // SPEAK MAIN RESPONSE - "I understood" text is REMOVED before speaking
        // ============================================================
        function speakMainResponse() {
            if (isMuted) return;
            if (!('speechSynthesis' in window)) return;
            
            const textElement = document.getElementById('responseText');
            let fullText = textElement.textContent || textElement.innerText;
            
            // Remove any "I understood" text from the speech
            let cleanText = stripEmojis(fullText);
            cleanText = cleanText.replace(/I understood:.*?\n/g, '').trim();
            cleanText = cleanText.replace(/<[^>]*>/g, '').trim();
            
            // Remove any keywords-tag styling text if it appears
            cleanText = cleanText.replace(/I understood:.*?(?=Hello|$)/gi, '').trim();
            
            if (!cleanText) {
                cleanText = stripEmojis(mainResponseText);
                cleanText = cleanText.replace(/I understood:.*?\n/g, '').trim();
            }
            
            if (!cleanText) {
                cleanText = "Hello " + '<?= htmlspecialchars($fullname) ?>' + "! Welcome to Petal.";
            }
            
            const shortText = cleanText.length > 35 ? cleanText.substring(0, 35) + '...' : cleanText;
            showDollBubble(shortText);
            makeDollTalk();

            window.speechSynthesis.cancel();
            
            const utterance = new SpeechSynthesisUtterance(cleanText);
            utterance.rate = 0.85;
            utterance.pitch = 1.1;
            utterance.volume = 1;

            const voices = window.speechSynthesis.getVoices();
            const femaleVoice = voices.find(v =>
                v.name.includes('Female') ||
                v.name.includes('Google UK English Female') ||
                v.name.includes('Samantha') ||
                v.name.includes('Victoria')
            );
            if (femaleVoice) utterance.voice = femaleVoice;

            const doll = document.getElementById('doll');
            utterance.onstart = function() {
                isSpeaking = true;
                doll.classList.add('talking');
            };
            utterance.onend = function() {
                isSpeaking = false;
                doll.classList.remove('talking');
            };
            utterance.onerror = function() {
                isSpeaking = false;
                doll.classList.remove('talking');
            };
            window.speechSynthesis.speak(utterance);
        }

        function speakWithDoll(text, callback) {
            if (isMuted || !text) {
                if (callback) callback();
                return;
            }
            if (!('speechSynthesis' in window)) {
                if (callback) callback();
                return;
            }

            // Remove any "I understood" text
            let cleanText = stripEmojis(text);
            cleanText = cleanText.replace(/I understood:.*?(?=\.|$)/gi, '').trim();
            cleanText = cleanText.replace(/<[^>]*>/g, '').trim();
            
            if (!cleanText) {
                if (callback) callback();
                return;
            }

            const shortText = cleanText.length > 35 ? cleanText.substring(0, 35) + '...' : cleanText;
            showDollBubble(shortText);
            makeDollTalk();

            window.speechSynthesis.cancel();
            
            const utterance = new SpeechSynthesisUtterance(cleanText);
            utterance.rate = 0.85;
            utterance.pitch = 1.1;
            utterance.volume = 1;

            const voices = window.speechSynthesis.getVoices();
            const femaleVoice = voices.find(v =>
                v.name.includes('Female') ||
                v.name.includes('Google UK English Female') ||
                v.name.includes('Samantha') ||
                v.name.includes('Victoria')
            );
            if (femaleVoice) utterance.voice = femaleVoice;

            const doll = document.getElementById('doll');
            utterance.onstart = function() {
                isSpeaking = true;
                doll.classList.add('talking');
            };
            utterance.onend = function() {
                isSpeaking = false;
                doll.classList.remove('talking');
                if (callback) callback();
            };
            utterance.onerror = function() {
                isSpeaking = false;
                doll.classList.remove('talking');
                if (callback) callback();
            };
            window.speechSynthesis.speak(utterance);
        }

        function toggleTalk() {
            const doll = document.getElementById('doll');
            if (!isSpeaking) {
                speakMainResponse();
            } else {
                window.speechSynthesis.cancel();
                doll.classList.remove('talking');
                isSpeaking = false;
            }
        }

        async function testAIConnection() {
            const statusEl = document.getElementById('aiStatus');
            try {
                statusEl.textContent = 'Testing...';
                statusEl.className = 'ai-status testing';
                const response = await fetch('github_ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=chat&message=Hello&entry_text=test'
                });
                const data = await response.json();
                if (data.success) {
                    statusEl.textContent = '✅ Online';
                    statusEl.className = 'ai-status online';
                    isAIReady = true;
                    addAIMessage('bot', '🌸 AI Connected! I\'m here to help you grow. 💕', false);
                } else {
                    throw new Error(data.message || 'Connection failed');
                }
            } catch (error) {
                statusEl.textContent = '❌ Offline (Using Fallback)';
                statusEl.className = 'ai-status offline';
                isAIReady = false;
                addAIMessage('bot', '🌸 I\'m here with my pre-programmed wisdom to help you! 💕', false);
            }
        }

        function addAIMessage(type, text, speak = true) {
            const container = document.getElementById('aiChatMessages');
            const time = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
            const div = document.createElement('div');
            div.className = 'ai-msg ' + type;
            const cleanText = text.replace(/<[^>]*>/g, '').trim();
            // Remove any "I understood" from AI messages as well
            const finalText = cleanText.replace(/I understood:.*?(?=\.|$)/gi, '').trim();
            div.innerHTML = finalText + ' <span class="time">' + time + '</span>';
            container.appendChild(div);
            container.scrollTop = container.scrollHeight;
            if (type === 'bot' && speak) { speakWithDoll(finalText); }
        }

        async function sendAIMessage() {
            const input = document.getElementById('aiChatInput');
            const btn = document.getElementById('aiSendBtn');
            const message = input.value.trim();
            if (!message) return;
            addAIMessage('user', '💬 ' + message);
            input.value = '';
            btn.disabled = true;
            btn.innerHTML = '⏳ Sending...';
            const typing = document.getElementById('typingIndicator');
            typing.classList.add('active');
            try {
                const response = await fetch('github_ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=chat&message=' + encodeURIComponent(message) + '&entry_text=' + encodeURIComponent(entryText)
                });
                const data = await response.json();
                typing.classList.remove('active');
                if (data.success && data.response) {
                    let responseText = data.response;
                    if (data.fallback) { responseText = '🌸 ' + responseText; }
                    addAIMessage('bot', responseText, true);
                } else {
                    throw new Error(data.message || 'Failed to get response');
                }
            } catch (error) {
                console.error('AI chat error:', error);
                typing.classList.remove('active');
                const fallbacks = [
                    "🌸 That's a great question! Remember, every small step counts. Keep going! 💕",
                    "💫 I believe in you! You are capable of amazing things. Trust yourself! 🌟",
                    "🌱 Thank you for sharing that with me. You're doing better than you think! 💖",
                    "🌸 Remember: You are worthy, you are enough, and you are loved. Never forget that. 💕"
                ];
                const fallbackMsg = fallbacks[Math.floor(Math.random() * fallbacks.length)];
                addAIMessage('bot', fallbackMsg, true);
            }
            btn.disabled = false;
            btn.innerHTML = '💬 Send';
        }

        document.getElementById('aiSendBtn').addEventListener('click', sendAIMessage);
        document.getElementById('aiChatInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); sendAIMessage(); }
        });
        document.getElementById('doll').addEventListener('click', toggleTalk);

        function loadVoices() {
            if ('speechSynthesis' in window) {
                window.speechSynthesis.getVoices();
                window.speechSynthesis.onvoiceschanged = function() { window.speechSynthesis.getVoices(); };
            }
        }

        loadVoices();
        initVoiceInput();
        setTimeout(function() { speakMainResponse(); }, 1500);
        setTimeout(function() { testAIConnection(); }, 2000);
        setTimeout(function() { showDollBubble('🌸 Hi ' + '<?= htmlspecialchars($fullname) ?>' + '!'); }, 800);

        console.log('🌸 Petal is talking to <?= htmlspecialchars($fullname) ?>!');
        console.log('📝 Main Response: <?= addslashes(substr($main_response_text, 0, 100)) ?>...');
        <?php if ($promise_completed): ?>
        console.log('🎉 Promise completed: <?= htmlspecialchars($completed_promise_text) ?>');
        <?php endif; ?>
        console.log('📝 User Entry: <?= addslashes($user_entry_text) ?>');
    </script>
</body>
</html>