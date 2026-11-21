<?php
/**
 * PROJET DAB BIOMÉTRIQUE (8 MEMBRES) — Fichier : config/mailer.php  [Membre 3]
 * Rôle : Envoi d'emails SMTP (PHPMailer) pour les codes OTP et les reçus.
 *
 * Fonctions principales :
 *   - sendProjectEmail($toEmail, $subject, $htmlBody) : bool
 *   - sendOtpEmail($toEmail, $fullName, $code, $purpose) : bool   (utilisable aussi par le Membre 5)
 *   - generateOtpCode() : string                                  (code à 6 chiffres)
 *
 * Installation de PHPMailer (au choix) :
 *   1) Composer :  composer require phpmailer/phpmailer   (dans dab_projet/)
 *   2) Manuelle :  copier le dossier "src" de PHPMailer dans dab_projet/libs/PHPMailer/src/
 *
 * Fallback développement : si SMTP n'est pas configuré (ou en échec) et que APP_DEBUG = true,
 * l'email est écrit dans logs/emails.log (le code OTP y est lisible) au lieu d'être envoyé.
 * En production (APP_DEBUG = false), aucun fallback : la fonction renvoie false.
 */

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/config.php';

/**
 * Génère un code numérique à 6 chiffres (zéros initiaux conservés).
 */
if (!function_exists('generateOtpCode')) {
    function generateOtpCode(): string {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }
}

/**
 * Vrai si des identifiants SMTP réels ont été renseignés (et non les valeurs d'exemple de config.php).
 */
function isSmtpConfigured(): bool {
    $placeholders = ['votre.projet.dab@gmail.com', 'votre_mot_de_passe_application_gmail'];
    return SMTP_USER !== '' && SMTP_PASS !== ''
        && !in_array(SMTP_USER, $placeholders, true)
        && !in_array(SMTP_PASS, $placeholders, true);
}

/**
 * Charge PHPMailer (Composer ou dossier libs/). Renvoie false s'il est introuvable.
 */
function loadPhpMailer(): bool {
    if (class_exists(PHPMailer::class, false)) {
        return true;
    }

    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    if (class_exists(PHPMailer::class)) {
        return true;
    }

    $dir = __DIR__ . '/../libs/PHPMailer/src/';
    if (is_file($dir . 'PHPMailer.php')) {
        require_once $dir . 'Exception.php';
        require_once $dir . 'PHPMailer.php';
        require_once $dir . 'SMTP.php';
    }
    return class_exists(PHPMailer::class, false);
}

/**
 * Écrit l'email dans logs/emails.log (mode développement uniquement).
 */
function logEmailLocally(string $toEmail, string $subject, string $htmlBody): void {
    $dir = __DIR__ . '/../logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    // Empêche l'accès web au dossier (les codes OTP y sont en clair)
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Require all denied\n");
    }

    $text = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', '', $htmlBody);
    $text = preg_replace('#<br\s*/?>|</p>|</tr>|</h[1-6]>#i', "\n", $text);
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    $entry = sprintf(
        "==== %s ====\nÀ : %s\nSujet : %s\n%s\n\n",
        date('Y-m-d H:i:s'),
        $toEmail,
        $subject,
        $text
    );
    @file_put_contents($dir . '/emails.log', $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Envoie un email HTML via SMTP (PHPMailer). Renvoie true si l'email est parti
 * (ou a été journalisé en mode développement), false sinon.
 */
function sendProjectEmail(string $toEmail, string $subject, string $htmlBody): bool {
    if (isSmtpConfigured() && loadPhpMailer()) {
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = SMTP_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = SMTP_USER;
            $mail->Password   = str_replace(' ', '', SMTP_PASS); // mot de passe d'application Gmail
            $mail->SMTPSecure = (SMTP_PORT === 465)
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = SMTP_PORT;
            $mail->Timeout    = 10;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom(SMTP_USER, SMTP_FROM_NAME);
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = trim(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $htmlBody)));

            $mail->send();
            return true;
        } catch (Throwable $e) {
            error_log('[mailer] Échec SMTP : ' . $e->getMessage());
            // on continue vers le fallback (développement uniquement)
        }
    }

    if (APP_DEBUG) {
        logEmailLocally($toEmail, $subject, $htmlBody);
        return true;
    }

    return false;
}

/**
 * Envoie un code OTP à 6 chiffres avec un gabarit HTML.
 * $purpose : 'REGISTER' | 'ATM_WITHDRAWAL' | 'PASSWORD_RESET'
 */
function sendOtpEmail(string $toEmail, string $fullName, string $code, string $purpose = 'REGISTER'): bool {
    $titles = [
        'REGISTER'       => 'Validation de votre inscription',
        'ATM_WITHDRAWAL' => 'Code de retrait au distributeur',
        'PASSWORD_RESET' => 'Réinitialisation de votre mot de passe',
    ];
    $title   = $titles[$purpose] ?? 'Votre code de vérification';
    $minutes = (int)OTP_EXPIRY_MINUTES;

    $safeName  = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeCode  = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $safeApp   = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');

    $html = <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:480px;margin:auto;padding:24px;border:1px solid #e5e5e5;border-radius:12px">
  <h2 style="margin-top:0;color:#0d6efd">{$safeApp}</h2>
  <p>Bonjour {$safeName},</p>
  <p><strong>{$safeTitle}</strong></p>
  <p>Votre code à usage unique est :</p>
  <p style="font-size:34px;letter-spacing:10px;font-weight:bold;text-align:center;background:#f4f6fb;padding:14px;border-radius:8px">{$safeCode}</p>
  <p>Ce code est valable <strong>{$minutes} minutes</strong> et ne doit être communiqué à personne.</p>
  <p style="color:#888;font-size:12px">Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.</p>
</div>
HTML;

    return sendProjectEmail($toEmail, '[' . APP_NAME . "] {$title} : {$code}", $html);
}
