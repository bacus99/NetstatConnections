# GLPI 11 — Migration et base de données

## Portée
S’applique à toute modification SQL, migration, ajout de table, changement de type, index, clé ou conversion de données.

## Principes
- Vérifier le schéma réel avant toute modification.
- Vérifier les migrations existantes avant d’en créer une nouvelle.
- Préserver les données existantes.
- Évaluer l’impact en mise à jour, pas seulement en installation neuve.

## Vérifications obligatoires
Avant d’écrire une migration :
- confirmer la version GLPI cible ;
- confirmer la structure actuelle ;
- confirmer types, tailles, nullabilité, index et clés ;
- confirmer les impacts sur les requêtes existantes ;
- confirmer la stratégie de rollback ou de correction.

## Interdictions
- Ne pas supposer qu’une colonne existe.
- Ne pas renommer ou supprimer sans analyse d’impact.
- Ne pas écrire une migration destructive sans justification claire.
- Ne pas livrer une migration non testée conceptuellement.

## Réponse attendue
Pour toute proposition de migration :
- objectif ;
- hypothèses validées ;
- SQL ou code de migration ;
- risques ;
- méthode de validation.
