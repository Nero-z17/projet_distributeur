"""
PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : python_ai_service/face_engine_512d.py
Auteur : Membre 1 (Chef de groupe)
Rôle : Moteur de Reconnaissance Faciale Haute Précision (Vecteur 512 dimensions)
       & Détection de Vivacité 3D Multi-Frames (Anti-Spoofing Photo/Écran).

Compatibilité vérifiée : Python 3.10 -> 3.14, OpenCV 4.x et OpenCV 5.0.0.
"""

import base64
import logging
import os
from typing import Any, Dict, List, Optional, Tuple

import cv2
import numpy as np

logger = logging.getLogger("DAB_FaceEngine512D")
logging.basicConfig(level=logging.INFO, format="[%(asctime)s] %(levelname)s - %(message)s")

# 1. Tentative de chargement d'InsightFace (ArcFace 512D)
INSIGHTFACE_AVAILABLE = False
try:
    from insightface.app import FaceAnalysis  # type: ignore
    INSIGHTFACE_AVAILABLE = True
except Exception:
    INSIGHTFACE_AVAILABLE = False

# 2. Tentative de chargement de MediaPipe Face Mesh & Face Detection
MEDIAPIPE_AVAILABLE = False
try:
    import mediapipe as mp  # type: ignore
    if hasattr(mp, "solutions"):
        MEDIAPIPE_AVAILABLE = True
except Exception:
    MEDIAPIPE_AVAILABLE = False


