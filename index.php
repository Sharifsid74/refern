<?php
// Bot configuration
define('BOT_TOKEN', getenv('BOT_TOKEN') ?: 'Place_Your_Token_Here');
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

// Set timezone
date_default_timezone_set('UTC');

// Error logging
function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
    error_log($message);
}

// Data management
function loadUsers() {
    try {
        if (!file_exists(USERS_FILE)) {
            file_put_contents(USERS_FILE, json_encode([]));
            chmod(USERS_FILE, 0664);
        }
        $data = file_get_contents(USERS_FILE);
        return json_decode($data, true) ?: [];
    } catch (Exception $e) {
        logError("Load users failed: " . $e->getMessage());
        return [];
    }
}

function saveUsers($users) {
    try {
        $result = file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
        if ($result === false) {
            throw new Exception("Failed to write to users file");
        }
        return true;
    } catch (Exception $e) {
        logError("Save users failed: " . $e->getMessage());
        return false;
    }
}

// Message sending with inline keyboard
function sendMessage($chat_id, $text, $keyboard = null) {
    try {
        $params = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode([
                'inline_keyboard' => $keyboard
            ]);
        }
        
        $url = API_URL . 'sendMessage?' . http_build_query($params);
        $response = file_get_contents($url);
        if ($response === false) {
            throw new Exception("Failed to send message to chat $chat_id");
        }
        return true;
    } catch (Exception $e) {
        logError("Send message failed: " . $e->getMessage());
        return false;
    }
}

// Main keyboard
function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

// Process commands and callbacks
function processUpdate($update) {
    $users = loadUsers();
    
    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');
        
        // Create new user if doesn't exist
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        if (strpos($text, '/start') === 0) {
            $parts = explode(' ', $text);
            $ref = $parts[1] ?? null;
            
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if (isset($user['ref_code']) && $user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals'] = ($users[$id]['referrals'] ?? 0) + 1;
                        $users[$id]['balance'] = ($users[$id]['balance'] ?? 0) + 50;
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }
            
            $msg = "Welcome to Earning Bot!\nEarn points, invite friends, and withdraw your earnings!\nYour referral code: <b>{$users[$chat_id]['ref_code']}</b>";
            sendMessage($chat_id, $msg, getMainKeyboard());
        }
        
    } elseif (isset($update['callback_query'])) {
        $chat_id = $update['callback_query']['message']['chat']['id'];
        $data = $update['callback_query']['data'];
        
        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }
        
        switch ($data) {
            case 'earn':
                $time_diff = time() - ($users[$chat_id]['last_earn'] ?? 0);
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Please wait $remaining seconds before earning again!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;
                
            case 'balance':
                $msg = "💳 Your Balance\nPoints: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;
                
            case 'leaderboard':
                $sorted = [];
                foreach ($users as $id => $user) {
                    $sorted[$id] = $user['balance'] ?? 0;
                }
                arsort($sorted);
                $top = array_slice($sorted, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;
                
            case 'referrals':
                $msg = "👥 Referral System\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nReferrals: {$users[$chat_id]['referrals']}\nInvite link: https://t.me/" . explode(':', BOT_TOKEN)[0] . "?start={$users[$chat_id]['ref_code']}\n50 points per referral!";
                break;
                
            case 'withdraw':
                $min = 100;
                $balance = $users[$chat_id]['balance'] ?? 0;
                if ($balance < $min) {
                    $msg = "🏧 Withdrawal\nMinimum: $min points\nYour balance: $balance\nNeed " . ($min - $balance) . " more points!";
                } else {
                    $amount = $balance;
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal of $amount points requested!\nOur team will process it soon.";
                }
                break;
                
            case 'help':
                $msg = "❓ Help\n💰 Earn: Get 10 points/min\n👥 Refer: 50 points/ref\n🏧 Withdraw: Min 100 points\nUse buttons below to navigate!";
                break;
                
            default:
                $msg = "❌ Unknown command";
        }
        
        sendMessage($chat_id, $msg, getMainKeyboard());
    }
    
    saveUsers($users);
}

// Webhook handler for Render.com
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $update = json_decode($input, true);
    
    if ($update) {
        processUpdate($update);
        http_response_code(200);
        echo "OK";
    } else {
        http_response_code(400);
        echo "Invalid update";
    }
    exit;
}

// Fallback to polling if webhook not set
function runBot() {
    $offset = 0;
    echo "Bot started in polling mode.\n";
    
    while (true) {
        try {
            $updates = file_get_contents(API_URL . "getUpdates?offset=$offset&timeout=30");
            $updates = json_decode($updates, true);
            
            if ($updates['ok'] && !empty($updates['result'])) {
                foreach ($updates['result'] as $update) {
                    $offset = $update['update_id'] + 1;
                    processUpdate($update);
                }
            }
            
            usleep(100000);
            
        } catch (Exception $e) {
            logError("Polling error: " . $e->getMessage());
            sleep(1);
        }
    }
}

// Start bot in polling mode if running via CLI
if (php_sapi_name() === 'cli') {
    try {
        runBot();
    } catch (Exception $e) {
        logError("Fatal error: " . $e->getMessage());
        echo "Bot crashed. Check error.log.\n";
    }
} else {
    // Web interface
    echo "<h1>Telegram Bot is Running</h1>";
    echo "<p>Bot is ready to receive webhook requests.</p>";
}
?>