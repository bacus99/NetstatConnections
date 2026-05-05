# GLPI 11 — Validation-first workflow

## Objectif
Appliquer un workflow strict de validation avant toute génération de code pour GLPI 11.

## Processus obligatoire
1. Reformuler l’objectif fonctionnel.
2. Identifier les composants concernés : classes, hooks, routes, templates, tables, migrations.
3. Vérifier les faits dans la documentation et le code existant.
4. Estimer explicitement si la confiance atteint 95%.
5. Produire du code final uniquement si le seuil est atteint.

## Quand la confiance est insuffisante
Ne pas inventer les détails manquants.

À la place :
- lister les éléments à confirmer ;
- demander les fichiers utiles ou inspecter le dépôt ;
- proposer une stratégie de validation ;
- proposer éventuellement un squelette limité, clairement signalé comme provisoire.

## Format de réponse conseillé
- Faits vérifiés.
- Hypothèses restantes.
- Niveau de confiance.
- Risques éventuels.
- Code ou plan d’action.

## Interdictions
- Inventer une API, un hook ou une classe GLPI non vérifiée.
- Supposer un schéma SQL sans contrôle.
- Donner une réponse “probable” comme si elle était certaine.
