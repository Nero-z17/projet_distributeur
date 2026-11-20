<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : config/config.php
 * Rôle : Configuration centrale (Local XAMPP ou Serveur Distant) + Ponts Python IA (512D) & Matériel NDE1000
 */

// 1. Environnement (Local XAMPP par défaut, surchargeable par variables d'environnement sur serveur distant)
define('APP_NAME', 'DAB Biométrique Cameroun');
define('APP_DEBUG', getenv('APP_DEBUG') !== false ? (bool)getenv('APP_DEBUG') : true);

// 2. Paramètres MySQL (XAMPP en local ou Serveur Distant en ligne)
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', (int)(getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'dab_banque');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');

// 3. Paramètres de la Borne Distributeur & Module ICT NDE1000
define('DEFAULT_ATM_ID', 'DAB-DOUALA-01');
define('NDE1000_DENOMINATION_FCFA', 2000); // Billet unique dans le bac du NDE1000
define('DAILY_WITHDRAWAL_LIMIT_FCFA', 200000);
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_MAX_ATTEMPTS', 3);
define('ATM_SESSION_EXPIRY_MINUTES', 5);

// 4. Clé secrète partagée entre PHP et le service Python du NDE1000 (empêche toute extraction non autorisée)
define('HARDWARE_HMAC_SECRET', getenv('HARDWARE_HMAC_SECRET') ?: 'dab_secret_key_2026_nde1000_secure');

// 5. URLs des microservices Python (Chef de groupe - Membre 1 & Membre 2)
// - Port 5000 : Moteur Reconnaissance Faciale 512D (ArcFace + MediaPipe Vivacité 3D)
// - Port 5001 : Pilote Matériel Raspberry Pi 5 & NDE1000 RS232
define('PYTHON_AI_BASE_URL', getenv('PYTHON_AI_BASE_URL') ?: 'http://127.0.0.1:5000');
define('PYTHON_HARDWARE_BASE_URL', getenv('PYTHON_HARDWARE_BASE_URL') ?: 'http://127.0.0.1:5001');
define('FACE_MATCH_THRESHOLD', 0.65);

// 6. Configuration Email SMTP (PHPMailer pour les codes OTP d'inscription et de retrait DAB)
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER', getenv('SMTP_USER') ?: 'votre.projet.dab@gmail.com');
define('SMTP_PASS', getenv('SMTP_PASS') ?: 'votre_mot_de_passe_application_gmail');
define('SMTP_FROM_NAME', 'Banque Biométrique DAB');

/**
 * Génère un jeton HMAC signé par PHP pour autoriser le service Python (port 5001) à éjecter N billets
 */
function generateDispenseHmacToken(string $sessionToken, int $notesCount, int $amountFcfa): string {
    $payload = sprintf('%s|%d|%d', $sessionToken, $notesCount, $amountFcfa);
    return hash_hmac('sha256', $payload, HARDWARE_HMAC_SECRET);
}

/**
 * Vérifie un jeton HMAC d'extraction de billets
 */
function verifyDispenseHmacToken(string $sessionToken, int $notesCount, int $amountFcfa, string $token): bool {
    $expected = generateDispenseHmacToken($sessionToken, $notesCount, $amountFcfa);
    return hash_equals($expected, $token);
}

/**
 * Appelle le service Python de Reconnaissance Faciale 512D (Membre 1 - Port 5000)
 *
 * - Pour l'enrôlement (Membre 3) :
 *   callPythonAI('/api/face/enroll', ['user_id' => $id, 'image_base64' => $img])
 * - Pour la vérification 1:1 au distributeur (Membre 5) :
 *   callPythonAI('/api/face/verify', [
 *       'user_id' => $id,
 *       'frames_base64' => $framesArray,
 *       'stored_embedding_512d' => json_decode($profile['embedding_512d_json'], true)
 *   ])
 */
function callPythonAI(string $endpoint, array $payload, int $timeoutSeconds = 15): array {
    $url = rtrim(PYTHON_AI_BASE_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'status'  => 'error',
            'message' => 'Service Python IA (port 5000) injoignable. Vérifiez que ai_server.py est démarré.',
            'detail'  => $curlErr
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return [
            'status'    => 'error',
            'message'   => 'Réponse JSON invalide reçue du service Python IA.',
            'http_code' => $httpCode
        ];
    }

    return $decoded;
}

/**
 * Appelle le service Python Matériel Raspberry Pi / NDE1000 (Membre 2 - Port 5001)
 */
function callPiHardware(string $endpoint, array $payload = [], int $timeoutSeconds = 30): array {
    $url = rtrim(PYTHON_HARDWARE_BASE_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $timeoutSeconds,
    ]);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'status'  => 'error',
            'message' => 'Service matériel Raspberry Pi / NDE1000 (port 5001) injoignable.',
            'detail'  => $curlErr
        ];
    }

    return json_decode($response, true) ?: ['status' => 'error', 'message' => 'Réponse JSON invalide'];
}