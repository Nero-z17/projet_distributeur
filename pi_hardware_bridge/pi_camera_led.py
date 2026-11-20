"""
Gestion de la LED d'éclairage du système DAB.
Simulation sur PC et possibilité de gestion GPIO sur Raspberry Pi.
"""


class LEDController:

    def __init__(self, pin=18):

        self.pin = pin
        self.gpio = None

        try:
            import RPi.GPIO as GPIO

            self.gpio = GPIO

            self.gpio.setmode(self.gpio.BCM)
            self.gpio.setup(self.pin, self.gpio.OUT)

            print("GPIO Raspberry Pi détecté.")

        except ImportError:

            print("Mode PC : simulation de la LED.")

    def turn_on(self):

        if self.gpio:

            self.gpio.output(self.pin, self.gpio.HIGH)

        print("LED ALLUMÉE")

    def turn_off(self):

        if self.gpio:

            self.gpio.output(self.pin, self.gpio.LOW)

        print("LED ÉTEINTE")

    def cleanup(self):

        if self.gpio:

            self.gpio.output(self.pin, self.gpio.LOW)
            self.gpio.cleanup(self.pin)

        print("GPIO libéré.")