from nde1000_driver import NDE1000Driver

# Création du pilote
driver = NDE1000Driver()

# Test 1 : distribution réussie
print("\n========== TEST SUCCESS ==========")
resultat = driver.dispense(3, "SUCCESS")
print("Résultat :", resultat)

# Test 2 : bourrage
print("\n========== TEST JAM_AT_2 ==========")
resultat = driver.dispense(3, "JAM_AT_2")
print("Résultat :", resultat)

# Test 3 : bac vide
print("\n========== TEST EMPTY ==========")
resultat = driver.dispense(3, "EMPTY")
print("Résultat :", resultat)
print("\n========== TEST DES ERREURS ==========")

# Test 4 : zéro billet
try:
    driver.dispense(0)
except ValueError as erreur:
    print("Erreur détectée :", erreur)

# Test 5 : nombre négatif
try:
    driver.dispense(-2)
except ValueError as erreur:
    print("Erreur détectée :", erreur)

# Test 6 : scénario inconnu
try:
    driver.dispense(3, "INCONNU")
except ValueError as erreur:
    print("Erreur détectée :", erreur)