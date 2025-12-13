<?php
require 'vendor/autoload.php';
require_once 'config.php';

use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\Response;

$channel_access_token = '58QwghlGu6I3kyXksd3+E2ewf71ug3hmDzhOtMVEr/mVo19FsmOP9PiNsijP85SQsRa7C61dHAJJ+8ssCxGPs8XjPM+sdK++0OM+S3JWKWB+wlGecp0ZL72eZY3ljFSlJsc81S5EEJxuO2d+w7XqwwdB04t89/1O/w1cDnyilFU=';
$channel_secret = 'd120efb76cb1e2f5465e68b97e96f5af';

const GOOGLE_WEB_RISK_API_KEY_CONST = GOOGLE_WEB_RISK_API_KEY;
const USER_STATE_FILE = 'user_states.json';

$httpClient = new CurlHTTPClient($channel_access_token);
$bot = new LINEBot($httpClient, ['channelSecret' => $channel_secret]);
$dataBot = new LINEBot($httpClient, ['channelSecret' => $channel_secret, 'endpointBase' => 'https://api-data.line.me']);

function getContentWithRetry(LINEBot $dataBot, string $messageId): Response {
    $response = $dataBot->getMessageContent($messageId);
    if ($response->getHTTPStatus() === 202) {
        sleep(2);
        $response = $dataBot->getMessageContent($messageId);
    }
    return $response;
}

function make_curl_request_with_files(string $url, array $postData = []): string|false {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    $headers = ['ngrok-skip-browser-warning: true'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    }
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { ($ch); return false; }
    ($ch);
    if ($http_status === 200) { return $response; }
    return false;
}

function make_curl_request(string $url, array $postData = []): string|false {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600); 
    $headers = ['ngrok-skip-browser-warning: true'];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (!empty($postData)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (curl_errno($ch)) { ($ch); return false; }
    ($ch);
    if ($http_status === 200) { return $response; }
    return false;
}

function has_image_trigger(string $message): bool {
    $normalizedMessage = mb_strtolower(str_replace(['？', '?'], '', $message));
    if (mb_strlen($normalizedMessage) > 20) { return false; }
    $commandVerbs = ['分析', '偵測', '查', '幫我分析', '幫我偵測', '幫我查'];
    $isCommand = false;
    foreach ($commandVerbs as $verb) { if (mb_strpos($normalizedMessage, $verb) === 0) { $isCommand = true; break; } }
    if (mb_strpos($normalizedMessage, '是不是ai合成') !== false && mb_strpos($normalizedMessage, '影片') === false && mb_strpos($normalizedMessage, '錄音') === false) { $isCommand = true; }
    if (!$isCommand) { return false; }
    $subjectKeywords = ['圖片', '照片', '圖'];
    foreach ($subjectKeywords as $keyword) { if (mb_strpos($normalizedMessage, $keyword) !== false) { return true; } }
    return false;
}

function has_video_trigger(string $message): bool {
    $normalizedMessage = mb_strtolower(str_replace(['？', '?'], '', $message));
    if (mb_strlen($normalizedMessage) > 20) { return false; }
    $commandVerbs = ['分析', '偵測', '查', '幫我分析', '幫我偵測', '幫我查'];
    $isCommand = false;
    foreach ($commandVerbs as $verb) { if (mb_strpos($normalizedMessage, $verb) === 0) { $isCommand = true; break; } }
    if (!$isCommand) { return false; }
    $subjectKeywords = ['影片', 'yt', 'youtube'];
    foreach ($subjectKeywords as $keyword) { if (mb_strpos($normalizedMessage, $keyword) !== false) { return true; } }
    return false;
}

function should_process_fact_check(string $message): bool { 
    $triggers = ['查一下', '查一下，', '查一下,']; 
    foreach ($triggers as $trigger) { 
        if (mb_strpos($message, $trigger) === 0) return true; 
    } 
    return false; 
}

function cleanup_message_for_query(string $message): string { 
    $triggers = ['查一下，', '查一下,', '查一下']; 
    foreach ($triggers as $trigger) { 
        if (mb_strpos($message, $trigger) === 0) return trim(mb_substr($message, mb_strlen($trigger))); 
    } 
    return $message; 
}

