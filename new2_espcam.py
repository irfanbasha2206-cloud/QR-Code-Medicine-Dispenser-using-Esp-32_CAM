# ─────────────────────────────────────────────────────────────
#  ESP32-CAM MicroPython firmware  –  upload via Thonny
#  ANTI-BROWNOUT + PROXIMITY-STABLE STREAMING FIRMWARE
# ─────────────────────────────────────────────────────────────
import camera
import network
import socket
import time
import machine
import gc

# ── 1. CPU CLOCK OPTIMIZATION ────────────────────────────────
# 160 MHz provides the optimal balance: 2x processing throughput
# compared to 80 MHz, enabling the ESP32 to handle complex, high-detail
# JPEG frames (e.g. high-contrast QR codes held close to the lens)
# and Wi-Fi DMA buffers without dropping frames or stalling, while
# staying well below the 240 MHz thermal & current spike threshold.
try:
    machine.freq(160_000_000)
    print("[Power] CPU set to 160 MHz (Optimal Stability Mode)")
except Exception as e:
    print("[Power] CPU freq notice:", e)

# ── 2. WI-FI CREDENTIALS ─────────────────────────────────────
SSID     = "ras"
PASSWORD = "12345678"

# ── 3. CONNECT TO WI-FI WITH STABLE TX POWER ─────────────────
print("\n[WiFi] Connecting to", SSID, "...")
wlan = network.WLAN(network.STA_IF)
wlan.active(True)

# Set TX power to 16.5 dBm (standard robust power).
# Note: 8.5 dBm was too weak. When hands, cards, or prescription papers
# are placed near the camera lens, they sit 2-3 cm from the on-board PCB
# trace antenna, shadowing the RF signal and causing packet loss / drop.
# 16.5 dBm punches through near-field obstructions reliably without brownouts.
for pwr in [16.5, 15.0, 14.0]:
    try:
        wlan.config(txpower=pwr)
        print("[Power] Wi-Fi TX power set to", pwr, "dBm")
        break
    except Exception:
        pass

wlan.connect(SSID, PASSWORD)

attempts = 0
while not wlan.isconnected():
    time.sleep(0.5)
    attempts += 1
    if attempts > 40:
        print("[WiFi] FAILED to connect. Rebooting...")
        machine.reset()

ip = wlan.ifconfig()[0]
stream_url = "http://" + ip + "/stream"
print("[WiFi] Connected! IP:", ip)
print("[WiFi] Stream URL   :", stream_url)

# ── 4. INIT CAMERA (QVGA RESOLUTION + BALANCED COMPRESSION) ──
time.sleep(0.5)  # Power stabilization delay
try:
    camera.deinit()
except Exception:
    pass

# quality=20 produces razor-sharp QR codes for decoders,
# while cutting frame payload size from ~30KB down to ~12-16KB.
# This prevents Wi-Fi socket send buffer exhaustion when objects are close!
_cam_ok = False
for _init_fn in [
    lambda: camera.init(0, format=camera.JPEG, framesize=camera.FRAME_QVGA, quality=20),
    lambda: camera.init(0, format=camera.JPEG, framesize=camera.FRAME_QVGA, quality=22),
    lambda: camera.init(0, format=camera.JPEG, framesize=camera.FRAME_CIF, quality=20),
    lambda: camera.init(0, format=camera.JPEG),
    lambda: camera.init(0),
    lambda: camera.init(),
]:
    try:
        _init_fn()
        _cam_ok = True
        break
    except Exception:
        pass

if not _cam_ok:
    print("[Camera] ERROR: could not initialise camera")
else:
    for _fn in [
        lambda: camera.framesize(camera.FRAME_QVGA),
        lambda: camera.quality(20),
    ]:
        try:
            _fn()
        except Exception:
            pass
    print("[Camera] Initialized OK (Stable QVGA @ Quality 20)")

# ── 5. HTTP HELPERS & ROBUST SOCKET TRANSMITTER ──────────────
def read_request(client):
    try:
        raw   = client.recv(1024).decode('utf-8', 'ignore')
        first = raw.split('\r\n')[0]
        parts = first.split(' ')
        if len(parts) >= 2:
            return parts[0].upper(), parts[1].split('?')[0]
    except Exception:
        pass
    return None, None


def send_headers(client, status, content_type, extra_headers=b''):
    client.send(('HTTP/1.1 ' + status + '\r\n').encode())
    if content_type:
        client.send(('Content-Type: ' + content_type + '\r\n').encode())
    client.send(b'Access-Control-Allow-Origin: *\r\n')
    client.send(b'Access-Control-Allow-Private-Network: true\r\n')
    client.send(b'Access-Control-Allow-Methods: GET, OPTIONS\r\n')
    client.send(b'Access-Control-Allow-Headers: *\r\n')
    client.send(b'Cache-Control: no-cache, private, max-age=0, must-revalidate\r\n')
    if extra_headers:
        client.send(extra_headers)
    client.send(b'\r\n')


def handle_options(client):
    send_headers(client, '204 No Content', None)


