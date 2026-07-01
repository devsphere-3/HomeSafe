import os
import urllib.request
import socket
import sys


def download_file(url, path, timeout=300):
    if os.path.exists(path):
        print(f"Already exists: {path}")
        return True
    print(f"Downloading {url} → {path}")
    try:
        socket.setdefaulttimeout(timeout)
        urllib.request.urlretrieve(url, path)
        print("Done.")
        return True
    except Exception as e:
        print(f"Error: {e}")
        return False
    finally:
        socket.setdefaulttimeout(None)


def download_models():
    models_dir = "models"
    os.makedirs(models_dir, exist_ok=True)
    download_file(
        "https://huggingface.co/opencv/face_recognition_sface/resolve/main/face_recognition_sface_2021dec.onnx",
        os.path.join(models_dir, "face_recognition_sface_2021dec.onnx"),
    )
    download_file(
        "https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite",
        os.path.join(models_dir, "blaze_face_short_range.tflite"),
    )


if __name__ == "__main__":
    download_models()
