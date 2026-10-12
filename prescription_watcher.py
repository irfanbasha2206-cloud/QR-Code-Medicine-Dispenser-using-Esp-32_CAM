"""
═══════════════════════════════════════════════════════════════════════════════
  prescription_watcher.py — Real-Time Cloud Prescription Monitor & AI Engine
═══════════════════════════════════════════════════════════════════════════════
  Continuously monitors https://iotcloud22.in/4973/get_emails.php
  As new prescriptions are generated / scanned, immediately generates
  doctoral-level clinical AI feedback, medication guidance, and stores
  them for live patient access on the prescription URL and chatbot.
═══════════════════════════════════════════════════════════════════════════════
"""

import sys
import os
import time
import json
import threading
import urllib.request
import urllib.error

# Ensure UTF-8 console output
try:
    if sys.stdout and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

from ai_clinical_agent import generate_clinical_analysis, doctor_consultation_chat

GET_EMAILS_URL = "https://iotcloud22.in/4973/get_emails.php"
RECORDS_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "ai_prescription_records.json")

# In-memory storage of processed prescriptions and clinical reports
processed_records = {}
records_lock = threading.Lock()


def load_cached_records():
    """Loads existing processed AI records from disk if available."""
    global processed_records
    if os.path.exists(RECORDS_FILE):
        try:
            with open(RECORDS_FILE, "r", encoding="utf-8") as f:
                data = json.load(f)
                with records_lock:
                    processed_records = data
            print(f"[WATCHER] Loaded {len(processed_records)} cached prescription AI analyses.")
        except Exception as e:
            print(f"[WATCHER] Note reading cache: {e}")


def save_cached_records():
    """Persists processed AI records to disk atomically."""
    with records_lock:
        data_to_save = dict(processed_records)
    try:
        tmp_file = RECORDS_FILE + ".tmp"
        with open(tmp_file, "w", encoding="utf-8") as f:
            json.dump(data_to_save, f, indent=2, ensure_ascii=False)
        if os.path.exists(RECORDS_FILE):
            os.remove(RECORDS_FILE)
        os.rename(tmp_file, RECORDS_FILE)
    except Exception as e:
        print(f"[WATCHER] Error saving cache: {e}")


