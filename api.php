<?php
ob_start(); // 1. 啟動輸出緩衝：將所有輸出先暫存，防止錯誤訊息直接印在畫面上

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); // 延長執行時間到 10 分鐘，避免處理大影片時超時

// 2. 設定正確的 Header (修正了之前的拼寫錯誤)
header('Content-Type: application/json; charset=utf-8');

define('YT_DLP_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\yt-dlp.exe');
define('FFMPEG_EXE_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\ffmpeg.exe');

require_once 'config.php';

const GOOGLE_API_KEY = GOOGLE_FACT_CHECK_API_KEY;
// 本地服務設定
const LOCAL_AI_SERVER = 'http://127.0.0.1:8000'; // Python AI Server (處理真偽偵測)
const LOCAL_OLLAMA_SERVER = 'http://localhost:11434'; // Ollama Server (處理文字辨識)

// --- 圖片壓縮函式 ---
function compress_image($source_path, $destination_path, $quality = 85, $max_width = 1500) {
    $info = getimagesize($source_path);
    if ($info === false) return false;
    list($width, $height) = $info;
    if ($width > $max_width) {
        $new_width = $max_width;
        $new_height = (int)($height * ($new_width / $width));
    } else {
        $new_width = $width;
        $new_height = $height;
    }
    $thumb = imagecreatetruecolor($new_width, $new_height);
    $image = null;
    switch ($info['mime']) {
        case 'image/jpeg': $image = imagecreatefromjpeg($source_path); break;
        case 'image/png': $image = imagecreatefrompng($source_path); imagealphablending($thumb, false); imagesavealpha($thumb, true); break;
        case 'image/gif': $image = imagecreatefromgif($source_path); break;
        default: return false;
    }
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    $success = false;
    switch ($info['mime']) {
        case 'image/jpeg': $success = imagejpeg($thumb, $destination_path, $quality); break;
        case 'image/png': $success = imagepng($thumb, $destination_path, 7); break;
        case 'image/gif': $success = imagegif($thumb, $destination_path); break;
    }
    ($thumb);
    ($image);
    return $success ? $destination_path : false;
}

// --- 基礎網路與工具函式 ---
function check_url_existence(string $url): bool { $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_NOBODY, true); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_exec($ch); if (curl_errno($ch)) { ($ch); return false; } $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); return ($http_code < 400); }
function call_google_factcheck($query, $language) { $url = GOOGLE_FACT_CHECK_API_URL . '?' . http_build_query(['query' => $query, 'languageCode' => $language, 'key' => GOOGLE_API_KEY]); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20]); $response = curl_exec($ch); $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); if ($http_status !== 200) { return ['error' => 'Google FactCheck API 請求失敗，狀態碼: ' . $http_status]; } return json_decode($response, true); }
function check_url_safety(string $url): array { $queryParams = http_build_query(['key' => GOOGLE_WEB_RISK_API_KEY, 'uri' => $url]); $threatTypes = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']; foreach ($threatTypes as $type) { $queryParams .= '&threatTypes=' . urlencode($type); } $apiUrl = 'https://webrisk.googleapis.com/v1/uris:search?' . $queryParams; $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]); $response = @file_get_contents($apiUrl, false, $context); if ($response === false) { return ['error' => '無法連接至 Google Web Risk API。']; } $data = json_decode($response, true); if (isset($data['error'])) { return ['error' => 'Google Web Risk API 回報錯誤: ' . ($data['error']['message'] ?? '未知錯誤')]; } if (isset($data['threat'])) { return ['safe' => false, 'threat_type' => $data['threat']['threatTypes'][0] ?? 'UNKNOWN']; } return ['safe' => true]; }

// -----------------------------------------------------------
//  混合式 AI 偵測與 OCR 核心區 (Cloud API -> Local Fallback)
// -----------------------------------------------------------

// 1. 混合式圖片 AI 偵測 (IsItAI -> Python Local)
function call_hybrid_image_detection(string $imagePath): array {
    // A. 優先嘗試雲端 API (IsItAI)
    $apiUrl = 'https://api.isitai.com/detect-img';
    
    if (file_exists($imagePath)) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => new CURLFile($imagePath)]);
        $headers = ['Authorization: Bearer ' . ISITAI_API_KEY]; 
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        ($ch);

        // 如果成功且有結果
        if ($http_status === 200) {
            $data = json_decode($response, true);
            if (isset($data['predicted_label'])) {
                return [
                    'status' => 'success', 
                    'source' => 'IsItAI (Cloud)', 
                    'result' => ['label' => $data['predicted_label'], 'confidence' => $data['probability']]
                ];
            }
        }
    }

    // B. 如果 IsItAI 失敗 (403/500/連不上)，轉用本地 Python
    $ch_local = curl_init();
    curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/image');
    curl_setopt($ch_local, CURLOPT_POST, true);
    curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($imagePath)]);
    curl_setopt($ch_local, CURLOPT_TIMEOUT, 120);
    
    $res_local = curl_exec($ch_local);
    $http_local = curl_getinfo($ch_local, CURLINFO_HTTP_CODE);
    ($ch_local);

    if ($http_local === 200) {
        $data_local = json_decode($res_local, true);
        if (isset($data_local['predicted_label'])) {
            return [
                'status' => 'success', 
                'source' => 'Python Server (Local)', 
                'result' => ['label' => $data_local['predicted_label'], 'confidence' => $data_local['probability']]
            ];
        }
    }

    return ['error' => '雲端 API 額度用盡，且無法連線至本地 AI 伺服器 (請確認 ai_server.py 是否執行中)。'];
}