function is_youtube_url(string $text): ?string { $pattern = '/(https?:\/\/(?:www\.)?(?:youtube\.com|youtu\.be)\/[^\s]+)/'; if (preg_match($pattern, $text, $matches)) { return $matches[0]; } return null; }
function setUserState(string $userId, string $state): void { $states = file_exists(USER_STATE_FILE) ? json_decode(file_get_contents(USER_STATE_FILE), true) : []; $states[$userId] = $state; file_put_contents(USER_STATE_FILE, json_encode($states, JSON_PRETTY_PRINT)); }
function getUserState(string $userId): ?string { if (!file_exists(USER_STATE_FILE)) return null; $states = json_decode(file_get_contents(USER_STATE_FILE), true); return $states[$userId] ?? null; }
function clearUserState(string $userId): void { $states = file_exists(USER_STATE_FILE) ? json_decode(file_get_contents(USER_STATE_FILE), true) : []; if (isset($states[$userId])) { unset($states[$userId]); file_put_contents(USER_STATE_FILE, json_encode($states, JSON_PRETTY_PRINT)); } }
function check_url_existence(string $url): bool { $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_NOBODY, true); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_exec($ch); if (curl_errno($ch)) { ($ch); return false; } $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); return ($http_code < 400); }
function check_url_safety(string $url, string $apiKey): array { $queryParams = http_build_query(['key' => $apiKey, 'uri' => $url]); $threatTypes = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']; foreach ($threatTypes as $type) { $queryParams .= '&threatTypes=' . urlencode($type); } $apiUrl = 'https://webrisk.googleapis.com/v1/uris:search?' . $queryParams; $response = make_curl_request($apiUrl); if ($response === false) { return ['error' => '無法連接至 Google Web Risk API。']; } $data = json_decode($response, true); if (isset($data['error'])) { return ['error' => $data['error']['message']]; } if (isset($data['threat'])) { return ['safe' => false, 'threat_type' => $data['threat']['threatTypes'][0] ?? 'UNKNOWN']; } return ['safe' => true]; }

// --- 處理熱門議題的函式 ---
function handle_hot_topics_response(string|false $apiResponse, string $targetId, LINEBot $bot): void {
    if ($apiResponse === false) {
        $bot->pushMessage($targetId, new TextMessageBuilder("無法連接至資料庫取得熱門議題。"));
        return;
    }
    
    $data = json_decode($apiResponse, true);
    if (!is_array($data) || isset($data['error'])) {
        $bot->pushMessage($targetId, new TextMessageBuilder("讀取錯誤：" . ($data['error'] ?? '未知')));
        return;
    }

    if (!isset($data['hot_topics']) || empty($data['hot_topics'])) {
        $bot->pushMessage($targetId, new TextMessageBuilder("目前沒有熱門查核資料。"));
        return;
    }

    $msg = "🔥 最近 10 則熱門查核議題：\n";
    $i = 1;
    foreach ($data['hot_topics'] as $topic) {
        // 簡短顯示標題，避免訊息太長
        $text = mb_substr($topic['claim_text'], 0, 30) . '...';
        $rating = $topic['rating'];
        $score = $topic['reliability_score'] ?? -1;
        
        $msg .= "\n{$i}. [{$rating}] {$text}";
        if ($score !== -1) {
             // 簡單顯示分數
             $msg .= " (可信度:{$score}%)";
        }
        $i++;
    }
    $msg .= "\n\n💡 提示：輸入「查一下 + 關鍵字」可搜尋更多細節。";

    $bot->pushMessage($targetId, new TextMessageBuilder($msg));
}
// ------------------------------

// --- 修改：移除 Deepfake，只顯示 AI 生成 ---
function handle_image_analysis_response(string|false $apiResponse, string $targetId, LINEBot $bot): void {
    if ($apiResponse === false) {
        $bot->pushMessage($targetId, new TextMessageBuilder("抱歉，圖片偵測服務暫時無法連線。"));
        return;
    }
    
    $data = json_decode($apiResponse, true);
    if (!is_array($data) || isset($data['error'])) {
        $bot->pushMessage($targetId, new TextMessageBuilder("圖片分析錯誤：" . ($data['error'] ?? '未知錯誤')));
        return;
    }

    $ai = $data['ai_detection'] ?? [];
    $g_score = $ai['general_ai_score'] ?? 0;
    $g_pct = round($g_score * 100, 1);

    $msg = "🖼️ 圖片分析結果：\n\n";
    $msg .= "🤖 AI 生成可能性 (AIGC): {$g_pct}%\n\n";
    
    if ($g_score > 0.5) $msg .= "⚠️ 警告：極高機率為 AI 生成圖像！\n";
    else $msg .= "✅ 判斷為真實/手繪影像。\n";

    $factData = $data['fact_check'] ?? null;
    if ($factData && !empty($factData['claims'])) {
        $msg .= "\n---\n🔍 文字查核結果：\n";
        $claim = $factData['claims'][0];
        $msg .= "評等：「{$claim['claimReview'][0]['textualRating']}」\n";
        
        $relScore = $claim['reliability_score'] ?? -1;
        $relLabel = $claim['risk_label'] ?? '';
        if ($relScore !== -1) {
            $msg .= "📊 可信度：{$relScore}% - {$relLabel}\n";
        }
        
        $msg .= "連結：{$claim['claimReview'][0]['url']}";
    }

    $bot->pushMessage($targetId, new TextMessageBuilder($msg));
}

// --- 修改：移除 Deepfake，只顯示 AI 生成 ---
function handle_video_analysis_response(string|false $apiResponse, string $targetId, LINEBot $bot): void {
    if ($apiResponse === false) {
        $bot->pushMessage($targetId, new TextMessageBuilder("抱歉，影片偵測服務暫時無法連線。"));
        return;
    }

    $data = json_decode($apiResponse, true);
    if (!is_array($data) || isset($data['error'])) {
        $bot->pushMessage($targetId, new TextMessageBuilder("影片分析錯誤：" . ($data['error'] ?? '未知錯誤')));
        return;
    }

    $g_score = $data['general_ai_score'] ?? 0;
    $g_pct = round($g_score * 100, 1);

    $msg = "🎬 影片分析結果：\n\n";
    $msg .= "🤖 AI 生成指數: {$g_pct}%\n";
    
    if ($g_score > 0.5) {
        $msg .= "\n⚠️ 結論：疑似 AI 生成影片。";
    } else {
        $msg .= "\n✅ 結論：未偵測到明顯 AI 特徵。";
    }
    
    $bot->pushMessage($targetId, new TextMessageBuilder($msg));
}

$input = file_get_contents('php://input');
$events = json_decode($input, true);

if (is_array($events) && !empty($events['events'])) {
    foreach ($events['events'] as $event) {
        if ($event['type'] == 'message') {
            $replyToken = $event['replyToken'];
            $source = $event['source'];
            $userId = $source['userId'];
            $apiUrl = 'https://b37a56729b1a.ngrok-free.app/api.php';
            $userState = getUserState($userId);
            $targetId = isset($source['groupId']) ? $source['groupId'] : $userId;

            if ($event['message']['type'] === 'image' && $userState === 'awaiting_image') {
                $bot->replyText($replyToken, '收到圖片，正在分析...'); clearUserState($userId);
                $response = getContentWithRetry($dataBot, $event['message']['id']);
                if ($response->isSucceeded()) {
                    $tempDir = 'uploads/';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    $tempFilePath = $tempDir . uniqid('line_img_', true) . '.jpg';
                    file_put_contents($tempFilePath, $response->getRawBody());
                    $postData = ['action' => 'detect_image', 'image_file' => new CURLFile($tempFilePath)];
                    $apiResponse = make_curl_request_with_files($apiUrl, $postData);
                    if (file_exists($tempFilePath)) unlink($tempFilePath);
                    handle_image_analysis_response($apiResponse, $targetId, $bot);
                }
                continue;
            }
            
            if ($event['message']['type'] === 'video' && $userState === 'awaiting_video') {
                $bot->replyText($replyToken, '收到影片，正在分析...'); clearUserState($userId);
                $response = getContentWithRetry($dataBot, $event['message']['id']);
                if ($response->isSucceeded()) {
                    $tempDir = 'uploads/';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    $tempFilePath = $tempDir . uniqid('line_vid_', true) . '.mp4';
                    file_put_contents($tempFilePath, $response->getRawBody());
                    $postData = ['action' => 'detect_video', 'video_file' => new CURLFile($tempFilePath)];
                    $apiResponse = make_curl_request_with_files($apiUrl, $postData);
                    if (file_exists($tempFilePath)) unlink($tempFilePath);
                    handle_video_analysis_response($apiResponse, $targetId, $bot);
                }
                continue;
            }
            
            if ($event['message']['type'] === 'text') {
                $userMessage = $event['message']['text'];
                $trimmedUserMessage = trim($userMessage);

                if ($userState !== null && $trimmedUserMessage === '取消') {
                    clearUserState($userId);
                    $bot->replyText($replyToken, '已取消。');
                    continue;
                }

                if ($trimmedUserMessage === '測試' || $trimmedUserMessage === '測試!') {
                    $bot->replyText($replyToken, '機器人已連線成功!');
                    continue;
                }
                if ($trimmedUserMessage === '網站') {
                    $bot->replyText($replyToken, 'https://b37a56729b1a.ngrok-free.app/');
                    continue;
                }
                if (has_image_trigger($userMessage)) {
                    setUserState($userId, 'awaiting_image');
                    $bot->replyText($replyToken, '請傳送圖片。或打「取消」來取消動作。');
                    continue;
                }
                
                if (has_video_trigger($userMessage)) {
                    setUserState($userId, 'awaiting_video');
                    $bot->replyText($replyToken, '請傳送影片或 YouTube 連結。或打「取消」來取消動作。');
                    continue;
                }

                if ($userState === 'awaiting_video') {
                    $ytUrl = is_youtube_url($userMessage);
                    if ($ytUrl) {
                        clearUserState($userId);
                        $bot->replyText($replyToken, '收到 YouTube 連結，正在分析...');
                        $postData = ['action' => 'detect_yt_video', 'video_url' => $ytUrl];
                        $apiResponse = make_curl_request($apiUrl, $postData);
                        handle_video_analysis_response($apiResponse, $targetId, $bot);
                    }
                    continue;
                }

                if ($trimmedUserMessage === '熱門議題' || $trimmedUserMessage === '熱門搜尋' || $trimmedUserMessage === '熱門') {
                    $bot->replyText($replyToken, '正在獲取熱門查核資料...');
                    $postData = ['action' => 'get_hot_searches'];
                    $apiResponse = make_curl_request($apiUrl, $postData);
                    handle_hot_topics_response($apiResponse, $targetId, $bot);
                    continue;
                }
                
                if (should_process_fact_check($userMessage)) {
                    $query = cleanup_message_for_query($userMessage);
                    
                    if (empty($query)) {
                        $bot->replyText($replyToken, "您輸入了「查一下」但未指定內容，以下是最近的熱門議題：");
                        $postData = ['action' => 'get_hot_searches'];
                        $apiResponse = make_curl_request($apiUrl, $postData);
                        handle_hot_topics_response($apiResponse, $targetId, $bot);
                        continue;
                    }
                    
                    $postData = ['action' => 'search', 'query' => $query];
                    $apiResponse = make_curl_request($apiUrl, $postData);
                    
                    if ($apiResponse === false) {
                        $bot->replyText($replyToken, '查核伺服器連線失敗。');
                        continue;
                    }
                    
                    $data = json_decode($apiResponse, true);
                    
                    if (isset($data['claims']) && is_array($data['claims']) && count($data['claims']) > 0) {
                        $replyMsg = "🔍 關於「{$query}」的查核結果：\n";
                        $count = 0;
                        foreach ($data['claims'] as $claim) {
                            if ($count >= 3) break;
                            
                            $title = $claim['text'] ?? '未知內容';
                            $rating = $claim['claimReview'][0]['textualRating'] ?? '未評等';
                            $url = $claim['claimReview'][0]['url'] ?? '';
                            
                            $relScore = $claim['reliability_score'] ?? -1;
                            $relLabel = $claim['risk_label'] ?? '';

                            $replyMsg .= "\n----------------\n";
                            $replyMsg .= "📢 陳述：{$title}\n";
                            $replyMsg .= "⚖️ 評等：{$rating}\n";
                            if ($relScore !== -1) {
                                $replyMsg .= "📊 可信度：{$relScore}% - {$relLabel}\n";
                            }
                            $replyMsg .= "🔗 詳情：{$url}\n";
                            $count++;
                        }
                    } else {
                        $replyMsg = "目前找不到關於「{$query}」的相關查核報告。";
                    }
                    
                    $bot->replyText($replyToken, $replyMsg);
                    continue;
                }

                // 網址檢查
                preg_match('/(https?:\/\/[^\s]+)/', $userMessage, $matches);
                if (isset($matches[0])) {
                    $urlToCheck = $matches[0];
                    if (!is_youtube_url($urlToCheck)) {
                        $safetyResult = check_url_safety($urlToCheck, GOOGLE_WEB_RISK_API_KEY_CONST);
                        $msg = isset($safetyResult['safe']) && $safetyResult['safe'] ? "✅ 網址安全" : "🚨 危險網址";
                        $bot->replyText($replyToken, $msg);
                    }
                    continue;
                }
            }
        }
    }
}
echo 'OK';
?>