<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : api/auth_helpers.php  [Membre 3]
 * Rôle : Fonctions utilitaires communes aux scripts api/auth_*.php (ce n'est PAS une route API).
 */

// Refuse l'appel direct de ce fichier depuis le navigateur
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

/**
 * Lit les données de la requête et impose la méthode POST.
 * (getRequestData() répond déjà aux pré-requêtes CORS OPTIONS.)
 */
function getPostData(): array {
    $data = getRequestData();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée : utilisez POST.'], 405);
    }
    return $data;
}

/**
 * Renvoie une chaîne nettoyée (trim) ou '' si la valeur est absente / n'est pas une chaîne.
 */
function inputString(array $data, string $key): string {
    return (isset($data[$key]) && is_string($data[$key])) ? trim($data[$key]) : '';
}

function normalizeEmail(string $email): string {
    return mb_strtolower(trim($email), 'UTF-8');
}

/**
 * Normalise un numéro camerounais : retire espaces, points, tirets, parenthèses
 * et l'indicatif (+237, 00237 ou 237) pour obtenir les 9 chiffres (ex. 690000001).
 */
function normalizePhone(string $phone): string {
    $p = preg_replace('/[\s.\-()]/', '', trim($phone));
    if (strpos($p, '+237') === 0) {
        $p = substr($p, 4);
    } elseif (strpos($p, '00237') === 0) {
        $p = substr($p, 5);
    } elseif (strlen($p) === 12 && strpos($p, '237') === 0) {
        $p = substr($p, 3);
    }
    return $p;
}

function isValidPhone(string $phone): bool {
    return (bool)preg_match('/^\d{9}$/', $phone);
}

/**
 * Annule la transaction PDO en cours, s'il y en a une.
 */
function rollBackIfNeeded(PDO $pdo): void {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

/**
 * Réponse d'erreur 500 générique (détail technique visible seulement si APP_DEBUG).
 */
function sendServerError(Throwable $e): void {
    error_log('[auth] ' . $e->getMessage());
    sendJsonResponse([
        'status'  => 'error',
        'message' => 'Erreur interne du serveur.',
        'debug'   => APP_DEBUG ? $e->getMessage() : null,
    ], 500);
}
