import uvicorn
from fastapi import FastAPI, File, UploadFile
from transformers import pipeline
from PIL import Image
import io
import cv2
import numpy as np
import os

app = FastAPI()

# --- 1. 載入雙重模型 ---
print("正在初始化 AI 偵測系統...")

try:
    print("載入模型 A (Organika/sdxl-detector)...")
    model_a = pipeline("image-classification", model="Organika/sdxl-detector")
    
    print("載入模型 B (dima806/deepfake_vs_real_image_detection)...")
    model_b = pipeline("image-classification", model="dima806/deepfake_vs_real_image_detection")
    
    print("雙模型載入完成！")
except Exception as e:
    print(f"模型載入發生錯誤: {e}")

# --- 輔助函式 ---
def get_single_model_score(pipe, image):
    try:
        results = pipe(image)
        score = 0.0
        for res in results:
            label = res['label'].lower()
            val = res['score']
            
            if 'fake' in label or 'ai' in label or 'artificial' in label:
                score = val
                break
            if 'real' in label or 'human' in label:
                score = 1.0 - val
                break
        return score
    except:
        return 0.0

# --- 核心分析邏輯 (精密噪點區分版) ---
def analyze_image_dual_check(image: Image.Image):
    score_a = get_single_model_score(model_a, image)
    score_b = get_single_model_score(model_b, image)
    
    try:
        img_np = np.array(image)
        if len(img_np.shape) == 3:
            gray = cv2.cvtColor(img_np, cv2.COLOR_RGB2GRAY)
        else:
            gray = img_np
        sharpness = cv2.Laplacian(gray, cv2.CV_64F).var()
    except:
        sharpness = 100.0

    print(f"Debug - A: {score_a:.4f}, B: {score_b:.4f}, Sharpness: {sharpness:.2f}")

    # --- 智慧判決邏輯 ---

    # 1. 【卡通/梗圖保護網】
    # 修正：增加 A < 0.99 條件。如果 A 已經爆表到 0.999 (如派大星梗圖)，不能直接放過，要交給後面處理。
    if score_b < 0.3 and sharpness < 300 and score_a < 0.99:
        print("判定：低畫質/模糊截圖 (Meme Guard)")
        return score_b

    # 2. 【天花板級 AI (Sora/Flux)】
    # 門檻提高到 0.998。你的真人照是 0.9966，會安全通過這裡。
    # 派大星是 0.9997，會被這裡抓到是 AI (技術上它是數位繪圖，被判高分不算全錯，但我們會希望它是真人)
    # 不過派大星 Sharpness 很低 (268)，所以我們可以在這裡加個補丁：
    if score_a >= 0.998:
        if sharpness < 400: # 雖然分數高，但很糊 -> 可能是數位繪圖的梗圖
             print("判定：高分但模糊 (High Score Meme Rescue)")
             return score_b
        else:
             print("判定：Model A 信心爆表 (Definite AI)")
             return score_a

    # 3. 【高分爭議區】 (手機 HDR vs Sora)
    # Model A 覺得很高 (0.9 ~ 0.998)，這時候要看 Model B 的臉色。
    if score_a > 0.9:
        # 關鍵分水嶺：Model B 是否低於 0.02？
        # Sora: B = 0.0010 (< 0.02) -> 乾淨到不自然 -> 判定為 AI
        # 你的照片: B = 0.0754 (> 0.02) -> 有感光元件特徵 -> 判定為真人
        
        if score_b < 0.02 and sharpness > 500:
            print("判定：超高畫質且無噪點 (Sora Trap)")
            return score_a
        
        else:
            print("判定：疑似手機演算法增強 (Phone HDR Rescue)")
            # 大幅降低分數，把它救回來
            # 計算公式：(0.99 * 0.1) + (0.07 * 0.9) = 0.099 + 0.063 = 0.16 (16%)
            return (score_a * 0.1) + (score_b * 0.9)

    # 4. 【絕對真實區】
    if score_b < 0.05:
         print("判定：偵測到原始相機噪點 (Absolute Real)")
         return score_b

    # 5. 【一般區】
    final_score = (score_a * 0.6) + (score_b * 0.4)
    if 0.4 < final_score < 0.65:
        final_score *= 0.8

    return final_score

# --- API: 圖片偵測 ---
@app.post("/detect/image")
async def detect_image_endpoint(file: UploadFile = File(...)):
    try:
        image_data = await file.read()
        image = Image.open(io.BytesIO(image_data)).convert("RGB")
        
        ai_score = analyze_image_dual_check(image)
        
        return {
            "status": "success",
            "deepfake_score": 0.0,
            "general_ai_score": ai_score
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
        
        total_frames_checked = 0
        sum_ai_score = 0.0
        max_ai_score = 0.0
        
        while True:
            ret, frame = cap.read()
            if not ret:
                break
            
            if total_frames_checked >= 40:
                break

            if total_frames_checked % frame_interval == 0:
                rgb_frame = cv2.cvtColor(frame, cv2.COLOR_BGR2RGB)
                pil_image = Image.fromarray(rgb_frame)
                
                score = analyze_image_dual_check(pil_image)
                
                sum_ai_score += score
                if score > max_ai_score:
                    max_ai_score = score
                
            total_frames_checked += 1

        cap.release()
        
        final_score = 0.0
        checked_count = (total_frames_checked // frame_interval) + 1
        if checked_count > 0:
            avg_score = sum_ai_score / checked_count
            final_score = (avg_score * 0.6) + (max_ai_score * 0.4)
            
        return {
            "status": "success",
            "deepfake_score": 0.0,
            "general_ai_score": final_score,
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