@echo off
title Installation Automatique - Projet DAB Biometrique (8 Membres)
color 0A

echo ============================================================================
echo   INSTALLATION EN 1 CLIC - ENVIRONNEMENT PYTHON (IA 512D + PILOTE NDE1000)
echo ============================================================================
echo.

set PY_CMD=python
python --version >nul 2>&1
if errorlevel 1 (
    py --version >nul 2>&1
    if errorlevel 1 (
        color 0C
        echo [ERREUR] Python n est pas installe ou n est pas dans le PATH Windows !
        echo Telechargez Python 3.11 ou 3.12 sur https://www.python.org/downloads/
        echo IMPORTANT : Cochez "Add Python to PATH" pendant l installation.
        echo.
        pause
        exit /b 1
    ) else (
        set PY_CMD=py
    )
)

echo [1/4] Python detecte :
%PY_CMD% --version
echo.

if not exist "venv\Scripts\activate.bat" (
    echo [2/4] Creation de l environnement virtuel isole ^(venv^)...
    %PY_CMD% -m venv venv
    if errorlevel 1 (
        color 0C
        echo [ERREUR] Impossible de creer le dossier venv.
        pause
        exit /b 1
    )
) else (
    echo [2/4] Environnement virtuel ^(venv^) deja present.
)

call venv\Scripts\activate.bat

echo.
echo [3/4] Mise a jour de pip et installation des bibliotheques de base...
python -m pip install --upgrade pip
pip install numpy opencv-python mediapipe onnxruntime pyserial

echo.
echo [4/4] Installation d InsightFace ^(Modele ArcFace 512D^)...
pip install insightface
if errorlevel 1 (
    echo.
    echo [AVERTISSEMENT] InsightFace n a pas pu s installer sur cette version de Python.
    echo Pas de panique : le moteur basculera automatiquement sur le mode OpenCV + MediaPipe !
)

echo.
echo ============================================================================
echo   INSTALLATION TERMINEE !
echo   Vous pouvez maintenant double-cliquer sur "lancer_services_python.bat"
echo   pour demarrer le serveur IA ^(port 5000^) et le simulateur NDE1000 ^(port 5001^).
echo ============================================================================
echo.
pause