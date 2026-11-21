<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_login.php  [Membre 3]
 * Entrée  (POST JSON) : identifier (email ou téléphone), password
 * Sortie  : {status:'success', user:{id, full_name, is_face_enrolled}}
 *
 * Remarque : ATTR_EMULATE_PREPARES = false dans db.php, donc un même paramètre nommé
 * ne peut pas être utilisé deux fois dans une requête : on utilise :email et :phone.
 */

require_once __DIR__ . '/auth_helpers.php';

$data       = getPostData();
$identifier = inputString($data, 'identifier');
$password   = (isset($data['password']) && is_string($data['password'])) ? $data['password'] : '';

if ($identifier === '' || $password === '') {
    sendJsonResponse(['status' => 'error', 'message' => 'Identifiant et mot de passe requis.'], 422);
}

// Un identifiant contenant « @ » est un email, sinon c'est un numéro de téléphone
if (strpos($identifier, '@') !== false) {
    $email = normalizeEmail($identifier);
    $phone = null;
} else {
    $email = null;
    $phone = normalizePhone($identifier);
}

try {
    $pdo  = getDB();
    $stmt = $pdo->prepare('
        SELECT id, full_name, password_hash, is_email_verified, is_face_enrolled
        FROM users
        WHERE email = :email OR phone = :phone
        LIMIT 1
    ');
    $stmt->execute([':email' => $email, ':phone' => $phone]); // NULL ne correspond jamais
    $user = $stmt->fetch();

    // Hash factice : le temps de réponse reste le même si le compte n'existe pas
    $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    $hashToVerify = is_array($user) ? $user['password_hash'] : $dummyHash;
    $passwordOk = password_verify($password, $hashToVerify);

    if (!$user || !$passwordOk) {
        sendJsonResponse(['status' => 'error', 'message' => 'Identifiants incorrects.'], 401);
    }

    if ((int)$user['is_email_verified'] !== 1) {
        sendJsonResponse([
            'status'     => 'error',
            'error_code' => 'EMAIL_NOT_VERIFIED',
            'message'    => 'Email non vérifié. Inscrivez-vous à nouveau pour recevoir un nouveau code.',
            'user_id'    => (int)$user['id'],
        ], 403);
    }

    // Met à jour le hash si l'algorithme PHP par défaut a évolué
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
            ->execute([':h' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['id']]);
    }
} catch (Throwable $e) {
    sendServerError($e);
}

// Ouverture de session (protection contre la fixation de session)
session_regenerate_id(true);
$_SESSION['user_id'] = (int)$user['id'];

sendJsonResponse([
    'status' => 'success',
    'user'   => [
        'id'               => (int)$user['id'],
        'full_name'        => $user['full_name'],
        'is_face_enrolled' => (int)$user['is_face_enrolled'],
    ],
]);