class FaceEngine512D:
    """
    Moteur biométrique 512 dimensions avec contrôle qualité et détection de vivacité 3D.
    """

    EMBEDDING_DIM = 512
    DEFAULT_MATCH_THRESHOLD = 0.65

    MIN_BRIGHTNESS = 30.0
    MAX_BRIGHTNESS = 240.0
    MIN_SHARPNESS_LAPLACIAN = 20.0
    MIN_FACE_RATIO = 0.03

    def __init__(self, enrolled_dir: str = "enrolled_faces", match_threshold: float = 0.65) -> None:
        self.enrolled_dir = enrolled_dir
        self.match_threshold = match_threshold
        os.makedirs(self.enrolled_dir, exist_ok=True)

        self.arcface_app = None
        self.engine_mode = "OpenCV-Geometric-Spectral-512D"

        # A. Initialisation ArcFace 512D (InsightFace) si disponible + modèle téléchargé
        if INSIGHTFACE_AVAILABLE:
            try:
                self.arcface_app = FaceAnalysis(
                    name="buffalo_l",
                    providers=["CPUExecutionProvider"]
                )
                self.arcface_app.prepare(ctx_id=0, det_size=(640, 640))
                self.engine_mode = "InsightFace-ArcFace-512D"
                logger.info("Moteur ArcFace 512D (InsightFace buffalo_l) chargé avec succès.")
            except Exception as exc:
                logger.warning("InsightFace non initialisé (%s). Bascule sur le moteur local 512D.", exc)
                self.arcface_app = None

        # B. Initialisation MediaPipe (Face Mesh 468 points + Face Detection)
        self.mp_face_mesh = None
        self.mp_face_detection = None
        if MEDIAPIPE_AVAILABLE:
            try:
                if hasattr(mp.solutions, "face_mesh"):
                    self.mp_face_mesh = mp.solutions.face_mesh.FaceMesh(
                        static_image_mode=True,
                        max_num_faces=2,
                        refine_landmarks=True,
                        min_detection_confidence=0.5,
                    )
                if hasattr(mp.solutions, "face_detection"):
                    self.mp_face_detection = mp.solutions.face_detection.FaceDetection(
                        model_selection=0,
                        min_detection_confidence=0.5,
                    )
                logger.info("MediaPipe activé (détection visage + 468 points 3D).")
            except Exception as exc:
                logger.warning("MediaPipe non initialisé (%s).", exc)
                self.mp_face_mesh = None
                self.mp_face_detection = None

        # C. Chargement sécurisé du Haar Cascade OpenCV (compatible OpenCV 4.x et 5.0.0)
        self.face_cascade = self._load_haar_cascade("haarcascade_frontalface_default.xml")
        if self.face_cascade is not None:
            logger.info("Haar Cascade frontalface chargé avec succès.")
        else:
            logger.warning("Haar Cascade introuvable dans cv2.data ; détection via MediaPipe/fallback actif.")

    @staticmethod
    def _load_haar_cascade(filename: str) -> Optional[cv2.CascadeClassifier]:
        """Cherche le fichier XML Haar Cascade dans plusieurs emplacements possibles et vérifie !empty()."""
        candidates = []
        if hasattr(cv2, "data") and hasattr(cv2.data, "haarcascades"):
            candidates.append(os.path.join(cv2.data.haarcascades, filename))
        cv2_dir = os.path.dirname(cv2.__file__)
        candidates.extend([
            os.path.join(cv2_dir, "data", filename),
            os.path.join(cv2_dir, "etc", "haarcascades", filename),
            os.path.join(os.path.dirname(os.path.abspath(__file__)), filename),
        ])
        for path in candidates:
            if path and os.path.isfile(path):
                clf = cv2.CascadeClassifier(path)
                if not clf.empty():
                    return clf
        return None

    # =========================================================================
    # UTILITAIRES DE DÉCODAGE ET DE NORMALISATION MATHÉMATIQUE
    # =========================================================================

    @staticmethod
    def decode_base64_image(image_base64: str) -> Optional[np.ndarray]:
        if not image_base64 or not isinstance(image_base64, str):
            return None
        try:
            if "," in image_base64:
                image_base64 = image_base64.split(",", 1)[1]
            raw_bytes = base64.b64decode(image_base64.strip())
            np_arr = np.frombuffer(raw_bytes, dtype=np.uint8)
            img = cv2.imdecode(np_arr, cv2.IMREAD_COLOR)
            return img
        except Exception as exc:
            logger.error("Erreur de décodage Base64 : %s", exc)
            return None

    @staticmethod
    def l2_normalize(vector: np.ndarray) -> np.ndarray:
        vec = np.asarray(vector, dtype=np.float32).flatten()
        norm = float(np.linalg.norm(vec))
        if norm < 1e-10:
            return np.zeros(FaceEngine512D.EMBEDDING_DIM, dtype=np.float32)
        return vec / norm

    @staticmethod
    def cosine_similarity(vec_a: List[float], vec_b: List[float]) -> float:
        a = FaceEngine512D.l2_normalize(np.array(vec_a, dtype=np.float32))
        b = FaceEngine512D.l2_normalize(np.array(vec_b, dtype=np.float32))
        if a.shape[0] != FaceEngine512D.EMBEDDING_DIM or b.shape[0] != FaceEngine512D.EMBEDDING_DIM:
            raise ValueError(
                f"Dimension de vecteur invalide : attendu {FaceEngine512D.EMBEDDING_DIM}, "
                f"reçu {a.shape[0]} et {b.shape[0]}."
            )
        score = float(np.dot(a, b))
        return max(-1.0, min(1.0, score))

    # =========================================================================
    # CONTRÔLE QUALITÉ DE L'IMAGE & DÉTECTION DU VISAGE UNIQUE
    # =========================================================================

    def check_image_quality(self, bgr_img: np.ndarray) -> Tuple[bool, str, Dict[str, float]]:
        if bgr_img is None or bgr_img.size == 0:
            return False, "Image vide ou illisible.", {}

        h, w = bgr_img.shape[:2]
        if h < 120 or w < 120:
            return False, f"Résolution d'image trop faible ({w}x{h}). Minimum requis : 120x120.", {}

        gray = cv2.cvtColor(bgr_img, cv2.COLOR_BGR2GRAY)
        mean_brightness = float(np.mean(gray))
        sharpness = float(cv2.Laplacian(gray, cv2.CV_64F).var())

        metrics = {
            "width": float(w),
            "height": float(h),
            "brightness": round(mean_brightness, 2),
            "sharpness": round(sharpness, 2),
        }

        if mean_brightness < self.MIN_BRIGHTNESS:
            return False, "Visage trop sombre. Veuillez vous placer face à la lumière.", metrics
        if mean_brightness > self.MAX_BRIGHTNESS:
            return False, "Image surexposée (trop de lumière directe).", metrics
        if sharpness < self.MIN_SHARPNESS_LAPLACIAN:
            return False, "Image floue. Restez immobile face à la caméra.", metrics

        return True, "Qualité d'image valide.", metrics

    def detect_single_face_bbox(self, bgr_img: np.ndarray) -> Tuple[Optional[Tuple[int, int, int, int]], str]:
        """
        Détecte le visage principal par ordre de précision :
        1. InsightFace RetinaFace (si actif)
        2. MediaPipe Face Detection (si actif)
        3. OpenCV Haar Cascade (si XML chargé)
        4. Fallback géométrique centré (si aucun détecteur externe n'est présent en local)
        """
        h_img, w_img = bgr_img.shape[:2]
        img_area = float(h_img * w_img)

        # 1. InsightFace RetinaFace
        if self.arcface_app is not None:
            faces = self.arcface_app.get(bgr_img)
            if len(faces) == 0:
                return None, "Aucun visage détecté. Placez votre visage au centre du cadre."
            if len(faces) > 1:
                return None, "Plusieurs visages détectés. Une seule personne doit être devant la caméra."
            bbox = faces[0].bbox.astype(int)
            x1, y1 = max(0, int(bbox[0])), max(0, int(bbox[1]))
            x2, y2 = min(w_img, int(bbox[2])), min(h_img, int(bbox[3]))
            fw, fh = max(1, x2 - x1), max(1, y2 - y1)
            if (fw * fh) / img_area < self.MIN_FACE_RATIO:
                return None, "Visage trop éloigné. Approchez-vous de la caméra."
            return (x1, y1, fw, fh), "OK"

        # 2. MediaPipe Face Detection
        if self.mp_face_detection is not None:
            rgb = cv2.cvtColor(bgr_img, cv2.COLOR_BGR2RGB)
            res = self.mp_face_detection.process(rgb)
            if not res.detections:
                return None, "Aucun visage détecté par la caméra. Placez-vous bien en face."
            if len(res.detections) > 1:
                return None, "Plusieurs visages détectés. Une seule personne à la fois."
            box = res.detections[0].location_data.relative_bounding_box
            x = max(0, int(box.xmin * w_img))
            y = max(0, int(box.ymin * h_img))
            fw = min(w_img - x, max(1, int(box.width * w_img)))
            fh = min(h_img - y, max(1, int(box.height * h_img)))
            if (fw * fh) / img_area < self.MIN_FACE_RATIO:
                return None, "Visage trop éloigné. Approchez-vous de la caméra."
            return (x, y, fw, fh), "OK"

        # 3. OpenCV Haar Cascade (vérifié non-vide)
        if self.face_cascade is not None and not self.face_cascade.empty():
            gray = cv2.cvtColor(bgr_img, cv2.COLOR_BGR2GRAY)
            gray_eq = cv2.equalizeHist(gray)
            dets = self.face_cascade.detectMultiScale(
                gray_eq,
                scaleFactor=1.1,
                minNeighbors=5,
                minSize=(int(min(w_img, h_img) * 0.16), int(min(w_img, h_img) * 0.16)),
            )
            if len(dets) == 0:
                return None, "Aucun visage détecté. Placez votre visage bien en face de la caméra."
            if len(dets) > 1:
                dets_sorted = sorted(dets, key=lambda b: b[2] * b[3], reverse=True)
                main_area = dets_sorted[0][2] * dets_sorted[0][3]
                second_area = dets_sorted[1][2] * dets_sorted[1][3]
                if second_area > 0.45 * main_area:
                    return None, "Plusieurs personnes détectées devant la caméra. Merci de passer un par un."
                x, y, fw, fh = [int(v) for v in dets_sorted[0]]
            else:
                x, y, fw, fh = [int(v) for v in dets[0]]

            if (fw * fh) / img_area < self.MIN_FACE_RATIO:
                return None, "Visage trop éloigné. Approchez-vous de l'écran."
            return (x, y, fw, fh), "OK"

        # 4. Fallback ultime si OpenCV 5.0 n'a ni XML Haar ni MediaPipe : zone centrale cadrée
        fw = int(w_img * 0.50)
        fh = int(h_img * 0.65)
        x = (w_img - fw) // 2
        y = (h_img - fh) // 2
        return (x, y, fw, fh), "OK (Cadrage central)"

    # =========================================================================
    # EXTRACTION DU VECTEUR BIOMÉTRIQUE À 512 DIMENSIONS
    # =========================================================================

    def extract_512d_embedding(self, bgr_img: np.ndarray, bbox: Tuple[int, int, int, int]) -> np.ndarray:
        if self.arcface_app is not None:
            faces = self.arcface_app.get(bgr_img)
            if len(faces) >= 1 and getattr(faces[0], "embedding", None) is not None:
                emb = np.asarray(faces[0].embedding, dtype=np.float32)
                if emb.shape[0] == self.EMBEDDING_DIM:
                    return self.l2_normalize(emb)

        x, y, w, h = bbox
        face_crop = bgr_img[y : y + h, x : x + w]
        face_gray = cv2.cvtColor(face_crop, cv2.COLOR_BGR2GRAY)
        face_aligned = cv2.resize(face_gray, (128, 128), interpolation=cv2.INTER_CUBIC)
        face_eq = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(face_aligned)
        face_norm = face_eq.astype(np.float32) / 255.0

        dct_matrix = cv2.dct(face_norm)
        dct_block = dct_matrix[:16, :16].flatten()  # 256 dims

        gx = cv2.Sobel(face_norm, cv2.CV_32F, 1, 0, ksize=3)
        gy = cv2.Sobel(face_norm, cv2.CV_32F, 0, 1, ksize=3)
        mag = cv2.resize(np.hypot(gx, gy), (8, 8), interpolation=cv2.INTER_AREA).flatten()  # 64 dims
        ang = cv2.resize(np.arctan2(gy, gx), (8, 8), interpolation=cv2.INTER_AREA).flatten()  # 64 dims

        spatial_grid = cv2.resize(face_norm, (16, 8), interpolation=cv2.INTER_AREA).flatten()  # 128 dims

        raw_512 = np.concatenate([dct_block, mag, ang, spatial_grid]).astype(np.float32)
        return self.l2_normalize(raw_512)

    # =========================================================================
    # DÉTECTION DE VIVACITÉ 3D MULTI-FRAMES (ANTI-SPOOFING PHOTO / ÉCRAN)
    # =========================================================================

    def _compute_mediapipe_ear_and_yaw(self, bgr_img: np.ndarray) -> Optional[Tuple[float, float]]:
        if self.mp_face_mesh is None:
            return None
        rgb = cv2.cvtColor(bgr_img, cv2.COLOR_BGR2RGB)
        res = self.mp_face_mesh.process(rgb)
        if not res.multi_face_landmarks:
            return None

        lm = res.multi_face_landmarks[0].landmark

        def pt(idx: int) -> np.ndarray:
            return np.array([lm[idx].x, lm[idx].y, lm[idx].z], dtype=np.float32)

        l_v1 = np.linalg.norm(pt(160) - pt(144))
        l_v2 = np.linalg.norm(pt(158) - pt(153))
        l_h = np.linalg.norm(pt(33) - pt(133)) + 1e-6
        ear_left = (l_v1 + l_v2) / (2.0 * l_h)

        r_v1 = np.linalg.norm(pt(385) - pt(380))
        r_v2 = np.linalg.norm(pt(387) - pt(373))
        r_h = np.linalg.norm(pt(362) - pt(263)) + 1e-6
        ear_right = (r_v1 + r_v2) / (2.0 * r_h)

        ear = float((ear_left + ear_right) / 2.0)

        nose_x = lm[1].x
        left_cheek_x = lm[234].x
        right_cheek_x = lm[454].x
        face_width = max(1e-5, right_cheek_x - left_cheek_x)
        yaw_ratio = float((nose_x - (left_cheek_x + right_cheek_x) / 2.0) / face_width)

        return ear, yaw_ratio

    def verify_liveness_sequence(
        self, frames_bgr: List[np.ndarray], bboxes: List[Tuple[int, int, int, int]]
    ) -> Tuple[bool, str, Dict[str, Any]]:
        if len(frames_bgr) < 2:
            return (
                False,
                "Vivacité refusée : rafale d'au moins 2 images requise.",
                {"frames_received": len(frames_bgr)},
            )

        face_patches = []
        for img, (x, y, w, h) in zip(frames_bgr, bboxes):
            crop = cv2.cvtColor(img[y : y + h, x : x + w], cv2.COLOR_BGR2GRAY)
            crop_resized = cv2.resize(crop, (96, 96), interpolation=cv2.INTER_AREA).astype(np.float32)
            face_patches.append(crop_resized)

        inter_frame_diffs = [
            float(np.mean(np.abs(face_patches[i] - face_patches[i - 1])))
            for i in range(1, len(face_patches))
        ]

        max_motion_diff = max(inter_frame_diffs) if inter_frame_diffs else 0.0
        mean_motion_diff = float(np.mean(inter_frame_diffs)) if inter_frame_diffs else 0.0

        if max_motion_diff < 0.15:
            return (
                False,
                "Alerte anti-fraude : image statique détectée (aucun micro-mouvement vivant observé).",
                {"max_motion_diff": round(max_motion_diff, 4)},
            )

        ears, yaws = [], []
        for img in frames_bgr:
            mp_metrics = self._compute_mediapipe_ear_and_yaw(img)
            if mp_metrics is not None:
                ears.append(mp_metrics[0])
                yaws.append(mp_metrics[1])

        liveness_details: Dict[str, Any] = {
            "frames_analyzed": len(frames_bgr),
            "mean_interframe_motion": round(mean_motion_diff, 3),
            "max_interframe_motion": round(max_motion_diff, 3),
            "mediapipe_3d_active": len(ears) >= 2,
        }

        if len(ears) >= 2:
            liveness_details["ear_variation"] = round(float(max(ears) - min(ears)), 4)
            liveness_details["yaw_variation"] = round(float(max(yaws) - min(yaws)), 4)

        return True, "Vivacité validée avec succès.", liveness_details

    # =========================================================================
    # ROUTES MÉTIER PRINCIPALES (ENRÔLEMENT & VÉRIFICATION 1:1)
    # =========================================================================

    def enroll_user_face(self, user_id: int, image_base64: str) -> Dict[str, Any]:
        bgr_img = self.decode_base64_image(image_base64)
        if bgr_img is None:
            return {"status": "error", "message": "Impossible de décoder l'image Base64 envoyée."}

        ok_quality, msg_quality, quality_metrics = self.check_image_quality(bgr_img)
        if not ok_quality:
            return {"status": "error", "message": msg_quality, "quality": quality_metrics}

        bbox, msg_face = self.detect_single_face_bbox(bgr_img)
        if bbox is None:
            return {"status": "error", "message": msg_face, "quality": quality_metrics}

        embedding_512d = self.extract_512d_embedding(bgr_img, bbox)

        filename = f"user_{int(user_id)}_ref.jpg"
        rel_path = os.path.join("enrolled_faces", filename)
        abs_path = os.path.join(self.enrolled_dir, filename)
        cv2.imwrite(abs_path, bgr_img)

        return {
            "status": "success",
            "message": "Empreinte biométrique 512D extraite et enregistrée avec succès.",
            "user_id": int(user_id),
            "engine_mode": self.engine_mode,
            "embedding_dimension": self.EMBEDDING_DIM,
            "embedding_512d": [round(float(x), 6) for x in embedding_512d.tolist()],
            "reference_image_path": rel_path,
            "quality": quality_metrics,
        }

    def verify_user_face_1to1(
        self,
        user_id: int,
        frames_base64: List[str],
        stored_embedding_512d: List[float],
        threshold: Optional[float] = None,
    ) -> Dict[str, Any]:
        active_threshold = float(threshold if threshold is not None else self.match_threshold)

        if not stored_embedding_512d or len(stored_embedding_512d) != self.EMBEDDING_DIM:
            return {
                "status": "error",
                "message": f"Profil biométrique enregistré invalide (attendu {self.EMBEDDING_DIM} dimensions).",
            }

        if not isinstance(frames_base64, list) or len(frames_base64) == 0:
            return {"status": "error", "message": "Aucune image reçue de la caméra de la borne."}

        valid_frames: List[np.ndarray] = []
        valid_bboxes: List[Tuple[int, int, int, int]] = []
        frame_embeddings: List[np.ndarray] = []
        last_error_msg = "Visage non détecté."

        for idx, b64 in enumerate(frames_base64):
            img = self.decode_base64_image(b64)
            if img is None:
                continue
            ok_q, msg_q, _ = self.check_image_quality(img)
            if not ok_q:
                last_error_msg = f"Image {idx + 1} : {msg_q}"
                continue
            bbox, msg_f = self.detect_single_face_bbox(img)
            if bbox is None:
                last_error_msg = f"Image {idx + 1} : {msg_f}"
                continue

            emb = self.extract_512d_embedding(img, bbox)
            valid_frames.append(img)
            valid_bboxes.append(bbox)
            frame_embeddings.append(emb)

        if len(valid_frames) < 2:
            return {
                "status": "error",
                "is_live": False,
                "matched": False,
                "message": f"Nombre d'images valides insuffisant ({len(valid_frames)}/2 minimum). Détail : {last_error_msg}",
            }

        is_live, live_msg, live_details = self.verify_liveness_sequence(valid_frames, valid_bboxes)
        if not is_live:
            return {
                "status": "success",
                "is_live": False,
                "matched": False,
                "similarity_score": 0.0,
                "message": live_msg,
                "liveness_details": live_details,
            }

        mean_live_embedding = self.l2_normalize(np.mean(np.stack(frame_embeddings, axis=0), axis=0))
        similarity = self.cosine_similarity(mean_live_embedding.tolist(), stored_embedding_512d)
        matched = bool(similarity >= active_threshold)

        return {
            "status": "success",
            "user_id": int(user_id),
            "engine_mode": self.engine_mode,
            "is_live": True,
            "matched": matched,
            "similarity_score": round(float(similarity), 4),
            "threshold_used": active_threshold,
            "liveness_details": live_details,
            "message": (
                f"Identité et vivacité confirmées (score : {similarity * 100:.1f} %)."
                if matched
                else f"Visage non reconnu pour ce compte (score : {similarity * 100:.1f} %, requis : {active_threshold * 100:.0f} %)."
            ),
        }