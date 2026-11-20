"""
Serveur matériel du DAB biométrique.
Membre 2 : Raspberry Pi 5 et ICT NDE1000.
"""

import os
import hmac
import hashlib

from flask import Flask, request, jsonify
from flask_cors import CORS

from nde1000_driver import NDE1000Driver


# ==========================================
# CONFIGURATION DU SERVEUR
# ==========================================

app = Flask(__name__)

CORS(app)

driver = NDE1000Driver()

HARDWARE_HMAC_SECRET = os.getenv("HARDWARE_HMAC_SECRET")


# ==========================================
# VERIFICATION HMAC
# ==========================================

def verify_hmac(session_token, notes_requested,
                amount_fcfa, received_token):

    if not HARDWARE_HMAC_SECRET:
        return False

    message = (
        f"{session_token}|{notes_requested}|{amount_fcfa}"
    )

    expected_token = hmac.new(
        HARDWARE_HMAC_SECRET.encode("utf-8"),
        message.encode("utf-8"),
        hashlib.sha256
    ).hexdigest()

    return hmac.compare_digest(
        expected_token,
        received_token
    )


# ==========================================
# ROUTE DE TEST
# ==========================================

@app.route("/health", methods=["GET"])
def health():

    return jsonify({
        "status": "success",
        "message": "Serveur matériel opérationnel."
    })


# ==========================================
# ROUTE DE DISTRIBUTION
# ==========================================

@app.route("/api/hardware/dispense", methods=["POST"])
def dispense():

    data = request.get_json(silent=True)

    if not isinstance(data, dict):

        return jsonify({
            "status": "error",
            "message": "Données JSON invalides."
        }), 400

    required_fields = [
        "session_token",
        "notes_requested",
        "amount_fcfa",
        "dispense_hmac_token"
    ]

    for field in required_fields:

        if field not in data:

            return jsonify({
                "status": "error",
                "message": f"Champ manquant : {field}"
            }), 400

    session_token = data["session_token"]
    received_token = data["dispense_hmac_token"]

    try:

        notes_requested = int(data["notes_requested"])
        amount_fcfa = int(data["amount_fcfa"])

    except (ValueError, TypeError):

        return jsonify({
            "status": "error",
            "message": "Nombre de billets ou montant invalide."
        }), 400

    if not isinstance(session_token, str) or not session_token:
        return jsonify({
            "status": "error",
            "message": "Session invalide."
        }), 400

    if not isinstance(received_token, str) or not received_token:
        return jsonify({
            "status": "error",
            "message": "Signature invalide."
        }), 400

    if notes_requested <= 0 or amount_fcfa <= 0:

        return jsonify({
            "status": "error",
            "message": "Les valeurs doivent être positives."
        }), 400

    # Vérification de la signature HMAC

    valid_signature = verify_hmac(
        session_token,
        notes_requested,
        amount_fcfa,
        received_token
    )

    if not valid_signature:

        return jsonify({
            "status": "error",
            "message": "Signature HMAC incorrecte."
        }), 401

    # Choix du scénario de simulation

    scenario = data.get(
        "simulation_scenario",
        "SUCCESS"
    )

    try:

        result = driver.dispense(
            notes_requested,
            scenario
        )

        return jsonify({
            "status": result["status"],
            "notes_requested": notes_requested,
            "notes_dispensed": result["notes_dispensed"],
            "amount_fcfa": amount_fcfa,
            "message": result["message"]
        }), 200

    except (ValueError, NotImplementedError) as error:

        return jsonify({
            "status": "error",
            "message": str(error)
        }), 400

    except Exception:

        app.logger.exception("Erreur pendant la distribution.")

        return jsonify({
            "status": "error",
            "message": "Erreur interne du serveur matériel."
        }), 500


# ==========================================
# DEMARRAGE DU SERVEUR
# ==========================================

if __name__ == "__main__":

    if not HARDWARE_HMAC_SECRET:

        raise RuntimeError(
            "La variable HARDWARE_HMAC_SECRET n'est pas définie."
        )

    print("===================================")
    print("     SERVEUR MATERIEL DU DAB")
    print("===================================")

    print("Adresse : http://127.0.0.1:5001")
    print("Mode simulation :", driver.simulation_mode)

    app.run(
        host="127.0.0.1",
        port=5001,
        debug=False
    )