document.addEventListener('DOMContentLoaded', function() {
    const searchBtn = document.getElementById('search-btn');
    const queryInput = document.getElementById('query-text');
    const resultsContainer = document.getElementById('results');
    const urlInput = document.getElementById('url-input');
    const checkUrlBtn = document.getElementById('check-url-btn');
    const imageFileInput = document.getElementById('image-file-input');
    const detectImageBtn = document.getElementById('detect-image-btn');
    const videoFileInput = document.getElementById('video-file-input');
    const detectVideoBtn = document.getElementById('detect-video-btn');
    const videoUrlInput = document.getElementById('video-url-input');
    const detectYtVideoBtn = document.getElementById('detect-yt-video-btn');
    const hotSearchSelect = document.getElementById('hot-search-select');
    const languageSelect = document.getElementById('language-select');

    // 進度條控制
    const progressOverlay = document.getElementById('progress-overlay');
    function startProgressSimulation() { if(progressOverlay) progressOverlay.style.display = 'flex'; }
    function completeProgress() { if(progressOverlay) progressOverlay.style.display = 'none'; }

    // 熱門搜尋載入
    fetch('api.php', { method: 'POST', body: new URLSearchParams('action=get_hot_searches') })
    .then(r => r.json())
    .then(d => {
        if(d.hot_topics && hotSearchSelect) {
            hotSearchSelect.innerHTML = '<option disabled selected>--- 選擇熱門議題 ---</option>';
            d.hot_topics.forEach(t => hotSearchSelect.innerHTML += `<option value="${t.claim_text}">[${t.rating}] ${t.claim_text.substr(0,20)}...</option>`);
        }
    })
    .catch(e => console.error("熱門搜尋載入失敗", e));

    if(hotSearchSelect) hotSearchSelect.onchange = function(){ queryInput.value=this.value; performSearch(); };

    // --- 核心功能函式 ---

    // 1. 文字搜尋
    function performSearch() {
        const q = queryInput.value.trim(); if(!q) return;
        resultsContainer.innerHTML = '<div class="info">查詢中...</div>';
        const fd = new FormData(); fd.append('action','search'); fd.append('query',q); fd.append('language', languageSelect.value);
        
        fetch('api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if (d.error) {
                resultsContainer.innerHTML = `<div class="error">查詢錯誤：${d.error}</div>`;
            } else if(d.claims && d.claims.length){
                let h = '<h3>🔍 查核結果</h3>';
                d.claims.slice(0,3).forEach(c=>{
                    const rating = c.claimReview[0].textualRating;
                    const colorClass = (rating.includes('不實') || rating.includes('錯誤') || rating.includes('False')) ? 'rating-false' : 'rating-true';
                    h += `<div class="claim"><p><strong>陳述：</strong>${c.text}</p><p><strong>評等：</strong><span class="${colorClass}">${rating}</span></p><a href="${c.claimReview[0].url}" target="_blank">查看詳情</a></div>`;
                });
                resultsContainer.innerHTML = h;
            } else {
                resultsContainer.innerHTML = '<div class="info">無相關查核報告。</div>';
            }
        })
        .catch(e => { resultsContainer.innerHTML = `<div class="error">連線錯誤：${e.message}</div>`; });
    }
    if(searchBtn) searchBtn.onclick = performSearch;

    // 2. 網址檢查
    if(checkUrlBtn) checkUrlBtn.onclick = function() {
        const u = urlInput.value.trim(); if(!u) return;
        resultsContainer.innerHTML = '<div class="info">檢查中...</div>';
        const fd = new FormData(); fd.append('action','check_url'); fd.append('url',u);
        
        fetch('api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            if(d.error) resultsContainer.innerHTML = `<div class="error">錯誤：${d.error}</div>`;
            else if(d.safe) resultsContainer.innerHTML = '<div class="result-display rating-true">✅ <strong>網址安全</strong> (Google Web Risk)</div>';
            else resultsContainer.innerHTML = `<div class="result-display rating-false">🚨 <strong>危險！</strong>偵測到威脅 (${d.threat_type})</div>`;
        })
        .catch(e => { resultsContainer.innerHTML = `<div class="error">連線錯誤：${e.message}</div>`; });
    };

    // 3. 圖片偵測 (關鍵修正)
    if(detectImageBtn) detectImageBtn.onclick = function() {
        const f = imageFileInput.files[0]; 
        if(!f) { resultsContainer.innerHTML = '<div class="error">請選擇圖片</div>'; return; }
        
        resultsContainer.innerHTML = '';
        startProgressSimulation();
        const fd = new FormData(); fd.append('action','detect_image'); fd.append('image_file',f);
        
        fetch('api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            completeProgress();
            
            // [修正重點] 優先檢查是否有錯誤訊息
            if (d.error) {
                let errorMsg = `<strong>處理失敗：</strong> ${d.error}`;
                if (d.details) errorMsg += `<br><small>詳細資訊: ${d.details}</small>`;
                resultsContainer.innerHTML = `<div class="error" style="word-break: break-all;">${errorMsg}</div>`;
                return;
            }

            let h = '<h3>🖼️ 圖片分析結果</h3>';
            
            // 嘗試讀取數值
            const fakeProb = d.fake_probability !== undefined ? d.fake_probability : (d.ai_detection ? d.ai_detection.fake_probability : undefined);

            if(fakeProb !== undefined) {
                const pct = (fakeProb * 100).toFixed(1);
                if(fakeProb > 0.5) h += `<div class="result-display rating-false">⚠️ <strong>疑似 AI/Deepfake</strong><p>合成可能性：${pct}%</p></div>`;
                else h += `<div class="result-display rating-true">✅ <strong>判定為真實影像</strong><p>合成可能性僅：${pct}%</p></div>`;
            } else {
                // 如果沒有錯誤訊息但也沒數據，這才顯示通用錯誤
                h += '<div class="error">AI 偵測失敗 (無法取得數據，且無明確錯誤訊息)</div>';
            }

            if(d.fact_check) {
                h += '<hr><h4>文字查核結果：</h4>';
                if(d.fact_check.claims && d.fact_check.claims.length) {
                    d.fact_check.claims.forEach(c => {
                         h += `<div class="claim"><p><strong>評等：</strong>${c.claimReview[0].textualRating}</p><a href="${c.claimReview[0].url}" target="_blank">詳情</a></div>`;
                    });
                } else if(d.fact_check.extracted_text) {
                     h += '<div class="info">已讀取圖片文字，但未找到相關查核報告。</div>';
                }
            }
            resultsContainer.innerHTML = h;
        })
        .catch(e => { 
            completeProgress(); 
            resultsContainer.innerHTML = `<div class="error">程式執行錯誤：${e.message}</div>`; 
        });
    };

    // 4. 影片偵測
    function handleVideo(fd) {
        resultsContainer.innerHTML = '';
        startProgressSimulation();
        fetch('api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(d => {
            completeProgress();
            if (d.error) {
                 resultsContainer.innerHTML = `<div class="error">錯誤：${d.error} ${d.details ? '('+d.details+')' : ''}</div>`;
                 return;
            }

            let h = '<h3>🎬 影片分析結果</h3>';
            if(d.status === 'success' && d.deepfake) {
                const prob = d.deepfake.prob;
                const pct = (prob * 100).toFixed(1);
                if(prob > 0.5) h += `<div class="result-display rating-false">⚠️ <strong>疑似 Deepfake</strong><p>偵測到合成特徵：${pct}%</p></div>`;
                else h += `<div class="result-display rating-true">✅ <strong>未檢測到明顯特徵</strong><p>合成可能性：${pct}%</p></div>`;
            } else {
                h += `<div class="error">錯誤：${d.message || d.error || '未知錯誤'}</div>`;
            }
            resultsContainer.innerHTML = h;
        })
        .catch(e => { completeProgress(); resultsContainer.innerHTML = `<div class="error">連線錯誤：${e.message}</div>`; });
    }

    if(detectVideoBtn) detectVideoBtn.onclick = function() {
        const f = videoFileInput.files[0]; if(f) { const fd=new FormData(); fd.append('action','detect_video'); fd.append('video_file',f); handleVideo(fd); }
    };
    if(detectYtVideoBtn) detectYtVideoBtn.onclick = function() {
        const u = videoUrlInput.value.trim(); if(u) { const fd=new FormData(); fd.append('action','detect_yt_video'); fd.append('video_url',u); handleVideo(fd); }
    };
});