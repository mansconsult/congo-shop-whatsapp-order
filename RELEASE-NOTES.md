Version 3.0.11

- Retrait du tableau des 20 dernières demandes dans la configuration, sans suppression des données.
- Conservation du fichier Google Sheets central.
- En mode Marketplace, création d'un fichier privé par marchand lors de sa première nouvelle commande.
- Les fichiers marchands omettent Site, Marchand, Numéro marchand, ID commande, ID produit et la référence de demande.
- Protection contre les doublons lors des reprises après erreur réseau.
- Liens vers les fichiers marchands dans la configuration.

Les anciennes commandes ne sont pas recopiées automatiquement. Aucun partage Google n'est ajouté automatiquement.
Tests PHP, WordPress/WooCommerce et scénarios Google simulés réussis. Le test de création avec le compte Google réel reste à effectuer sur le site de test.
