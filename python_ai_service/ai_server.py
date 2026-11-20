"""
PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : python_ai_service/ai_server.py
Auteur : Membre 1 (Chef de groupe — Evan Ryan)
Rôle : Serveur HTTP JSON Multi-Thread (Port 5000) appelé par les scripts PHP via callPythonAI()
       + Console de Test Visuelle Intégrée sur http://localhost:5000/ permettant au Membre 1
         de tester immédiatement l'enrôlement 512D et la vérification 1:1 + vivacité avec la webcam de son PC,
         sans attendre les fichiers PHP/Bootstrap des autres membres !

Routes exposées :
  - GET  /                 : Console visuelle de test Webcam pour le Membre 1
  - GET  /api/face/health  : État du serveur IA et du modèle 512D actif
  - POST /api/face/enroll  : Enrôlement du selfie client -> renvoie le vecteur 512D
  - POST /api/face/verify  : Vérification 1:1 + vivacité sur rafale d'images au distributeur
"""

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from typing import Any, Dict
from face_engine_512d import FaceEngine512D

ENROLLED_FACES_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "enrolled_faces")
MATCH_THRESHOLD = float(os.environ.get("FACE_MATCH_THRESHOLD", "0.65"))

engine = FaceEngine512D(enrolled_dir=ENROLLED_FACES_DIR, match_threshold=MATCH_THRESHOLD)

TEST_CONSOLE_HTML = """<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Console de Test Membre 1 — Reconnaissance Faciale 512D & Vivacité</title>
<style>
  body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 24px; }
  .wrap { max-width: 980px; margin: 0 auto; }
  .hdr { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
  .hdr h1 { font-size: 18px; margin: 0; }
  .badge { background: #dcfce7; color: #166534; font-weight: 600; font-size: 12px; padding: 4px 10px; border-radius: 999px; }
  .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 768px) { .grid { grid-template-columns: 1fr; } }
  .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 18px; }
  .card h2 { font-size: 15px; margin: 0 0 12px; }
  video { width: 100%; border-radius: 10px; background: #0f172a; aspect-ratio: 4/3; object-fit: cover; }
  .btns { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
  button { font: inherit; font-size: 13px; font-weight: 600; padding: 9px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; }
  button.pri { background: #2563eb; color: #fff; border-color: #2563eb; }
  button.sec { background: #059669; color: #fff; border-color: #059669; }
  pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; font-size: 12px; overflow-x: auto; max-height: 340px; margin: 10px 0 0; }
  .status { padding: 10px 12px; border-radius: 8px; font-size: 13px; font-weight: 500; margin-bottom: 10px; background: #f1f5f9; }
</style>
</head>
<body>
<div class="wrap">
  <div class="hdr">
    <div>
      <h1>Console de Test Membre 1 — Moteur Biométrique 512D & Vivacité</h1>
      <div style="font-size:13px;color:#64748b;margin-top:4px;">Teste directement <code>/api/face/enroll</code> et <code>/api/face/verify</code> avec ta webcam PC sans attendre le PHP des autres membres.</div>
    </div>
    <span class="badge" id="engBadge">Chargement moteur...</span>
  </div>
  <div class="grid">
    <div class="card">
      <h2>1. Flux Webcam en Direct</h2>
      <video id="cam" autoplay playsinline muted></video>
      <canvas id="cvs" style="display:none;"></canvas>
      <div class="btns">
        <button class="pri" id="btnEnroll">1. Tester Enrôlement Selfie (512D)</button>
        <button class="sec" id="btnVerify">2. Tester Vérification 1:1 (Rafale 5 images)</button>
      </div>
    </div>
    <div class="card">
      <h2>2. Résultat du Moteur Python 512D</h2>
      <div class="status" id="stBox">Prêt. Clique sur « 1. Tester Enrôlement Selfie » devant ta webcam.</div>
      <pre id="outJson">{}</pre>
    </div>
  </div>
</div>
<script>
let storedEmbedding512D = null;
const video = document.getElementById('cam');
const cvs = document.getElementById('cvs');
const stBox = document.getElementById('stBox');
const outJson = document.getElementById('outJson');

fetch('/api/face/health').then(r=>r.json()).then(d=>{
  document.getElementById('engBadge').textContent = d.engine_mode + ' (' + d.embedding_dimension + 'D)';
});

navigator.mediaDevices.getUserMedia({video: {width: 640, height: 480}}).then(stream => {
  video.srcObject = stream;
}).catch(err => {
  stBox.textContent = "Erreur ouverture webcam : " + err.message;
});

function captureFrameBase64() {
  cvs.width = video.videoWidth || 640;
  cvs.height = video.videoHeight || 480;
  cvs.getContext('2d').drawImage(video, 0, 0, cvs.width, cvs.height);
  return cvs.toDataURL('image/jpeg', 0.9);
}

document.getElementById('btnEnroll').addEventListener('click', async () => {
  stBox.textContent = "Capture et extraction du vecteur 512D en cours...";
  const b64 = captureFrameBase64();
  const res = await fetch('/api/face/enroll', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({user_id: 1, image_base64: b64})
  });
  const data = await res.json();
  if (data.status === 'success') {
    storedEmbedding512D = data.embedding_512d;
    stBox.style.background = '#dcfce7';
    stBox.style.color = '#166534';
    stBox.textContent = "Enrôlement réussi ! Vecteur de " + data.embedding_dimension + " dimensions mémorisé. Tu peux maintenant tester le bouton 2.";
    const preview = Object.assign({}, data, {embedding_512d: data.embedding_512d.slice(0, 8).concat(['... (512 valeurs au total)'])});
    outJson.textContent = JSON.stringify(preview, null, 2);
  } else {
    stBox.style.background = '#fee2e2';
    stBox.style.color = '#991b1b';
    stBox.textContent = "Échec enrôlement : " + data.message;
    outJson.textContent = JSON.stringify(data, null, 2);
  }
});

document.getElementById('btnVerify').addEventListener('click', async () => {
  if (!storedEmbedding512D) {
    stBox.style.background = '#fef3c7';
    stBox.style.color = '#92400e';
    stBox.textContent = "Enrôle d'abord ton visage avec le bouton 1 pour avoir un vecteur 512D de référence !";
    return;
  }
  stBox.style.background = '#e0f2fe';
  stBox.style.color = '#075985';
  const frames = [];
  for (let i = 1; i <= 5; i++) {
    stBox.textContent = "Capture de la rafale de vivacité (" + i + "/5)... Cligne des yeux ou bouge légèrement la tête !";
    frames.push(captureFrameBase64());
    await new Promise(r => setTimeout(r, 280));
  }
  stBox.textContent = "Analyse de la vivacité 3D et comparaison cosinus 512D...";
  const res = await fetch('/api/face/verify', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({user_id: 1, frames_base64: frames, stored_embedding_512d: storedEmbedding512D})
  });
  const data = await res.json();
  stBox.style.background = data.matched ? '#dcfce7' : '#fee2e2';
  stBox.style.color = data.matched ? '#166534' : '#991b1b';
  stBox.textContent = data.message;
  outJson.textContent = JSON.stringify(data, null, 2);
});
</script>
</body>
</html>
"""


