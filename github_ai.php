<?php
// ============================================================
//  GITHUB_AI.PHP - SMART AI CHAT WITH DATABASE FALLBACK
//  Handles: greetings, appreciation, farewells, emotions, and more
// ============================================================

session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first.']);
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
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit();
}

// ============================================================
//  EMOJI MAPPING (SAME AS RESULTS.PHP)
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
//  SMART INTENT DETECTION
// ============================================================
function detectIntent($text) {
    $text_lower = strtolower($text);
    
    // ===== GREETINGS =====
    $greetings = ['hello', 'hi', 'hey', 'good morning', 'good afternoon', 'good evening', 'howdy', 'yo', 'sup', 'what\'s up', 'how are you', 'morning', 'evening', 'hey there'];
    foreach ($greetings as $g) {
        if (strpos($text_lower, $g) !== false) {
            return 'greeting';
        }
    }
    
    // ===== APPRECIATION / THANK YOU =====
    $appreciation = ['thank', 'thanks', 'thx', 'appreciate', 'grateful', 'thankful', 'ty', 'much obliged', 'you\'re the best', 'you are the best'];
    foreach ($appreciation as $a) {
        if (strpos($text_lower, $a) !== false) {
            return 'appreciation';
        }
    }
    
    // ===== FAREWELLS =====
    $farewells = ['bye', 'goodbye', 'see you', 'later', 'cya', 'catch you later', 'good night', 'goodnight', 'take care', 'farewell'];
    foreach ($farewells as $f) {
        if (strpos($text_lower, $f) !== false) {
            return 'farewell';
        }
    }
    
    // ===== POSITIVE EMOTIONS =====
    $positive = ['happy', 'joy', 'great', 'wonderful', 'amazing', 'excellent', 'fantastic', 'awesome', 'love', 'glad', 'pleased', 'excited'];
    foreach ($positive as $p) {
        if (strpos($text_lower, $p) !== false) {
            return 'positive';
        }
    }
    
    // ===== NEGATIVE EMOTIONS =====
    $negative = ['sad', 'cry', 'depress', 'lonely', 'anxious', 'worried', 'scared', 'afraid', 'stressed', 'overwhelmed', 'upset', 'angry', 'frustrated'];
    foreach ($negative as $n) {
        if (strpos($text_lower, $n) !== false) {
            return 'negative';
        }
    }
    
    // ===== CONFUSION =====
    $confusion = ['confused', 'lost', 'unsure', 'don\'t know', 'not sure', 'unclear', 'what', 'huh', 'i don\'t understand'];
    foreach ($confusion as $c) {
        if (strpos($text_lower, $c) !== false) {
            return 'confusion';
        }
    }
    
    // ===== MOTIVATION / ENCOURAGEMENT =====
    $motivation = ['motivate', 'encourage', 'inspire', 'need a boost', 'feeling down', 'help me', 'i need', 'support', 'believe in me'];
    foreach ($motivation as $m) {
        if (strpos($text_lower, $m) !== false) {
            return 'motivation_needed';
        }
    }
    
    return 'general';
}

