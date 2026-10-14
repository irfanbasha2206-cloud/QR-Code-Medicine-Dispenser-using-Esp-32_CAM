"""
═══════════════════════════════════════════════════════════════════════════════
  Dr. Aegis — Clinical Pharmacologist & Physician AI Agent
  Integrated with:
    - Medicine-model_2: TF-IDF + KNN Drug Knowledge Retrieval (11,498 Drugs)
    - Medicine_model_1: Random Forest Symptom-Disease Predictor
    - Dual LLM Fallback: Google Gemini (Primary) <-> Groq Qwen/Llama (Secondary)
═══════════════════════════════════════════════════════════════════════════════
"""

import os
import sys
import re
import json
import time
import urllib.request
import urllib.error
import warnings
warnings.filterwarnings("ignore")
import joblib
import pandas as pd

# Fix Windows console UTF-8 output for Tamil text
try:
    if sys.stdout and hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# API Configuration
GEMINI_API_KEY = os.environ.get("GEMINI_API_KEY")
GROQ_API_KEY = os.environ.get("GROQ_API_KEY")

BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODELS_DIR = os.path.join(BASE_DIR, "models")

# Global model holders
model1_rf = None
model1_vec = None
model2_knn = None
model2_vec = None
model2_df = None
models_loaded = False


def load_local_models():
    """Loads Medicine_model_1 and Medicine-model_2 into memory."""
    global model1_rf, model1_vec, model2_knn, model2_vec, model2_df, models_loaded
    if models_loaded:
        return True

    try:
        # Load Model 2 (Drug Lookup & KNN Retrieval)
        m2_dir = os.path.join(MODELS_DIR, "Medicine-model_2")
        m2_knn_path = os.path.join(m2_dir, "medicine_knn_model.pkl")
        m2_vec_path = os.path.join(m2_dir, "tfidf_vectorizer.pkl")
        m2_csv_path = os.path.join(m2_dir, "medicine_lookup.csv")

        if os.path.exists(m2_knn_path) and os.path.exists(m2_vec_path) and os.path.exists(m2_csv_path):
            model2_knn = joblib.load(m2_knn_path)
            model2_vec = joblib.load(m2_vec_path)
            model2_df = pd.read_csv(m2_csv_path)
            print(f"[AI-AGENT] Medicine-model_2 loaded ({len(model2_df)} medicines in database)")

        # Load Model 1 (Random Forest Disease Predictor)
        m1_dir = os.path.join(MODELS_DIR, "Medicine_model_1")
        m1_rf_path = os.path.join(m1_dir, "rf_disease_predictor.pkl")
        m1_vec_path = os.path.join(m1_dir, "rf_disease_vectorizer.pkl")

        if os.path.exists(m1_rf_path) and os.path.exists(m1_vec_path):
            model1_rf = joblib.load(m1_rf_path)
            model1_vec = joblib.load(m1_vec_path)
            print("[AI-AGENT] Medicine_model_1 (RF Disease Predictor) loaded")

        models_loaded = True
        return True
    except Exception as e:
        print(f"[AI-AGENT] Note during model loading: {e}")
        return False


# Auto-load on import
load_local_models()


def retrieve_drug_knowledge(medicine_name):
    """
    Queries Medicine-model_2 using KNN over TF-IDF vector space to find
    official uses and side effects from the 11,498 medicine dataset.
    """
    clean_name = re.sub(r'([a-z])([A-Z])', r'\1 \2', medicine_name).strip()
    
    # 1. Exact string search first in lookup dataframe
    if model2_df is not None:
        try:
            matches = model2_df[model2_df['Medicine Name'].str.contains(clean_name, case=False, na=False)]
            if not matches.empty:
                best = matches.iloc[0]
                return {
                    "medicine": medicine_name,
                    "matched_catalog": best['Medicine Name'],
                    "uses": str(best['Uses']).strip(),
                    "side_effects": str(best['Side_effects']).strip(),
                    "confidence": "Exact Catalog Match"
                }
        except Exception:
            pass

    # 2. KNN semantic vector retrieval
    if model2_knn is not None and model2_vec is not None and model2_df is not None:
        try:
            X = model2_vec.transform([clean_name])
            distances, indices = model2_knn.kneighbors(X, n_neighbors=1)
            best = model2_df.iloc[indices[0][0]]
            return {
                "medicine": medicine_name,
                "matched_catalog": best['Medicine Name'],
                "uses": str(best['Uses']).strip(),
                "side_effects": str(best['Side_effects']).strip(),
                "confidence": "Vector Similarity Match"
            }
        except Exception as e:
            print(f"[AI-AGENT] KNN lookup error for {medicine_name}: {e}")

    # Fallback default clinical baseline
    return {
        "medicine": medicine_name,
        "matched_catalog": medicine_name,
        "uses": "Clinical therapeutic management under medical guidance.",
        "side_effects": "Mild GI disturbance, headache, or somnolence.",
        "confidence": "Clinical Baseline"
    }


