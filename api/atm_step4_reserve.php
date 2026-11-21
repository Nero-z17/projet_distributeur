<?php
// api/atm_step4_reserve.php — [Membre 5] Étape 4 (Temps 1) : réservation atomique + jeton HMAC
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

$in           = getRequestData();
// === BLOC 1 : validation de la requête ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$sessionToken = trim($in['session_token'] ?? '');
$amount       = filter_var($in['amount_fcfa'] ?? null, FILTER_VALIDATE_INT);

if ($sessionToken === '' || $amount === false || $amount <= 0) {
    sendJsonResponse(['status' => 'error', 'message' => 'Montant invalide'], 400);
}

$out = null;
try {
    $pdo->beginTransaction();

    // === BLOC 2 : verrouillage de la session ===
    $stmt = $pdo->prepare(
        "SELECT id, user_id, atm_id, state, (expires_at < NOW()) AS expired
         FROM atm_withdrawal_sessions WHERE session_token = ? FOR UPDATE"
    );
    $stmt->execute([$sessionToken]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        $out = [['status' => 'error', 'message' => 'Session introuvable'], 404];
    } elseif ($session['expired']) {
        $out = [['status' => 'error', 'message' => 'Session expirée'], 410];
    } elseif ($session['state'] !== 'FACE_VERIFIED') {
        $out = [['status' => 'error', 'message' => 'Étape invalide pour cette session'], 409];
    } else {
        // === BLOC 3 : verrouillage de la borne et du portefeuille ===
        $stmt = $pdo->prepare(
            "SELECT denomination_fcfa, notes_remaining, status FROM atm_machines WHERE id = ? FOR UPDATE"
        );
        $stmt->execute([$session['atm_id']]);
        $atm = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare(
            "SELECT id, balance_fcfa, reserved_fcfa FROM wallets WHERE user_id = ? FOR UPDATE"
        );
        $stmt->execute([$session['user_id']]);
        $wallet = $stmt->fetch(PDO::FETCH_ASSOC);

        $denom = (int)$atm['denomination_fcfa'];

        // === BLOC 4 : règles métier ===
        if ($atm['status'] === 'JAMMED') {
            $out = [['status' => 'error', 'message' => 'Borne indisponible'], 503];
        } elseif ($amount % $denom !== 0) {
            $out = [['status' => 'error', 'message' => "Le montant doit être un multiple de $denom FCFA"], 400];
        } elseif ((int)$wallet['balance_fcfa'] < $amount) {
            $out = [['status' => 'error', 'message' => 'Solde insuffisant'], 402];
        } elseif ((int)$atm['notes_remaining'] < intdiv($amount, $denom)) {
            $out = [['status' => 'error', 'message' => 'Billets insuffisants dans la borne'], 409];
        } else {
            $notes         = intdiv($amount, $denom);
            $balanceBefore = (int)$wallet['balance_fcfa'];
            $balanceAfter  = $balanceBefore - $amount;

            // === BLOC 5 : balance_fcfa -> reserved_fcfa ===
            $pdo->prepare(
                "UPDATE wallets
                 SET balance_fcfa = balance_fcfa - ?, reserved_fcfa = reserved_fcfa + ?
                 WHERE id = ?"
            )->execute([$amount, $amount, $wallet['id']]);

            // === BLOC 6 : transaction RESERVED (colonnes exactes du cahier) ===
            $pdo->prepare(
                "INSERT INTO transactions
                    (reference, tx_type, source_wallet_id, external_operator, external_target,
                    amount_fcfa, balance_before_fcfa, balance_after_fcfa, status, description)
                VALUES (?, 'RETRAIT_DAB', ?, 'DAB_NDE1000', ?, ?, ?, ?, 'RESERVED', ?)"
            )->execute([
                generateTxReference(), $wallet['id'], $session['atm_id'], $amount,
                $balanceBefore, $balanceAfter, 'Retrait distributeur (réservé)',
            ]);
            $txId = (int)$pdo->lastInsertId();

            // === BLOC 7 : jeton HMAC (signé sur session_token|notes_requested|amount_fcfa,
            //             comme le vérifie le serveur matériel du Membre 2) ===
            $token = generateDispenseHmacToken($sessionToken, $notes, $amount);

            $pdo->prepare(
                "UPDATE atm_withdrawal_sessions
                 SET transaction_id = ?, amount_fcfa = ?, notes_requested = ?,
                     dispense_hmac_token = ?, state = 'RESERVED_DISPENSING'
                 WHERE id = ?"
            )->execute([$txId, $amount, $notes, $token, $session['id']]);

            $out = [[
                'status'              => 'success',
                'state'               => 'RESERVED_DISPENSING',
                'amount_fcfa'         => $amount,
                'notes_requested'     => $notes,
                'dispense_hmac_token' => $token,
            ], 200];
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse(['status' => 'error', 'message' => 'Erreur serveur'], 500);
}

sendJsonResponse($out[0], $out[1]);
