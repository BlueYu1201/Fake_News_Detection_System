import uvicorn
from fastapi import FastAPI, File, UploadFile, HTTPException
from transformers import pipeline
from PIL import Image
import io
import cv2
import numpy as np
import os

app = FastAPI()

# --- 1. 載入模型 ---
print("正在載入 AI 偵測模型，請稍候...")
# 改回這個模型，它的通用性較好，對非寫實圖片的誤判率稍微低一點
model_name = "umm-maybe/AI-image-detector"
detector = pipeline("image-classification", model=model_name)
print("模型載入完成！")

# --- 圖片偵測邏輯 ---
def predict_image(image: Image.Image):
    results = detector(image)
    # results 範例: [{'label': 'artificial', 'score': 0.99}, {'label': 'human', 'score': 0.01}]
    
    ai_score = 0.0
    for res in results:
        label = res['label'].lower()
        # 累積所有代表 AI 的標籤分數
        if 'ai' in label or 'fake' in label or 'artificial' in label:
            ai_score = res['score']
            break
        # 如果模型回傳的是 'human' 或 'real'，那 AI 分數就是 1.0 - real
        if 'real' in label or 'human' in label:
            ai_score = 1.0 - res['score']
            
    return ai_score

# --- API: 圖片偵測 ---
@app.post("/detect/image")
async def detect_image_endpoint(file: UploadFile = File(...)):
    try:
        # 讀取上傳的圖片
        image_data = await file.read()
        image = Image.open(io.BytesIO(image_data)).convert("RGB")
        
        # 進行預測 (取得 AI 的機率，例如 0.15)
        ai_probability = predict_image(image)
        
        # --- 修正判定與回傳邏輯 ---
        if ai_probability > 0.5:
            label = "AI"
            display_prob = ai_probability # 如果是 AI，顯示 AI 的機率 (例如 90%)
        else:
            label = "Real"
            display_prob = 1.0 - ai_probability # 如果是真人，顯示真人的機率 (1 - 0.15 = 0.85 即 85%)

        # 回傳格式
        return {
            "status": "success",
            "predicted_label": label,
            "probability": display_prob # 這裡回傳修正後的數值
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}

# --- API: 影片偵測 ---
@app.post("/detect/video")
async def detect_video_endpoint(file: UploadFile = File(...)):
    temp_filename = f"temp_{file.filename}"
    try:
        with open(temp_filename, "wb") as buffer:
            buffer.write(await file.read())
            
        cap = cv2.VideoCapture(temp_filename)
        if not cap.isOpened():
            raise Exception("無法開啟影片檔案")

        frame_count = 0
        ai_frames = 0
        total_frames_checked = 0
        fps = cap.get(cv2.CAP_PROP_FPS)
        if fps == 0: fps = 24 
        
        frame_interval = int(fps) 
        
        while True:
            ret, frame = cap.read()
            if not ret:
                break
                
            if frame_count % frame_interval == 0:
                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                pil_image = Image.fromarray(rgb_frame)
                
                prob = predict_image(pil_image)
                total_frames_checked += 1
                if prob > 0.6: 
                    ai_frames += 1
            
            frame_count += 1
            
            if total_frames_checked >= 30:
                break

        cap.release()
        
        final_prob = 0.0
        if total_frames_checked > 0:
            final_prob = ai_frames / total_frames_checked
            
        return {
            "status": "success",
            "deepfake": {
                "prob": final_prob
            }
        }

    except Exception as e:
        return {"status": "error", "message": str(e)}
    finally:
        if os.path.exists(temp_filename):
            os.remove(temp_filename)

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=8000)