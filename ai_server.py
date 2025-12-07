import uvicorn
from fastapi import FastAPI, File, UploadFile
from transformers import pipeline
from PIL import Image
import io
import cv2
import numpy as np
import os

app = FastAPI()

# --- 1. 載入模型 ---
print("正在載入 AI 模型，請稍候...")

# 模型 A: 針對 Deepfake (換臉)
print("載入 Deepfake 模型...")
deepfake_model = "dima806/deepfake_vs_real_image_detection"
deepfake_detector = pipeline("image-classification", model=deepfake_model)

# 模型 B: 針對 General AI (AI 生成/繪圖)
print("載入 General AI 模型...")
general_model = "umm-maybe/AI-image-detector"
general_detector = pipeline("image-classification", model=general_model)

# 人臉偵測工具
face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

print("所有模型載入完成！")

# --- 輔助函式：計算分數 ---
def get_ai_score(pipe, image):
    results = pipe(image)
    score = 0.0
    for res in results:
        label = res['label'].lower()
        if 'fake' in label or 'ai' in label or 'artificial' in label:
            score = res['score']
            break
        if 'real' in label or 'human' in label:
            score = 1.0 - res['score']
    return score

# --- 核心分析邏輯 ---
def analyze_image_content(image: Image.Image):
    # 1. 計算通用 AI 分數 (分析整張圖)
    general_score = get_ai_score(general_detector, image)

    # 2. 計算 Deepfake 分數 (只分析人臉)
    deepfake_score = 0.0
    
    img_np = np.array(image)
    if len(img_np.shape) == 2:
        img_np = cv2.cvtColor(img_np, cv2.COLOR_GRAY2RGB)
    
    open_cv_image = img_np[:, :, ::-1].copy()
    gray = cv2.cvtColor(open_cv_image, cv2.COLOR_BGR2GRAY)
    
    faces = face_cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=5, minSize=(30, 30))
    
    if len(faces) > 0:
        for (x, y, w, h) in faces:
            margin = int(w * 0.1)
            x_start = max(0, x - margin)
            y_start = max(0, y - margin)
            x_end = min(image.width, x + w + margin)
            y_end = min(image.height, y + h + margin)
            
            face_crop = image.crop((x_start, y_start, x_end, y_end))
            s = get_ai_score(deepfake_detector, face_crop)
            if s > deepfake_score:
                deepfake_score = s
    else:
        # 如果沒臉，Deepfake 分數為 0
        deepfake_score = 0.0

    return deepfake_score, general_score

# --- API: 圖片偵測 ---
@app.post("/detect/image")
async def detect_image_endpoint(file: UploadFile = File(...)):
    try:
        image_data = await file.read()
        image = Image.open(io.BytesIO(image_data)).convert("RGB")
        
        d_score, g_score = analyze_image_content(image)
        
        return {
            "status": "success",
            "deepfake_score": d_score,
            "general_ai_score": g_score
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}

# --- API: 影片偵測 ---
@app.post("/detect/video")
async def detect_video_endpoint(file: UploadFile = File(...)):
    safe_filename = os.path.basename(file.filename)
    temp_filename = f"temp_{safe_filename}"
    try:
        with open(temp_filename, "wb") as buffer:
            buffer.write(await file.read())
            
        cap = cv2.VideoCapture(temp_filename)
        if not cap.isOpened():
            raise Exception("無法開啟影片")

        fps = cap.get(cv2.CAP_PROP_FPS)
        if fps == 0: fps = 24
        frame_interval = int(fps) 
        
        frame_count = 0
        total_frames_checked = 0
        
        sum_deepfake = 0.0
        sum_general = 0.0
        max_deepfake = 0.0
        max_general = 0.0
        
        while True:
            ret, frame = cap.read()
            if not ret:
                break
            
            if total_frames_checked >= 40:
                break

            if frame_count % frame_interval == 0:
                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                pil_image = Image.fromarray(rgb_frame)
                
                d, g = analyze_image_content(pil_image)
                
                sum_deepfake += d
                sum_general += g
                
                if d > max_deepfake: max_deepfake = d
                if g > max_general: max_general = g
                
                total_frames_checked += 1
            
            frame_count += 1

        cap.release()
        
        final_deepfake = 0.0
        final_general = 0.0
        
        if total_frames_checked > 0:
            avg_d = sum_deepfake / total_frames_checked
            avg_g = sum_general / total_frames_checked
            
            # 綜合分數：70% 平均 + 30% 最大值 (避免漏抓單幀異常)
            final_deepfake = (avg_d * 0.7) + (max_deepfake * 0.3)
            final_general = (avg_g * 0.7) + (max_general * 0.3)
            
        return {
            "status": "success",
            "deepfake_score": final_deepfake,
            "general_ai_score": final_general,
            "frames_checked": total_frames_checked
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}
    finally:
        if os.path.exists(temp_filename):
            try:
                os.remove(temp_filename)
            except:
                pass

if __name__ == "__main__":
    uvicorn.run(app, host="127.0.0.1", port=8000)