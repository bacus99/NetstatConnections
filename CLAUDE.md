# CLAUDE.md — NetstatConnections (GLPI 11 outil)

## Conventions partagées

Les conventions et règles globales pour tous mes projets GLPI 11 sont centralisées dans [`../GLPI-Shared/`](../GLPI-Shared/CLAUDE.md). À lire en premier pour toute tâche : versioning, namespacing, hooks, API DB, workflow de validation, migrations, endpoints AJAX, et processus de build/release.

Ce fichier ne couvre que ce qui est spécifique à *ce* projet.

## Portée du projet

NetstatConnections est un **collecteur d'inventaire** écrit en Perl (`glpi-netstat-collect.pl`) qui interroge `netstat` sur Windows et soumet les connexions actives à un serveur GLPI 11 via son API.

Ce n'est **pas** un plugin GLPI au sens classique (pas de `setup.php`, pas de tables `glpi_plugin_*`). C'est un **agent autonome** qui consomme l'API GLPI côté client.

## Architecture spécifique

```
NetstatConnections/
├── glpi-netstat-collect.pl   Script principal Perl (~33 KB)
├── glpi-netstat.bat          Wrapper Windows pour l'exécution planifiée
├── netstat-collect.ini       Configuration runtime (cible GLPI, credentials)
├── netstat-collect_empty.ini Template de config sans secrets
└── perl/                     Modules Perl bundlés
```

## Points spécifiques à ce projet

- **Langue Perl, pas PHP.** Les règles dans GLPI-Shared sur PSR-4, Hooks::, `$DB->doQuery()` ne s'appliquent pas ici — ce code ne tourne pas dans GLPI.
- **Consommateur d'API GLPI** — les règles de [`../GLPI-Shared/rules/glpi-plugin-api.md`](../GLPI-Shared/rules/glpi-plugin-api.md) s'appliquent inversement : il faut respecter le contrat d'API tel qu'exposé par GLPI 11.
- **Pas de cible de catalogue plugins** — ce projet ne va pas sur plugins.glpi-project.org. Le build/release de [`../GLPI-Shared/rules/glpi-build-release.md`](../GLPI-Shared/rules/glpi-build-release.md) ne s'applique pas.
- **Configuration** : `netstat-collect.ini` contient des secrets — ne jamais commiter. Le template `netstat-collect_empty.ini` est ce qui est versionné.

## Règles globales (rappel)

Les règles non-négociables de [`../GLPI-Shared/CLAUDE.md`](../GLPI-Shared/CLAUDE.md) s'appliquent ici aussi :

1. **Compatibilité GLPI 11** côté API consommée.
2. **Lire le code existant** avant de modifier.
3. **Changements minimaux, ciblés et réversibles.**
4. **Préserver le comportement existant** sauf demande explicite.
5. **Ne jamais faire confiance aux entrées brutes.**

Workflow de validation à 95% : voir [`../GLPI-Shared/rules/glpi-validation.md`](../GLPI-Shared/rules/glpi-validation.md).
