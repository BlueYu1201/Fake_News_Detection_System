<?php
// 1. 啟動緩衝區
ob_start();

// 2. 設定
ini_set('display_errors', 0); 
error_reporting(E_ALL);
set_time_limit(300); 
ini_set('memory_limit', '512M'); 

header('Content-Type: application/json; charset=utf-8');

// 3. 註冊防崩潰函式
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_CORE_ERROR || $error['type'] === E_COMPILE_ERROR || $error['type'] === E_USER_ERROR)) {
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'error' => 'Server Fatal Error',
            'details' => $error['message'] . " in line " . $error['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

define('YT_DLP_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\yt-dlp.exe');
define('FFMPEG_EXE_PATH', 'C:\ffmpeg-2025-09-01-git-3ea6c2fe25-essentials_build\bin\ffmpeg.exe');

require_once 'config.php';

const GOOGLE_API_KEY = GOOGLE_FACT_CHECK_API_KEY;
const LOCAL_AI_SERVER = 'http://127.0.0.1:8000';

try {

    // --- 工具函式 (已修復 GD 缺失問題) ---
    function compress_image($source_path, $destination_path, $quality = 85, $max_width = 1500) {
        // [防呆] 檢查檔案是否存在
        if (!file_exists($source_path)) return false;

        // [關鍵修正] 檢查伺服器是否支援 GD 函式庫
        if (!function_exists('imagecreatetruecolor')) {
            // 如果不支援，直接複製檔案，不進行壓縮，避免崩潰
            return copy($source_path, $destination_path) ? $destination_path : false;
        }
        
        $info = @getimagesize($source_path);
        if ($info === false) return false;
        
        list($width, $height) = $info;
        
        // [防呆] 檢查圖片是否過大
        if ($width * $height > 10000000) { 
             ini_set('memory_limit', '1024M');
        }

        if ($width > $max_width) { $new_width = $max_width; $new_height = (int)($height * ($new_width / $width)); } else { $new_width = $width; $new_height = $height; }
        
        $thumb = imagecreatetruecolor($new_width, $new_height);
        $image = null;
        
        try {
            switch ($info['mime']) { 
                case 'image/jpeg': $image = imagecreatefromjpeg($source_path); break; 
                case 'image/png': $image = imagecreatefrompng($source_path); imagealphablending($thumb, false); imagesavealpha($thumb, true); break; 
                case 'image/gif': $image = imagecreatefromgif($source_path); break; 
                default: return false; 
            }
        } catch (Throwable $t) {
            return false;
        }

        if (!$image) return false;
        
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
        
        $success = false;
        switch ($info['mime']) { 
            case 'image/jpeg': $success = imagejpeg($thumb, $destination_path, $quality); break; 
            case 'image/png': $success = imagepng($thumb, $destination_path, 7); break; 
            case 'image/gif': $success = imagegif($thumb, $destination_path); break; 
        }
        
        if($thumb) ($thumb); 
        if($image) ($image);
        return $success ? $destination_path : false;
    }

    function check_url_existence(string $url): bool { 
        $ch = curl_init($url); curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); curl_setopt($ch, CURLOPT_NOBODY, true); curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); curl_setopt($ch, CURLOPT_TIMEOUT, 15); curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); 
        curl_exec($ch); 
        if (curl_errno($ch)) { ($ch); return false; } 
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        ($ch); 
        return ($http_code < 400); 
    }

    function call_google_factcheck($query, $language) { 
        $url = GOOGLE_FACT_CHECK_API_URL . '?' . http_build_query(['query' => $query, 'languageCode' => $language, 'key' => GOOGLE_API_KEY]); 
        $ch = curl_init(); curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 20]); 
        $response = curl_exec($ch); $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE); ($ch); 
        if ($http_status !== 200) { return ['error' => 'Google FactCheck API Error (HTTP ' . $http_status . ')']; } 
        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : ['error' => 'Invalid JSON from Google']; 
    }

    function check_url_safety(string $url): array { 
        $queryParams = http_build_query(['key' => GOOGLE_WEB_RISK_API_KEY, 'uri' => $url]); 
        $threatTypes = ['MALWARE', 'SOCIAL_ENGINEERING', 'UNWANTED_SOFTWARE']; 
        foreach ($threatTypes as $type) { $queryParams .= '&threatTypes=' . urlencode($type); } 
        $apiUrl = 'https://webrisk.googleapis.com/v1/uris:search?' . $queryParams; 
        $context = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 15]]); 
        $response = @file_get_contents($apiUrl, false, $context); 
        if ($response === false) { return ['error' => 'Connection Error (Web Risk API)']; } 
        $data = json_decode($response, true); 
        if (isset($data['error'])) { return ['error' => 'Google Web Risk API Error: ' . ($data['error']['message'] ?? 'Unknown')]; } 
        if (isset($data['threat'])) { return ['safe' => false, 'threat_type' => $data['threat']['threatTypes'][0] ?? 'UNKNOWN']; } 
        return ['safe' => true]; 
    }

    // --- Call Python AI ---
    function call_hybrid_image_detection(string $imagePath): array {
        $ch_local = curl_init();
        curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/image');
        curl_setopt($ch_local, CURLOPT_POST, true);
        curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($imagePath)]);
        curl_setopt($ch_local, CURLOPT_TIMEOUT, 120);
        
        $res_local = curl_exec($ch_local); 
        $http_code = curl_getinfo($ch_local, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch_local);
        ($ch_local);

        if ($curl_error) {
            return ['error' => 'CURL Error: ' . $curl_error];
        }

        if ($http_code === 200 && $res_local) {
            $decoded = json_decode($res_local, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return ['error' => 'Python JSON Parse Error', 'raw' => $res_local];
            }
            return $decoded;
        }
        return ['error' => 'AI Server Connect Failed (HTTP ' . $http_code . ')'];
    }

    function call_hybrid_video_detection(string $videoPath): array {
        $ch_local = curl_init();
        curl_setopt($ch_local, CURLOPT_URL, LOCAL_AI_SERVER . '/detect/video');
        curl_setopt($ch_local, CURLOPT_POST, true);
        curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch_local, CURLOPT_POSTFIELDS, ['file' => new CURLFile($videoPath)]);
        curl_setopt($ch_local, CURLOPT_TIMEOUT, 600);
        $res_local = curl_exec($ch_local); 
        $http_code = curl_getinfo($ch_local, CURLINFO_HTTP_CODE);
        ($ch_local);

        if ($http_code === 200 && $res_local) {
            $decoded = json_decode($res_local, true);
            return is_array($decoded) ? $decoded : ['error' => 'Invalid JSON from Python'];
        }
        return ['error' => 'AI Server Connect Failed'];
    }

    // --- OCR ---
    function call_hybrid_ocr(string $imagePath): array {
        if (file_exists($imagePath) && filesize($imagePath) < 1024 * 1024) { 
            $ch = curl_init(); 
            $postData = ['apikey' => OCR_SPACE_API_KEY, 'language' => 'cht', 'isOverlayRequired' => 'false', 'file' => new CURLFile($imagePath)]; 
            curl_setopt_array($ch, [CURLOPT_URL => 'https://api.ocr.space/parse/image', CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_TIMEOUT => 30]); 
            $response = curl_exec($ch); 
            ($ch); 
            if ($response) { 
                $data = json_decode($response, true); 
                if (!empty($data['ParsedResults'][0]['ParsedText'])) {
                    return ['status' => 'success', 'source' => 'OCR.space', 'text' => trim($data['ParsedResults'][0]['ParsedText'])];
                }
            }
        }
        return ['status' => 'error', 'message' => 'OCR Failed'];
    }

    // --- YouTube Download ---
    function analyze_youtube_video(string $ytUrl): array { 
        if (!file_exists(YT_DLP_PATH)) return ['error' => 'yt-dlp not found']; 
        if (!file_exists(FFMPEG_EXE_PATH)) return ['error' => 'ffmpeg not found']; 
        $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true); 
        $uniqueId = uniqid('yt_', true); 
        $finalFilePath = $uploadDir . $uniqueId . '.mp4'; 
        
        $cmd = sprintf('"%s" -f "worstvideo[height>=360][ext=mp4]+bestaudio/best[ext=mp4]/best" --output "%s.%%(ext)s" %s', YT_DLP_PATH, $uploadDir . $uniqueId, escapeshellarg($ytUrl)); 
        shell_exec($cmd . ' 2>&1'); 
        
        $tempFiles = glob($uploadDir . $uniqueId . '.*'); 
        $downloadedVideo = ''; 
        foreach($tempFiles as $f) { if (pathinfo($f, PATHINFO_EXTENSION) == 'mp4') { $downloadedVideo = $f; break; } } 
        if(!$downloadedVideo && !empty($tempFiles)) $downloadedVideo = $tempFiles[0]; 
        if($downloadedVideo) { 
            if(pathinfo($downloadedVideo, PATHINFO_EXTENSION) != 'mp4') { shell_exec(sprintf('"%s" -i "%s" -c:v copy -c:a aac -y "%s"', FFMPEG_EXE_PATH, $downloadedVideo, $finalFilePath)); } else { rename($downloadedVideo, $finalFilePath); } 
        } 
        if(!file_exists($finalFilePath)) { foreach($tempFiles as $f) @unlink($f); return ['error' => 'Download Failed']; } 
        $res = call_hybrid_video_detection($finalFilePath); 
        foreach($tempFiles as $f) @unlink($f); @unlink($finalFilePath); return $res; 
    }

    // --- Main Switch ---
    $action = $_POST['action'] ?? 'search';
    $final_response = [];

    switch ($action) {
        case 'get_hot_searches':
            $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            if ($conn->connect_error) { $final_response = ['error' => 'DB Error: ' . $conn->connect_error]; break; }
            $conn->set_charset("utf8mb4");
            $sql = "SELECT claim_text, claimant, rating, url FROM fact_check_cache ORDER BY id DESC LIMIT 5";
            $result = $conn->query($sql);
            $hot_topics = []; if ($result) { while($row = $result->fetch_assoc()) $hot_topics[] = $row; }
            $final_response = ['hot_topics' => $hot_topics]; $conn->close(); break;

        case 'check_url':
            $url = trim($_POST['url'] ?? '');
            if (empty($url) || !check_url_existence($url)) { $final_response = ['error' => 'Invalid URL or Cannot Connect']; break; }
            $final_response = check_url_safety($url); break;

        case 'detect_image':
            $imageFile = $_FILES['image_file'] ?? null;
            if (!$imageFile || $imageFile['error'] !== UPLOAD_ERR_OK) { $final_response = ['error' => 'Upload Failed (Error Code: ' . $imageFile['error'] . ')']; break; }
            
            $uploadDir = 'uploads/'; if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            $originalFilePath = $uploadDir . uniqid('img_orig_', true) . '.' . pathinfo($imageFile['name'], PATHINFO_EXTENSION);
            if (!move_uploaded_file($imageFile['tmp_name'], $originalFilePath)) {
                $final_response = ['error' => 'Failed to move uploaded file']; break;
            }

            // 1. AI Detection
            $ai_result = call_hybrid_image_detection($originalFilePath);

            // 2. OCR Processing
            $ocrFileToProcess = $originalFilePath;
            $compressedFilePath = null;
            
            // 嘗試壓縮圖片，如果壓縮失敗(沒GD或圖片壞掉)則使用原圖
            if (filesize($originalFilePath) > 1024 * 1024) {
                 $compressedFilePath = $uploadDir . uniqid('img_comp_', true) . '.jpg';
                 $result = compress_image($originalFilePath, $compressedFilePath, 90);
                 if ($result !== false) {
                     $ocrFileToProcess = $result;
                 } else {
                     // 壓縮失敗，刪除暫存，使用原圖
                     if(file_exists($compressedFilePath)) @unlink($compressedFilePath);
                     $compressedFilePath = null;
                 }
            }
            $ocr_result = call_hybrid_ocr($ocrFileToProcess);

            // 3. Fact Check
            $fact_check_result = [];
            if (isset($ocr_result['status']) && $ocr_result['status'] === 'success') {
                if (!empty($ocr_result['text'])) {
                    $check = call_google_factcheck($ocr_result['text'], 'zh');
                    if (!is_array($check)) { 
                        $fact_check_result = ['claims' => [], 'extracted_text' => $ocr_result['text']]; 
                    } else { 
                        $fact_check_result = $check; 
                        $fact_check_result['extracted_text'] = $ocr_result['text']; 
                    }
                } else { 
                    $fact_check_result = ['claims' => [], 'extracted_text' => '']; 
                }
            } else { 
                $fact_check_result = ['error' => $ocr_result['message'] ?? 'OCR Failed']; 
            }

            $final_response = [
                'fake_probability' => $ai_result['fake_probability'] ?? 0, 
                'ai_detection' => $ai_result, 
                'fact_check' => $fact_check_result
            ];
            
            @unlink($originalFilePath); 
            if ($compressedFilePath) @unlink($compressedFilePath); 
            break;

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
            
        default:
            $final_response = ['error' => 'Unknown Action'];
    }

} catch (Exception $e) {
    // Catch logical errors
    $final_response = ['error' => 'Server Exception: ' . $e->getMessage()];
}

// 4. Output Clean JSON
ob_clean(); 
echo json_encode($final_response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>