"""
PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : pi_hardware_bridge/hardware_server.py
Rôle : Serveur matériel HTTP JSON local (Port 5001) sur Raspberry Pi 5 ou PC de test.

Corrections & Améliorations intégrées :
  1. Zéro dépendance Flask : utilise ThreadingHTTPServer (bibliothèque standard Python) comme ai_server.py.
  2. Clé HMAC synchronisée par défaut avec config/config.php ('dab_secret_key_2026_nde1000_secure').
  3. Protection Anti-Rejeu (HTTP 409) + Verrou matériel (threading.Lock) : empêche toute double distribution
     si un même session_token est renvoyé deux fois.
  4. Contrôle de cohérence montant / coupure (amount_fcfa == notes_requested * 2000 FCFA).
  5. Pilotage de la LED d'éclairage exposé via POST /api/hardware/led/on et /api/hardware/led/off.
  6. Routes de santé disponibles sur GET /api/hardware/health ET GET /health (rétro-compatible).
"""

import atexit
import hashlib
import hmac
import json
import os
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict, Set

from nde1000_driver import NDE1000Driver
from pi_camera_led import LEDController

HARDWARE_HMAC_SECRET = os.getenv("HARDWARE_HMAC_SECRET", "dab_secret_key_2026_nde1000_secure")
DENOMINATION_FCFA = int(os.getenv("NDE1000_DENOMINATION_FCFA", "2000"))

driver = NDE1000Driver()
led_controller = LEDController(pin=int(os.getenv("LED_GPIO_PIN", "18")))
atexit.register(led_controller.cleanup)

# Verrou matériel (1 seul retrait physique à la fois) + registre anti-rejeu des sessions déjà servies
DISPENSE_LOCK = threading.Lock()
USED_SESSION_TOKENS: Set[str] = set()


def verify_hmac(session_token: str, notes_requested: int, amount_fcfa: int, received_token: str) -> bool:
    """Vérifie la signature HMAC-SHA256 générée par config/config.php (generateDispenseHmacToken)."""
    if not HARDWARE_HMAC_SECRET:
        return False
    message = f"{session_token}|{notes_requested}|{amount_fcfa}"
    expected_token = hmac.new(
        HARDWARE_HMAC_SECRET.encode("utf-8"),
        message.encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    return hmac.compare_digest(expected_token, received_token)


class HardwareBridgeHandler(BaseHTTPRequestHandler):
    def _send_json(self, payload: Dict[str, Any], status_code: int = 200) -> None:
        body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type, Authorization")
        self.end_headers()
        self.wfile.write(body)

    def _read_json_body(self) -> Dict[str, Any]:
        length = int(self.headers.get("Content-Length", "0"))
        if length <= 0:
            return {}
        raw = self.rfile.read(length).decode("utf-8", errors="replace")
        try:
            data = json.loads(raw)
            return data if isinstance(data, dict) else {}
        except Exception:
            return {}

    def do_OPTIONS(self) -> None:
        self._send_json({"status": "ok"}, 200)

    def do_GET(self) -> None:
        path = self.path.split("?", 1)[0].rstrip("/")
        if path in ("/api/hardware/health", "/health"):
            self._send_json({
                "status": "success",
                "service": "Serveur Matériel DAB (Raspberry Pi 5 & ICT NDE1000)",
                "simulation_mode": driver.simulation_mode,
                "denomination_fcfa": DENOMINATION_FCFA,
                "led_on": led_controller.is_on,
                "led_backend": led_controller.backend,
            }, 200)
            return

        self._send_json({"status": "error", "message": f"Route GET inconnue : {path}"}, 404)

    def do_POST(self) -> None:
        path = self.path.split("?", 1)[0].rstrip("/")

        # 1. Pilotage LED d'éclairage visage
        if path == "/api/hardware/led/on":
            led_controller.turn_on()
            self._send_json({"status": "success", "led_on": True, "message": "LED d'éclairage allumée."}, 200)
            return

        if path == "/api/hardware/led/off":
            led_controller.turn_off()
            self._send_json({"status": "success", "led_on": False, "message": "LED d'éclairage éteinte."}, 200)
            return

        # 2. Distribution sécurisée des billets (ICT NDE1000)
        if path != "/api/hardware/dispense":
            self._send_json({"status": "error", "message": f"Route POST inconnue : {path}"}, 404)
            return

        data = self._read_json_body()
        required_fields = ["session_token", "notes_requested", "amount_fcfa", "dispense_hmac_token"]
        for field in required_fields:
            if field not in data:
                self._send_json({"status": "error", "message": f"Champ manquant : {field}"}, 400)
                return

        session_token = data["session_token"]
        received_token = data["dispense_hmac_token"]

        try:
            notes_requested = int(data["notes_requested"])
            amount_fcfa = int(data["amount_fcfa"])
        except (ValueError, TypeError):
            self._send_json({"status": "error", "message": "Nombre de billets ou montant invalide."}, 400)
            return

        if not isinstance(session_token, str) or not session_token.strip():
            self._send_json({"status": "error", "message": "Session invalide."}, 400)
            return

        if not isinstance(received_token, str) or not received_token.strip():
            self._send_json({"status": "error", "message": "Signature HMAC manquante."}, 400)
            return

        if notes_requested <= 0 or amount_fcfa <= 0:
            self._send_json({"status": "error", "message": "Les valeurs doivent être positives."}, 400)
            return

        if amount_fcfa != notes_requested * DENOMINATION_FCFA:
            self._send_json({
                "status": "error",
                "message": (
                    f"Incohérence montant/billets : {notes_requested} x {DENOMINATION_FCFA} FCFA "
                    f"!= {amount_fcfa} FCFA."
                ),
            }, 400)
            return

        if not verify_hmac(session_token, notes_requested, amount_fcfa, received_token):
            self._send_json({"status": "error", "message": "Signature HMAC incorrecte."}, 401)
            return

        scenario = str(data.get("simulation_scenario", "SUCCESS"))

        # Verrou matériel + protection anti-rejeu
        with DISPENSE_LOCK:
            if session_token in USED_SESSION_TOKENS:
                self._send_json({
                    "status": "error",
                    "message": "Rejet anti-rejeu : ce session_token a déjà déclenché une distribution.",
                }, 409)
                return

            try:
                result = driver.dispense(notes_requested, scenario)
                USED_SESSION_TOKENS.add(session_token)
                self._send_json({
                    "status": result["status"],
                    "hardware_state": result.get("hardware_state", "ONLINE"),
                    "notes_requested": notes_requested,
                    "notes_dispensed": result["notes_dispensed"],
                    "amount_fcfa": amount_fcfa,
                    "message": result["message"],
                }, 200)
            except (ValueError, NotImplementedError) as err:
                self._send_json({"status": "error", "message": str(err)}, 400)
            except Exception as err:
                self._send_json({"status": "error", "message": f"Erreur interne matériel : {err}"}, 500)


def run_hardware_server(host: str = "127.0.0.1", port: int = 5001) -> None:
    server = ThreadingHTTPServer((host, port), HardwareBridgeHandler)
    print("==============================================")
    print("   SERVEUR MATÉRIEL DU DAB (Pi 5 & NDE1000)   ")
    print("==============================================")
    print(f"Adresse         : http://{host}:{port}")
    print(f"Santé           : http://{host}:{port}/api/hardware/health")
    print(f"Mode simulation : {driver.simulation_mode} (Coupure = {DENOMINATION_FCFA} FCFA)")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nArrêt du serveur matériel.")
        server.server_close()


if __name__ == "__main__":
    port = int(os.getenv("PORT", "5001"))
    run_hardware_server(port=port)