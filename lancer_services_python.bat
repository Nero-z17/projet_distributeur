@echo off
title Lancement des Services Python - DAB Biometrique

if not exist "venv\Scripts\activate.bat" (
    color 0C
    echo [ERREUR] L environnement virtuel ^(venv^) est introuvable.
    echo Double-cliquez d abord sur "installer_tout_en_un_clic.bat" !
    pause
    exit /b 1
)

echo Demarrage du Serveur IA Reconnaissance Faciale 512D ^(Port 5000^)...
start "DAB - Serveur IA 512D (Port 5000)" cmd /k "call venv\Scripts\activate.bat && cd python_ai_service && python ai_server.py"

echo Demarrage du Pont Materiel et Simulateur NDE1000 ^(Port 5001^)...
start "DAB - Simulateur NDE1000 (Port 5001)" cmd /k "call venv\Scripts\activate.bat && cd pi_hardware_bridge && python hardware_server.py"

echo.
echo Les deux services sont lances !
echo - Console Test Webcam IA : http://localhost:5000
echo - Sante Pont Materiel     : http://localhost:5001/api/hardware/health
echo.
timeout /t 4 >nul