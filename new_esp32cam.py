# ─────────────────────────────────────────────────────────────
#  ESP32-CAM  –  CLEAN STATION MODE (NO CONFLICT)
#  FULL INTERNET + FIXED DOMAIN: http://esp32cam.local/stream
# ─────────────────────────────────────────────────────────────
import camera
import network
import socket
import time
import machine
import gc

# ── 1. CPU CLOCK OPTIMIZATION ────────────────────────────────
try:
    machine.freq(160_000_000)
    print("[Power] CPU set to 160 MHz (Optimal Stability Mode)")
except Exception as e:
    print("[Power] CPU freq notice:", e)

# ── 2. DISABLE ESP32 AP & CONNECT TO MOBILE HOTSPOT ──────────
SSID     = "NarenTech"
PASSWORD = "9994119444"

# 1. First, completely TURN OFF ESP32 AP (Fake Wi-Fi stop aagidum!)
ap = network.WLAN(network.AP_IF)
ap.active(False)
print("[WiFi] ESP32 Hotspot disabled.")

# 2. Register Hostname
try:
    network.hostname("esp32cam")
    print("[mDNS] Hostname set: esp32cam.local")
except Exception as e:
    print("[mDNS] Notice:", e)

# 3. Connect to YOUR Mobile Hotspot
wlan = network.WLAN(network.STA_IF)
wlan.active(True)

for pwr in [16.5, 15.0, 14.0]:
    try:
        wlan.config(txpower=pwr)
        break
    except Exception:
        pass

print("\n[WiFi] Connecting to Mobile Hotspot:", SSID, "...")
wlan.connect(SSID, PASSWORD)

attempts = 0
while not wlan.isconnected():
    time.sleep(0.5)
    attempts += 1
    if attempts > 40:
        print("[WiFi] Failed to connect! Rebooting...")
        machine.reset()

ip = wlan.ifconfig()[0]
print("\n" + "="*50)
print("[WiFi] Connected to your Mobile Hotspot!")
print("[WiFi] ESP32 IP Address :", ip)
print("[WiFi] Open in Chrome    : http://" + ip + "/stream")
print("[WiFi] Or use Domain     : http://esp32cam.local/stream")
print("="*50 + "\n")

# ── 3. INIT CAMERA ──────────────────────────────────────────
time.sleep(0.5)
try:
    camera.deinit()
except Exception:
    pass

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
    print("[Camera] Initialized OK (QVGA @ Quality 20)")

# ── 4. HTTP HELPERS & ROBUST SOCKET TRANSMITTER ──────────────
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
    mv = memoryview(data)
    total = len(mv)
    sent = 0
    while sent < total:
        n = sock.send(mv[sent:])
        if n is None or n <= 0:
            raise OSError("Socket connection dropped during send")
        sent += n


def handle_stream(client):
    BOUNDARY = b'123456789000000000000987654321'
    send_headers(
        client, 
        '200 OK', 
        'multipart/x-mixed-replace; boundary=' + BOUNDARY.decode()
    )
    
    print("[Server] Client streaming started...")
    
    try:
        client.settimeout(6.0)
    except Exception:
        pass
    
    frame_count = 0
    consecutive_capture_fails = 0
    
    while True:
        if frame_count % 50 == 0:
            if not wlan.isconnected():
                print("[Server] WiFi disconnected! Closing stream.")
                break
            gc.collect()
        
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
            header = (
                b'--' + BOUNDARY + b'\r\n'
                b'Content-Type: image/jpeg\r\n'
                b'Content-Length: ' + str(len(buf)).encode() + b'\r\n\r\n'
            )
            send_all(client, header)
            
            chunk_size = 2048
            for i in range(0, len(buf), chunk_size):
                send_all(client, buf[i:i + chunk_size])
                
            send_all(client, b'\r\n')
            frame_count += 1
            time.sleep(0.045)
            
        except OSError as e:
            print("[Server] Stream socket closed/timeout:", e)
            break
        except Exception as e:
            print("[Server] Stream exception:", e)
            break
    
    print("[Server] Stream session ended after", frame_count, "frames")


def handle_health(client):
    body = b'{"status":"ok","device":"ESP32-CAM"}'
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


# ── 5. MAIN SERVER LOOP ─────────────────────────────────────
addr = socket.getaddrinfo('0.0.0.0', 80)[0][-1]
srv  = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(addr)
srv.listen(5)

print("[Server] Listening on port 80 (Backlog: 5)")
print("[Server] Waiting for connections...\n")

while True:
    if not wlan.isconnected():
        print("[WiFi] Lost connection! Reconnecting...")
        reconnect_tries = 0
        while not wlan.isconnected() and reconnect_tries < 30:
            try:
                wlan.connect(SSID, PASSWORD)
            except Exception:
                pass
            time.sleep(1)
            reconnect_tries += 1
        if not wlan.isconnected():
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
        if e.args and e.args[0] != 11:
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