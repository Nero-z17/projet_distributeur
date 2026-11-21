<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_register.php  [Membre 3]
 * Entrée  (POST JSON) : full_name, email, phone, password
 * Sortie  : {status:'success', user_id, message:'Code OTP envoyé'}
 *
 * - Crée le compte (password_hash) + le wallet à 0 FCFA.
 * - Génère un code OTP à 6 chiffres (purpose = 'REGISTER') et l'envoie par email.
 * - Si un compte NON vérifié existe déjà avec le même email/téléphone, il est remplacé
 *   (permet de se réinscrire pour recevoir un nouveau code). Un compte déjà vérifié est refusé.
 */

require_once __DIR__ . '/auth_helpers.php';

$data     = getPostData();
$fullName = inputString($data, 'full_name');
$email    = normalizeEmail(inputString($data, 'email'));
$phone    = normalizePhone(inputString($data, 'phone'));
$password = (isset($data['password']) && is_string($data['password'])) ? $data['password'] : '';

// ---- Validation des champs ----
$nameLength = mb_strlen($fullName, 'UTF-8');
if ($nameLength < 3 || $nameLength > 120) {
    sendJsonResponse(['status' => 'error', 'message' => 'Le nom complet doit contenir entre 3 et 120 caractères.'], 422);
}
if ($email === '' || strlen($email) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Adresse email invalide.'], 422);
}
if (!isValidPhone($phone)) {
    sendJsonResponse(['status' => 'error', 'message' => 'Numéro de téléphone invalide (9 chiffres attendus, ex. 690000001).'], 422);
}
if (strlen($password) < 8) {
    sendJsonResponse(['status' => 'error', 'message' => 'Le mot de passe doit contenir au moins 8 caractères.'], 422);
}
if (strlen($password) > 72) {
    sendJsonResponse(['status' => 'error', 'message' => 'Le mot de passe est trop long (72 caractères maximum).'], 422);
}

$pdo = getDB();

try {
    $pdo->beginTransaction();

    // ---- 1. Doublons : refuser si vérifié, remplacer si non vérifié ----
    $stmt = $pdo->prepare('
        SELECT id, email, phone, is_email_verified
        FROM users
        WHERE email = :email OR phone = :phone
        FOR UPDATE
    ');
    $stmt->execute([':email' => $email, ':phone' => $phone]);

    $staleIds = [];
    foreach ($stmt->fetchAll() as $existing) {
        if ((int)$existing['is_email_verified'] === 1) {
            rollBackIfNeeded($pdo);
            $field = ($existing['email'] === $email) ? 'cet email' : 'ce numéro de téléphone';
            sendJsonResponse(['status' => 'error', 'message' => "Un compte existe déjà avec $field."], 409);
        }
        $staleIds[] = (int)$existing['id'];
    }
    foreach ($staleIds as $staleId) {
        // ON DELETE CASCADE supprime aussi wallet, codes OTP et profil biométrique
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $staleId]);
    }

    // ---- 2. Création de l'utilisateur ----
    $ins = $pdo->prepare('
        INSERT INTO users (full_name, email, phone, password_hash)
        VALUES (:full_name, :email, :phone, :hash)
    ');
    $ins->execute([
        ':full_name' => $fullName,
        ':email'     => $email,
        ':phone'     => $phone,
        ':hash'      => password_hash($password, PASSWORD_DEFAULT),
    ]);
    $userId = (int)$pdo->lastInsertId();

    // ---- 3. Création du wallet à 0 FCFA (numéro de compte unique, 5 essais max) ----
    $walletCreated = false;
    for ($i = 0; $i < 5 && !$walletCreated; $i++) {
        try {
            $pdo->prepare('
                INSERT INTO wallets (user_id, account_number, balance_fcfa, reserved_fcfa)
                VALUES (:uid, :acc, 0, 0)
            ')->execute([':uid' => $userId, ':acc' => generateAccountNumber()]);
            $walletCreated = true;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { // 1062 = doublon : on retente un autre numéro
                throw $e;
            }
        }
    }
    if (!$walletCreated) {
        throw new RuntimeException('Impossible de générer un numéro de compte unique.');
    }

    // ---- 4. Code OTP d'inscription (valable OTP_EXPIRY_MINUTES minutes) ----
    $code = generateOtpCode();
    $pdo->prepare('
        INSERT INTO otp_codes (user_id, code, purpose, expires_at)
        VALUES (:uid, :code, \'REGISTER\', DATE_ADD(NOW(), INTERVAL ' . (int)OTP_EXPIRY_MINUTES . ' MINUTE))
    ')->execute([':uid' => $userId, ':code' => $code]);

    $pdo->commit();
} catch (PDOException $e) {
    rollBackIfNeeded($pdo);
    if ($e->getCode() === '23000') { // course critique : doublon créé entre-temps
        sendJsonResponse(['status' => 'error', 'message' => 'Email ou téléphone déjà utilisé.'], 409);
    }
    sendServerError($e);
} catch (Throwable $e) {
    rollBackIfNeeded($pdo);
    sendServerError($e);
}

// ---- 5. Envoi de l'email (hors transaction) ----
if (!sendOtpEmail($email, $fullName, $code, 'REGISTER')) {
    sendJsonResponse([
        'status'  => 'error',
        'message' => "Compte créé, mais l'email n'a pas pu être envoyé. Réessayez de vous inscrire avec les mêmes informations pour recevoir un nouveau code.",
    ], 502);
}

sendJsonResponse([
    'status'  => 'success',
    'user_id' => $userId,
    'message' => 'Code OTP envoyé',
], 201);
