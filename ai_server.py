import uvicorn
from fastapi import FastAPI, File, UploadFile, HTTPException
from transformers import pipeline
from PIL import Image
import io
import cv2
import numpy as np
import os

app = FastAPI()

# --- 1. 載入模型與工具 ---
print("正在載入 Deepfake 偵測模型與人臉辨識工具，請稍候...")

# 使用針對 Deepfake/Face Swap 的模型
model_name = "dima806/deepfake_vs_real_image_detection"
detector = pipeline("image-classification", model=model_name)

# 載入 OpenCV 的人臉偵測器
face_cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')

print("模型與工具載入完成！")

# --- 核心邏輯：輔助函式 ---
def analyze_single_face_prob(pil_image):
    """
    針對單一圖片(或裁切後的臉)進行 AI 預測
    """
    results = detector(pil_image)
    
    fake_prob = 0.0
    for res in results:
        label = res['label'].upper()
        score = res['score']
        
        if label in ['FAKE', 'AI', 'ARTIFICIAL', 'DEEPFAKE']:
            fake_prob = score
            break
        
        if label in ['REAL', 'HUMAN']:
            fake_prob = 1.0 - score
            
    return fake_prob

# --- 核心邏輯：圖片預測 (含人臉偵測) ---
def predict_image(image: Image.Image):
    # 轉 OpenCV 格式
    img_np = np.array(image)
    if len(img_np.shape) == 2:
        img_np = cv2.cvtColor(img_np, cv2.COLOR_GRAY2RGB)
        
    open_cv_image = img_np[:, :, ::-1].copy() # RGB to BGR
    gray = cv2.cvtColor(open_cv_image, cv2.COLOR_BGR2GRAY)

    # 偵測人臉
    faces = face_cascade.detectMultiScale(
        gray,
        scaleFactor=1.1,
        minNeighbors=5,
        minSize=(30, 30)
    )

    # A: 無臉，分析全圖
    if len(faces) == 0:
        return analyze_single_face_prob(image)

    # B: 有臉，切臉分析
    max_fake_score = 0.0
    
    for (x, y, w, h) in faces:
        margin = int(w * 0.1)
        x_start = max(0, x - margin)
        y_start = max(0, y - margin)
        x_end = min(image.width, x + w + margin)
        y_end = min(image.height, y + h + margin)

        face_crop = image.crop((x_start, y_start, x_end, y_end))
        score = analyze_single_face_prob(face_crop)
        
        if score > max_fake_score:
            max_fake_score = score

    return max_fake_score

# --- API: 圖片偵測 ---
@app.post("/detect/image")
async def detect_image_endpoint(file: UploadFile = File(...)):
    try:
        image_data = await file.read()
        image = Image.open(io.BytesIO(image_data)).convert("RGB")
        
        ai_probability = predict_image(image)
        
        if ai_probability > 0.5:
            label = "AI/Deepfake"
            display_prob = ai_probability 
        else:
            label = "Real"
            display_prob = 1.0 - ai_probability 

        return {
            "status": "success",
            "predicted_label": label,
            "probability": display_prob
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}

# --- API: 影片偵測 ---
@app.post("/detect/video")
async def detect_video_endpoint(file: UploadFile = File(...)):
    # 確保只取檔名，避免路徑錯誤
    safe_filename = os.path.basename(file.filename)
    temp_filename = f"temp_{safe_filename}"
    
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
                
                if prob > 0.7: 
                    ai_frames += 1
            
            frame_count += 1
            
            if total_frames_checked >= 60:
                break

        cap.release()
        
        final_prob = 0.0
        if total_frames_checked > 0:
            final_prob = ai_frames / total_frames_checked
            
        return {
            "status": "success",
            "deepfake": {
                "prob": final_prob,
                "frames_checked": total_frames_checked,
                "fake_frames": ai_frames
            },
            "debug_output": f"Checked {total_frames_checked} frames, found {ai_frames} suspicious frames."
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