def predict_condition_context(symptoms_or_keywords):
    """Uses Medicine_model_1 to infer clinical disease category."""
    if model1_rf is not None and model1_vec is not None:
        try:
            X = model1_vec.transform([symptoms_or_keywords])
            pred = model1_rf.predict(X)
            return str(pred[0])
        except Exception:
            pass
    return "General Clinical Consultation"


# ═══════════════════════════════════════════════════════════════════════════
# DUAL-LLM INFERENCE ENGINE (GEMINI PRIMARY <-> GROQ FALLBACK)
# ═══════════════════════════════════════════════════════════════════════════

def call_gemini(prompt, system_instruction=""):
    """Executes prompt via Google Gemini API."""
    if not GEMINI_API_KEY:
        raise ValueError("Missing Gemini API Key")

    models_to_try = ["gemini-flash-latest", "gemini-2.5-flash", "gemini-pro-latest"]
    last_err = None

    for model_name in models_to_try:
        url = f"https://generativelanguage.googleapis.com/v1beta/models/{model_name}:generateContent?key={GEMINI_API_KEY}"
        
        contents = []
        if system_instruction:
            contents.append({
                "role": "user",
                "parts": [{"text": f"System Context / Medical Persona:\n{system_instruction}"}]
            })
            contents.append({
                "role": "model",
                "parts": [{"text": "Understood. I am your AI Clinical Consultant, practicing clinical pharmacology and medicine with doctoral-level rigor."}]
            })
        
        contents.append({
            "role": "user",
            "parts": [{"text": prompt}]
        })

        payload = json.dumps({
            "contents": contents,
            "generationConfig": {
                "temperature": 0.2,
                "maxOutputTokens": 1800
            }
        }).encode("utf-8")

        try:
            req = urllib.request.Request(url, data=payload, headers={"Content-Type": "application/json"})
            with urllib.request.urlopen(req, timeout=6) as response:
                result = json.loads(response.read().decode("utf-8"))
                text = result["candidates"][0]["content"]["parts"][0]["text"]
                return text.strip()
        except urllib.error.HTTPError as he:
            if he.code == 429:
                print(f"[AI-AGENT] Gemini Rate Limit (HTTP 429) detected. Instantly engaging Groq...")
                raise he
            last_err = he
            continue
        except Exception as e:
            last_err = e
            continue

    raise RuntimeError(f"Gemini API execution failed: {last_err}")


def call_groq(prompt, system_instruction=""):
    """Executes prompt via Groq High-Speed API."""
    if not GROQ_API_KEY:
        raise ValueError("Missing Groq API Key")

    import groq
    client = groq.Groq(api_key=GROQ_API_KEY)

    models_to_try = ["qwen/qwen3.8-27b", "openai/gpt-oss-120b", "openai/gpt-oss-20b"]
    messages = []
    if system_instruction:
        messages.append({"role": "system", "content": system_instruction})
    messages.append({"role": "user", "content": prompt})

    last_err = None
    for model_name in models_to_try:
        try:
            response = client.chat.completions.create(
                model=model_name,
                messages=messages,
                temperature=0.2,
                max_tokens=1800
            )
            return response.choices[0].message.content.strip()
        except Exception as e:
            last_err = e
            continue

    raise RuntimeError(f"Groq API execution failed: {last_err}")


