@echo off
chcp 65001 >nul
title Installation Automatique — Projet DAB Biometrique (8 Membres)
color 0A

echo ============================================================================
echo   INSTALLATION EN 1 CLIC — ENVIRONNEMENT PYTHON (IA 512D + PILOTE NDE1000)
echo ============================================================================
echo.

:: 1. Verification de la presence de Python
python --version >nul 2>&1
if errorlevel 1 (
    color 0C
    echo [ERREUR] Python n'est pas installe ou n'est pas dans le PATH Windows !
    echo Telechargez Python 3.11 ou 3.12 sur https://www.python.org/downloads/
    echo IMPORTANT : Cochez "Add Python to PATH" pendant l'installation.
    echo.
    pause
    exit /b 1
)

echo [1/4] Python detecte :
python --version
echo.

:: 2. Creation de l'environnement virtuel (venv) s'il n'existe pas
if not exist "venv\Scripts\activate.bat" (
    echo [2/4] Creation de l'environnement virtuel isole (venv)...
    python -m venv venv
    if errorlevel 1 (
        color 0C
        echo [ERREUR] Impossible de creer le dossier venv.
        pause
        exit /b 1
    )
) else (
    echo [2/4] Environnement virtuel (venv) deja present.
)

call venv\Scripts\activate.bat

:: 3. Mise a jour de pip et installation des bibliotheques principales
echo.
echo [3/4] Mise a jour de pip et installation des bibliotheques de base...
python -m pip install --upgrade pip
pip install numpy opencv-python mediapipe onnxruntime pyserial

:: 4. Tentative d'installation d'InsightFace (ArcFace 512D)
echo.
echo [4/4] Installation d'InsightFace (Modele ArcFace 512D)...
pip install insightface
if errorlevel 1 (
    echo.
    echo [AVERTISSEMENT] InsightFace n'a pas pu s'installer sur cette version de Python
    echo (souvent par manque de Visual C++ Build Tools sur Windows).
    echo Pas de panique : le moteur basculera automatiquement sur le mode OpenCV + MediaPipe !
)

echo.
echo ============================================================================
echo   INSTALLATION TERMINEE !
echo   Vous pouvez maintenant double-cliquer sur "lancer_services_python.bat"
echo   pour demarrer le serveur IA (port 5000) et le simulateur NDE1000 (port 5001).
echo ============================================================================
echo.
pause
