"""
PROJET DAB BIOMÉTRIQUE — Fichier : python_ai_service/arduino_led_bridge.py
Rôle : Pont Série (pyserial) optionnel entre ai_server.py et l'Arduino Uno sur COM3.
       - Ouvre la connexion UNE SEULE FOIS au démarrage (évite l'auto-reset Uno à chaque requête).
       - Ne fait jamais planter ai_server.py si l'Arduino est débranché ou si COM3 est absent.
"""

import atexit
import os
import threading
import time
from typing import Optional

try:
    import serial  # type: ignore
    PYSERIAL_AVAILABLE = True
except Exception:
    PYSERIAL_AVAILABLE = False


class ArduinoLEDBridge:
    """Envoie 'G' (LED Verte), 'R' (LED Rouge) ou 'O' (Éteint) à l'Arduino Uno via COM3."""

    def __init__(self, port: Optional[str] = None, baudrate: int = 9600) -> None:
        self.port = port or os.getenv("ARDUINO_PORT", "COM3")
        self.baudrate = baudrate
        self._lock = threading.Lock()
        self._serial = None
        self.connected = False
        self.status_message = "Non connecté"

        self._connect()
        atexit.register(self.close)

    def _connect(self) -> None:
        if not PYSERIAL_AVAILABLE:
            self.status_message = "pyserial non installé (mode sans Arduino)"
            print(f"[Arduino] {self.status_message}")
            return

        try:
            self._serial = serial.Serial(self.port, self.baudrate, timeout=1)
            # Pause obligatoire de 2s : l'ouverture du port COM redémarre l'Arduino Uno
            time.sleep(2.0)
            self.connected = True
            self.status_message = f"Connecté sur {self.port} ({self.baudrate} bauds)"
            print(f"[Arduino] {self.status_message}")
        except Exception as exc:
            self._serial = None
            self.connected = False
            self.status_message = f"Non détecté sur {self.port} (mode sans LED)"
            print(f"[Arduino] {self.status_message} — Détail : {exc}")

    def _send_byte(self, cmd: bytes) -> bool:
        with self._lock:
            if not self.connected or self._serial is None:
                return False
            try:
                self._serial.write(cmd)
                self._serial.flush()
                return True
            except Exception as exc:
                print(f"[Arduino] Erreur d'envoi série sur {self.port} : {exc}")
                self.connected = False
                self.status_message = f"Déconnecté ({self.port})"
                return False

    def signal_green(self) -> bool:
        """Allume la LED Verte (visage reconnu)."""
        return self._send_byte(b"G")

    def signal_red(self) -> bool:
        """Allume la LED Rouge (visage refusé / fraude photo)."""
        return self._send_byte(b"R")

    def signal_off(self) -> bool:
        """Éteint les deux LEDs."""
        return self._send_byte(b"O")

    def close(self) -> None:
        with self._lock:
            if self._serial is not None:
                try:
                    self._serial.write(b"O")
                    self._serial.close()
                except Exception:
                    pass
                self._serial = None
                self.connected = False