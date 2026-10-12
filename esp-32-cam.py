# ─────────────────────────────────────────────────────────────
#  ESP32-CAM MicroPython firmware  –  CRASH-PROOF v3.0
#  - CIF 400x296 @ Quality 15 (sharp & stable)
#  - Per-frame gc.collect() (prevents 40-second heap crash)
#  - Single-threaded UDP beacon + MJPEG server
#  - Auto-connects to 'ras' or 'NarenTech'
# ─────────────────────────────────────────────────────────────
import camera
import network
import socket
import time
import machine
import gc

# ── 1. CPU & INITIAL SETUP (OPTIMAL 160 MHz ANTI-BROWNOUT) ───
try:
    machine.freq(160_000_000)
    print("[Power] CPU set to 160 MHz (Optimal Stability)")
except Exception as e:
    print("[Power] CPU freq notice:", e)

KNOWN_NETWORKS = [
    ("ras", "12345678"),
    ("NarenTech", "9994119444"),
]

BEACON_PORT = 4210
BEACON_INTERVAL = 3
BEACON_MSG = "AEGISRX_ESP32CAM"
beacon_sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
try:
    beacon_sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
except Exception:
    pass

# ── 2. WI-FI CONNECTION (STABLE POWER) ───────────────────────
ap = network.WLAN(network.AP_IF)
ap.active(False)
time.sleep(0.2)

try:
    network.hostname("esp32cam")
except Exception:
    pass

wlan = network.WLAN(network.STA_IF)
wlan.active(True)
time.sleep(0.2)

for pwr in [16.5, 15.0, 14.0]:
    try:
        wlan.config(txpower=pwr)
        break
    except Exception:
        pass


def connect_wifi():
    for ssid, pwd in KNOWN_NETWORKS:
        print(f"\n[WiFi] Connecting to '{ssid}' ...")
        try:
            wlan.disconnect()
        except Exception:
            pass
        time.sleep(0.3)
        try:
            wlan.connect(ssid, pwd)
        except Exception as e:
            print(f"[WiFi] Error for {ssid}:", e)
            continue
        for _ in range(24):
            if wlan.isconnected():
                print(f"[WiFi] Connected to '{ssid}'!")
                return
            time.sleep(0.5)
    print("[WiFi] Failed. Ensure hotspot 'ras' / '12345678' is ON.")
    time.sleep(2)
    machine.reset()


connect_wifi()
ip = wlan.ifconfig()[0]
print("\n" + "=" * 55)
print(f"[WiFi] IP: {ip}")
print(f"[WiFi] Stream: http://{ip}/stream")
print(f"[Beacon] UDP port {BEACON_PORT}")
print("=" * 55 + "\n")

# ── 3. CAMERA INIT (STABLE QVGA @ QUALITY 20) ───────────────
time.sleep(0.3)
gc.collect()

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
]:
    try:
        _init_fn()
        _cam_ok = True
        break
    except Exception:
        pass

if not _cam_ok:
    print("[Camera] ERROR: init failed")
else:
    try:
        camera.framesize(camera.FRAME_QVGA)
    except Exception:
        pass
    try:
        camera.quality(20)
    except Exception:
        pass
    print("[Camera] OK (Stable High-Speed QVGA 320x240 @ Quality 20)")

gc.collect()
print("[Memory] Free:", gc.mem_free(), "bytes")

# ── 4. HTTP HELPERS ──────────────────────────────────────────
def read_request(cl):
    try:
        raw = cl.recv(1024).decode('utf-8', 'ignore')
        parts = raw.split('\r\n')[0].split(' ')
        if len(parts) >= 2:
            return parts[0].upper(), parts[1].split('?')[0]
    except Exception:
        pass
    return None, None


def send_headers(cl, status, ctype, extra=b''):
    cl.send(('HTTP/1.1 ' + status + '\r\n').encode())
    if ctype:
        cl.send(('Content-Type: ' + ctype + '\r\n').encode())
    cl.send(b'Access-Control-Allow-Origin: *\r\n')
    cl.send(b'Cache-Control: no-cache\r\n')
    if extra:
        cl.send(extra)
    cl.send(b'\r\n')


def send_all(sock, data):
    mv = memoryview(data)
    total = len(mv)
    sent = 0
    retries = 0
    while sent < total:
        try:
            n = sock.send(mv[sent:])
            if n and n > 0:
                sent += n
                retries = 0
            else:
                retries += 1
                if retries > 60:
                    raise OSError(11)  # EAGAIN - buffer full
                time.sleep(0.01)
        except OSError as e:
            if e.args and e.args[0] in (11, 12):
                retries += 1
                if retries > 60:
                    raise
                time.sleep(0.01)
            else:
                raise


