# GLPI 11 — Plugin API rules

## Portée
S’applique aux plugins GLPI 11 qui exposent leur propre API, notamment via routes, controllers, JSON, AJAX ou intégrations externes.

## Principes
- Préférer les mécanismes modernes compatibles GLPI 11.
- Garder les controllers fins et déplacer la logique métier dans des classes dédiées.
- Définir des routes explicites et des méthodes HTTP cohérentes.
- Valider toutes les entrées avant traitement.
- Contrôler les permissions avant toute lecture ou écriture sensible.
- Retourner des réponses JSON claires, stables et prévisibles.

## Contrat d’API
Avant de coder un endpoint, confirmer :
- le chemin ;
- la méthode HTTP ;
- l’authentification attendue ;
- les paramètres d’entrée ;
- le format de sortie ;
- les codes d’erreur ;
- les impacts de compatibilité.

## Règles de conception
- Ne pas exposer inutilement des détails internes du plugin.
- Versionner l’API si elle est destinée à des consommateurs externes.
- Séparer l’API interne d’interface et l’API d’intégration externe.
- Éviter les comportements implicites non documentés.

## Validation avant livraison
Avant de considérer un endpoint comme prêt :
- vérifier les droits ;
- vérifier la validation des entrées ;
- vérifier les cas d’erreur ;
- vérifier le format JSON ;
- vérifier la compatibilité avec GLPI 11 ;
- vérifier si un cas AJAX impose une contrainte spécifique.