// ============================================================
//  GENERATE RESPONSES BY INTENT
// ============================================================
function getIntentResponse($intent, $fullname) {
    $responses = [
        'greeting' => [
            "🌸 Hello $fullname! It's so wonderful to see you! How are you feeling today? 💕",
            "🌷 Hi $fullname! I'm so glad you're here! What's on your mind? 🌟",
            "💫 Hey $fullname! You make my day brighter just by being here! How can I help you? 🌸",
            "🌻 Good to see you $fullname! I hope you're having a beautiful day! What would you like to talk about? 💕",
            "🌸 $fullname! You're back! I was hoping you'd come. What's on your heart today? 💖"
        ],
        'appreciation' => [
            "🌸 You are so welcome, $fullname! Your kindness means the world to me. You are truly special! 💕",
            "💫 Thank YOU, $fullname! You are the reason I'm here. Your gratitude warms my heart! 🌸",
            "🌟 You're so sweet, $fullname! Never forget how much you are loved and appreciated! 💕",
            "🌷 It's my absolute pleasure, $fullname! You deserve all the love and kindness in the world! 💖",
            "💕 You are a beautiful soul, $fullname! Thank you for being YOU! 🌸"
        ],
        'farewell' => [
            "🌸 Goodbye, $fullname! Take care of yourself and remember: you are amazing! Come back soon! 💕",
            "🌙 Goodnight, $fullname! Sleep well and dream big! I'll be here when you return! 💫",
            "💕 See you later, $fullname! Stay safe and keep shining bright! 🌸",
            "🌟 Until next time, $fullname! You are always welcome here! 💕",
            "🌸 Take care, $fullname! You are stronger than you know. I believe in you! 💖"
        ],
        'positive' => [
            "🌟 That's wonderful to hear, $fullname! Your happiness is contagious! Keep spreading that joy! 💕",
            "🌸 I'm so happy for you, $fullname! You deserve every bit of this joy! 🎉",
            "💫 You are radiating such beautiful energy, $fullname! Keep shining bright! 🌸",
            "🌷 This makes my heart so happy, $fullname! You are doing amazing things! 💕",
            "🎉 Yay, $fullname! Your joy is my joy! Never stop being you! 🌟"
        ],
        'negative' => [
            "🌧️ I hear you, $fullname. It's okay to feel this way. You are not alone. I'm here with you. 💕",
            "💫 Sending you so much love, $fullname. You are stronger than you know. You will get through this. 🌸",
            "🌱 Be gentle with yourself, $fullname. You are doing the best you can, and that is enough. 💖",
            "💕 I'm here with you, $fullname. This moment is not your forever. Brighter days are ahead. 🌸",
            "🌸 You are so brave, $fullname. Even on hard days, you are still standing. I believe in you. 💪"
        ],
        'confusion' => [
            "🌸 That's okay, $fullname. It's okay to feel confused. We can figure this out together. 💕",
            "💫 Take your time, $fullname. Sometimes things take a moment to make sense. I'm here to help! 🌸",
            "🌷 It's okay to be unsure, $fullname. What's on your mind? Let's talk it through. 💖",
            "🌟 You don't have to have all the answers, $fullname. Just taking one step at a time is enough. 💕"
        ],
        'motivation_needed' => [
            "💪 You are stronger than you think, $fullname! I believe in you with my whole heart! 🌟",
            "🌸 You are capable of amazing things, $fullname! Never doubt your own power! 💕",
            "💫 $fullname, you are a force of nature! Keep going, keep growing, keep shining! 🌸",
            "🌟 You've got this, $fullname! I believe in you, and you should believe in yourself too! 💕",
            "💕 Remember, $fullname: every great journey begins with a single step. You've already taken yours! 🌸"
        ],
        'general' => [
            "🌸 That's a beautiful thought, $fullname! Tell me more about it. I'm listening. 💕",
            "💫 I love how you think, $fullname! You are so wise and wonderful! 🌸",
            "🌟 $fullname, you are such a special person! Keep sharing your beautiful heart! 💕",
            "🌷 You amaze me, $fullname! Your words always inspire me! 💖",
            "💕 I'm so grateful to know you, $fullname! You make the world a better place! 🌸"
        ]
    ];
    
    $list = $responses[$intent] ?? $responses['general'];
    return $list[array_rand($list)];
}

// ============================================================
//  ANALYZE TEXT FROM DATABASE (SAME AS RESULTS.PHP)
// ============================================================
function analyzeTextFromDB($text, $conn) {
    if (empty($text)) return null;
    
    $text_lower = strtolower($text);
    $matched_responses = [];
    $matched_keywords = [];
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
        $selected = array_slice($matched_responses, 0, 3);
        return [
            'responses' => $selected,
            'matched_keywords' => $matched_keywords
        ];
    }
    
    if (!empty($fallback_responses)) {
        shuffle($fallback_responses);
        $selected = array_slice($fallback_responses, 0, 2);
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
            'matched_keywords' => []
        ];
    }
    
    return null;
}

// ============================================================
//  GET ACTION
// ============================================================
$action = $_POST['action'] ?? '';

