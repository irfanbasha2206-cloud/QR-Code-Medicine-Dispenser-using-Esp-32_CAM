import cv2

ESP32_STREAM_URL = "http://172.20.99.128/stream"

cap = cv2.VideoCapture(ESP32_STREAM_URL)

if not cap.isOpened():
    print("❌ ESP32-CAM stream connect aagala")
    exit()

print("✅ ESP32-CAM stream connected!")
print("📷 QR code-ah camera munadi kaamikka...")
print("Press Q to exit")

qr_detector = cv2.QRCodeDetector()

last_data = ""

while True:
    ret, frame = cap.read()

    if not ret:
        print("❌ Frame receive aagala")
        break

    data = ""
    points = None

    # -----------------------------
    # 1. Original frame
    # -----------------------------
    data, points, _ = qr_detector.detectAndDecode(frame)

    # -----------------------------
    # 2. If failed -> grayscale
    # -----------------------------
    if not data:
        gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
        data, points, _ = qr_detector.detectAndDecode(gray)

    # -----------------------------
    # 3. If failed -> resize
    # -----------------------------
    if not data:
        resized = cv2.resize(
            frame,
            None,
            fx=2,
            fy=2,
            interpolation=cv2.INTER_CUBIC
        )

        data, points, _ = qr_detector.detectAndDecode(resized)

        # Convert points back to original frame size
        if points is not None:
            points = points / 2

    # -----------------------------
    # QR detected
    # -----------------------------
    if data:
        if data != last_data:
            print("\n✅ QR CODE DETECTED!")
            print("📄 QR Content:", data)
            last_data = data

        if points is not None:
            points = points.astype(int)

            for i in range(4):
                pt1 = tuple(points[0][i])
                pt2 = tuple(points[0][(i + 1) % 4])
                cv2.line(
                    frame,
                    pt1,
                    pt2,
                    (0, 255, 0),
                    3
                )

        cv2.putText(
            frame,
            "QR Detected",
            (20, 40),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.9,
            (0, 255, 0),
            2
        )

    else:
        cv2.putText(
            frame,
            "Searching for QR...",
            (20, 40),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.8,
            (0, 255, 255),
            2
        )

    cv2.imshow("ESP32-CAM QR Scanner", frame)

    if cv2.waitKey(1) & 0xFF == ord("q"):
        break

cap.release()
cv2.destroyAllWindows()