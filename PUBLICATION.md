# Publication manuelle sur GitHub

Le dépôt public est https://github.com/mansconsult/congo-shop-whatsapp-order.
Le code existant contient des numéros commerciaux et une correspondance boutique/numéro par défaut. Les configurations réelles WordPress, comptes clients, commandes et secrets OAuth ne sont pas dans ce dépôt.

## Première installation

La version 3.0.8 ajoute le module natif de mise à jour GitHub (WordPress 5.8 minimum). Elle ne change pas le schéma de données, les réglages ou les fonctions métier de la 3.0.7. Installer le ZIP sur un site de test via Extensions > Ajouter > Téléverser et choisir de remplacer la version existante. Ne pas supprimer l'extension. Aucun jeton GitHub ni accès à l'hébergement n'est nécessaire. Garder le plugin actif pour ses vérifications de mise à jour.

## À chaque version

1. Récupérer la dernière branche main, modifier le code et tester sur le site de test.
2. Incrémenter Version dans l'en-tête ET CSWA_VERSION dans le fichier principal. Mettre à jour Requires at least et Requires PHP si nécessaire. Garder le même dossier et fichier principal.
3. Exécuter `python3 tools/build-release.py` (ou `python tools/build-release.py` sous Windows). Le dossier dist/VERSION contient le ZIP, update.json et SHA256SUMS.txt.
4. Commit et push du code testé. Un push ne publie PAS de mise à jour.
5. Dans GitHub > Releases > Draft a new release, créer un tag vVERSION sur le commit testé. Joindre le ZIP généré, update.json et éventuellement SHA256SUMS.txt. Ne pas utiliser les archives automatiques « Source code » : elles contiennent les fichiers de développement et n'ont pas le dossier garanti par notre script.
6. Ajouter les notes de version, puis publier en Release stable et la définir comme Latest. Les brouillons et pre-releases ne sont pas proposés aux sites par ce module.
7. WordPress > Extensions > Congo-Shop > Vérifier les mises à jour, puis Mettre à jour maintenant.

Toujours publier un NOUVEAU numéro : ne pas remplacer le ZIP d'une version déjà publiée. Pour corriger ou revenir au comportement précédent, publier une nouvelle version supérieure. Ne jamais retirer une Release sans comprendre l'effet sur les sites qui ont déjà sa notification en cache.

## Vérification et limites

Le module consulte uniquement l'API GitHub releases/latest, exige un ZIP nommé exactement congo-shop-whatsapp-order-VERSION.zip et un update.json correspondant. Aucun repli sur une branche, un tag seul ou une archive source. Cache de six heures ; erreurs/absence de Release mises en cache quinze minutes. Le lien de vérification manuelle purge ces caches. WP-Cron et les accès HTTPS sortants doivent fonctionner. L'API GitHub publique peut limiter les requêtes, notamment sur un hébergement partagé ; une erreur n'interrompt pas les commandes WhatsApp.

Les mises à jour automatiques sans clic ne sont pas activées de force. Leur activation éventuelle reste une décision de l'administrateur WordPress après validation du test. Les prérequis futurs sont transmis par update.json. La compatibilité WooCommerce doit être vérifiée avant publication ; elle n'est pas validée par ce module.

## Test de bout en bout obligatoire (pas encore réalisé)

- Sauvegarder le site de test (base et fichiers), noter les versions WordPress/PHP/WooCommerce et le mode HPOS.
- Vérifier les fonctions de référence en 3.0.7. Installer 3.0.8 et confirmer les mêmes réglages, commandes, marchands et photos.
- Publier 3.0.8, vérifier qu'aucune mise à jour de même version n'est proposée.
- Préparer puis publier 3.0.9 après test. Déclencher la recherche depuis 3.0.8, vérifier les détails, puis cliquer Mettre à jour maintenant.
- Vérifier version 3.0.9, extension toujours active, options cswa_settings et cswa_google préservées, table cswa_requests et commandes historiques intactes.
- Tester une nouvelle commande retrait, livraison, numéro du bon marchand, synchro Sheets sans doublon, inscription et notification avec photos, portail marchand.
- Vérifier qu'une panne GitHub ne bloque pas une commande.

Le projet n'est pas validé tant que ces essais WordPress réels ne sont pas terminés. Les tests PHP simulés ne remplacent pas cette recette.
