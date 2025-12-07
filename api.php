<?php
ob_start(); // 緩衝輸出，防止 HTML 錯誤混入 JSON

ini_set('display_errors', 1);
error_reporting(E_ALL);
set_time_limit(600); 

header('Content-Type: application/json; charset=utf-8');

define('YT_DLP_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\yt-dlp.exe');
define('FFMPEG_EXE_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\ffmpeg.exe');

require_once 'config.php';

const GOOGLE_API_KEY = GOOGLE_FACT_CHECK_API_KEY;
const LOCAL_AI_SERVER = 'http://127.0.0.1:8000';
const LOCAL_OLLAMA_SERVER = 'http://localhost:11434'; // 用於 OCR

// --- 工具函式 ---
function compress_image($source_path, $destination_path, $quality = 85, $max_width = 1500) {
    $info = getimagesize($source_path); if ($info === false) return false; list($width, $height) = $info; if ($width > $max_width) { $new_width = $max_width; $new_height = (int)($height * ($new_width / $width)); } else { $new_width = $width; $new_height = $height; } $thumb = imagecreatetruecolor($new_width, $new_height); $image = null; switch ($info['mime']) { case 'image/jpeg': $image = imagecreatefromjpeg($source_path); break; case 'image/png': $image = imagecreatefrompng($source_path); imagealphablending($thumb, false); imagesavealpha($thumb, true); break; case 'image/gif': $image = imagecreatefromgif($source_path); break; default: return false; } imagecopyresampled($thumb, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height); $success = false; switch ($info['mime']) { case 'image/jpeg': $success = imagejpeg($thumb, $destination_path, $quality); break; case 'image/png': $success = imagepng($thumb, $destination_path, 7); break; case 'image/gif': $success = imagegif($thumb, $destination_path); break; } ($thumb); ($image); return $success ? $destination_path : false;
}

function check_url_existence(string $url): bool { $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_NOBODY, true); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); curl_exec($ch); if (curl_errno($ch)) { ($ch); return false; } $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); return ($http_code < 400); }
function call_google_factcheck($query, $language) { $url = GOOGLE_FACT_CHECK_API_URL . '?' . http_build_query(['query' => $query, 'languageCode' => $language, 'key' => GOOGLE_API_KEY]); $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20]); $response = curl_exec($ch); $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); if ($http_status !== 200) { return ['error' => 'Google FactCheck API Error']; } return json_decode($response, true); }
function check_url_safety(string $url): array { $queryParams = http_build_query(['key' => GOOGLE_WEB_RISK_API_KEY, 'uri' => $url]); $threatTypes = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']; foreach ($threatTypes as $type) { $queryParams .= '&threatTypes=' . urlencode($type); } $apiUrl = 'https://webrisk.googleapis.com/v1/uris:search?' . $queryParams; $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]); $response = @file_get_contents($apiUrl, false, $context); if ($response === false) { return ['error' => 'Connection Error']; } $data = json_decode($response, true); if (isset($data['threat'])) { return ['safe' => false, 'threat_type' => $data['threat']['threatTypes'][0] ?? 'UNKNOWN']; } return ['safe' => true]; }

// --- 呼叫 Python AI ---
function call_hybrid_image_detection(string $imagePath): array {
    $ch_local = curl_init();
    curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/image');
    curl_setopt($ch_local, CURLOPT_POST, true);
    curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($imagePath)]);
    curl_setopt($ch_local, CURLOPT_TIMEOUT, 120);
    $res_local = curl_exec($ch_local); ($ch_local);
    if ($res_local) return json_decode($res_local, true);
    return ['error' => 'AI Server Connect Failed'];
}

function call_hybrid_video_detection(string $videoPath): array {
    $ch_local = curl_init();
    curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/video');
    curl_setopt($ch_local, CURLOPT_POST, true);
    curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($videoPath)]);
    curl_setopt($ch_local, CURLOPT_TIMEOUT, 600);
    $res_local = curl_exec($ch_local); ($ch_local);
    if ($res_local) return json_decode($res_local, true);
    return ['error' => 'AI Server Connect Failed'];
}

// --- OCR (含 Ollama) ---
function call_hybrid_ocr(string $imagePath): array {
    // 1. 優先 OCR.space
    if (file_exists($imagePath) && filesize($imagePath) < 1024 * 1024) { 
        $ch = curl_init(); $postData = ['apikey' => OCR_SPACE_API_KEY, 'language' => 'cht', 'isOverlayRequired' => 'false', 'file' => new CURLFile($imagePath)]; curl_setopt_array($ch, [CURLOPT_URL => 'https://api.ocr.space/parse/image', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]); $response = curl_exec($ch); ($ch); 
        if ($response) { $data = json_decode($response, true); if (!empty($data['ParsedResults'][0]['ParsedText'])) return ['status' => 'success', 'source' => 'OCR.space', 'text' => trim($data['ParsedResults'][0]['ParsedText'])]; }
    }
    // 2. Ollama Fallback
    $ch_ollama = curl_init(); $imageData = base64_encode(file_get_contents($imagePath)); 
    // Prompt 強制只要文字，不要分析
    $promptText = "ACT AS AN OCR MACHINE. Read text from the image. Only output the text found. 輸出圖片中的中文與數字。"; 
    $postData = ['model' => 'llava', 'stream' => false, 'prompt' => $promptText, 'images' => [$imageData]]; 
    curl_setopt_array($ch_ollama, [CURLOPT_URL => LOCAL_OLLAMA_SERVER . '/api/generate', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($postData), CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 120]); 
    $res_ollama = curl_exec($ch_ollama); ($ch_ollama); 
    if ($res_ollama) { $data = json_decode($res_ollama, true); if (isset($data['response'])) return ['status' => 'success', 'source' => 'Ollama (Llava)', 'text' => trim($data['response'])]; } 
    return ['status' => 'error', 'message' => 'OCR Failed'];
}

