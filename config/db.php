<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : config/db.php
 * Rôle : Connexion PDO MySQL + Nettoyage automatique des réservations expirées + Fonctions JSON
 * Inclusion dans chaque fichier PHP : require_once __DIR__ . '/../config/db.php';
 */

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Retourne l'instance PDO connectée à la base MySQL `dab_banque`
 */
function getDB(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            sendJsonResponse([
                'status'  => 'error',
                'message' => 'Erreur de connexion MySQL. Vérifiez que MySQL est actif ou que les identifiants distants sont corrects.',
                'debug'   => APP_DEBUG ? $e->getMessage() : null
            ], 500);
        }
    }

    return $pdo;
}

/**
 * Libère automatiquement l'argent bloqué dans `reserved_fcfa` si une session de retrait DAB
 * est restée bloquée en `RESERVED_DISPENSING` après expiration (ex: coupure réseau ou crash navigateur).
 */
function cleanupExpiredAtmSessions(PDO $pdo): void {
    try {
        // 1. Expirer les sessions simples (OTP_SENT, OTP_VERIFIED, FACE_VERIFIED) dépassées
        $pdo->exec("
            UPDATE atm_withdrawal_sessions
            SET state = 'EXPIRED'
            WHERE state IN ('OTP_SENT', 'OTP_VERIFIED', 'FACE_VERIFIED')
              AND expires_at < NOW()
        ");

        // 2. Rembourser les sessions RESERVED_DISPENSING dont le délai a expiré sans confirmation
        $stmt = $pdo->query("
            SELECT id, user_id, transaction_id, amount_fcfa
            FROM atm_withdrawal_sessions
            WHERE state = 'RESERVED_DISPENSING'
              AND expires_at < NOW()
            FOR UPDATE
        ");
        $expiredReservations = $stmt->fetchAll();

        foreach ($expiredReservations as $sess) {
            $pdo->beginTransaction();
            try {
                // Restituer reserved_fcfa vers balance_fcfa
                $updWallet = $pdo->prepare("
                    UPDATE wallets
                    SET balance_fcfa = balance_fcfa + :amt,
                        reserved_fcfa = GREATEST(0, reserved_fcfa - :amt)
                    WHERE user_id = :uid
                ");
                $updWallet->execute([
                    ':amt' => $sess['amount_fcfa'],
                    ':uid' => $sess['user_id']
                ]);

                // Marquer la transaction comme REVERSED
                if (!empty($sess['transaction_id'])) {
                    $updTx = $pdo->prepare("
                        UPDATE transactions
                        SET status = 'REVERSED',
                            description = CONCAT(IFNULL(description, ''), ' [Annulation auto : délai dépassé]')
                        WHERE id = :txid AND status = 'RESERVED'
                    ");
                    $updTx->execute([':txid' => $sess['transaction_id']]);
                }

                // Marquer la session comme ABORTED_REVERSED
                $updSess = $pdo->prepare("
                    UPDATE atm_withdrawal_sessions
                    SET state = 'ABORTED_REVERSED'
                    WHERE id = :sid
                ");
                $updSess->execute([':sid' => $sess['id']]);

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
            }
        }
    } catch (Throwable $ignored) {
        // Ne bloque pas la requête appelante
    }
}

/**
 * Envoie une réponse JSON normalisée et arrête l'exécution
 */
function sendJsonResponse(array $data, int $httpCode = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        http_response_code($httpCode);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Récupère les paramètres envoyés en JSON (fetch JS) ou en POST classique
 */
function getRequestData(): array {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        sendJsonResponse(['status' => 'ok'], 200);
    }
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $decoded = json_decode($rawInput, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

/**
 * Génère une référence unique de transaction (ex: TX-20261113-4829)
 */
function generateTxReference(string $prefix = 'TX'): string {
    return sprintf('%s-%s-%04d', $prefix, date('YmdHis'), random_int(1000, 9999));
}

/**
 * Génère un numéro de compte bancaire unique (ex: CM-2026-84920)
 */
function generateAccountNumber(): string {
    return sprintf('CM-%s-%05d', date('Y'), random_int(10000, 99999));
}