"""
Pilote du distributeur ICT NDE1000.
Mode simulation PC et préparation du mode Raspberry Pi.
"""

import os
import time


class NDE1000Driver:

    def __init__(self):
        self.simulation_mode = (
            os.getenv("SIMULATION_MODE", "true").lower() == "true"
        )

        self.serial_port = os.getenv(
            "NDE1000_PORT",
            "/dev/ttyUSB0"
        )

    def dispense(self, notes_requested, simulation_scenario="SUCCESS"):

        if notes_requested <= 0:
            raise ValueError("Le nombre de billets doit être positif.")

        if self.simulation_mode:
            return self.simulate_dispense(
                notes_requested,
                simulation_scenario
            )

        return self.real_dispense(notes_requested)

    def simulate_dispense(self, notes_requested, scenario):

        scenario = scenario.upper()

        print("\n===== SIMULATION NDE1000 =====")
        print(f"Billets demandés : {notes_requested}")
        print(f"Scénario : {scenario}")

        if scenario == "SUCCESS":

            for billet in range(1, notes_requested + 1):
                print(f"Distribution du billet {billet}...")
                time.sleep(0.3)

            return {
                "status": "success",
                "notes_dispensed": notes_requested,
                "message": "Distribution réussie."
            }

        elif scenario == "JAM_AT_2":

            print("Simulation d'un bourrage.")

            time.sleep(0.5)

            # Règle de retour indiquée dans le cahier des charges.
            notes_dispensed = min(1, notes_requested - 1)

            return {
                "status": "jammed",
                "notes_dispensed": notes_dispensed,
                "message": "Bourrage détecté."
            }

        elif scenario == "EMPTY":

            print("Le bac est vide.")

            return {
                "status": "empty",
                "notes_dispensed": 0,
                "message": "Aucun billet disponible."
            }

        else:

            raise ValueError(
                "Scénario inconnu. "
                "Utilisez SUCCESS, JAM_AT_2 ou EMPTY."
            )

    def real_dispense(self, notes_requested):

        """
        Le cahier des charges prévoit une liaison RS232 :
        9600 bauds, 8 bits, parité paire, 1 bit d'arrêt.

        Les commandes réelles du ICT NDE1000 nécessitent
        sa documentation officielle de protocole.
        Elles ne sont donc pas inventées ici.
        """

        raise NotImplementedError(
            "Le protocole de commande réel du NDE1000 "
            "doit être intégré après obtention de sa documentation."
        )