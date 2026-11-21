<?php
// api/atm_step3_verify_face.php — [Membre 5] Étape 3 : vivacité 3D + comparaison 1:1 -> FACE_VERIFIED
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

$in           = getRequestData();
// === BLOC 1 : validation de la requête (3 à 5 images, d'après le cahier) ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$sessionToken = trim($in['session_token'] ?? '');
$frames       = $in['frames_base64'] ?? null;

if ($sessionToken === '' || !is_array($frames) || count($frames) < 3 || count($frames) > 5) {
    sendJsonResponse(['status' => 'error', 'message' => 'Il faut une rafale de 3 à 5 images'], 400);
}

// === BLOC 2 : la session doit être à OTP_VERIFIED et non expirée ===
$stmt = $pdo->prepare(
    "SELECT id, user_id, atm_id, state, (expires_at < NOW()) AS expired
     FROM atm_withdrawal_sessions WHERE session_token = ?"
);
$stmt->execute([$sessionToken]);
$session = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$session)                            sendJsonResponse(['status' => 'error', 'message' => 'Session introuvable'], 404);
if ($session['expired'])                  sendJsonResponse(['status' => 'error', 'message' => 'Session expirée'], 410);
if ($session['state'] !== 'OTP_VERIFIED') sendJsonResponse(['status' => 'error', 'message' => 'Étape invalide pour cette session'], 409);

// === BLOC 3 : empreinte 512D de CET utilisateur uniquement ===
$stmt = $pdo->prepare("SELECT embedding_512d_json FROM biometric_profiles WHERE user_id = ?");
$stmt->execute([$session['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$profile) {
    sendJsonResponse(['status' => 'error', 'message' => 'Aucune empreinte faciale enregistrée'], 404);
}
$embedding = json_decode($profile['embedding_512d_json'], true);

// === BLOC 4 : appel du service Python (contrat du Membre 1) ===
$ai = callPythonAI('/api/face/verify', [
    'user_id'               => (int)$session['user_id'],
    'frames_base64'         => $frames,
    'stored_embedding_512d' => $embedding,
]);
if (!is_array($ai)) {
    // Pas de réponse exploitable : service Python arrêté ou timeout
    sendJsonResponse(['status' => 'error', 'message' => 'Service de reconnaissance indisponible'], 502);
}
if (($ai['status'] ?? '') !== 'error' && ($ai['status'] ?? '') !== 'success') {
    sendJsonResponse(['status' => 'error', 'message' => 'Réponse inattendue du service facial'], 502);
}
if ($ai['status'] === 'error') {
    // Erreur "corrigeable" (image floue, trop sombre, aucun visage...) : on affiche le message du moteur
    sendJsonResponse(['status' => 'error', 'message' => $ai['message'] ?? 'Image inexploitable, recommencez'], 422);
}

// === BLOC 5 : décision ===
if (empty($ai['is_live'])) {
    sendJsonResponse(['status' => 'error', 'message' => 'Vivacité non confirmée, recommencez'], 403);
}
if (empty($ai['matched'])) {
    sendJsonResponse(['status' => 'error', 'message' => 'Visage non reconnu'], 403);
}
$score = (float)($ai['similarity_score'] ?? 0);

// UPDATE conditionnel : évite une double validation en parallèle
$upd = $pdo->prepare(
    "UPDATE atm_withdrawal_sessions
     SET face_similarity_score = ?, state = 'FACE_VERIFIED'
     WHERE id = ? AND state = 'OTP_VERIFIED'"
);
$upd->execute([$score, $session['id']]);
if ($upd->rowCount() !== 1) {
    sendJsonResponse(['status' => 'error', 'message' => 'Session déjà traitée'], 409);
}

// === BLOC 6 : montant maximum retirable (min(solde, stock de billets)) ===
$stmt = $pdo->prepare(
    "SELECT w.balance_fcfa, a.denomination_fcfa, a.notes_remaining
     FROM wallets w, atm_machines a WHERE w.user_id = ? AND a.id = ?"
);
$stmt->execute([$session['user_id'], $session['atm_id']]);
$r     = $stmt->fetch(PDO::FETCH_ASSOC);
$denom = max(1, (int)$r['denomination_fcfa']);
$max   = min((int)$r['balance_fcfa'], (int)$r['notes_remaining'] * $denom);
$max   = $max - ($max % $denom);

sendJsonResponse([
    'status'               => 'success',
    'state'                => 'FACE_VERIFIED',
    'similarity_score'     => $score,
    'max_withdrawable_fcfa'=> $max,
]);
