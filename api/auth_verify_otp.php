<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_verify_otp.php  [Membre 3]
 * Entrée  (POST JSON) : user_id (ou email), code
 * Sortie  : {status:'success', message:'Email vérifié'}
 *
 * - Vérifie le dernier code 'REGISTER' non utilisé (expiration + 3 essais maximum).
 * - Succès : otp_codes.is_used = 1 et users.is_email_verified = 1.
 * - Ouvre la session PHP ($_SESSION['user_id']) : l'étape suivante (auth_enroll_face.php)
 *   ne reçoit que l'image et identifie donc le client grâce à cette session.
 */

require_once __DIR__ . '/auth_helpers.php';

$data = getPostData();
$code = inputString($data, 'code');

if (!preg_match('/^\d{6}$/', $code)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Le code doit contenir exactement 6 chiffres.'], 422);
}

$userIdInput = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
$emailInput  = normalizeEmail(inputString($data, 'email'));
if ($userIdInput === false && $emailInput === '') {
    sendJsonResponse(['status' => 'error', 'message' => 'Fournissez user_id ou email.'], 422);
}

$pdo = getDB();

$httpCode   = 200;
$response   = [];
$openSession = false;
$userId     = 0;

try {
    $pdo->beginTransaction();

    // ---- Utilisateur ----
    if ($userIdInput !== false) {
        $stmt = $pdo->prepare('SELECT id, is_email_verified FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $userIdInput]);
    } else {
        $stmt = $pdo->prepare('SELECT id, is_email_verified FROM users WHERE email = :email FOR UPDATE');
        $stmt->execute([':email' => $emailInput]);
    }
    $user = $stmt->fetch();

    if (!$user) {
        $httpCode = 404;
        $response = ['status' => 'error', 'message' => 'Utilisateur introuvable.'];
    } elseif ((int)$user['is_email_verified'] === 1) {
        $response = ['status' => 'success', 'message' => 'Email déjà vérifié. Vous pouvez vous connecter.'];
    } else {
        $userId = (int)$user['id'];

        // ---- Dernier code d'inscription actif ----
        $stmt = $pdo->prepare('
            SELECT id, code, attempts, (expires_at < NOW()) AS is_expired
            FROM otp_codes
            WHERE user_id = :uid AND purpose = \'REGISTER\' AND is_used = 0
            ORDER BY id DESC
            LIMIT 1
            FOR UPDATE
        ');
        $stmt->execute([':uid' => $userId]);
        $otp = $stmt->fetch();

        if (!$otp) {
            $httpCode = 400;
            $response = ['status' => 'error', 'message' => 'Aucun code actif. Inscrivez-vous à nouveau pour recevoir un nouveau code.'];
        } elseif ((int)$otp['attempts'] >= OTP_MAX_ATTEMPTS) {
            $httpCode = 429;
            $response = ['status' => 'error', 'message' => 'Trop de tentatives. Inscrivez-vous à nouveau pour recevoir un nouveau code.'];
        } elseif ((int)$otp['is_expired'] === 1) {
            $httpCode = 410;
            $response = ['status' => 'error', 'message' => 'Code expiré. Inscrivez-vous à nouveau pour recevoir un nouveau code.'];
        } elseif (!hash_equals((string)$otp['code'], $code)) {
            $pdo->prepare('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = :id')
                ->execute([':id' => $otp['id']]);
            $remaining = max(0, OTP_MAX_ATTEMPTS - ((int)$otp['attempts'] + 1));
            $httpCode  = 401;
            $response  = [
                'status'             => 'error',
                'message'            => $remaining > 0
                    ? "Code incorrect. Il vous reste $remaining essai(s)."
                    : 'Code incorrect. Nombre maximal d\'essais atteint.',
                'attempts_remaining' => $remaining,
            ];
        } else {
            // Code correct : on consomme tous les codes d'inscription et on valide l'email
            $pdo->prepare('UPDATE otp_codes SET is_used = 1 WHERE user_id = :uid AND purpose = \'REGISTER\'')
                ->execute([':uid' => $userId]);
            $pdo->prepare('UPDATE users SET is_email_verified = 1 WHERE id = :uid')
                ->execute([':uid' => $userId]);

            $response    = ['status' => 'success', 'message' => 'Email vérifié'];
            $openSession = true;
        }
    }

    $pdo->commit(); // valide aussi l'incrément des tentatives en cas d'erreur
} catch (Throwable $e) {
    rollBackIfNeeded($pdo);
    sendServerError($e);
}

if ($openSession) {
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

sendJsonResponse($response, $httpCode);
