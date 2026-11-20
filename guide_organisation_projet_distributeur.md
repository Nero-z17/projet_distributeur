# Guide d'Architecture, d'Organisation et de Répartition (8 Membres) — Projet Distributeur Automatique de Billets (DAB) Biométrique

Ce document est le référentiel technique officiel et vérifié pour les **8 membres de l'équipe**. Il élimine toute ambiguïté entre la base de données MySQL, les scripts Back-End PHP, les interfaces Front-End Bootstrap 5 / JS et les deux services Python (Reconnaissance faciale 512D + Pilote matériel ICT NDE1000).

## 1. Fonctionnement Global & Complémentarité des Deux Interfaces

> **Principe fondamental :** Il n'y a qu'**UNE SEULE base de données MySQL (`dab_banque`)** hébergée sur XAMPP pendant les tests puis sur un **serveur distant en ligne** lors de la présentation. L'application téléphone (`app_mobile/`) et l'écran tactile 15 pouces du distributeur (`borne_dab/`) sont deux interfaces complémentaires connectées en temps réel à cette même base.

### A. Parcours sur l'Application Téléphone (`app_mobile/`)
1. **Inscription & Enrôlement du Visage** : Le client saisit son nom complet, son email, son numéro de téléphone et son mot de passe $\rightarrow$ valide son adresse avec un code OTP à 6 chiffres reçu par email $\rightarrow$ prend son **selfie** (le serveur Python extrait les **512 dimensions** de son visage et les stocke dans `biometric_profiles`).
2. **Connexion simple** : Le client se connecte avec **son Email OU son Numéro de téléphone** + **son Mot de passe**.
3. **Opérations bancaires & Contrôle** : Consultation du solde en FCFA, dépôt (simulation Orange Money / MTN MoMo), envoi/réception d'argent vers un autre membre (par téléphone, email ou QR Code), retrait vers un autre opérateur Mobile Money, **interrupteur ON/OFF pour bloquer/autoriser les retraits au distributeur**, et affichage en temps réel du code de retrait si le client est devant la borne.

### B. Parcours Sécurisé en 5 Étapes sur la Borne Tactile 15" (`borne_dab/`)
1. **Étape 1 — Saisie de l'Email** : Le client tape son adresse email sur le clavier tactile 15 pouces (avec touches rapides `@gmail.com`, `@yahoo.com`, `.com`). Le serveur vérifie que son compte existe, que son visage est enregistré et que ses retraits DAB ne sont pas bloqués (`atm_withdrawal_enabled = 1`), puis lui envoie immédiatement un **code unique à 6 chiffres par email** (`state = 'OTP_SENT'`).
2. **Étape 2 — Validation du Code Mail** : Le client saisit le code à 6 chiffres reçu par mail (limité à 3 essais). La session passe à `state = 'OTP_VERIFIED'`.
3. **Étape 3 — Vérification 1:1 du Visage de cet Email + Vivacité 3D** : La caméra de la borne capture une courte rafale de 5 images pendant un mouvement demandé (*clignement des yeux / légère rotation*). Le moteur Python vérifie la vivacité 3D (`MediaPipe`) et compare le visage **uniquement** à l'empreinte 512D (`ArcFace`) de cet email. Si c'est bien lui, la session passe à `state = 'FACE_VERIFIED'`.
4. **Étape 4 — Choix du Montant & Réservation (Temps 1)** : Le client choisit un montant multiple de la coupure du module ICT NDE1000 (ex. `2 000`, `4 000`, `10 000`, `20 000 FCFA`). PHP verrouille le portefeuille (`SELECT ... FOR UPDATE`), déplace la somme de `balance_fcfa` vers `reserved_fcfa`, crée la transaction en `RESERVED` et génère un jeton signé `dispense_hmac_token` (`state = 'RESERVED_DISPENSING'`).
5. **Étape 5 — Extraction des Billets par le NDE1000 & Confirmation/Remboursement (Temps 2)** :
   * Le service Python matériel vérifie le `dispense_hmac_token` et commande le module ICT NDE1000 en RS232 (ou le simulateur sur PC).
   * **Si tous les billets sortent** : `atm_step5_confirm.php` vide `reserved_fcfa`, passe la transaction à `COMPLETED`, décrémente `notes_remaining` dans `atm_machines` et envoie le reçu par mail.
   * **En cas de bourrage ou d'erreur matérielle** : `atm_step5_abort.php` recrédite immédiatement sur `balance_fcfa` les billets non distribués (`REVERSED` ou `PARTIAL_COMPLETED`) et passe la borne en statut `JAMMED`.

