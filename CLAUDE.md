# CLAUDE.md — Projet GLPI 11

## Portée
Ce dépôt concerne principalement le développement, la maintenance et l’intégration de plugins **GLPI 11** en PHP/MySQL.

## Règles globales
- Toujours privilégier la compatibilité **GLPI 11**.
- Lire le code existant avant de modifier quoi que ce soit.
- Faire des changements minimaux, ciblés et réversibles.
- Préserver le comportement existant sauf demande explicite.
- Réutiliser les mécanismes GLPI existants avant de créer une solution custom.

## Validation avant code
Avant d’écrire, modifier ou proposer du code, valider les faits nécessaires.

Niveau de confiance minimal requis : **95%** sur :
- la version GLPI visée ;
- les classes, hooks, routes ou fichiers concernés ;
- le schéma de données et l’impact technique ;
- les implications de sécurité et de compatibilité.

Si le seuil de confiance n’est pas atteint :
- ne pas produire de code final directement ;
- vérifier la documentation, le dépôt et le schéma réel ;
- signaler explicitement les incertitudes ;
- proposer d’abord une étape de validation ou un prototype limité.

## Sécurité
- Ne jamais faire confiance aux entrées brutes.
- Prévenir SQL injection, XSS et erreurs d’autorisation.
- Vérifier les droits avant toute action sensible.
- Ne jamais stocker de secrets dans le code.

## Usage des règles spécialisées
Pour les tâches spécifiques, appliquer en plus les règles dans `.claude/rules/` :
- `glpi-plugin-api.md`
- `glpi-migration.md`
- `glpi-validation.md`

En cas de doute :
- `CLAUDE.md` définit les règles globales et non négociables ;
- les fichiers dans `.claude/rules/` ajoutent des règles spécialisées ;
- aucune règle spécialisée ne doit contredire ce fichier.
