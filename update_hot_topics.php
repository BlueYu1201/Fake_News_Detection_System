<?php
// 此腳本應由 Cron Job 定期執行 (例如每天凌晨)
require_once 'config.php';

// --- 要自動查詢的關鍵字列表 ---
$search_topics = [
    "健康", "醫療", "科技", "政治", "選舉", "台灣", "中國", "美國", "詐騙", "財經"
];

// --- Google Fact Check API 呼叫函式 ---
function call_google_factcheck_for_update($query) {
    $url = GOOGLE_FACT_CHECK_API_URL . '?' . http_build_query([
        'query' => $query,
        'languageCode' => 'zh',
        'pageSize' => 50, // 每個關鍵字抓 50 筆最新的
        'key' => GOOGLE_FACT_CHECK_API_KEY
    ]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url, 
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_SSL_VERIFYPEER => false, 
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    ($ch);
    return json_decode($response, true);
}

// --- 主要執行邏輯 ---
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("資料庫連線失敗: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// 1. 清空舊的快取資料
$conn->query("TRUNCATE TABLE fact_check_cache");

$unique_claims = []; // 用來避免重複的陣列

foreach ($search_topics as $topic) {
    echo "正在查詢主題: $topic ...\n";
    $data = call_google_factcheck_for_update($topic);

    if (isset($data['claims']) && is_array($data['claims'])) {
        foreach ($data['claims'] as $claim) {
            $claimText = $claim['text'];
            
            // 如果這個陳述已經處理過，就跳過
            if (isset($unique_claims[$claimText])) {
                continue;
            }
            $unique_claims[$claimText] = true; // 標記為已處理

            if (isset($claim['claimReview'][0])) {
                $review = $claim['claimReview'][0];
                $claimant = $claim['claimant'] ?? '未知機構';
                $rating = $review['textualRating'] ?? '未知評等';
                $url = $review['url'] ?? '';

                // 2. 將新的查核結果插入資料庫
                $stmt = $conn->prepare("INSERT INTO fact_check_cache (claim_text, claimant, rating, url) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $claimText, $claimant, $rating, $url);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
    sleep(5); // 避免請求過於頻繁
}

$conn->close();
echo "熱門議題更新完成！\n";
?>