## 2. Arborescence Complète et Verrouillée du Dépôt GitHub (`dab_projet/`)

```text
dab_projet/                              # Cloner dans C:\xampp\htdocs\dab_projet\
├── README.md                            # Ce guide de référence pour les 8 membres
├── sql/
│   └── dab_banque.sql                   # [Membre 3] Les 7 tables MySQL + données de test
├── config/
│   ├── config.php                       # [Membre 1] Configuration hybride (XAMPP / Serveur en ligne) + HMAC + Ponts Python
│   ├── db.php                           # [Membre 3] Connexion PDO + Nettoyage auto des réservations expirées + JSON helpers
│   └── mailer.php                       # [Membre 3] Envoi d'emails SMTP (PHPMailer) pour codes OTP et reçus
│
├── assets/                              # Ressources statiques partagées (Bootstrap 5 + JS sans framework)
│   ├── css/
│   │   ├── bootstrap.min.css            # Bootstrap 5 en local
│   │   ├── mobile.css                   # [Membre 6] Design bancaire de l'application téléphone
│   │   └── kiosk.css                    # [Membre 8] Design plein écran 15 pouces (gros boutons tactiles)
│   ├── js/
│   │   ├── bootstrap.bundle.min.js      # Scripts Bootstrap
│   │   └── mobile_ops.js                # [Membre 7] Fonctions JS des formulaires d'opérations mobiles
│   └── img/                             # Logos, icônes Orange Money / MTN MoMo
│
├── api/                                 # Scripts Back-End PHP (renvoient tous du JSON standardisé)
│   ├── auth_register.php                # [Membre 3] Création compte + wallet + envoi code OTP d'inscription
│   ├── auth_verify_otp.php              # [Membre 3] Validation du code OTP d'inscription (is_email_verified = 1)
│   ├── auth_login.php                   # [Membre 3] Connexion par Email OU Téléphone + Mot de passe
│   ├── auth_logout.php                  # [Membre 3] Déconnexion de la session PHP
│   ├── auth_enroll_face.php             # [Membre 3] Envoie le selfie à Python IA (port 5000) et stocke les 512 dimensions
│   │
│   ├── wallet_info.php                  # [Membre 4] Renvoie solde, historique, état du DAB et code de retrait actif
│   ├── wallet_deposit.php               # [Membre 4] Dépôt sur le compte (simulation Orange Money / MTN MoMo)
│   ├── wallet_transfer.php              # [Membre 4] Virement instantané vers un autre membre (téléphone, email ou QR)
│   ├── wallet_withdraw_ext.php          # [Membre 4] Retrait vers un compte externe Orange Money / MTN MoMo
│   ├── wallet_security.php              # [Membre 4] Interrupteur ON/OFF atm_withdrawal_enabled
│   │
│   ├── atm_status.php                   # [Membre 5] État de la borne DAB-DOUALA-01 (coupure 2000 FCFA, stock, statut)
│   ├── atm_step1_send_code.php          # [Membre 5] Étape 1 DAB : Reçoit email -> crée session OTP_SENT -> envoie code mail
│   ├── atm_step2_verify_code.php        # [Membre 5] Étape 2 DAB : Vérifie le code à 6 chiffres -> passe à OTP_VERIFIED
│   ├── atm_step3_verify_face.php        # [Membre 5] Étape 3 DAB : Vérifie uniquement le visage de cet email via Python -> FACE_VERIFIED
│   ├── atm_step4_reserve.php            # [Membre 5] Étape 4 DAB (Temps 1) : Bloque la somme dans reserved_fcfa + signe HMAC
│   ├── atm_step5_confirm.php            # [Membre 5] Étape 5 DAB (Temps 2 Succès) : Débite définitivement + réduit stock + reçu mail
│   └── atm_step5_abort.php              # [Membre 5] Étape 5 DAB (Temps 2 Bourrage) : Rembourse reserved_fcfa + alerte JAMMED
│
├── app_mobile/                          # Interface Téléphone Client (HTML5 / Bootstrap 5 / JS)
│   ├── index.php                        # [Membre 6] Connexion (Email ou Téléphone + Mot de passe) & Inscription
│   ├── verify_otp.php                   # [Membre 6] Saisie du code OTP d'inscription reçu par email
│   ├── enroll_face.php                  # [Membre 6] Capture Selfie (Caméra WebRTC + Fallback appareil photo natif)
│   ├── dashboard.php                    # [Membre 6] Tableau de bord : Solde FCFA, Météo DAB, Bannière Code DAB, Historique
│   ├── deposit.php                      # [Membre 7] Page de dépôt Mobile Money sur son compte
│   ├── transfer.php                     # [Membre 7] Page d'envoi / réception d'argent (Numéro, Email ou QR Code)
│   ├── withdraw_ext.php                 # [Membre 7] Page de retrait vers un autre opérateur (Orange / MTN)
│   └── security.php                     # [Membre 7] Interrupteur de blocage des retraits DAB & consultation des reçus
│
├── borne_dab/                           # [Membre 8] Interface Écran Tactile 15" du Distributeur
│   ├── index.php                        # Application Kiosk plein écran en 5 étapes + tiroir de simulation matérielle
│   └── kiosk.js                         # Logique JS : Clavier email tactile -> Pavé OTP -> Rafale Caméra -> Montant -> NDE1000
│
├── python_ai_service/                   # [Membre 1 - Chef de groupe] Reconnaissance Faciale 512D & Vivacité 3D
│   ├── requirements.txt                 # flask, flask-cors, opencv-python, insightface, onnxruntime, mediapipe, numpy
│   ├── ai_server.py                     # Serveur HTTP JSON (port 5000) : routes /api/face/enroll et /api/face/verify
│   ├── face_engine_512d.py              # Extraction ArcFace 512D + Vérification vivacité 3D sur rafale d'images
│   └── enrolled_faces/                  # Dossier de sauvegarde des photos de référence
│
└── pi_hardware_bridge/                  # [Membre 2] Pilote Python Raspberry Pi 5 & Module de Billets ICT NDE1000
    ├── requirements.txt                 # flask, flask-cors, pyserial, opencv-python
    ├── hardware_server.py               # Serveur HTTP JSON local (port 5001) avec vérification du jeton HMAC
    ├── nde1000_driver.py                # Pilote RS232 (9600 bauds, 8E1) + Simulateur PC (Succès, Bourrage, Bac vide)
    └── pi_camera_led.py                 # Abstraction Camera Module 3 (Picamera2 / OpenCV) et relais LED d'éclairage
```

