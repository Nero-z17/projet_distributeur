<?php
// api/atm_step1_send_code.php — [Membre 5] Étape 1 : email -> code OTP -> session OTP_SENT
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

$pdo = getDB();

function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2);
    return substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $domain;
}

$in      = getRequestData();
// === BLOC 1 : validation de la requête ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$email   = strtolower(trim($in['email'] ?? ''));
$atmName = trim($in['atm_id'] ?? 'DAB-DOUALA-01');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Adresse email invalide'], 400);
}

// === BLOC 2 : état de la borne ===
cleanupExpiredAtmSessions($pdo);
$stmt = $pdo->prepare("SELECT id, status, notes_remaining FROM atm_machines WHERE id = ?");
$stmt->execute([$atmName]);
$atm = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$atm) {
    sendJsonResponse(['status' => 'error', 'message' => 'Borne introuvable'], 404);
}
if ($atm['status'] === 'JAMMED' || (int)$atm['notes_remaining'] <= 0) {
    sendJsonResponse(['status' => 'error', 'message' => 'Borne indisponible'], 503);
}

// === BLOC 3 : vérifications du client ===
$stmt = $pdo->prepare(
    "SELECT id, full_name, is_face_enrolled, atm_withdrawal_enabled
     FROM users WHERE email = ?"
);
$stmt->execute([$email]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    sendJsonResponse(['status' => 'error', 'message' => 'Aucun compte avec cet email'], 404);
}
if ((int)$user['is_face_enrolled'] !== 1) {
    sendJsonResponse(['status' => 'error', 'message' => 'Visage non enregistré : utilisez l\'application mobile'], 403);
}
if ((int)$user['atm_withdrawal_enabled'] !== 1) {
    sendJsonResponse(['status' => 'error', 'message' => 'Retraits au distributeur bloqués sur ce compte'], 403);
}

// === BLOC 4 : code OTP + session (atomique) ===
$code         = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$sessionToken = bin2hex(random_bytes(32));

try {
    $pdo->beginTransaction();

    // On invalide les anciens codes DAB non utilisés de ce client
    $pdo->prepare(
        "UPDATE otp_codes SET is_used = 1
         WHERE user_id = ? AND purpose = 'ATM_WITHDRAWAL' AND is_used = 0"
    )->execute([$user['id']]);

    $pdo->prepare(
        "INSERT INTO otp_codes (user_id, code, purpose, attempts, is_used, expires_at)
         VALUES (?, ?, 'ATM_WITHDRAWAL', 0, 0, DATE_ADD(NOW(), INTERVAL 5 MINUTE))"
    )->execute([$user['id'], $code]);
    $otpId = (int)$pdo->lastInsertId();

    $pdo->prepare(
        "INSERT INTO atm_withdrawal_sessions
            (session_token, user_id, atm_id, otp_code_id, state, expires_at)
         VALUES (?, ?, ?, ?, 'OTP_SENT', DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
    )->execute([$sessionToken, $user['id'], $atm['id'], $otpId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse(['status' => 'error', 'message' => 'Erreur serveur'], 500);
}

// === BLOC 5 : envoi du mail (après le commit) ===
$body = "<p>Bonjour " . htmlspecialchars($user['full_name']) . ",</p>"
      . "<p>Votre code de retrait au distributeur : <b style='font-size:22px'>$code</b></p>"
      . "<p>Valable 5 minutes. Ne le communiquez à personne.</p>";

if (!sendProjectEmail($email, 'Code de retrait DAB', $body)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Envoi de l\'email impossible'], 502);
}

// === BLOC 6 : réponse (contrat du cahier) ===
sendJsonResponse([
    'status'        => 'success',
    'session_token' => $sessionToken,
    'masked_email'  => maskEmail($email),
    'message'       => 'Code envoyé par email',
]);