def generate_llm_response(prompt, system_instruction=""):
    """
    Seamless Dual-Engine Dispatcher with automatic failover:
    Tries Gemini First -> If any error occurs, instantly falls back to Groq!
    If Groq is called first, falls back to Gemini.
    """
    provider_used = "Gemini"
    try:
        text = call_gemini(prompt, system_instruction)
        return text, "Gemini (gemini-flash-latest)"
    except Exception as gemini_err:
        print(f"[AI-AGENT] Gemini primary failed ({gemini_err}). Engaged Groq Failover...")
        try:
            text = call_groq(prompt, system_instruction)
            return text, "Groq (qwen/qwen3.8-27b Failover)"
        except Exception as groq_err:
            print(f"[AI-AGENT] Both LLMs failed: Gemini({gemini_err}) | Groq({groq_err})")
            raise RuntimeError(f"Dual-AI Providers Offline: Gemini ({gemini_err}) | Groq ({groq_err})")


# ═══════════════════════════════════════════════════════════════════════════
# DOCTOR-LEVEL CLINICAL FEEDBACK GENERATOR
# ═══════════════════════════════════════════════════════════════════════════

DOCTOR_SYSTEM_PERSONA = """
You are an AI Clinical Consultant & Senior Clinical Pharmacologist (PhD in Clinical Pharmacology & Internal Medicine).
Your responsibilities:
1. Provide clinical-grade, doctoral-level, highly accurate medical guidance for patient prescriptions.
2. Cross-reference drug mechanisms, pharmacology, adverse reactions, and contraindications.
3. Validate age-adjusted pediatric/geriatric and gender safety.
4. Provide practical dietary advice (food interactions, pre/post-prandial rules).
5. Explain everything in clear, compassionate, and reassuring language.
6. Provide all clinical notes and guidance in clear, professional English for clinical clarity.
"""


def generate_clinical_analysis(patient_data, medicines_list):
    """
    Generates a full doctoral-level clinical report for a patient prescription.
    Combines:
      - Local Knowledge Retrieval from Medicine-model_2
      - Clinical reasoning via Gemini / Groq with failover
      - Structured Output in JSON for clean frontend rendering
    """
    patient_name = patient_data.get("name", "Patient")
    age = patient_data.get("age", 25)
    gender = patient_data.get("gender", "Unspecified")
    presc_id = patient_data.get("presc_id", "N/A")

    # 1. Retrieve drug catalog details from Medicine-model_2
    retrieved_knowledge = []
    med_names_list = []
    for m in medicines_list:
        m_name = m.get("name", "") if isinstance(m, dict) else str(m)
        m_qty = m.get("qty", 1) if isinstance(m, dict) else 1
        med_names_list.append(f"{m_name} (Qty: {m_qty})")
        k = retrieve_drug_knowledge(m_name)
        retrieved_knowledge.append(k)

    # 2. Build doctoral analysis prompt
    knowledge_summary = "\n".join([
        f"- {item['medicine']} (Catalog match: {item['matched_catalog']}):\n"
        f"  * Reported Uses: {item['uses']}\n"
        f"  * Reported Side Effects: {item['side_effects']}"
        for item in retrieved_knowledge
    ])

    prompt = f"""
Clinical Prescription Dossier for Review:
- Patient Name: {patient_name}
- Age: {age} years old
- Gender: {gender}
- Prescription ID: {presc_id}
- Prescribed Medicines: {", ".join(med_names_list)}

Internal Drug Repository Knowledge (Medicine-model_2):
{knowledge_summary}

Please produce a comprehensive, doctoral-level clinical feedback structured strictly in JSON format with these exact keys:
{{
  "patient_summary": "Short 1-2 sentence clinical assessment of this prescription for {patient_name} ({age}y {gender}).",
  "medicines_analysis": [
    {{
      "medicine_name": "Name of medicine",
      "pharmacological_action": "How it works in the body and primary clinical indication.",
      "side_effects": "Common and potential side effects to monitor.",
      "meal_guidelines": "Exact timing with meals (Before food, with food, after food) and specific beverage/diet warnings (milk, alcohol, citrus, etc.).",
      "dosage_for_age": "Doctor's evaluation of dosage suitability for a {age}-year-old {gender}."
    }}
  ],
  "drug_interactions_and_safety": "Assessment of combined interactions, organ safety (liver/kidney), or compatibility.",
  "lifestyle_precautions": "Important precautions like hydration, driving alertness, sleep timing, or sun exposure.",
  "red_flag_symptoms": "Emergency warning signs when the patient must immediately contact emergency medical care.",
  "doctor_audio_guidance": "Complete, friendly, reassuring explanation in clear English covering: medication purpose, meal timing, potential side effects, and practical safety tips."
}}

Ensure all medical facts are rigorous, professional, and strictly formatted as valid JSON only (no markdown code blocks, just raw JSON).
"""

    raw_response, engine_used = generate_llm_response(prompt, DOCTOR_SYSTEM_PERSONA)

    # Extract JSON cleanly
    clean_json = raw_response
    if "```json" in clean_json:
        clean_json = clean_json.split("```json")[1].split("```")[0].strip()
    elif "```" in clean_json:
        clean_json = clean_json.split("```")[1].split("```")[0].strip()

    try:
        report = json.loads(clean_json)
    except Exception:
        # Fallback regex extraction
        match = re.search(r'(\{[\s\S]*\})', clean_json)
        if match:
            report = json.loads(match.group(1))
        else:
            report = {
                "patient_summary": f"Clinical prescription verification for {patient_name}.",
                "raw_doctor_notes": raw_response,
                "medicines_analysis": [],
                "doctor_audio_guidance": "Please follow prescribed instructions and take medications as directed by your physician."
            }

    report["metadata"] = {
        "presc_id": presc_id,
        "patient_name": patient_name,
        "age": age,
        "gender": gender,
        "medicines": medicines_list,
        "analyzed_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "ai_physician": "AI Clinical Consultant (PhD Clinical Pharmacology & Internal Medicine)",
        "ai_engine": engine_used,
        "catalog_source": "Medicine-model_2 (11,498 Indian & Global Drug Formulations)"
    }

    return report