// --- YouTube 下載 (Fix 503) ---
function analyze_youtube_video(string $ytUrl): array { 
    if (!file_exists(YT_DLP_PATH)) return ['error' => 'yt-dlp not found']; 
    if (!file_exists(FFMPEG_EXE_PATH)) return ['error' => 'ffmpeg not found']; 
    $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true); 
    $uniqueId = uniqid('yt_', true); 
    $finalFilePath = $uploadDir . $uniqueId . '.mp4'; 
    
    // [FIX] worstvideo[height>=360] 避免 503 錯誤
    $cmd = sprintf('"%s" -f "worstvideo[height>=360][ext=mp4]+bestaudio/best[ext=mp4]/best" --output "%s.%%(ext)s" %s', YT_DLP_PATH, $uploadDir . $uniqueId, escapeshellarg($ytUrl)); 
    
    shell_exec($cmd . ' 2>&1'); 
    $tempFiles = glob($uploadDir . $uniqueId . '.*'); 
    $downloadedVideo = ''; 
    foreach($tempFiles as $f) { if (pathinfo($f, PATHINFO_EXTENSION) == 'mp4') { $downloadedVideo = $f; break; } } 
    if(!$downloadedVideo && !empty($tempFiles)) $downloadedVideo = $tempFiles[0]; 
    if($downloadedVideo) { 
        if(pathinfo($downloadedVideo, PATHINFO_EXTENSION) != 'mp4') { shell_exec(sprintf('"%s" -i "%s" -c:v copy -c:a aac -y "%s"', FFMPEG_EXE_PATH, $downloadedVideo, $finalFilePath)); } else { rename($downloadedVideo, $finalFilePath); } 
    } 
    if(!file_exists($finalFilePath)) { foreach($tempFiles as $f) unlink($f); return ['error' => 'Download Failed']; } 
    $res = call_hybrid_video_detection($finalFilePath); 
    foreach($tempFiles as $f) @unlink($f); @unlink($finalFilePath); return $res; 
}

// --- Main Switch ---
$action = $_POST['action'] ?? 'search';
$final_response = [];

switch ($action) {
    case 'get_hot_searches':
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($conn->connect_error) { $final_response = ['error' => 'DB Error']; break; }
        $conn->set_charset("utf8mb4");
        $sql = "SELECT claim_text, claimant, rating, url FROM fact_check_cache ORDER BY id DESC LIMIT 5";
        $result = $conn->query($sql);
        $hot_topics = []; if ($result) { while($row = $result->fetch_assoc()) $hot_topics[] = $row; }
        $final_response = ['hot_topics' => $hot_topics]; $conn->close(); break;

    case 'check_url':
        $url = trim($_POST['url'] ?? '');
        if (empty($url) || !check_url_existence($url)) { $final_response = ['error' => 'Invalid URL']; break; }
        $final_response = check_url_safety($url); break;

    case 'detect_image':
        $imageFile = $_FILES['image_file'] ?? null;
        if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) { $final_response = ['error' => 'Upload Failed']; break; }
        $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $originalFilePath = $uploadDir . uniqid('img_orig_', true) . '.' . pathinfo($imageFile['name'], PATHINFO_EXTENSION);
        move_uploaded_file($imageFile['tmp_name'], $originalFilePath);

        $ai_result = call_hybrid_image_detection($originalFilePath);

        // OCR 處理
        $ocrFileToProcess = $originalFilePath;
        $compressedFilePath = null;
        if (filesize($originalFilePath) > 1024 * 1024) {
             $compressedFilePath = $uploadDir . uniqid('img_comp_', true) . '.jpg';
             $result = compress_image($originalFilePath, $compressedFilePath, 90);
             if ($result !== false) $ocrFileToProcess = $result;
        }
        $ocr_result = call_hybrid_ocr($ocrFileToProcess);

        // Google Fact Check
        $fact_check_result = [];
        if (isset($ocr_result['status']) && $ocr_result['status'] === 'success') {
            if (!empty($ocr_result['text'])) {
                $check = call_google_factcheck($ocr_result['text'], 'zh');
                if (!is_array($check)) { $fact_check_result = ['claims' => [], 'extracted_text' => $ocr_result['text']]; }
                else { $fact_check_result = $check; $fact_check_result['extracted_text'] = $ocr_result['text']; }
            } else { $fact_check_result = ['claims' => [], 'extracted_text' => '']; }
        } else { $fact_check_result = ['error' => $ocr_result['message'] ?? 'OCR Failed']; }

        $final_response = ['ai_detection' => $ai_result, 'fact_check' => $fact_check_result];
        @unlink($originalFilePath); if ($compressedFilePath) @unlink($compressedFilePath); break;

    case 'detect_yt_video':
        $ytUrl = trim($_POST['video_url'] ?? '');
        $final_response = analyze_youtube_video($ytUrl); break;

    case 'detect_video':
        $videoFile = $_FILES['video_file'] ?? null;
        if (!$videoFile || $videoFile['error'] !== UPLOAD_ERR_OK) { $final_response = ['error' => 'Upload Failed']; break; }
        $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
        $filePath = $uploadDir . uniqid('vid_', true) . '.' . pathinfo($videoFile['name'], PATHINFO_EXTENSION);
        move_uploaded_file($videoFile['tmp_name'], $filePath);
        $final_response = call_hybrid_video_detection($filePath);
        @unlink($filePath); break;

    case 'search':
        $query = trim($_POST['query'] ?? '');
        $final_response = call_google_factcheck($query, 'zh'); break;
}

ob_clean(); // 清除緩衝區，保證 JSON 純淨
echo json_encode($final_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>