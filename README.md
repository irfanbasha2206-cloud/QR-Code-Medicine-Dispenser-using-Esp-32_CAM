# AegisRx: QR-Code Medicine Dispenser

AegisRx is an IoT prescription-scanning and medicine-dispensing project. An ESP32-CAM serves a live MJPEG video stream, while a Flask application detects prescription QR codes, extracts medicine quantities, and submits the result to a PHP cloud endpoint for dispensing records.

## Features

- Live, low-latency ESP32-CAM MJPEG stream in a web dashboard.
- Hybrid QR decoding using Pyzbar when available, with OpenCV fallbacks.
- Prescription parsing in the format `Medicine:Quantity|Medicine:Quantity`.
- Cloud dispatch to a PHP API that finds a prescription and marks it as dispensed.
- Local fallback result when the cloud service cannot be reached.
- ESP32-CAM firmware with stream, health-check, Wi-Fi recovery, and stability safeguards.

## Project layout

| Path | Purpose |
| --- | --- |
| `app.py` | Flask dashboard, MJPEG ingestion, QR detection, prescription parsing, and cloud dispatch. |
| `esp-32-cam.py` | MicroPython firmware for the ESP32-CAM video stream. |
| `receive_scan.php` | PHP endpoint that validates and records dispensing against the database. |
| `templates/index.html` | Browser dashboard UI. |
| `requirements.txt` | Python dependencies. |
| `test_esp32_stream.py` | Standalone ESP32-CAM stream/QR test script. |

## Prerequisites

- Python 3.9 or newer
- An ESP32-CAM running MicroPython with the `camera` module
- A reachable PHP/MySQL server for cloud dispensing (optional when using local fallback)
- A working `zbar` shared library if you want Pyzbar acceleration; OpenCV remains available as a fallback

## Setup

1. Install the Python packages:

   ```powershell
   python -m pip install -r requirements.txt
   ```

2. In `esp-32-cam.py`, set the Wi-Fi credentials appropriate to your network, then upload the file to the ESP32-CAM through Thonny or another MicroPython uploader.

3. Find the stream URL printed by the ESP32-CAM, normally `http://<device-ip>/stream`.

4. In `app.py`, configure `ESP32_STREAM_URL` to that URL. Configure `CLOUD_API_URL` if the PHP receiver is hosted elsewhere.

5. Start the dashboard:

   ```powershell
   python app.py
   ```

6. Open `http://localhost:5000` in a browser. The dashboard also includes an endpoint update control for changing the ESP32-CAM stream URL while the server is running.

## QR payload format

The application expects a pipe-separated list of medicine names and quantities:

```text
Paracetamol:2|Amoxicillin:1
```

Invalid or omitted quantities default to `1`.

## Cloud API

`app.py` sends a JSON `POST` request to `receive_scan.php` with this shape:

```json
{
  "qr_text": "Paracetamol:2|Amoxicillin:1",
  "medicines": [
    {"name": "Paracetamol", "qty": 2},
    {"name": "Amoxicillin", "qty": 1}
  ]
}
```

The PHP endpoint expects a `connection.php` file on the server that supplies a MySQL connection named `$conn`. It searches the `4973_patient` table, sets the matching record to `DISPENSED`, and returns the prescription and patient details as JSON.

## Notes

- Keep Wi-Fi credentials and database connection details out of version control.
- Update the hard-coded device and cloud URLs before deployment.
- This project supports dispensing workflow automation; any production use should include appropriate clinical validation, authentication, access controls, and audit requirements.
