import json
import hmac
import hashlib
import urllib.request

# 1. Informations de test
SECRET = "CLE_TEMPORAIRE_DE_TEST"

session_token = "SESSION_TEST_001"
notes_requested = 3
amount_fcfa = 15000

# 2. Création du message à signer
message = f"{session_token}|{notes_requested}|{amount_fcfa}"

# 3. Calcul de la signature HMAC
signature = hmac.new(
    SECRET.encode("utf-8"),
    message.encode("utf-8"),
    hashlib.sha256
).hexdigest()

# 4. Préparation des données JSON
data = {
    "session_token": session_token,
    "notes_requested": notes_requested,
    "amount_fcfa": amount_fcfa,
    "dispense_hmac_token": signature,
    "simulation_scenario": "SUCCESS"
}

# 5. Préparation de la requête HTTP POST
url = "http://127.0.0.1:5001/api/hardware/dispense"

request = urllib.request.Request(
    url,
    data=json.dumps(data).encode("utf-8"),
    headers={"Content-Type": "application/json"},
    method="POST"
)

# 6. Envoi de la requête et réception de la réponse
try:
    with urllib.request.urlopen(request) as response:
        resultat = json.loads(response.read().decode("utf-8"))

        print("\n===== REPONSE DU SERVEUR =====")
        print(json.dumps(resultat, indent=4, ensure_ascii=False))

except Exception as erreur:
    print("Erreur :", erreur)