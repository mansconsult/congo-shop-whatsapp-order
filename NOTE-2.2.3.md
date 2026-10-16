> Note historique de la version 2.2.3. Pour le fonctionnement actuel, lire README.md (version 3.0.2).

# Congo Shop WhatsApp 2.2.3 — correctif conservateur (à valider)

## Modifications
- Même formulaire, mêmes règles retrait/livraison/prestations, mêmes numéros de vendeurs.
- L'ouverture WhatsApp reste un lien direct, sans attente Google Sheets.
- La route REST écrit d'abord la demande dans la table WordPress, puis lance Action Scheduler (WooCommerce) pour la synchronisation en arrière-plan ; WP-Cron est la reprise.
- Le navigateur vérifie la réponse de la sauvegarde et retente en cas de problème tant que l'onglet reste vivant (même clé d'idempotence). Aucun nom ou numéro client n'est stocké dans localStorage.
- La référence de demande est ajoutée au message WhatsApp pour simplifier les rapprochements.
- Les réessais Google vérifient que l'ID n'existe pas déjà avant d'ajouter une ligne.
- Un verrou évite le chevauchement des synchronisations d'une même demande.

## Points à contrôler avant mise en production
1. Sauvegarder fichiers et base WordPress ; exporter les réglages du plugin.
2. Vérifier dans WooCommerce > Commande WhatsApp que le mode « Administrateurs uniquement » est décoché si l'objectif est de traiter tous les visiteurs. Le correctif ne modifie **pas** ce réglage.
3. Vérifier Action Scheduler (WooCommerce > État > Actions planifiées), la tâche cswa_retry_sheet et les erreurs associées.
4. Mettre d'abord la mise à jour sur un site de préproduction. Tester commande, numéro vendeur, devises, prestation, mode livraison/retrait et insertion Google.
5. Ne pas supposer que ce correctif récupère automatiquement les anciens clics Matomo ou les messages réellement envoyés.
6. Si WP-Cron est désactivé, configurer un vrai cron hôte afin de traiter la file. Les exceptions où le navigateur est tué immédiatement après le clic restent possibles.

## Limites
Analyse statique du ZIP. Installation, requêtes réelles, API Google et logs WordPress non vérifiés. Une correspondance entre clic Matomo et message effectivement envoyé ne peut pas être garantie par wa.me seul.
