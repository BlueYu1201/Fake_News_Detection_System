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

function should_process_fact_check(string $message): bool { $triggers = ['查一下', '查一下，']; foreach ($triggers as $trigger) { if (mb_strpos($message, $trigger) === 0) return true; } return false; }
function cleanup_message_for_query(string $message): string { $triggers = ['查一下，', '查一下']; foreach ($triggers as $trigger) { if (mb_strpos($message, $trigger) === 0) return trim(mb_substr($message, mb_strlen($trigger))); } return $message; }
function is_youtube_url(string $text): ?string { $pattern = '/(https?:\/\/(?:www\.)?(?:youtube\.com|youtu\.be)\/[^\s]+)/'; if (preg_match($pattern, $text, $matches)) { return $matches[0]; } return null; }
function setUserState(string $userId, string $state): void { $states = file_exists(USER_STATE_FILE) ? json_decode(file_get_contents(USER_STATE_FILE), true) : []; $states[$userId] = $state; file_put_contents(USER_STATE_FILE, json_encode($states, JSON_PRETTY_PRINT)); }
function getUserState(string $userId): ?string { if (!file_exists(USER_STATE_FILE)) return null; $states = json_decode(file_get_contents(USER_STATE_FILE), true); return $states[$userId] ?? null; }
function clearUserState(string $userId): void { $states = file_exists(USER_STATE_FILE) ? json_decode(file_get_contents(USER_STATE_FILE), true) : []; if (isset($states[$userId])) { unset($states[$userId]); file_put_contents(USER_STATE_FILE, json_encode($states, JSON_PRETTY_PRINT)); } }
function check_url_existence(string $url): bool { $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_NOBODY, true); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_exec($ch); if (curl_errno($ch)) { ($ch); return false; } $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); return ($http_code < 400); }
function check_url_safety(string $url, string $apiKey): array { $queryParams = http_build_query(['key' => $apiKey, 'uri' => $url]); $threatTypes = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']; foreach ($threatTypes as $type) { $queryParams .= '&threatTypes=' . urlencode($type); } $apiUrl = 'https://webrisk.googleapis.com/v1/uris:search?' . $queryParams; $response = make_curl_request($apiUrl); if ($response === false) { return ['error' => '無法連接至 Google Web Risk API。']; } $data = json_decode($response, true); if (isset($data['error'])) { return ['error' => $data['error']['message']]; } if (isset($data['threat'])) { return ['safe' => false, 'threat_type' => $data['threat']['threatTypes'][0] ?? 'UNKNOWN']; } return ['safe' => true]; }
function format_probability(float $prob): string { $percentage = round($prob * 100); if ($percentage > 75) return "🚨 高風險 ({$percentage}%)"; if ($percentage > 40) return "⚠️ 中風險 ({$percentage}%)"; return "✅ 低風險 ({$percentage}%)"; }

function handle_image_analysis_response(string|false $apiResponse, string $targetId, LINEBot $bot): void {
    if ($apiResponse === false) {
        $bot->pushMessage($targetId, new TextMessageBuilder("抱歉，圖片偵測服務暫時無法連線。"));
        return;
    }
    
    $data = json_decode($apiResponse, true);
    if (!is_array($data)) {
        $bot->pushMessage($targetId, new TextMessageBuilder("圖片分析服務回傳格式錯誤。"));
        return;
    }

    $ai_detection_message = "🖼️ AI 圖片分析結果：\n\n";
    $aiData = $data['ai_detection'] ?? null;
    if (!$aiData || isset($aiData['error'])) {
        $ai_detection_message .= "AI 生成偵測失敗: " . ($aiData['error'] ?? '未知錯誤');
    } elseif (isset($aiData['status']) && $aiData['status'] === 'success') {
        $result = $aiData['result'];
        $confidence = round($result['confidence'] * 100);
        if (strtolower($result['label']) === 'ai/deepfake' || strtolower($result['label']) === 'ai') {
            $ai_detection_message .= "判斷結果：AI 生成 🤖\n(有 {$confidence}% 的機率是由 AI 生成)";
        } else {
            $ai_detection_message .= "判斷結果：真人創作 ✅\n(有 {$confidence}% 的機率為真人創作)";
        }
    } else {
        $ai_detection_message .= "分析圖片 AI 生成可能性時發生未知錯誤。";
    }

    $fact_check_message = '';
    $factData = $data['fact_check'] ?? null;
    if ($factData && !isset($factData['error']) && !empty($factData['claims'])) {
        $fact_check_message .= "\n\n---\n\n";
        $fact_check_message .= "🔍 圖片內文字查核結果：\n\n";
        if (!empty($factData['extracted_text'])) {
             $fact_check_message .= "辨識文字: 「" . mb_strimwidth($factData['extracted_text'], 0, 80, "...") . "」\n\n";
        }
        $claim = $factData['claims'][0];
        $review = $claim['claimReview'][0];
        $fact_check_message .= "相關陳述評等為「{$review['textualRating']}」\n";
        if (!empty($review['url'])) {
            $fact_check_message .= "🔗 詳情: {$review['url']}";
        }
    } elseif ($factData && empty($factData['claims']) && !empty($factData['extracted_text'])) {
        $fact_check_message .= "\n\n---\n\n🔍 圖片內文字查核結果：\n圖片內文字未找到相關的查核報告。";
    } elseif (isset($factData['error'])) {
        $fact_check_message .= "\n\n---\n\n🔍 圖片內文字查核結果：\n服務錯誤({$factData['error']})";
    }
    
    $finalMessage = trim($ai_detection_message . $fact_check_message);
    $bot->pushMessage($targetId, new TextMessageBuilder($finalMessage));
}

function handle_video_analysis_response(string|false $apiResponse, string $targetId, LINEBot $bot): void {
    $followUpMessage = '';
    if ($apiResponse === false) {
        $followUpMessage = "抱歉，影片偵測服務暫時無法連線。";
    } else {
        $data = json_decode($apiResponse, true);
        if (!is_array($data) || isset($data['error'])) {
            $errorMessage = "影片偵測服務回報錯誤: " . ($data['error'] ?? '未知錯誤');
            if (!empty($data['debug_output'])) {
                $errorMessage .= "\n\n[詳細日誌]:\n" . trim($data['debug_output']);
            }
            $followUpMessage = $errorMessage;
        } elseif (isset($data['status']) && $data['status'] === 'success') {
            $deepfakeProb = $data['deepfake']['prob'] ?? 0;
            $percentage = round($deepfakeProb * 100, 1);
            
            // 將門檻調整為 0.5 (50%)
            $threshold = 0.5;
            
            $summary = "🎬 Deepfake 影片分析結果：\n\n";
            
            if ($deepfakeProb > $threshold) {
                $summary .= "判斷結果：⚠️ 疑似 Deepfake 影片\n";
                $summary .= "(偵測到合成特徵的可能性為 {$percentage}%)";
            } else {
                $summary .= "判斷結果：✅ 未檢測到明顯特徵\n";
                $summary .= "(Deepfake 可能性較低，僅為 {$percentage}%)";
            }

            if ($deepfakeProb > 0.4 && $deepfakeProb <= 0.5) {
                $summary .= "\n\n💡 提示：數值接近警戒線，建議進一步查證來源。";
            }
            
            $followUpMessage = $summary;
        } else {
            $followUpMessage = "分析影片時發生未知的錯誤。";
        }
    }
    $bot->pushMessage($targetId, new TextMessageBuilder($followUpMessage));
}

$input = file_get_contents('php://input');
$events = json_decode($input, true);

if (is_array($events) && !empty($events['events'])) {
    foreach ($events['events'] as $event) {
        if ($event['type'] == 'message') {
            $replyToken = $event['replyToken'];
            $source = $event['source'];
            $userId = $source['userId'];
            $apiUrl = 'https://a9c5958fe6e2.ngrok-free.app/api.php';
            $userState = getUserState($userId);
            $targetId = $userId;
            if (isset($source['groupId'])) { $targetId = $source['groupId']; }

            if ($event['message']['type'] === 'image' && $userState === 'awaiting_image') {
                $bot->replyText($replyToken, '收到圖片，正在為您進行雙重分析(AI生成/內容查核)，請稍候...');
                clearUserState($userId);
                $response = getContentWithRetry($dataBot, $event['message']['id']);
                if ($response->isSucceeded()) {
                    $tempDir = 'uploads/';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    $tempFilePath = $tempDir . uniqid('line_img_', true) . '.jpg';
                    file_put_contents($tempFilePath, $response->getRawBody());
                    
                    $postData = ['action' => 'detect_image', 'image_file' => new CURLFile($tempFilePath)];
                    $apiResponse = make_curl_request_with_files($apiUrl, $postData);

                    if (file_exists($tempFilePath)) { unlink($tempFilePath); }
                    handle_image_analysis_response($apiResponse, $targetId, $bot);
                } else {
                    $bot->pushMessage($targetId, new TextMessageBuilder("抱歉，無法從 LINE 取得您傳送的圖片。\n狀態碼: {$response->getHTTPStatus()}\n錯誤回應: {$response->getRawBody()}"));
                }
                continue;
            }
            
            if ($event['message']['type'] === 'video' && $userState === 'awaiting_video') {
                $bot->replyText($replyToken, '收到影片，正在為您分析，請稍候...');
                clearUserState($userId);
                $response = getContentWithRetry($dataBot, $event['message']['id']);
                if ($response->isSucceeded()) {
                    $tempDir = 'uploads/';
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    $tempFilePath = $tempDir . uniqid('line_vid_', true) . '.mp4';
                    file_put_contents($tempFilePath, $response->getRawBody());

                    $postData = ['action' => 'detect_video', 'video_file' => new CURLFile($tempFilePath)];
                    $apiResponse = make_curl_request_with_files($apiUrl, $postData);

                    if (file_exists($tempFilePath)) { unlink($tempFilePath); }
                    handle_video_analysis_response($apiResponse, $targetId, $bot);
                } else {
                     $bot->pushMessage($targetId, new TextMessageBuilder("抱歉，無法從 LINE 取得您傳送的影片。\n狀態碼: {$response->getHTTPStatus()}\n錯誤回應: {$response->getRawBody()}"));
                }
                continue;
            }
            
            if ($event['message']['type'] === 'text') {
                $userMessage = $event['message']['text'];
                $trimmedUserMessage = trim($userMessage);

                if ($userState !== null && $trimmedUserMessage === '取消') {
                    clearUserState($userId);
                    $bot->replyText($replyToken, '好的，已取消目前的分析操作。');
                    continue;
                }

                if ($trimmedUserMessage === '測試' || $trimmedUserMessage === '測試!') {
                    $bot->replyText($replyToken, '機器人已連線成功!');
                    continue;
                }
                
                if ($trimmedUserMessage === '網站'|| $trimmedUserMessage === "web" || $trimmedUserMessage === "網址") {
                    $bot->replyText($replyToken, 'https://a9c5958fe6e2.ngrok-free.app/');
                    continue;
                }

                if (has_image_trigger($userMessage)) {
                    setUserState($userId, 'awaiting_image');
                    $bot->replyText($replyToken, '好的，請將您想分析的圖片傳送給我。(若要取消，請輸入「取消」)');
                    continue;
                }
                
                if (has_video_trigger($userMessage)) {
                    setUserState($userId, 'awaiting_video');
                    $bot->replyText($replyToken, '好的，請將您想分析的影片檔案或 YouTube 連結傳送給我。(若要取消，請輸入「取消」)');
                    continue;
                }

                if ($trimmedUserMessage === '熱門議題') {
                    $postData = ['action' => 'get_hot_searches'];
                    $response = make_curl_request($apiUrl, $postData);
                    $replyText = '';
                    if ($response === false) {
                        $replyText = "抱歉，暫時無法取得熱門議題。";
                    } else {
                        $data = json_decode($response, true);
                        if (is_array($data) && !empty($data['hot_topics'])) {
                            $replyText = "🔥 近期熱門查核議題:\n";
                            foreach($data['hot_topics'] as $item) {
                                $replyText .= "\n---\n";
                                $replyText .= "議題: {$item['claim_text']}\n";
                                $replyText .= "評等: {$item['rating']} (由 {$item['claimant']})\n";
                                if (!empty($item['url'])) {
                                    $replyText .= "🔗 詳情: {$item['url']}\n";
                                }
                            }
                        } else {
                            $replyText = "目前沒有熱門查核議題。";
                        }
                    }
                    $bot->replyText($replyToken, $replyText);
                    continue;
                }

                if (should_process_fact_check($userMessage)) {
                    $queryText = cleanup_message_for_query($userMessage);
                    if (empty($queryText)) {
                        $bot->replyText($replyToken, "請在「查一下」後面加上您想查核的內容喔！");
                        continue;
                    }
                    $postData = ['action' => 'search', 'query' => $queryText, 'language' => 'zh'];
                    $response = make_curl_request($apiUrl, $postData);
                    $text = '';
                    if ($response === false) {
                        $text = "抱歉，後端查核服務暫時無法連線。";
                    } else {
                        $data = json_decode($response, true);
                        if (!is_array($data) || isset($data['error'])) {
                            $text = "查核服務回報錯誤，請稍後再試。";
                        } else if (!empty($data['claims'])) {
                            $text = "[Google 查核結果]\n\n";
                            $claims = array_slice($data['claims'], 0, 2); 
                            foreach ($claims as $claim) {
                                $review = $claim['claimReview'][0];
                                $text .= "Ｑ: {$claim['text']}\nＡ: 由「{$claim['claimant']}」評斷為「{$review['textualRating']}」\n";
                                if (!empty($review['url'])) { $text .= "🔗 詳情: {$review['url']}\n\n"; }
                            }
                        }
                    }
                    if (!empty(trim($text))) { $bot->replyText($replyToken, trim($text)); }
                    continue;
                }

                if ($userState === 'awaiting_image') {
                    $bot->replyText($replyToken, '請直接傳送「圖片」檔案喔！若不想分析，請輸入「取消」。');
                    continue;
                }

                if ($userState === 'awaiting_video') {
                    $ytUrl = is_youtube_url($userMessage);
                    if ($ytUrl) {
                        clearUserState($userId);
                        $bot->replyText($replyToken, '收到 YouTube 連結，正在分析影片...');
                        $postData = ['action' => 'detect_yt_video', 'video_url' => $ytUrl];
                        $apiResponse = make_curl_request($apiUrl, $postData);
                        handle_video_analysis_response($apiResponse, $targetId, $bot);
                    } else {
                        $bot->replyText($replyToken, '請直接傳送「影片檔案」或「YouTube 連結」喔！若不想分析，請輸入「取消」。');
                    }
                    continue;
                }
                
                preg_match('/(https?:\/\/[^\s]+)/', $userMessage, $matches);
                if (isset($matches[0])) {
                    $urlToCheck = $matches[0];
                    if (is_youtube_url($urlToCheck)) {
                        continue;
                    }
                    if (!check_url_existence($urlToCheck)) {
                        $bot->replyText($replyToken, "🤔 檢查結果：\n此網址不存在或目前無法連線。");
                        continue;
                    }
                    $safetyResult = check_url_safety($urlToCheck, GOOGLE_WEB_RISK_API_KEY_CONST);
                    $replyText = '';
                    if (isset($safetyResult['error'])) {
                        $replyText = "抱歉，網址安全檢查服務暫時無法使用。";
                    } elseif (!$safetyResult['safe']) {
                        $threatType = $safetyResult['threat_type'];
                        $warning = "🚨 高風險警告！ 🚨\n此網址已被標記為不安全，請勿點擊！\n";
                        switch ($threatType) {
                            case 'SOCIAL_ENGINEERING': $warning .= "威脅類型：社交工程 (釣魚網站)"; break;
                            case 'MALWARE': $warning .= "威脅類型：惡意軟體"; break;
                            case 'UNWANTED_SOFTWARE': $warning .= "威脅類型：可能包含垃圾軟體"; break;
                            default: $warning .= "威脅類型：未知"; break;
                        }
                        $replyText = $warning;
                    }
                    if (!empty($replyText)) { $bot->replyText($replyToken, $replyText); }
                    continue;
                }
            }
        }
    }
}

http_response_code(200);
echo 'OK';
?>