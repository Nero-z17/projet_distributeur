<?php
// api/atm_step2_verify_code.php — [Membre 5] Étape 2 : code à 6 chiffres -> OTP_VERIFIED
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

const MAX_ATTEMPTS = 3;
$pdo = getDB();

$in           = getRequestData();
// === BLOC 1 : validation de la requête ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$sessionToken = trim($in['session_token'] ?? '');
$code         = trim((string)($in['code'] ?? ''));
if ($sessionToken === '' || !preg_match('/^\d{6}$/', $code)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Code à 6 chiffres requis'], 400);
}

$result = null;   // [réponse, code HTTP] rempli dans la transaction
try {
    $pdo->beginTransaction();

    // === BLOC 2 : chargement verrouillé de la session, du code et du client ===
    $stmt = $pdo->prepare(
        "SELECT s.id AS sid, s.state, (s.expires_at < NOW()) AS session_expired,
                o.id AS oid, o.code, o.attempts, o.is_used, (o.expires_at < NOW()) AS otp_expired,
                u.full_name
         FROM atm_withdrawal_sessions s
         JOIN otp_codes o ON o.id = s.otp_code_id
         JOIN users u     ON u.id = s.user_id
         WHERE s.session_token = ?
         FOR UPDATE"
    );
    $stmt->execute([$sessionToken]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    // === BLOC 3 : contrôles d'état ===
    if (!$row) {
        $result = [['status' => 'error', 'message' => 'Session introuvable'], 404];
    } elseif ($row['state'] !== 'OTP_SENT') {
        $result = [['status' => 'error', 'message' => 'Étape invalide pour cette session'], 409];
    } elseif ((int)$row['attempts'] >= MAX_ATTEMPTS) {
        $result = [['status' => 'error', 'message' => 'Trop d\'essais, recommencez depuis l\'étape 1', 'attempts_left' => 0], 429];
    } elseif ($row['session_expired'] || $row['otp_expired'] || $row['is_used']) {
        $result = [['status' => 'error', 'message' => 'Code expiré, recommencez'], 410];
    } elseif (hash_equals((string)$row['code'], $code)) {
        // === BLOC 4a : code correct ===
        $pdo->prepare("UPDATE otp_codes SET is_used = 1 WHERE id = ?")->execute([$row['oid']]);
        $pdo->prepare("UPDATE atm_withdrawal_sessions SET state = 'OTP_VERIFIED' WHERE id = ?")
            ->execute([$row['sid']]);
        $result = [[
            'status'    => 'success',
            'state'     => 'OTP_VERIFIED',
            'user_name' => $row['full_name'],
        ], 200];
    } else {
        // === BLOC 4b : code faux -> +1 essai ; à 3 le code est brûlé ===
        $attempts = (int)$row['attempts'] + 1;
        $pdo->prepare("UPDATE otp_codes SET attempts = ?, is_used = ? WHERE id = ?")
            ->execute([$attempts, $attempts >= MAX_ATTEMPTS ? 1 : 0, $row['oid']]);
        $result = [[
            'status'        => 'error',
            'message'       => $attempts >= MAX_ATTEMPTS ? 'Trop d\'essais, recommencez depuis l\'étape 1' : 'Code incorrect',
            'attempts_left' => MAX_ATTEMPTS - $attempts,
        ], $attempts >= MAX_ATTEMPTS ? 429 : 401];
    }

    $pdo->commit();   // toujours commit : le compteur d'essais doit être conservé
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse(['status' => 'error', 'message' => 'Erreur serveur'], 500);
}

sendJsonResponse($result[0], $result[1]);