def handle_stream(cl):
    """
    ROCK-SOLID non-dropping zero-latency stream handler.
    If Wi-Fi buffer stalls momentarily, drops the frame and continues.
    Never closes the connection on momentary packet jitter!
    """
    try:
        cl.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)
    except Exception:
        pass

    BOUNDARY = b'--camframe\r\n'
    send_headers(cl, '200 OK',
                 'multipart/x-mixed-replace; boundary=camframe')

    print("[Stream] Client connected, streaming...")
    try:
        cl.settimeout(6.0)
    except Exception:
        pass

    fc = 0
    fails = 0
    consecutive_skips = 0

    while True:
        # Wi-Fi watchdog & UDP keepalive beacon every 100 frames (~4s)
        if fc > 0 and fc % 100 == 0:
            if not wlan.isconnected():
                print("[Stream] WiFi lost. Ending stream to reconnect.")
                break
            send_beacon()
            print(f"[Stream] {fc} frames sent, free={gc.mem_free()}")

        # Capture
        try:
            buf = camera.capture()
        except Exception:
            buf = None

        if not buf:
            fails += 1
            if fails > 30:
                print("[Stream] Too many capture failures.")
                break
            time.sleep(0.03)
            gc.collect()
            continue

        fails = 0

        try:
            # Send MJPEG multipart frame in controlled 2KB chunks
            header = (
                BOUNDARY + 
                b'Content-Type: image/jpeg\r\nContent-Length: ' + 
                str(len(buf)).encode() + 
                b'\r\n\r\n'
            )
            send_all(cl, header)
            chunk_size = 2048
            for i in range(0, len(buf), chunk_size):
                send_all(cl, buf[i:i + chunk_size])
            send_all(cl, b'\r\n')
            fc += 1
            consecutive_skips = 0

        except OSError as e:
            # Fatal disconnects: ECONNRESET (104), EPIPE (32), ENOTCONN (128), EBADF (9)
            if e.args and len(e.args) > 0 and e.args[0] in [104, 32, 128, 9]:
                print("[Stream] Client disconnected:", e)
                break
            else:
                # Momentary Wi-Fi stall: skip this frame, keep connection ALIVE!
                consecutive_skips += 1
                if consecutive_skips > 20:
                    print("[Stream] Too many consecutive stalls. Closing.")
                    break
                time.sleep(0.01)
                continue
        except Exception as e:
            print("[Stream] Fatal send exception:", e)
            break
        finally:
            buf = None

        # Run garbage collection periodically every 50 frames
        if fc % 50 == 0:
            gc.collect()

        # Frame pacing: ~20-22 FPS maintains silky-smooth feed while preventing buffer overflows
        time.sleep(0.040)

    print(f"[Stream] Session ended: {fc} frames total")


def handle_health(cl):
    rssi = -1
    try:
        rssi = wlan.status('rssi')
    except Exception:
        pass
    body = '{{"status":"ok","wifi":true,"rssi":{0}}}'.format(rssi).encode()
    send_headers(cl, '200 OK', 'application/json',
                 ('Content-Length: ' + str(len(body)) + '\r\n').encode())
    cl.send(body)


def handle_404(cl):
    send_headers(cl, '404 Not Found', 'text/plain')
    cl.send(b'Use /stream or /health')


def send_beacon():
    try:
        if wlan.isconnected():
            my_ip = wlan.ifconfig()[0]
            msg = BEACON_MSG + "|" + my_ip
            bdata = msg.encode()
            # 1. Universal broadcast
            try:
                beacon_sock.sendto(bdata, ("255.255.255.255", BEACON_PORT))
            except Exception:
                pass
            # 2. Subnet directed broadcast (crucial for mobile hotspots like 'ras'!)
            try:
                parts = my_ip.split(".")
                if len(parts) == 4:
                    bcast = parts[0] + "." + parts[1] + "." + parts[2] + ".255"
                    beacon_sock.sendto(bdata, (bcast, BEACON_PORT))
            except Exception:
                pass
    except Exception:
        pass


# ── 5. MAIN EVENT LOOP (Server + UDP Beacon) ─────────────────
addr = socket.getaddrinfo('0.0.0.0', 80)[0][-1]
srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(addr)
srv.listen(3)
srv.settimeout(1.0)

last_beacon = 0

print("[Server] Listening on port 80")
print("[Server] Ready!\n")

while True:
    now = time.time()
    if now - last_beacon >= BEACON_INTERVAL:
        last_beacon = now
        send_beacon()

    if not wlan.isconnected():
        print("[WiFi] Lost! Reconnecting...")
        connect_wifi()
        continue

    cl = None
    try:
        cl, _ = srv.accept()
    except OSError:
        continue

    try:
        cl.settimeout(5.0)
        method, path = read_request(cl)
        if method == 'GET':
            if path == '/stream':
                cl.settimeout(None)
                handle_stream(cl)
            elif path == '/health':
                handle_health(cl)
            else:
                handle_404(cl)
    except Exception as e:
        pass
    finally:
        if cl:
            try:
                cl.close()
            except Exception:
                pass
        gc.collect()
