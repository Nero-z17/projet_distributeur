-- ============================================================================
-- PROJET : DISTRIBUTEUR AUTOMATIQUE DE BILLETS (DAB) BIOMÉTRIQUE (8 MEMBRES)
-- Fichier : sql/dab_banque.sql
-- Compatible : MySQL 5.7+ / MariaDB 10.4+ (XAMPP / WAMP / Serveur Distant)
-- Instructions : Ouvrir phpMyAdmin -> Onglet "Importer" -> Choisir ce fichier
-- ============================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+01:00";

CREATE DATABASE IF NOT EXISTS `dab_banque`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `dab_banque`;

-- Nettoyage dans l'ordre inverse des clés étrangères
DROP TABLE IF EXISTS `atm_withdrawal_sessions`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `otp_codes`;
DROP TABLE IF EXISTS `biometric_profiles`;
DROP TABLE IF EXISTS `wallets`;
DROP TABLE IF EXISTS `atm_machines`;
DROP TABLE IF EXISTS `users`;

-- ============================================================================
-- 1. TABLE `users` : Comptes clients (Connexion par Email OU Téléphone + Mot de passe)
-- ============================================================================
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name` VARCHAR(120) NOT NULL COMMENT 'Nom et prénom complets du client',
  `email` VARCHAR(120) NOT NULL COMMENT 'Email unique (connexion mobile + identification DAB + codes OTP)',
  `phone` VARCHAR(20) NOT NULL COMMENT 'Téléphone unique (connexion mobile + virements + Mobile Money)',
  `password_hash` VARCHAR(255) NOT NULL COMMENT 'Mot de passe haché avec password_hash() de PHP',
  `is_email_verified` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Email validé par OTP lors de l inscription',
  `is_face_enrolled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Empreinte faciale 512D enregistrée par Python',
  `atm_withdrawal_enabled` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '1 = Retrait DAB autorisé, 0 = Bloqué par le client depuis son téléphone',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  UNIQUE KEY `uk_users_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. TABLE `wallets` : Portefeuilles bancaires et soldes en FCFA (Entiers BIGINT)
-- ============================================================================
CREATE TABLE `wallets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Clé étrangère unique vers users.id',
  `account_number` VARCHAR(24) NOT NULL COMMENT 'Numéro de compte unique (ex: CM-2026-00001)',
  `balance_fcfa` BIGINT NOT NULL DEFAULT 0 COMMENT 'Solde disponible en FCFA (toujours entier >= 0)',
  `reserved_fcfa` BIGINT NOT NULL DEFAULT 0 COMMENT 'Somme temporairement gelée pendant l extraction des billets au DAB',
  `daily_limit_fcfa` BIGINT NOT NULL DEFAULT 200000 COMMENT 'Plafond journalier de retrait au distributeur en FCFA',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wallets_user` (`user_id`),
  UNIQUE KEY `uk_wallets_account_number` (`account_number`),
  CONSTRAINT `fk_wallets_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. TABLE `biometric_profiles` : Empreinte Faciale 512D (Gérée par Python Membre 1)