// 2. 混合式影片偵測 (Sightengine -> Python Local)
function call_hybrid_video_detection(string $videoPath): array {
    // A. 優先嘗試雲端 (Sightengine)
    $apiUrl = 'https://api.sightengine.com/1.0/video/check.json';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $postFields = ['api_user' => SIGHTENGINE_USER, 'api_secret' => SIGHTENGINE_SECRET, 'models' => 'deepfake', 'async' => 'false', 'media' => new CURLFile($videoPath)];
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    ($ch);

    if ($http_status === 200) {
        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] === 'success') {
            return $data; 
        }
    }

    // B. 失敗則轉用本地 Python
    $ch_local = curl_init();
    curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/video');
    curl_setopt($ch_local, CURLOPT_POST, true);
    curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($videoPath)]);
    curl_setopt($ch_local, CURLOPT_TIMEOUT, 600);
    $res_local = curl_exec($ch_local);
    $http_local = curl_getinfo($ch_local, CURLINFO_HTTP_CODE);
    ($ch_local);

    if ($http_local === 200) {
        return json_decode($res_local, true);
    }

    return ['error' => '雲端與本地影片偵測服務皆失敗。'];
}

// 3. 混合式 OCR (OCR.space -> Ollama Llava)
function call_hybrid_ocr(string $imagePath): array {
    // A. 優先嘗試 OCR.space (若檔案小於 1MB 且存在)
    if (file_exists($imagePath) && filesize($imagePath) < 1024 * 1024) { 
        $ch = curl_init();
        $postData = ['apikey' => OCR_SPACE_API_KEY, 'language' => 'cht', 'isOverlayRequired' => 'false', 'file' => new CURLFile($imagePath)];
        curl_setopt_array($ch, [CURLOPT_URL => 'https://api.ocr.space/parse/image', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        ($ch);

        if ($http_status === 200) {
            $data = json_decode($response, true);
            if (!empty($data['ParsedResults'][0]['ParsedText'])) {
                return ['status' => 'success', 'source' => 'OCR.space', 'text' => trim($data['ParsedResults'][0]['ParsedText'])];
            }
        }
    }

    // B. 轉用本地 Ollama (Llava)
    // 使用 "角色扮演" Prompt 強制它當 OCR 機器
    $ch_ollama = curl_init();
    $imageData = base64_encode(file_get_contents($imagePath));
    
    // 強力 Prompt：禁止道歉，禁止解釋，只要文字
    $promptText = "ACT AS AN OCR MACHINE. 你的唯一任務是讀取圖片中的文字。\n" .
                  "規則：\n" .
                  "1. 逐字輸出圖片中所有的中文和數字。\n" .
                  "2. 不要輸出「抱歉」、「我無法」、「這是一張...」等任何無關語句。\n" .
                  "3. 如果圖片有文字，直接輸出文字內容。";

    $postData = [
        'model' => 'llava', 
        'stream' => false, 
        'prompt' => $promptText, 
        'images' => [$imageData]
    ];
    
    curl_setopt_array($ch_ollama, [
        CURLOPT_URL => LOCAL_OLLAMA_SERVER . '/api/generate',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120
    ]);
    
    $res_ollama = curl_exec($ch_ollama);
    $http_ollama = curl_getinfo($ch_ollama, CURLINFO_HTTP_CODE);
    ($ch_ollama);

    if ($http_ollama === 200) {
        $data = json_decode($res_ollama, true);
        if (isset($data['response'])) {
            $cleaned_text = trim($data['response']);
            if (empty($cleaned_text)) {
                return ['status' => 'success', 'source' => 'Ollama (Empty)', 'text' => ''];
            }
            return ['status' => 'success', 'source' => 'Ollama (Llava)', 'text' => $cleaned_text];
        }
    }

    return ['status' => 'error', 'message' => 'OCR 服務與 Ollama 皆無法辨識文字。'];
}

// 4. 下載 YouTube 影片輔助函式
function analyze_youtube_video(string $ytUrl): array { if (!file_exists(YT_DLP_PATH)) return ['error' => 'yt-dlp not found']; if (!file_exists(FFMPEG_EXE_PATH)) return ['error' => 'ffmpeg not found']; $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true); $uniqueId = uniqid('yt_', true); $finalFilePath = $uploadDir . $uniqueId . '.mp4'; $cmd = sprintf('"%s" -f "bestvideo[ext=mp4]+bestaudio[ext=m4a]/mp4" --output "%s.%%(ext)s" %s', YT_DLP_PATH, $uploadDir . $uniqueId, escapeshellarg($ytUrl)); shell_exec($cmd . ' 2>&1'); $tempFiles = glob($uploadDir . $uniqueId . '.*'); $downloadedVideo = ''; foreach($tempFiles as $f) { if (pathinfo($f, PATHINFO_EXTENSION) == 'mp4') { $downloadedVideo = $f; break; } } if(!$downloadedVideo && !empty($tempFiles)) $downloadedVideo = $tempFiles[0]; if($downloadedVideo) { if(pathinfo($downloadedVideo, PATHINFO_EXTENSION) != 'mp4') { shell_exec(sprintf('"%s" -i "%s" -c:v copy -c:a aac -y "%s"', FFMPEG_EXE_PATH, $downloadedVideo, $finalFilePath)); } else { rename($downloadedVideo, $finalFilePath); } } if(!file_exists($finalFilePath)) { foreach($tempFiles as $f) unlink($f); return ['error' => 'Download failed']; } 
    $res = call_hybrid_video_detection($finalFilePath); 
    foreach($tempFiles as $f) @unlink($f); @unlink($finalFilePath); return $res; 
}


// --- API 處理邏輯 (Main Switch) ---
$action = $_POST['action'] ?? 'search';
$final_response = [];

switch ($action) {
    case 'get_hot_searches':
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) { $final_response = ['error' => 'DB Error']; break; }
        $conn->set_charset("utf8mb4");
        $sql = "SELECT claim_text, claimant, rating, url FROM fact_check_cache ORDER BY id DESC LIMIT 5";
        $result = $conn->query($sql);
        $hot_topics = [];
        if ($result) { while($row = $result->fetch_assoc()) $hot_topics[] = $row; }
        $final_response = ['hot_topics' => $hot_topics];
        $conn->close();
        break;

    case 'check_url':
        $url = trim($_POST['url'] ?? '');
        if (empty($url) || !check_url_existence($url)) { $final_response = ['error' => '網址無效或無法連線']; break; }
        $final_response = check_url_safety($url);
        break;

    case 'detect_image':
        $imageFile = $_FILES['image_file'] ?? null;
        if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) { $final_response = ['error' => '上傳失敗']; break; }
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $originalFilePath = $uploadDir . uniqid('img_orig_', true) . '.' . pathinfo($imageFile['name'], PATHINFO_EXTENSION);
        move_uploaded_file($imageFile['tmp_name'], $originalFilePath);

        // 1. AI 偵測：使用原始檔案 (不壓縮) 以求精準
        $ai_result = call_hybrid_image_detection($originalFilePath);

        // 2. OCR：判斷是否需要壓縮
        $ocrFileToProcess = $originalFilePath;
        $compressedFilePath = null;
        
        // 為了相容 OCR.space 的免費限制 (1MB)，如果檔案真的很大，壓一份備用
        // 但如果 Hybrid OCR 決定用 Ollama，它會讀取原始檔案 (因為 call_hybrid_ocr 內部會判斷)
        if (filesize($originalFilePath) > 1024 * 1024) {
             $compressedFilePath = $uploadDir . uniqid('img_comp_', true) . '.jpg';
             // 壓縮品質 90，保留細節
             $result = compress_image($originalFilePath, $compressedFilePath, 90);
             if ($result !== false) $ocrFileToProcess = $result;
        }

        // 呼叫混合式 OCR
        $ocr_result = call_hybrid_ocr($ocrFileToProcess);

        // 3. Fact Check
        $fact_check_result = [];
        if (isset($ocr_result['status']) && $ocr_result['status'] === 'success') {
            if (!empty($ocr_result['text'])) {
                $check = call_google_factcheck($ocr_result['text'], 'zh');
                
                // --- 關鍵修正：防呆機制 ---
                // 確保 Google 回傳的是陣列，不然給空陣列，防止程式崩潰
                if (!is_array($check)) {
                    // 這裡可以選擇回傳空，或是回傳一個錯誤標記
                    // 為了不讓前端壞掉，我們模擬一個空的結構
                    $fact_check_result = [
                        'claims' => [], 
                        'extracted_text' => $ocr_result['text'],
                        'ocr_source' => $ocr_result['source'] ?? 'Unknown'
                    ];
                } else {
                    $fact_check_result = $check;
                    $fact_check_result['extracted_text'] = $ocr_result['text'];
                    $fact_check_result['ocr_source'] = $ocr_result['source'] ?? 'Unknown';
                }
                // -----------------------
            } else {
                $fact_check_result = ['claims' => [], 'extracted_text' => ''];
            }
        } else {
            $fact_check_result = ['error' => $ocr_result['message'] ?? 'OCR Failed'];
        }

        $final_response = [
            'ai_detection' => $ai_result,
            'fact_check' => $fact_check_result
        ];

        @unlink($originalFilePath);
        if ($compressedFilePath) @unlink($compressedFilePath);
        break;

    case 'detect_yt_video':
        $ytUrl = trim($_POST['video_url'] ?? '');
        $final_response = analyze_youtube_video($ytUrl);
        break;

    case 'detect_video':
        $videoFile = $_FILES['video_file'] ?? null;
        if (!$videoFile || $videoFile['error'] !== UPLOAD_ERR_OK) { $final_response = ['error' => '上傳失敗']; break; }
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $filePath = $uploadDir . uniqid('vid_', true) . '.' . pathinfo($videoFile['name'], PATHINFO_EXTENSION);
        move_uploaded_file($videoFile['tmp_name'], $filePath);
        
        $final_response = call_hybrid_video_detection($filePath);
        
        @unlink($filePath);
        break;

    case 'search':
        $query = trim($_POST['query'] ?? '');
        $final_response = call_google_factcheck($query, 'zh');
        break;
}

// 4. 清除緩衝區：這行最重要，把所有 PHP 錯誤、警告全部丟掉
ob_clean();

// 5. 輸出乾淨的 JSON
echo json_encode($final_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>