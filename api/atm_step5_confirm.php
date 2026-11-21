<?php
// api/atm_step5_confirm.php — [Membre 5] Étape 5 (Temps 2, succès) : débit définitif + stock + reçu
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

$pdo = getDB();

$in           = getRequestData();
// === BLOC 1 : validation de la requête (contrat : session_token, notes_dispensed) ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$sessionToken = trim($in['session_token'] ?? '');
$notesOut     = isset($in['notes_dispensed']) ? filter_var($in['notes_dispensed'], FILTER_VALIDATE_INT) : null;

if ($sessionToken === '' || $notesOut === false) {
    sendJsonResponse(['status' => 'error', 'message' => 'Paramètres invalides'], 400);
}

$out     = null;
$receipt = null;
try {
    $pdo->beginTransaction();

    // === BLOC 2 : verrouillage de la session ===
    $stmt = $pdo->prepare(
        "SELECT id, user_id, atm_id, transaction_id, amount_fcfa, notes_requested, state
         FROM atm_withdrawal_sessions WHERE session_token = ? FOR UPDATE"
    );
    $stmt->execute([$sessionToken]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$s) {
        $out = [['status' => 'error', 'message' => 'Session introuvable'], 404];
    } elseif ($s['state'] !== 'RESERVED_DISPENSING') {
        $out = [['status' => 'error', 'message' => 'Étape invalide pour cette session'], 409];
    } else {
        $notes = $notesOut === null ? (int)$s['notes_requested'] : $notesOut;

        if ($notes !== (int)$s['notes_requested']) {
            $out = [['status' => 'error', 'message' => 'Nombre de billets différent : utilisez atm_step5_abort'], 409];
        } else {
            // === BLOC 3 : le réservé disparaît définitivement ===
            $pdo->prepare("UPDATE wallets SET reserved_fcfa = reserved_fcfa - ? WHERE user_id = ?")
                ->execute([$s['amount_fcfa'], $s['user_id']]);

            // === BLOC 4 : transaction terminée ===
            $pdo->prepare("UPDATE transactions SET status = 'COMPLETED' WHERE id = ?")
                ->execute([$s['transaction_id']]);

            // === BLOC 5 : stock de billets ===
            $pdo->prepare("UPDATE atm_machines SET notes_remaining = notes_remaining - ? WHERE id = ?")
                ->execute([$notes, $s['atm_id']]);

            // === BLOC 6 : session terminée ===
            $pdo->prepare(
                "UPDATE atm_withdrawal_sessions SET notes_dispensed = ?, state = 'COMPLETED' WHERE id = ?"
            )->execute([$notes, $s['id']]);

            // Données pour la réponse et le reçu
            $stmt = $pdo->prepare(
                "SELECT u.email, u.full_name, w.balance_fcfa, t.reference
                 FROM users u
                 JOIN wallets w      ON w.user_id = u.id
                 JOIN transactions t ON t.id = ?
                 WHERE u.id = ?"
            );
            $stmt->execute([$s['transaction_id'], $s['user_id']]);
            $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
            $receipt['amount'] = (int)$s['amount_fcfa'];

            $out = [[
                'status'          => 'success',
                'state'           => 'COMPLETED',
                'reference'       => $receipt['reference'],
                'new_balance_fcfa'=> (int)$receipt['balance_fcfa'],
            ], 200];
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse(['status' => 'error', 'message' => 'Erreur serveur'], 500);
}

// === BLOC 7 : reçu par mail (un échec d'envoi n'annule pas le retrait) ===
if ($receipt) {
    $body = "<p>Bonjour " . htmlspecialchars($receipt['full_name']) . ",</p>"
          . "<p>Retrait au distributeur effectué : <b>" . number_format($receipt['amount'], 0, ',', ' ') . " FCFA</b></p>"
          . "<p>Nouveau solde : " . number_format((int)$receipt['balance_fcfa'], 0, ',', ' ') . " FCFA</p>"
          . "<p>Référence : " . htmlspecialchars($receipt['reference']) . "</p>";
    @sendProjectEmail($receipt['email'], 'Reçu de retrait DAB', $body);
}

sendJsonResponse($out[0], $out[1]);