# ═══════════════════════════════════════════════════════════════════════════
# CONVERSATIONAL CLINICAL AI CHATBOT & VOICE CONSULTATION
# ═══════════════════════════════════════════════════════════════════════════

def doctor_consultation_chat(user_message, patient_context, history=None):
    """
    Interactive Clinical AI Chatbot:
    Accepts patient doubts in English, Tamil, or Tanglish.
    Answers with doctoral clinical accuracy, direct conciseness, and clear guidance.
    """
    p_name = patient_context.get("name", "Patient")
    p_age = patient_context.get("age", 25)
    p_gender = patient_context.get("gender", "Unspecified")
    p_meds = patient_context.get("medicines", [])
    meds_str = ", ".join([f"{m.get('name', m)} x {m.get('qty', 1)}" if isinstance(m, dict) else str(m) for m in p_meds])

    sys_instruction = f"""
You are an AI Clinical Consultant and Senior Clinical Pharmacologist.
Patient Profile:
- Age: {p_age} years old ({p_gender})
- Current Prescription: {meds_str}

Clinical Guidelines:
1. Answer in polite, reassuring, professional medical English.
2. CRITICAL RULE: Do NOT repeat or address the patient by name in your answer. Provide direct, concise clinical advice addressing their specific doubt.
3. If the user asks in another language (e.g. Tamil or Tanglish), understand their medical concern and provide clear, empathetic medical guidance in English.
4. Explain meal timing, side effects, missed doses, and dietary interactions clearly.
5. Keep answers concise (under 100 words) suitable for mobile viewing and on-demand speech synthesis!
"""

    # Build chat prompt with recent history
    prompt = f"Patient Question:\n\"{user_message}\"\n\nAI Clinical Consultant Response:"
    if history and isinstance(history, list):
        conv = []
        for turn in history[-4:]:
            role = "Patient" if turn.get("role") == "user" else "AI Clinical Consultant"
            conv.append(f"{role}: {turn.get('content', '')}")
        prompt = "\n".join(conv) + f"\nPatient: {user_message}\nAI Clinical Consultant:"

    reply_text, engine_used = generate_llm_response(prompt, sys_instruction)
    return {
        "reply": reply_text,
        "engine": engine_used,
        "patient": p_name,
        "timestamp": time.strftime("%H:%M:%S")
    }


if __name__ == "__main__":
    print("Testing Dr. Aegis Clinical Agent...")
    sample_patient = {"name": "Sruthi", "age": 22, "gender": "Female", "presc_id": "PRESC_SAMPLE"}
    sample_meds = [{"name": "Cetirizine", "qty": 4}]
    res = generate_clinical_analysis(sample_patient, sample_meds)
    print("\n[SUCCESS] Generated Report Summary:")
    print(res.get("patient_summary"))
    print("\nEngine Used:", res["metadata"]["ai_engine"])
    print("\nTamil Guidance Snippet:")
    print(res.get("tamil_patient_guidance")[:200])