## 3. Répartition Chirurgicale des Tâches entre les 8 Membres

| Membre | Rôle & Spécialité | Fichiers Attribués | Responsabilités Exactes |
| :--- | :--- | :--- | :--- |
| **Membre 1 (Toi — Chef de groupe)** | **Architecture, Python IA Biométrique 512D & Vivacité 3D** | `python_ai_service/*`<br>`config/config.php` | Développer le moteur Python d'extraction 512D (`ArcFace`) et de détection de vivacité sur rafale d'images (`MediaPipe`), exposer `/api/face/enroll` et `/api/face/verify`, et superviser le dépôt GitHub et le déploiement distant. |
| **Membre 2** | **Python Raspberry Pi 5, Pilote RS232 NDE1000 & Simulateur** | `pi_hardware_bridge/*` | Développer `hardware_server.py` (vérification de la signature `dispense_hmac_token`), le simulateur logiciel de distribution/bourrage sur PC et le pilote série RS232 (`9600 bauds, Parité Paire 8E1`) du module ICT NDE1000 + relais LED. |
| **Membre 3** | **Back-End PHP/MySQL : Comptes, Auth & Emails SMTP** | `sql/dab_banque.sql`<br>`config/db.php`, `mailer.php`<br>`api/auth_*.php` | Gérer l'inscription, l'envoi des emails SMTP (`PHPMailer`), la validation OTP d'inscription, la connexion unifiée (`email` OU `phone` + `password`) et la sauvegarde du profil biométrique 512D. |
| **Membre 4** | **Back-End PHP/MySQL : Portefeuille & Opérations Mobile** | `api/wallet_info.php`<br>`api/wallet_deposit.php`<br>`api/wallet_transfer.php`<br>`api/wallet_withdraw_ext.php`<br>`api/wallet_security.php` | Coder avec transactions PDO (`beginTransaction` / `commit` / `rollBack`) la lecture du solde, le dépôt Mobile Money, le virement entre membres (avec traçabilité `balance_before_fcfa` / `balance_after_fcfa`), le retrait opérateur et le blocage sécurité DAB. |
| **Membre 5** | **Back-End PHP/MySQL : Séquence Guichet DAB & Retrait en 2 Temps** | `api/atm_status.php`<br>`api/atm_step1_send_code.php`<br>`api/atm_step2_verify_code.php`<br>`api/atm_step3_verify_face.php`<br>`api/atm_step4_reserve.php`<br>`api/atm_step5_confirm.php`<br>`api/atm_step5_abort.php` | Coder les 5 étapes strictes du retrait au distributeur : 1) Envoi du code mail à l'email saisi, 2) Validation du code mail (max 3 essais), 3) Appel Python pour vérifier uniquement le visage de cet email, 4) Réservation atomique des fonds + signature HMAC, 5) Confirmation ou remboursement automatique en cas de bourrage. |
| **Membre 6** | **Front-End Bootstrap 5 / JS — Mobile : Auth, Selfie & Dashboard** | `app_mobile/index.php`<br>`app_mobile/verify_otp.php`<br>`app_mobile/enroll_face.php`<br>`app_mobile/dashboard.php`<br>`assets/css/mobile.css` | Créer les pages responsives de connexion/inscription, de validation OTP, de prise de selfie (avec support caméra WebRTC + bouton photo natif mobile pour fonctionner même sans HTTPS local) et le tableau de bord affichant le solde, l'état du DAB et le code de retrait en cours. |
| **Membre 7** | **Front-End Bootstrap 5 / JS — Mobile : Dépôt, Virements, Retrait & Sécurité** | `app_mobile/deposit.php`<br>`app_mobile/transfer.php`<br>`app_mobile/withdraw_ext.php`<br>`app_mobile/security.php`<br>`assets/js/mobile_ops.js` | Créer les pages d'opérations financières sur téléphone (dépôt Orange/MTN, envoi/réception par numéro/email/QR Code, retrait vers Mobile Money), la modale de reçu téléchargeable et la page de sécurité permettant de verrouiller les retraits au distributeur. |
| **Membre 8** | **Front-End Bootstrap 5 / JS — Écran Tactile 15" du Distributeur** | `borne_dab/index.php`<br>`borne_dab/kiosk.js`<br>`assets/css/kiosk.css` | Créer l'interface Kiosk plein écran 15 pouces sans barre de défilement : clavier tactile AZERTY + touches rapides (`@gmail.com`) pour l'email, pavé numérique pour le code mail à 6 chiffres, cercle caméra avec capture d'une rafale de 5 images, choix des montants multiples de 2 000 FCFA, et tiroir de test pour simuler Succès, Bourrage au 2e billet ou Bac vide. |
