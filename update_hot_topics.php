<?php
// 設置腳本執行時間為無限，確保不會因為超時而停止
set_time_limit(0);
// 忽略使用者斷開連線，確保腳本在後台持續運行
ignore_user_abort(true);

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

echo "熱門議題自動更新服務已啟動 (每 1 小時更新一次)...\n";

// --- 無窮迴圈，實現常駐服務 ---
while (true) {
    $startTime = date('Y-m-d H:i:s');
    echo "[{$startTime}] 開始本輪熱門議題更新...\n";

    // 每次循環都重新建立資料庫連線，避免長時間閒置導致連線超時 (MySQL has gone away)
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        echo "資料庫連線失敗: " . $conn->connect_error . "\n";
        // 如果連線失敗，等待 1 分鐘後重試，而不是 1 小時
        sleep(60); 
        continue;
    }
    $conn->set_charset("utf8mb4");

    // 用來暫存所有抓取到的資料，避免邊抓邊寫入導致長時間鎖表或資料不完整
    $all_claims_buffer = [];
    $unique_claims = []; // 用來避免重複的陣列

    // 1. 先抓取所有資料到記憶體
    foreach ($search_topics as $topic) {
        echo "正在查詢 API 主題: $topic ...\n";
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

                    // 將資料存入緩衝區
                    $all_claims_buffer[] = [
                        'claim_text' => $claimText,
                        'claimant'   => $claimant,
                        'rating'     => $rating,
                        'url'        => $url
                    ];
                }
            }
        }
        // 避免 API 請求過於頻繁
        sleep(2); 
    }

    // 2. 一次性寫入資料庫
    if (!empty($all_claims_buffer)) {
        echo "共獲取 " . count($all_claims_buffer) . " 筆資料，正在更新資料庫...\n";
        
        // 清空舊的快取資料
        $conn->query("TRUNCATE TABLE fact_check_cache");

        // 準備插入語句
        $stmt = $conn->prepare("INSERT INTO fact_check_cache (claim_text, claimant, rating, url) VALUES (?, ?, ?, ?)");
        
        // 批次執行插入
        $conn->begin_transaction(); // 開啟事務處理以加快速度
        try {
            foreach ($all_claims_buffer as $row) {
                $stmt->bind_param("ssss", $row['claim_text'], $row['claimant'], $row['rating'], $row['url']);
                $stmt->execute();
            }
            $conn->commit();
            echo "資料庫更新成功。\n";
        } catch (Exception $e) {
            $conn->rollback();
            echo "資料庫更新發生錯誤: " . $e->getMessage() . "\n";
        }
        $stmt->close();

    } else {
        echo "本輪未獲取到任何有效資料，略過資料庫更新。\n";
    }

    // 關閉資料庫連線
    $conn->close();

    $endTime = date('Y-m-d H:i:s');
    echo "[{$endTime}] 更新完成。系統將休眠 1 小時...\n";
    echo "--------------------------------------------------\n";

    // 3. 休眠 1 小時 (3600 秒)
    sleep(3600);
}
?>