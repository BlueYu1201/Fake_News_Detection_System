    <!DOCTYPE html>
    <html lang="zh">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
        <meta http-equiv="Pragma" content="no-cache">
        <meta http-equiv="Expires" content="0">
        <title>事實查核與假訊息偵測系統</title>
        <link rel="stylesheet" href="css/style.css">
    </head>
    <body>
    <div class="container">
        
        <h2>🔍 文字事實查核</h2>
        <div class="hot-searches-container">
            <label for="hot-search-select">或直接選擇熱門議題查詢：</label>
            <select id="hot-search-select" class="input-control">
                <option value="" disabled selected>--- 載入中 ---</option>
            </select>
        </div>
        <input type="text" id="query-text" placeholder="或在此手動輸入查詢內容" class="input-control">
        <select id="language-select" class="input-control">
            <option value="zh">中文</option>
            <option value="en">English</option>
        </select>
        <button id="search-btn" class="search-btn">查詢文字</button>

        <hr>

        <h2>🔗 網址安全性偵測</h2>
        <p>輸入或貼上可疑的網址，檢查它是否為已知的釣魚或惡意網站。</p>
        <input type="url" id="url-input" placeholder="請輸入或貼上要檢查的網址 (例如 https://...)" class="input-control">
        <button id="check-url-btn" class="search-btn">檢查網址</button>

        <hr>
        
        <h2>🖼️ 圖片 AI 生成偵測</h2>
        <p>上傳圖片，偵測它是否由 AI 生成 (例如 Midjourney, Stable Diffusion 等)。</p>
        <input type="file" id="image-file-input" accept="image/*" class="input-control">
        <button id="detect-image-btn" class="search-btn">偵測圖片</button>

        <hr>

        <h2>🎬 影片 Deepfake 偵測</h2>
        <p><strong>選項 1:</strong> 請直接上傳影片檔案進行分析。</p>
        <input type="file" id="video-file-input" accept="video/*" class="input-control">
        <button id="detect-video-btn" class="search-btn">偵測上傳的影片</button>

        <p style="margin-top: 20px;"><strong>選項 2:</strong> 或貼上 YouTube 網址進行分析。</p>
        <input type="url" id="video-url-input" placeholder="請輸入或貼上 YouTube 網址" class="input-control">
        <button id="detect-yt-video-btn" class="search-btn">偵測 YouTube 影片</button>
        
        <div id="results" class="results"></div>    

    </div>

    <!-- 彈出式進度條視窗 -->
    <div id="progress-overlay" class="progress-overlay" style="display: none;">
        <div class="progress-modal">
            <h3 id="progress-title">處理中，請稍候...</h3>
            <p id="progress-message">正在初始化...</p>
            <div class="progress-container">
                <div id="progress-bar" class="progress-bar">0%</div>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
    </body>
    </html>