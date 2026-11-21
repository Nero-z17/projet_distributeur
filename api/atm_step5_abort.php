<?php
// api/atm_step5_abort.php — [Membre 5] Étape 5 (Temps 2, bourrage/erreur) : remboursement total ou partiel
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

$in           = getRequestData();
// === BLOC 1 : validation de la requête (contrat : session_token, notes_dispensed, reason) ===
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(['status' => 'error', 'message' => 'Méthode non autorisée'], 405);
}

$sessionToken = trim($in['session_token'] ?? '');
$notesOut     = filter_var($in['notes_dispensed'] ?? 0, FILTER_VALIDATE_INT);
$reason       = substr(trim((string)($in['reason'] ?? 'UNKNOWN')), 0, 100);

if ($sessionToken === '' || $notesOut === false || $notesOut < 0) {
    sendJsonResponse(['status' => 'error', 'message' => 'Paramètres invalides'], 400);
}

$out = null;
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
        // empêche un double remboursement
        $out = [['status' => 'error', 'message' => 'Étape invalide pour cette session'], 409];
    } elseif ($notesOut > (int)$s['notes_requested']) {
        $out = [['status' => 'error', 'message' => 'Plus de billets sortis que demandés'], 400];
    } else {
        // === BLOC 3 : débit réel et remboursement ===
        $stmt = $pdo->prepare("SELECT denomination_fcfa FROM atm_machines WHERE id = ? FOR UPDATE");
        $stmt->execute([$s['atm_id']]);
        $denom = (int)$stmt->fetchColumn();

        $reserved = (int)$s['amount_fcfa'];
        $debited  = $notesOut * $denom;
        $refund   = $reserved - $debited;

        // === BLOC 4 : portefeuille ===
        $pdo->prepare(
            "UPDATE wallets
             SET reserved_fcfa = reserved_fcfa - ?, balance_fcfa = balance_fcfa + ?
             WHERE user_id = ?"
        )->execute([$reserved, $refund, $s['user_id']]);

        if ($notesOut === 0) {
            // === BLOC 5a : aucun billet -> annulation totale ===
            $pdo->prepare(
                "UPDATE transactions SET status = 'REVERSED', balance_after_fcfa = balance_before_fcfa WHERE id = ?"
            )->execute([$s['transaction_id']]);
        } else {
            // === BLOC 5b : partiel -> on ne facture que les billets sortis ===
            $pdo->prepare(
                "UPDATE transactions
                 SET status = 'PARTIAL_COMPLETED', amount_fcfa = ?,
                     balance_after_fcfa = balance_before_fcfa - ?
                 WHERE id = ?"
            )->execute([$debited, $debited, $s['transaction_id']]);
            $pdo->prepare("UPDATE atm_machines SET notes_remaining = notes_remaining - ? WHERE id = ?")
                ->execute([$notesOut, $s['atm_id']]);
        }

        // === BLOC 6 : borne en panne si billets sortis OU motif de bourrage ===
        $jam = $notesOut > 0 || stripos($reason, 'JAM') !== false;
        if ($jam) {
            $pdo->prepare("UPDATE atm_machines SET status = 'JAMMED' WHERE id = ?")->execute([$s['atm_id']]);
        }

        // === BLOC 7 : fermeture de la session (état du cahier : ABORTED_REVERSED) ===
        $pdo->prepare(
            "UPDATE atm_withdrawal_sessions SET notes_dispensed = ?, state = 'ABORTED_REVERSED' WHERE id = ?"
        )->execute([$notesOut, $s['id']]);

        $out = [[
            'status'        => 'success',
            'state'         => 'ABORTED_REVERSED',
            'refunded_fcfa' => $refund,
            'debited_fcfa'  => $debited,
            'atm_jammed'    => $jam,
        ], 200];
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse(['status' => 'error', 'message' => 'Erreur serveur'], 500);
}

sendJsonResponse($out[0], $out[1]);
