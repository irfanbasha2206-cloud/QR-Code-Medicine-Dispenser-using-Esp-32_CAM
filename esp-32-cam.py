# ─────────────────────────────────────────────────────────────
#  ESP32-CAM MicroPython firmware  –  upload via Thonny
#  ANTI-BROWNOUT + STREAM STABILITY OPTIMIZED VERSION
# ─────────────────────────────────────────────────────────────
import camera
import network
import socket
import time
import machine
import gc

# ── 1. REDUCE CPU CLOCK (PREVENTS BROWNOUT) ────────────────
machine.freq(80_000_000)
print("[Power] CPU throttled to 80 MHz")

# ── 2. WI-FI CREDENTIALS ───────────────────────────────────
SSID     = "ras"
PASSWORD = "12345678"

# ── 3. CONNECT TO WI-FI WITH REDUCED TX POWER ──────────────
print("\n[WiFi] Connecting to", SSID, "...")
wlan = network.WLAN(network.STA_IF)
wlan.active(True)

# Reduce Wi-Fi transmission power spikes to avoid brownout
try:
    wlan.config(txpower=8.5)
    print("[Power] Wi-Fi TX power set to 8.5 dBm")
except:
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

# ── 4. INIT CAMERA (STABLE RESOLUTION) ──────────────────────
time.sleep(0.5) # Power stabilization delay
try:
    camera.deinit()
except:
    pass

_cam_ok = False
for _init_fn in [
    lambda: camera.init(0, format=camera.JPEG, framesize=camera.FRAME_QVGA, quality=15),
    lambda: camera.init(0, format=camera.JPEG, framesize=camera.FRAME_CIF, quality=15),
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
        lambda: camera.quality(15),
    ]:
        try:
            _fn()
        except:
            pass
    print("[Camera] Initialized OK (Stable Mode)")

# ── 5. HTTP HELPERS ────────────────────────────────────────
def read_request(client):
    try:
        raw   = client.recv(1024).decode('utf-8', 'ignore')
        first = raw.split('\r\n')[0]
        parts = first.split(' ')
        if len(parts) >= 2:
            return parts[0].upper(), parts[1].split('?')[0]
    except:
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


def handle_stream(client):
    """
    MJPEG stream handler with stability improvements:
    - WiFi connectivity watchdog (checks every 50 frames)
    - Socket write timeout prevents infinite blocking on dead connection
    - Larger send chunks for better throughput
    - Periodic garbage collection prevents memory fragmentation
    - Graceful error recovery with explicit cleanup
    """
    BOUNDARY = b'123456789000000000000987654321'
    send_headers(
        client, 
        '200 OK', 
        'multipart/x-mixed-replace; boundary=' + BOUNDARY.decode()
    )
    
    print("[Server] Client streaming started...")
    
    # Set a write timeout so send() doesn't block forever on dead WiFi
    try:
        client.settimeout(3)
    except:
        pass
    
    frame_count = 0
    consecutive_capture_fails = 0
    
    while True:
        # ── WiFi Watchdog: Check connectivity every 50 frames ──
        if frame_count % 50 == 0:
            if not wlan.isconnected():
                print("[Server] WiFi disconnected during stream! Closing client.")
                break
            # Periodic garbage collection to prevent memory fragmentation
            gc.collect()
        
        # ── Capture frame from camera ──
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
            # Build the multipart header + JPEG in fewer send() calls
            # This reduces per-call overhead and WiFi interrupt risk
            header = (
                b'--' + BOUNDARY + b'\r\n'
                b'Content-Type: image/jpeg\r\n'
                b'Content-Length: ' + str(len(buf)).encode() + b'\r\n\r\n'
            )
            client.send(header)
            
            # Send JPEG data in larger chunks (2048 bytes)
            # Fewer send() calls = fewer WiFi interrupt windows
            view = memoryview(buf)
            chunk_size = 2048
            for i in range(0, len(buf), chunk_size):
                client.send(view[i:i + chunk_size])
                
            client.send(b'\r\n')
            frame_count += 1
            
            # Frame pacing: prevents current surges on ESP32
            time.sleep(0.04)
            
        except OSError as e:
            # Socket error — client disconnected or WiFi hiccup
            print("[Server] Stream send error:", e)
            break
        except Exception as e:
            print("[Server] Client disconnected:", e)
            break
    
    print("[Server] Stream ended after", frame_count, "frames")


def handle_health(client):
    wifi_ok = wlan.isconnected()
    rssi = -1
    try:
        rssi = wlan.status('rssi')
    except:
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


# ── 6. MAIN SERVER LOOP (WITH WIFI RECONNECTION) ──────────
addr = socket.getaddrinfo('0.0.0.0', 80)[0][-1]
srv  = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(addr)
srv.listen(3)  # Increased backlog from 2 to 3 for reconnect tolerance

print("[Server] Listening on port 80")
print("[Server] Waiting for requests...\n")

while True:
    # ── WiFi Reconnection Watchdog ──
    if not wlan.isconnected():
        print("[WiFi] Connection lost! Attempting reconnect...")
        reconnect_tries = 0
        while not wlan.isconnected() and reconnect_tries < 30:
            try:
                wlan.connect(SSID, PASSWORD)
            except:
                pass
            time.sleep(1)
            reconnect_tries += 1
        
        if wlan.isconnected():
            new_ip = wlan.ifconfig()[0]
            print("[WiFi] Reconnected! IP:", new_ip)
        else:
            print("[WiFi] Reconnect failed after 30 attempts. Rebooting...")
            machine.reset()
    
    cl = None
    try:
        cl, remote = srv.accept()
        cl.settimeout(10)
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
        # Socket accept timeout or client error — normal during reconnects
        if e.args[0] != 11:  # EAGAIN is normal
            print("[Server] Socket error:", e)
    except Exception as e:
        print("[Server] Error:", e)
    finally:
        if cl:
            try:
                cl.close()
            except:
                pass
        gc.collect()  # Clean up after each request
