from pi_camera_led import LEDController

# Création du contrôleur
led = LEDController()

# Allumer la LED
print("\n===== TEST ALLUMAGE =====")
led.turn_on()

# Éteindre la LED
print("\n===== TEST EXTINCTION =====")
led.turn_off()

# Libérer les ressources
print("\n===== TEST NETTOYAGE =====")
led.cleanup()