class BiometricAIRequestHandler(BaseHTTPRequestHandler):
    """Gestionnaire HTTP JSON avec support CORS complet pour PHP (cURL) et JavaScript (fetch)."""

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

    def _send_html(self, html_str: str, status_code: int = 200) -> None:
        body = html_str.encode("utf-8")
        self.send_response(status_code)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
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
        if path == "" or path == "/test":
            self._send_html(TEST_CONSOLE_HTML, 200)
        elif path == "/api/face/health":
            self._send_json({
                "status": "success",
                "service": "DAB Biometric AI Server 512D",
                "engine_mode": engine.engine_mode,
                "embedding_dimension": engine.EMBEDDING_DIM,
                "match_threshold": engine.match_threshold,
            }, 200)
        else:
            self._send_json({"status": "error", "message": f"Route GET inconnue : {path}"}, 404)

    def do_POST(self) -> None:
        path = self.path.split("?", 1)[0].rstrip("/")
        data = self._read_json_body()

        if path == "/api/face/enroll":
            user_id = data.get("user_id")
            image_base64 = data.get("image_base64")
            if user_id is None or not image_base64:
                self._send_json({
                    "status": "error",
                    "message": "Paramètres manquants : 'user_id' et 'image_base64' sont obligatoires."
                }, 400)
                return

            result = engine.enroll_user_face(int(user_id), str(image_base64))
            code = 200 if result.get("status") == "success" else 422
            self._send_json(result, code)
            return

        if path == "/api/face/verify":
            user_id = data.get("user_id", 0)
            frames_base64 = data.get("frames_base64") or data.get("live_frames_base64")
            stored_embedding_512d = data.get("stored_embedding_512d")
            custom_threshold = data.get("threshold")

            if not frames_base64 or not stored_embedding_512d:
                self._send_json({
                    "status": "error",
                    "message": "Paramètres manquants : 'frames_base64' (liste d'images) et 'stored_embedding_512d' (512 flottants) sont requis."
                }, 400)
                return

            result = engine.verify_user_face_1to1(
                user_id=int(user_id),
                frames_base64=frames_base64,
                stored_embedding_512d=stored_embedding_512d,
                threshold=float(custom_threshold) if custom_threshold is not None else None,
            )
            code = 200 if result.get("status") == "success" else 422
            self._send_json(result, code)
            return

        self._send_json({"status": "error", "message": f"Route POST inconnue : {path}"}, 404)


def run_server(host: str = "0.0.0.0", port: int = 5000) -> None:
    server = ThreadingHTTPServer((host, port), BiometricAIRequestHandler)
    print(f"=== Serveur Python IA Biométrique 512D actif sur http://localhost:{port} ({engine.engine_mode}) ===")
    print(f"👉 Ouvre http://localhost:{port} dans ton navigateur pour tester ta webcam en direct !")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nArrêt du serveur IA 512D.")
        server.server_close()


if __name__ == "__main__":
    port = int(os.environ.get("PORT", 5000))
    run_server(port=port)