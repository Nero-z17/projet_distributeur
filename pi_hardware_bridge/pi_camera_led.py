"""
PROJET DAB BIOMÉTRIQUE — Fichier : pi_hardware_bridge/pi_camera_led.py
Rôle : Gestion de la LED d'éclairage (4000K-5500K) commandée par relais/MOSFET.
Compatibilité :
  1. Raspberry Pi 5 (OS Bookworm) : utilise `gpiozero` (natif sur Pi 5, contrairement à RPi.GPIO).
  2. Raspberry Pi 3/4 (ancien OS) : fallback sur `RPi.GPIO`.
  3. PC Windows / Mac / Linux     : mode simulation console automatique.
"""


class LEDController:
    """Pilote le relais de la LED d'éclairage du visage avec suivi d'état."""

    def __init__(self, pin: int = 18) -> None:
        self.pin = pin
        self.is_on = False
        self.backend = "SIMULATION_PC"
        self._gpiozero_led = None
        self._rpi_gpio = None

        # 1. Priorité Raspberry Pi 5 : gpiozero (compatible puce RP1)
        try:
            from gpiozero import LED  # type: ignore

            self._gpiozero_led = LED(self.pin)
            self.backend = "GPIOZERO_PI5"
            print(f"[LED] gpiozero détecté (Raspberry Pi 5) sur GPIO {self.pin}.")
            return
        except Exception:
            self._gpiozero_led = None

        # 2. Fallback Raspberry Pi 3/4 : RPi.GPIO
        try:
            import RPi.GPIO as GPIO  # type: ignore

            self._rpi_gpio = GPIO
            self._rpi_gpio.setmode(self._rpi_gpio.BCM)
            self._rpi_gpio.setup(self.pin, self._rpi_gpio.OUT)
            self.backend = "RPI_GPIO"
            print(f"[LED] RPi.GPIO détecté sur GPIO {self.pin}.")
            return
        except Exception:
            self._rpi_gpio = None

        print(f"[LED] Mode PC : simulation de la LED (GPIO {self.pin}).")

    def turn_on(self) -> None:
        if self._gpiozero_led is not None:
            self._gpiozero_led.on()
        elif self._rpi_gpio is not None:
            self._rpi_gpio.output(self.pin, self._rpi_gpio.HIGH)
        self.is_on = True
        print("[LED] Éclairage visage ALLUMÉ")

    def turn_off(self) -> None:
        if self._gpiozero_led is not None:
            self._gpiozero_led.off()
        elif self._rpi_gpio is not None:
            self._rpi_gpio.output(self.pin, self._rpi_gpio.LOW)
        self.is_on = False
        print("[LED] Éclairage visage ÉTEINT")

    def cleanup(self) -> None:
        self.turn_off()
        if self._gpiozero_led is not None:
            self._gpiozero_led.close()
        elif self._rpi_gpio is not None:
            self._rpi_gpio.cleanup(self.pin)
        print("[LED] Ressources GPIO libérées.")