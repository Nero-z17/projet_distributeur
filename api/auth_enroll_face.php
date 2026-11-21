<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_enroll_face.php  [Membre 3]
 * Entrée  (POST JSON) : image_base64 (selfie du client)
 * Sortie  : {status:'success', message:'Visage 512D enregistré'}
 *
 * - Le client est identifié par la session PHP ($_SESSION['user_id'], ouverte par
 *   auth_verify_otp.php ou auth_login.php). Le front doit donc envoyer le cookie de session
 *   (fetch(..., {credentials: 'same-origin'}) — c'est le comportement par défaut en même origine).
 * - Appelle le service Python IA (POST /api/face/enroll, port 5000).
 * - Enregistre embedding_512d_json dans biometric_profiles et passe users.is_face_enrolled = 1.
 */

require_once __DIR__ . '/auth_helpers.php';

$data = getPostData();

// ---- 1. Utilisateur connecté ----
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
if ($userId <= 0) {
    sendJsonResponse(['status' => 'error', 'message' => 'Non connecté. Veuillez vous connecter.'], 401);
}

$pdo = getDB();

try {
    $stmt = $pdo->prepare('SELECT id, is_email_verified FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
} catch (Throwable $e) {
    sendServerError($e);
}

if (!$user) {
    sendJsonResponse(['status' => 'error', 'message' => 'Utilisateur introuvable.'], 404);
}
if ((int)$user['is_email_verified'] !== 1) {
    sendJsonResponse(['status' => 'error', 'message' => 'Validez d\'abord votre email.'], 403);
}

// ---- 2. Validation de l'image ----
$img = inputString($data, 'image_base64');
$img = preg_replace('#^data:image/[a-zA-Z0-9.+-]+;base64,#', '', $img); // retire un éventuel préfixe data:
$img = preg_replace('/\s+/', '', $img);

if ($img === '') {
    sendJsonResponse(['status' => 'error', 'message' => 'Image manquante (image_base64).'], 422);
}

$raw = base64_decode($img, true);
if ($raw === false || $raw === '') {
    sendJsonResponse(['status' => 'error', 'message' => 'Image Base64 invalide.'], 422);
}
if (strlen($raw) > 8 * 1024 * 1024) {
    sendJsonResponse(['status' => 'error', 'message' => 'Image trop volumineuse (8 Mo maximum).'], 413);
}

$info = @getimagesizefromstring($raw);
$allowedTypes = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];
if ($info === false || !in_array($info[2], $allowedTypes, true)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Format d\'image non supporté (JPEG, PNG ou WebP).'], 422);
}

// ---- 3. Appel du service Python IA (Membre 1, port 5000) ----
$res = callPythonAI('/api/face/enroll', ['user_id' => $userId, 'image_base64' => $img], 30);

if (($res['status'] ?? '') !== 'success') {
    if (isset($res['detail'])) {            // service injoignable (erreur cURL)
        $http = 503;
    } elseif (isset($res['http_code'])) {   // réponse non JSON
        $http = 502;
    } else {                                // refus métier (aucun visage, image floue, ...)
        $http = 422;
    }
    sendJsonResponse([
        'status'  => 'error',
        'message' => $res['message'] ?? 'Échec de l\'enrôlement facial.',
        'debug'   => APP_DEBUG ? ($res['detail'] ?? null) : null,
    ], $http);
}

// ---- 4. Validation du vecteur 512D renvoyé ----
$embedding = $res['embedding_512d'] ?? null;
$valid = is_array($embedding) && count($embedding) === 512;
if ($valid) {
    foreach ($embedding as $v) {
        if (!is_int($v) && !is_float($v)) {
            $valid = false;
            break;
        }
    }
}
if (!$valid) {
    sendJsonResponse(['status' => 'error', 'message' => 'Le service IA a renvoyé un vecteur facial invalide (512 valeurs attendues).'], 502);
}

$embeddingJson = json_encode(array_map('floatval', $embedding));
$refPath = mb_substr((string)($res['reference_image_path'] ?? "enrolled_faces/user_{$userId}.jpg"), 0, 255, 'UTF-8');
$modelVersion = mb_substr((string)($res['model_version'] ?? $res['engine_mode'] ?? 'ArcFace-512D'), 0, 50, 'UTF-8');

// ---- 5. Enregistrement (création ou remplacement du profil) ----
try {
    $pdo->beginTransaction();

    $pdo->prepare('
        INSERT INTO biometric_profiles (user_id, embedding_512d_json, reference_image_path, model_version)
        VALUES (:uid, :emb, :path, :ver)
        ON DUPLICATE KEY UPDATE
            embedding_512d_json  = VALUES(embedding_512d_json),
            reference_image_path = VALUES(reference_image_path),
            model_version        = VALUES(model_version)
    ')->execute([
        ':uid'  => $userId,
        ':emb'  => $embeddingJson,
        ':path' => $refPath,
        ':ver'  => $modelVersion,
    ]);

    $pdo->prepare('UPDATE users SET is_face_enrolled = 1 WHERE id = :uid')
        ->execute([':uid' => $userId]);

    $pdo->commit();
} catch (Throwable $e) {
    rollBackIfNeeded($pdo);
    sendServerError($e);
}

sendJsonResponse(['status' => 'success', 'message' => 'Visage 512D enregistré']);