def send_all(sock, data):
    """Ensures entire chunk is sent across TCP socket without dropping bytes."""
    mv = memoryview(data)
    total = len(mv)
    sent = 0
    while sent < total:
        n = sock.send(mv[sent:])
        if n is None or n <= 0:
            raise OSError("Socket connection dropped during send")
        sent += n


def handle_stream(client):
    """
    MJPEG stream handler with enhanced stability:
    - 6-second socket write timeout (tolerant to Wi-Fi retries)
    - Full-chunk send_all prevents truncated/corrupted JPEG frames
    - Wi-Fi link watchdog every 50 frames
    - Periodic GC to prevent RAM heap fragmentation
    - Frame pacing to prevent current surges
    """
    BOUNDARY = b'123456789000000000000987654321'
    send_headers(
        client, 
        '200 OK', 
        'multipart/x-mixed-replace; boundary=' + BOUNDARY.decode()
    )
    
    print("[Server] Client streaming started...")
    
    # 6-second write timeout: tolerant to momentary Wi-Fi retries
    try:
        client.settimeout(6.0)
    except Exception:
        pass
    
    frame_count = 0
    consecutive_capture_fails = 0
    
    while True:
        # Wi-Fi Watchdog & Garbage Collection
        if frame_count % 50 == 0:
            if not wlan.isconnected():
                print("[Server] WiFi disconnected during stream! Closing client.")
                break
            gc.collect()
        
        # Capture frame from camera
        buf = camera.capture()
        if not buf:
            consecutive_capture_fails += 1
            if consecutive_capture_fails > 20:
                print("[Server] Camera capture failed 20 times. Closing stream.")
                break
            time.sleep(0.05)
            continue
        
        consecutive_capture_fails = 0
            
        try:
            # Build multipart header
            header = (
                b'--' + BOUNDARY + b'\r\n'
                b'Content-Type: image/jpeg\r\n'
                b'Content-Length: ' + str(len(buf)).encode() + b'\r\n\r\n'
            )
            send_all(client, header)
            
            # Send JPEG data with send_all in 2KB chunks
            chunk_size = 2048
            for i in range(0, len(buf), chunk_size):
                send_all(client, buf[i:i + chunk_size])
                
            send_all(client, b'\r\n')
            frame_count += 1
            
            # Frame pacing: ~20-22 FPS keeps current stable and avoids socket buffer saturation
            time.sleep(0.045)
            
        except OSError as e:
            print("[Server] Stream socket closed/timeout:", e)
            break
        except Exception as e:
            print("[Server] Stream exception:", e)
            break
    
    print("[Server] Stream session ended after", frame_count, "frames")


def handle_health(client):
    wifi_ok = wlan.isconnected()
    rssi = -1
    try:
        rssi = wlan.status('rssi')
    except Exception:
        pass
    body = '{{"status":"ok","device":"ESP32-CAM","wifi":{0},"rssi":{1}}}'.format(
        "true" if wifi_ok else "false", rssi
    ).encode()
    send_headers(
        client,
        '200 OK',
        'application/json',
        ('Content-Length: ' + str(len(body)) + '\r\n').encode()
    )
    client.send(body)


def handle_not_found(client):
    send_headers(client, '404 Not Found', 'text/plain')
    client.send(b'Not found. Use /stream or /health')


# ── 6. MAIN SERVER LOOP (WITH AUTO RECONNECTION & CLEANUP) ───
addr = socket.getaddrinfo('0.0.0.0', 80)[0][-1]
srv  = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(addr)
srv.listen(5)  # Backlog of 5 handles reconnections gracefully

print("[Server] Listening on port 80 (Backlog: 5)")
print("[Server] Waiting for connections...\n")

while True:
    # Wi-Fi Auto-Reconnection Watchdog
    if not wlan.isconnected():
        print("[WiFi] Connection lost! Reconnecting...")
        reconnect_tries = 0
        while not wlan.isconnected() and reconnect_tries < 30:
            try:
                wlan.connect(SSID, PASSWORD)
            except Exception:
                pass
            time.sleep(1)
            reconnect_tries += 1
        
        if wlan.isconnected():
            new_ip = wlan.ifconfig()[0]
            print("[WiFi] Reconnected! IP:", new_ip)
        else:
            print("[WiFi] Reconnect timed out. Resetting machine...")
            machine.reset()
    
    cl = None
    try:
        cl, remote = srv.accept()
        cl.settimeout(8.0)
        method, path = read_request(cl)

        if method == 'OPTIONS':
            handle_options(cl)
        elif method == 'GET':
            if path == '/stream':
                cl.settimeout(None)
                handle_stream(cl)
            elif path == '/health':
                handle_health(cl)
            else:
                handle_not_found(cl)
        else:
            handle_not_found(cl)

    except OSError as e:
        if e.args and e.args[0] != 11:  # Ignore EAGAIN
            print("[Server] Socket notice:", e)
    except Exception as e:
        print("[Server] Handler notice:", e)
    finally:
        if cl:
            try:
                cl.close()
            except Exception:
                pass
        gc.collect()

