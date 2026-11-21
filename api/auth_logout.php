<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_logout.php  [Membre 3]
 * Entrée  : aucune (POST ou GET)
 * Sortie  : {status:'success', message:'Déconnexion réussie'}
 */

require_once __DIR__ . '/../config/db.php';

getRequestData(); // répond aux pré-requêtes CORS OPTIONS

// Vide la session, supprime le cookie puis détruit la session côté serveur
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?: 'Lax',
    ]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

sendJsonResponse(['status' => 'success', 'message' => 'Déconnexion réussie']);