def fetch_cloud_prescriptions():
    """Fetches the latest active prescriptions list from get_emails.php."""
    try:
        req = urllib.request.Request(
            GET_EMAILS_URL,
            headers={"User-Agent": "AegisRx-Clinical-Watcher/2.0"}
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            data = json.loads(resp.read().decode("utf-8"))
            if data.get("status") == "success":
                return data.get("emails", [])
    except Exception as e:
        print(f"[WATCHER] Cloud polling notice: {e}")
    return []


def fetch_prescription_details(json_url):
    """Fetches complete prescription payload including medicines list."""
    try:
        req = urllib.request.Request(
            json_url,
            headers={"User-Agent": "AegisRx-Clinical-Watcher/2.0"}
        )
        with urllib.request.urlopen(req, timeout=10) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except Exception as e:
        print(f"[WATCHER] Error reading json_url ({json_url}): {e}")
    return None


def push_ai_feedback_to_cloud(token, presc_id, report):
    """Attempts to POST generated AI feedback to the cloud server."""
    cloud_url = "https://iotcloud22.in/4973/save_ai_feedback.php"
    try:
        payload = json.dumps({
            "token": token,
            "presc_id": presc_id,
            "ai_report": report
        }, ensure_ascii=False).encode("utf-8")
        req = urllib.request.Request(
            cloud_url,
            data=payload,
            headers={"Content-Type": "application/json", "User-Agent": "AegisRx-Watcher/2.0"},
            method="POST"
        )
        with urllib.request.urlopen(req, timeout=6) as resp:
            data = resp.read().decode("utf-8")
            if "success" in data:
                print(f"[AI-WATCHER] ☁️ AI Feedback successfully synced to Cloud Server: {presc_id}")
    except Exception:
        pass


def process_single_prescription(item):
    """Generates AI Clinical Analysis for a single prescription item."""
    presc_id = item.get("presc_id")
    if not presc_id:
        return None

    json_url = item.get("json_url")
    presc_url = item.get("prescription_url")
    
    # Extract token from prescription_url if available
    token = ""
    if presc_url and "token=" in presc_url:
        token = presc_url.split("token=")[1].split("&")[0]

    with records_lock:
        if presc_id in processed_records:
            return processed_records[presc_id]

    # Fetch detailed medicines payload
    details = fetch_prescription_details(json_url) if json_url else None
    
    patient_data = {
        "name": item.get("patient_name", "Patient"),
        "age": item.get("age", 25),
        "gender": item.get("gender", "Unspecified"),
        "email": item.get("email", ""),
        "presc_id": presc_id,
        "token": token,
        "prescription_url": presc_url,
        "dispense_status": item.get("dispense_status", "PENDING")
    }

    medicines_list = []
    if details and "medicines" in details:
        medicines_list = details.get("medicines", [])
    elif "medicines" in item:
        medicines_list = item.get("medicines", [])

    if not medicines_list:
        medicines_list = [{"name": "General Medication", "qty": 1}]

    print(f"\n[AI-WATCHER] 🩺 Generating Clinical AI Dossier for: {patient_data['name']} ({presc_id})...")
    print(f"             Medicines: {[m.get('name') for m in medicines_list]} | Age: {patient_data['age']}")

    try:
        report = generate_clinical_analysis(patient_data, medicines_list)
        report["token"] = token
        report["prescription_url"] = presc_url
        report["dispense_status"] = item.get("dispense_status", "PENDING")

        with records_lock:
            processed_records[presc_id] = report
            if token:
                processed_records[token] = report

        save_cached_records()
        print(f"[AI-WATCHER] ✅ Analysis Complete & Stored: {patient_data['name']} (Engine: {report['metadata']['ai_engine']})")
        # Sync to cloud server if save_ai_feedback.php is deployed
        push_ai_feedback_to_cloud(token, presc_id, report)
        return report
    except Exception as e:
        print(f"[AI-WATCHER] ❌ Error analyzing prescription {presc_id}: {e}")
        return None


def get_ai_feedback(presc_id_or_token):
    """Public helper to get AI feedback for any presc_id or token."""
    with records_lock:
        if presc_id_or_token in processed_records:
            return processed_records[presc_id_or_token]
    return None


pushed_to_cloud_set = set()
logged_pending_set = set()


def watcher_loop(poll_interval=8):
    """
    Continuous polling loop running in daemon background thread:
    Monitors https://iotcloud22.in/4973/get_emails.php.
    ONLY WHEN status becomes 'DISPENSED', pushes the doctoral AI Clinical
    feedback to that prescription URL (save_ai_feedback.php)!
    """
    print(f"[WATCHER] Starting continuous polling on {GET_EMAILS_URL} (every {poll_interval}s)...")
    load_cached_records()

    while True:
        try:
            items = fetch_cloud_prescriptions()
            for it in items:
                p_id = it.get("presc_id")
                if not p_id:
                    continue
                
                status = str(it.get("dispense_status", "")).upper()
                p_name = it.get("patient_name", "Patient")
                presc_url = it.get("prescription_url", "")
                token = ""
                if presc_url and "token=" in presc_url:
                    token = presc_url.split("token=")[1].split("&")[0]

                # Pre-cache AI dossier if not yet cached so it is ready in 0ms
                with records_lock:
                    cached_report = processed_records.get(p_id)
                if not cached_report:
                    cached_report = process_single_prescription(it)

                # ─── STATUS CHECK: ONLY DISPATCH TO CLOUD PRESCRIPTION URL WHEN DISPENSED ───
                if status == "DISPENSED":
                    if p_id not in pushed_to_cloud_set:
                        print(f"\n[AI-WATCHER] 💊 Prescription {p_id} ({p_name}) Status is DISPENSED!")
                        print(f"             Pushing Doctoral AI Dossier directly to Prescription URL...")
                        if cached_report:
                            push_ai_feedback_to_cloud(token, p_id, cached_report)
                            pushed_to_cloud_set.add(p_id)
                            print(f"             ✅ AI Feedback successfully active on: {presc_url}")
                else:
                    if p_id not in logged_pending_set:
                        logged_pending_set.add(p_id)
                        print(f"[WATCHER] ⏳ Prescription {p_id} ({p_name}): Status is {status}. Waiting for dispenser scan to activate AI feedback.")
        except Exception as loop_err:
            print(f"[WATCHER] Loop notice: {loop_err}")

        time.sleep(poll_interval)


def start_watcher_background():
    """Starts watcher in daemon thread."""
    t = threading.Thread(target=watcher_loop, daemon=True)
    t.start()
    return t


if __name__ == "__main__":
    load_cached_records()
    items = fetch_cloud_prescriptions()
    print(f"Found {len(items)} prescriptions in cloud database.")
    for item in items:
        process_single_prescription(item)
    print("\nAll active prescriptions processed!")
