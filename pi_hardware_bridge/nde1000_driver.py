"""
PROJET DAB BIOMÉTRIQUE — Fichier : pi_hardware_bridge/nde1000_driver.py
Rôle : Pilote du distributeur simple bac ICT NDE1000.
       Mode simulation PC complet (SUCCESS, JAM_AT_2, EMPTY) et préparation du mode RS232 Pi 5.
"""

import os
import time
from typing import Any, Dict


class NDE1000Driver:
    MAX_NOTES_PER_TX = 50  # Limite mécanique d'une liasse sur module ICT NDE1000

    def __init__(self) -> None:
        self.simulation_mode = os.getenv("SIMULATION_MODE", "true").lower() in ("true", "1", "yes")
        self.serial_port = os.getenv("NDE1000_PORT", os.getenv("NDE1000_SERIAL_PORT", "/dev/ttyUSB0"))
        self.baudrate = 9600

    def dispense(self, notes_requested: int, simulation_scenario: str = "SUCCESS") -> Dict[str, Any]:
        if not isinstance(notes_requested, int) or notes_requested <= 0:
            raise ValueError("Le nombre de billets doit être un entier strictement positif.")
        if notes_requested > self.MAX_NOTES_PER_TX:
            raise ValueError(f"Maximum {self.MAX_NOTES_PER_TX} billets autorisés par retrait.")

        if self.simulation_mode:
            return self.simulate_dispense(notes_requested, simulation_scenario)

        return self.real_dispense(notes_requested)

    def simulate_dispense(self, notes_requested: int, scenario: str) -> Dict[str, Any]:
        scenario = str(scenario or "SUCCESS").strip().upper()

        print(f"\n===== SIMULATION NDE1000 ({notes_requested} billet(s) | {scenario}) =====")

        if scenario == "SUCCESS":
            for billet in range(1, notes_requested + 1):
                print(f"  -> Extraction du billet {billet}/{notes_requested}...")
                time.sleep(0.15)
            return {
                "status": "success",
                "hardware_state": "ONLINE",
                "notes_dispensed": notes_requested,
                "message": f"Distribution réussie ({notes_requested} billet(s) éjecté(s)).",
            }

        if scenario == "JAM_AT_2":
            print("  -> Simulation d'un bourrage au 2e billet.")
            time.sleep(0.25)
            notes_dispensed = min(1, notes_requested - 1)
            return {
                "status": "jammed",
                "hardware_state": "JAMMED",
                "notes_dispensed": notes_dispensed,
                "message": f"Bourrage détecté après {notes_dispensed} billet(s) sorti(s).",
            }

        if scenario == "EMPTY":
            print("  -> Capteur bac vide déclenché.")
            time.sleep(0.15)
            return {
                "status": "empty",
                "hardware_state": "EMPTY",
                "notes_dispensed": 0,
                "message": "Bac de billets vide (0 billet distribué).",
            }

        raise ValueError("Scénario inconnu. Utilisez SUCCESS, JAM_AT_2 ou EMPTY.")

    def real_dispense(self, notes_requested: int) -> Dict[str, Any]:
        """
        Liaison série RS232 prévue pour le module ICT NDE1000 :
        9600 bauds, 8 bits de données, parité paire (EVEN), 1 bit d'arrêt.
        Les trames exactes seront branchées après réception et vérification de la documentation
        officielle ICT et des interrupteurs DIP du module.
        """
        raise NotImplementedError(
            "Le protocole de commande RS232 réel du NDE1000 sera activé après réception "
            "du module physique et validation de ses DIP switches."
        )