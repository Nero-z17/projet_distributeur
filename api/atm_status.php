<?php
// api/atm_status.php — [Membre 5] État de la borne DAB-DOUALA-01
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

$pdo = getDB();

// === BLOC 1 : nettoyage des sessions/réservations expirées ===
cleanupExpiredAtmSessions($pdo);

// === BLOC 2 : lecture de la borne (par son nom) ===
$atmName = trim($_GET['atm_id'] ?? 'DAB-DOUALA-01');
$stmt = $pdo->prepare(
    "SELECT name, location, denomination_fcfa, notes_remaining, status
     FROM atm_machines WHERE id = ?"
);
$stmt->execute([$atmName]);
$atm = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$atm) {
    sendJsonResponse(['status' => 'error', 'message' => 'Borne introuvable'], 404);
}

// === BLOC 3 : réponse (objet "atm" + champs à plat pour simplifier le JS) ===
$atm['denomination_fcfa'] = (int)$atm['denomination_fcfa'];
$atm['notes_remaining']   = (int)$atm['notes_remaining'];

sendJsonResponse([
    'status'            => 'success',
    'atm'               => $atm,
    'denomination_fcfa' => $atm['denomination_fcfa'],
    'notes_remaining'   => $atm['notes_remaining'],
    'atm_status'        => $atm['status'],
]);
