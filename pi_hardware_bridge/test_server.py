"""
PROJET DAB BIOMÉTRIQUE — Fichier : pi_hardware_bridge/test_server.py
Suite de tests complète du serveur matériel (Port 5001) :
  1. Santé (/api/hardware/health)
  2. Pilotage LED ON / OFF (/api/hardware/led/on & /off)
  3. Retrait nominal SUCCESS (3 billets de 2 000 FCFA = 6 000 FCFA)
  4. Protection Anti-Rejeu (409 Conflict si on réutilise le même session_token)
  5. Protection Signature HMAC invalide (401 Unauthorized)
  6. Scénarios JAM_AT_2 (bourrage au 2e billet) et EMPTY (bac vide)
"""

import hashlib
import hmac
import json
import urllib.error
import urllib.request

BASE_URL = "http://127.0.0.1:5001"
SECRET = "dab_secret_key_2026_nde1000_secure"


def sign_hmac(session_token: str, notes_requested: int, amount_fcfa: int, secret: str = SECRET) -> str:
    msg = f"{session_token}|{notes_requested}|{amount_fcfa}"
    return hmac.new(secret.encode("utf-8"), msg.encode("utf-8"), hashlib.sha256).hexdigest()


def http_call(method: str, path: str, payload=None):
    url = BASE_URL + path
    body = json.dumps(payload).encode("utf-8") if payload is not None else None
    headers = {"Content-Type": "application/json"} if body is not None else {}
    req = urllib.request.Request(url, data=body, headers=headers, method=method)
    try:
        with urllib.request.urlopen(req, timeout=10) as resp:
            return resp.status, json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as err:
        return err.code, json.loads(err.read().decode("utf-8"))


def run_all_tests():
    # 1. Santé
    code, data = http_call("GET", "/api/hardware/health")
    assert code == 200 and data["status"] == "success", f"Health KO: {code} {data}"
    print("[OK] 1. /api/hardware/health ->", data["service"])

    # 2. LED ON / OFF
    code, data = http_call("POST", "/api/hardware/led/on", {})
    assert code == 200 and data["led_on"] is True
    code, data = http_call("POST", "/api/hardware/led/off", {})
    assert code == 200 and data["led_on"] is False
    print("[OK] 2. Pilotage LED ON/OFF valide")

    # 3. Retrait nominal SUCCESS (3 billets de 2 000 FCFA = 6 000 FCFA)
    tok1 = "SESSION_TEST_SUCCESS_001"
    payload1 = {
        "session_token": tok1,
        "notes_requested": 3,
        "amount_fcfa": 6000,
        "dispense_hmac_token": sign_hmac(tok1, 3, 6000),
        "simulation_scenario": "SUCCESS",
    }
    code, data = http_call("POST", "/api/hardware/dispense", payload1)
    assert code == 200 and data["status"] == "success" and data["notes_dispensed"] == 3
    print("[OK] 3. Retrait SUCCESS -> 3/3 billets distribués (6 000 FCFA)")

    # 4. Protection Anti-Rejeu (même session_token renvoyé 2 fois -> 409)
    code, data = http_call("POST", "/api/hardware/dispense", payload1)
    assert code == 409, f"Anti-rejeu attendu 409, reçu {code}: {data}"
    print("[OK] 4. Protection Anti-Rejeu -> 409 Conflict bloqué")

    # 5. Protection Signature HMAC invalide -> 401
    tok_bad = "SESSION_TEST_BAD_HMAC"
    code, data = http_call("POST", "/api/hardware/dispense", {
        "session_token": tok_bad,
        "notes_requested": 2,
        "amount_fcfa": 4000,
        "dispense_hmac_token": sign_hmac(tok_bad, 2, 4000, secret="MAUVAISE_CLE"),
        "simulation_scenario": "SUCCESS",
    })
    assert code == 401, f"HMAC invalide attendu 401, reçu {code}: {data}"
    print("[OK] 5. Signature HMAC invalide -> 401 Unauthorized bloqué")

    # 6. Scénario JAM_AT_2 (bourrage au 2e billet -> 1 billet sorti)
    tok_jam = "SESSION_TEST_JAM_002"
    code, data = http_call("POST", "/api/hardware/dispense", {
        "session_token": tok_jam,
        "notes_requested": 5,
        "amount_fcfa": 10000,
        "dispense_hmac_token": sign_hmac(tok_jam, 5, 10000),
        "simulation_scenario": "JAM_AT_2",
    })
    assert code == 200 and data["status"] == "jammed" and data["notes_dispensed"] == 1
    print("[OK] 6. Scénario JAM_AT_2 -> status='jammed', 1/5 billet sorti")

    # 7. Scénario EMPTY (bac vide -> 0 billet sorti)
    tok_empty = "SESSION_TEST_EMPTY_003"
    code, data = http_call("POST", "/api/hardware/dispense", {
        "session_token": tok_empty,
        "notes_requested": 2,
        "amount_fcfa": 4000,
        "dispense_hmac_token": sign_hmac(tok_empty, 2, 4000),
        "simulation_scenario": "EMPTY",
    })
    assert code == 200 and data["status"] == "empty" and data["notes_dispensed"] == 0
    print("[OK] 7. Scénario EMPTY -> status='empty', 0/2 billet sorti")

    print("\n=== TOUS LES 7 TESTS D'INTÉGRATION ONT RÉUSSI SANS ERREUR ===")


if __name__ == "__main__":
    run_all_tests()