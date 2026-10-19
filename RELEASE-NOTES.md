Version 3.0.12 — Un seul fichier marchand pour plusieurs sites

- Identité commune basée sur le numéro principal du marchand et le classeur central partagé.
- Registre technique masqué dans le classeur central pour choisir un seul fichier, même en cas de créations simultanées.
- Réutilisation du fichier 3.0.11 du premier site à synchroniser. Les autres sites regroupent leurs anciens fichiers à la prochaine commande du marchand, par lots, sans supprimer les sources.
- Conservation du fichier central, de ses feuilles mensuelles, des réglages et des colonnes marchands simplifiées.

Conditions : mettre à jour tous les sites ; utiliser le même classeur central et le même premier numéro marchand ; les connexions Google doivent autoriser l'accès au fichier commun (même compte et même projet OAuth recommandés). Aucun partage de fichier automatique. Ne pas renommer les onglets techniques ni modifier l'ordre des lignes des anciens fichiers avant leur reprise.

Une réservation interrompue attend la reprise du site qui l'a créée. Elle n'est jamais remplacée automatiquement par une seconde création. Les fichiers sources restent dans Drive après regroupement ; vérifier le contenu commun avant de les archiver.

Tests automatisés sur WordPress/WooCommerce avec Google simulé. Validation réelle sur deux sites encore requise.