if ($action === 'chat') {
    $message = $_POST['message'] ?? '';
    $entry_text = $_POST['entry_text'] ?? '';
    
    if (empty($message) && empty($entry_text)) {
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit();
    }
    
    $text_to_analyze = !empty($message) ? $message : $entry_text;
    
    // ===== STEP 1: Detect Intent =====
    $intent = detectIntent($text_to_analyze);
    
    // ===== STEP 2: If it's a greeting, appreciation, or farewell, return directly =====
    if (in_array($intent, ['greeting', 'appreciation', 'farewell'])) {
        $response = getIntentResponse($intent, $fullname);
        echo json_encode([
            'success' => true,
            'response' => $response,
            'from' => 'intent'
        ]);
        exit();
    }
    
    // ===== STEP 3: Try to match from database =====
    $analysis = analyzeTextFromDB($text_to_analyze, $conn);
    
    if ($analysis && !empty($analysis['responses'])) {
        $response_parts = [];
        foreach ($analysis['responses'] as $r) {
            $response_parts[] = $r['emoji'] . ' ' . $r['text'];
        }
        $final_response = implode(' ', $response_parts);
        
        // Add an appropriate follow-up based on intent
        if ($intent === 'positive') {
            $final_response .= ' 🌟 Keep shining, ' . $fullname . '! 💕';
        } elseif ($intent === 'negative') {
            $final_response .= ' 🌸 I believe in you, ' . $fullname . '. You are stronger than you think. 💕';
        } elseif ($intent === 'confusion') {
            $final_response .= ' 🌷 We can figure this out together, ' . $fullname . '. I\'m here for you. 💕';
        } elseif ($intent === 'motivation_needed') {
            $final_response .= ' 💪 You\'ve got this, ' . $fullname . '! I believe in you! 🌟';
        } else {
            $final_response .= ' 🌸 Remember: You are worthy, you are enough, and you are loved. 💕';
        }
        
        echo json_encode([
            'success' => true,
            'response' => $final_response,
            'from' => 'database'
        ]);
        exit();
    }
    
    // ===== STEP 4: Use intent-based response if database has no match =====
    if ($intent !== 'general') {
        $response = getIntentResponse($intent, $fullname);
        echo json_encode([
            'success' => true,
            'response' => $response,
            'from' => 'intent'
        ]);
        exit();
    }
    
    // ===== STEP 5: Try GitHub API (if available) =====
    $GITHUB_TOKEN = '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';
    
    try {
        $url = 'https://api.github.com/chat/completions';
        
        $prompt = "You are Petal, a warm, encouraging, and empathetic AI companion. The user's name is $fullname.\n\n";
        $prompt .= "The user just said: \"$text_to_analyze\"\n\n";
        $prompt .= "Respond with warmth, empathy, and practical encouragement. Keep your response warm, personal, and conversational (2-3 sentences). End with: 'What good thing do you want to do today? Say it, commit to it, and follow through.'";
        
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are Petal, a warm, encouraging, and empathetic AI companion. You help people grow, reflect, and stay motivated.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.8,
            'max_tokens' => 300
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['choices'][0]['message']['content'])) {
                $ai_response = $data['choices'][0]['message']['content'];
                echo json_encode([
                    'success' => true,
                    'response' => $ai_response,
                    'from' => 'github_ai'
                ]);
                exit();
            }
        }
    } catch (Exception $e) {
        // Fall through to final fallback
    }
    
    // ===== STEP 6: Ultimate fallback =====
    $fallback_result = $conn->query("SELECT response_text, response_type, tone FROM petal_responses WHERE response_type = 'motivation' ORDER BY RAND() LIMIT 2");
    $fallback_parts = [];
    while ($row = $fallback_result->fetch_assoc()) {
        $emoji = getEmojiForResponse($row['response_type'], $row['tone']);
        $fallback_parts[] = $emoji . ' ' . $row['response_text'];
    }
    
    if (empty($fallback_parts)) {
        $fallback_parts = [
            "🌸 " . $fullname . ", I hear you. Every step you take matters. Keep going! 💕",
            "💫 " . $fullname . ", you are capable of amazing things. Trust yourself! 🌟",
            "🌱 " . $fullname . ", remember: you are worthy, you are enough, and you are loved. 💖"
        ];
    }
    
    $final_fallback = implode(' ', $fallback_parts) . ' 🌸 What good thing do you want to do today? Say it, commit to it, and follow through. 💕';
    
    echo json_encode([
        'success' => true,
        'response' => $final_fallback,
        'from' => 'fallback'
    ]);
    exit();
}

$conn->close();
?>