-- ============================================================================
CREATE TABLE `biometric_profiles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Clé étrangère unique vers users.id',
  `embedding_512d_json` LONGTEXT NOT NULL COMMENT 'Tableau JSON des 512 flottants générés par ArcFace / InsightFace',
  `reference_image_path` VARCHAR(255) NOT NULL COMMENT 'Chemin relatif de la photo de référence sauvegardée',
  `model_version` VARCHAR(50) NOT NULL DEFAULT 'ArcFace-512D',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_biometric_user` (`user_id`),
  CONSTRAINT `fk_biometric_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. TABLE `otp_codes` : Codes uniques à 6 chiffres envoyés par Email
--    (Sert à l'inscription REGISTER et au retrait distributeur ATM_WITHDRAWAL)
-- ============================================================================
CREATE TABLE `otp_codes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Client destinataire du code mail',
  `code` VARCHAR(6) NOT NULL COMMENT 'Code numérique à 6 chiffres',
  `purpose` ENUM('REGISTER', 'ATM_WITHDRAWAL', 'PASSWORD_RESET') NOT NULL,
  `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre de tentatives (bloqué à 3 erreurs)',
  `expires_at` DATETIME NOT NULL COMMENT 'Date limite de validité (ex: +5 minutes)',
  `is_used` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = Code déjà consommé',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_lookup` (`user_id`, `purpose`, `is_used`),
  CONSTRAINT `fk_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. TABLE `atm_machines` : État de la Borne Raspberry Pi 5 & Module ICT NDE1000
-- ============================================================================
CREATE TABLE `atm_machines` (
  `id` VARCHAR(30) NOT NULL COMMENT 'Identifiant de la borne (ex: DAB-DOUALA-01)',
  `name` VARCHAR(100) NOT NULL COMMENT 'Nom affiché sur mobile et sur l écran 15 pouces',
  `location` VARCHAR(120) NOT NULL DEFAULT 'Campus Douala - Hall Central',
  `denomination_fcfa` INT UNSIGNED NOT NULL DEFAULT 2000 COMMENT 'Valeur du billet unique dans le bac NDE1000 (2000 FCFA)',
  `notes_remaining` INT UNSIGNED NOT NULL DEFAULT 250 COMMENT 'Nombre de billets restants dans le bac',
  `status` ENUM('ONLINE', 'LOW_CASH', 'EMPTY', 'JAMMED', 'OFFLINE') NOT NULL DEFAULT 'ONLINE',
  `last_ping` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. TABLE `transactions` : Grand Livre Comptable (Traçabilité avant/après)
-- ============================================================================
CREATE TABLE `transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference` VARCHAR(32) NOT NULL COMMENT 'Référence unique (ex: TX-20261113-4829)',
  `tx_type` ENUM('DEPOT_MOMO', 'VIREMENT_INTERNE', 'RETRAIT_OPERATEUR', 'RETRAIT_DAB') NOT NULL,
  `source_wallet_id` INT UNSIGNED DEFAULT NULL COMMENT 'Portefeuille débité (NULL si dépôt externe)',
  `dest_wallet_id` INT UNSIGNED DEFAULT NULL COMMENT 'Portefeuille crédité (NULL si retrait externe ou DAB)',
  `external_operator` ENUM('INTERNE', 'ORANGE_MONEY', 'MTN_MOMO', 'DAB_NDE1000') NOT NULL DEFAULT 'INTERNE',
  `external_target` VARCHAR(60) DEFAULT NULL COMMENT 'Numéro Mobile Money ou ID de la borne DAB',
  `amount_fcfa` BIGINT NOT NULL COMMENT 'Montant de l opération en FCFA',
  `balance_before_fcfa` BIGINT DEFAULT NULL COMMENT 'Solde du compte principal avant opération',
  `balance_after_fcfa` BIGINT DEFAULT NULL COMMENT 'Solde du compte principal après opération',
  `status` ENUM('PENDING', 'RESERVED', 'COMPLETED', 'PARTIAL_COMPLETED', 'REVERSED', 'FAILED') NOT NULL DEFAULT 'PENDING',
  `description` VARCHAR(255) DEFAULT NULL COMMENT 'Détail lisible (ex: Retrait 5 billets de 2000 FCFA / Bourrage au 2e billet)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transactions_ref` (`reference`),
  KEY `idx_tx_source` (`source_wallet_id`),
  KEY `idx_tx_dest` (`dest_wallet_id`),
  CONSTRAINT `fk_tx_source_wallet` FOREIGN KEY (`source_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tx_dest_wallet` FOREIGN KEY (`dest_wallet_id`) REFERENCES `wallets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. TABLE `atm_withdrawal_sessions` : Suivi Séquentiel du Retrait au Distributeur
--    Ordre strict : OTP_SENT -> OTP_VERIFIED -> FACE_VERIFIED -> RESERVED_DISPENSING -> COMPLETED
-- ============================================================================
CREATE TABLE `atm_withdrawal_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_token` VARCHAR(64) NOT NULL COMMENT 'Jeton unique de la session guichet',
  `user_id` INT UNSIGNED NOT NULL COMMENT 'Client identifié par son email à l étape 1',
  `atm_id` VARCHAR(30) NOT NULL DEFAULT 'DAB-DOUALA-01',
  `otp_code_id` INT UNSIGNED DEFAULT NULL COMMENT 'Référence vers le code envoyé par mail (otp_codes.id)',
  `face_similarity_score` FLOAT DEFAULT NULL COMMENT 'Score cosinus renvoyé par le moteur Python 512D (étape 3)',
  `transaction_id` INT UNSIGNED DEFAULT NULL COMMENT 'Lié à transactions.id dès la réservation (étape 4)',
  `amount_fcfa` BIGINT NOT NULL DEFAULT 0 COMMENT 'Montant choisi (multiple de denomination_fcfa)',
  `notes_requested` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre de billets commandés au NDE1000',
  `notes_dispensed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Nombre de billets réellement sortis du NDE1000',
  `dispense_hmac_token` VARCHAR(128) DEFAULT NULL COMMENT 'Signature HMAC autorisant le script Python NDE1000 à éjecter les billets',
  `state` ENUM('OTP_SENT', 'OTP_VERIFIED', 'FACE_VERIFIED', 'RESERVED_DISPENSING', 'COMPLETED', 'ABORTED_REVERSED', 'EXPIRED') NOT NULL DEFAULT 'OTP_SENT',
  `expires_at` DATETIME NOT NULL COMMENT 'Expiration automatique (évite tout blocage de fonds)',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_atm_session_token` (`session_token`),
  KEY `idx_atm_session_user_state` (`user_id`, `state`),
  CONSTRAINT `fk_session_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_session_atm` FOREIGN KEY (`atm_id`) REFERENCES `atm_machines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_session_otp` FOREIGN KEY (`otp_code_id`) REFERENCES `otp_codes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_session_tx` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- DONNÉES INITIALES DE TEST (Prêtes pour les tests des 8 membres)
-- ============================================================================

-- 1. Borne DAB équipée du module ICT NDE1000 (250 billets de 2 000 FCFA = 500 000 FCFA en caisse)
INSERT INTO `atm_machines` (`id`, `name`, `location`, `denomination_fcfa`, `notes_remaining`, `status`)
VALUES ('DAB-DOUALA-01', 'Distributeur Biométrique Principal', 'Campus Douala - Hall Central', 2000, 250, 'ONLINE');

-- 2. Deux comptes clients de démonstration
-- Mot de passe pour les deux comptes : "password" (haché en bcrypt standard PHP)
INSERT INTO `users` (`id`, `full_name`, `email`, `phone`, `password_hash`, `is_email_verified`, `is_face_enrolled`, `atm_withdrawal_enabled`)
VALUES
(1, 'Client Test Alpha', 'alpha@dab-projet.cm', '690000001', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 0, 1),
(2, 'Client Test Beta', 'beta@dab-projet.cm', '670000002', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 1, 0, 1);

-- 3. Portefeuilles associés (Alpha = 50 000 FCFA, Beta = 20 000 FCFA)
INSERT INTO `wallets` (`id`, `user_id`, `account_number`, `balance_fcfa`, `reserved_fcfa`, `daily_limit_fcfa`)
VALUES
(1, 1, 'CM-2026-00001', 50000, 0, 200000),
(2, 2, 'CM-2026-00002', 20000, 0, 200000);

-- 4. Transactions initiales
INSERT INTO `transactions` (`reference`, `tx_type`, `source_wallet_id`, `dest_wallet_id`, `external_operator`, `external_target`, `amount_fcfa`, `balance_before_fcfa`, `balance_after_fcfa`, `status`, `description`)
VALUES
('TX-20261113-0001', 'DEPOT_MOMO', NULL, 1, 'ORANGE_MONEY', '690000001', 50000, 0, 50000, 'COMPLETED', 'Dépôt initial Orange Money'),
('TX-20261113-0002', 'DEPOT_MOMO', NULL, 2, 'MTN_MOMO', '670000002', 20000, 0, 20000, 'COMPLETED', 'Dépôt initial MTN Mobile Money');

COMMIT;