# Version 2.1.3 — modifications demandées

Cette archive est une mise à jour complète du plugin existant. Elle conserve son dossier et ses options : la connexion Google, le classeur, la liste des marchands et les demandes WordPress ne sont pas réinitialisés.

## Modifications

- Retrait : toutes les informations client sont facultatives. Si un téléphone ou un e-mail est renseigné, sa validité reste vérifiée.
- Livraison : les obligations existantes sont conservées (nom, téléphone, ville, commune, adresse). Le pays, l'e-mail et le point de repère restent facultatifs comme dans la version initiale.
- Le caractère obligatoire des champs et les indications du formulaire changent avec le mode de réception.
- La ligne « Référence de ma demande : CS-… » disparaît du message WhatsApp, côté navigateur et serveur. L'identifiant interne reste enregistré pour le suivi.
- Boutique unique : le numéro configuré dans « Numéro de repli » est utilisé, et Sheets reçoit le nom configuré dans « Nom de ce site » ainsi que ce numéro.
- Marketplace : un numéro explicitement associé au produit (ou fourni par le filtre existant) prime sur un bouton global. Les autres mécanismes existants de résolution sont conservés. Les métadonnées numériques sans lien avec un téléphone ne sont plus balayées comme numéros candidats de produit.
- Reconnaissance des numéros déclarés corrigée : PHP peut convertir les clés numériques en entiers, ce qui faisait échouer la comparaison stricte avec un numéro texte.
- La colonne « Origine du numéro » est absente des nouveaux onglets. Les informations techniques de résolution restent internes au plugin.
- L'écriture suit les en-têtes réellement présents, notamment les ordres différents de septembre et octobre. Une colonne obligatoire manquante ou ambiguë empêche une écriture mal alignée ; la demande reste en attente.
- Les listes déroulantes Statut et Site sont réappliquées après l'ajout d'une demande. L'ajout ne décale plus les règles sous les lignes remplies.

## Installation et adaptation du classeur commun

1. Conserver le ZIP original et une copie du Google Sheets avant la mise à jour.
2. Dans WordPress, Extensions → Ajouter une extension → Téléverser une extension, sélectionner ce ZIP et remplacer la version existante. Ne pas supprimer le plugin au préalable.
3. Vérifier les réglages conservés : mode du site, nom du site, numéro, marchands et classeur. Aucune reconnexion Google n'est prévue pour cette mise à jour.
4. Mettre à jour tous les sites qui écrivent dans le classeur commun AVANT de supprimer une colonne. Une ancienne version continuerait à écrire selon ses anciennes positions.
5. Depuis UN site connecté, cliquer sur « Réparer le classeur ». Dans les onglets mensuels et l'ancien onglet « Demandes », ce bouton supprime uniquement « Origine du numéro » et remet les listes déroulantes aux colonnes retrouvées par leur titre. Il ne réordonne pas les autres colonnes et ne réduit pas le nombre de lignes.
6. Tester un retrait sans coordonnées puis une livraison incomplète et complète. Le mode test existant permet de prévisualiser le message sans ouvrir WhatsApp ; attention, il enregistre tout de même une demande.

Avant réparation, cette version peut encore écrire dans les onglets contenant l'ancienne colonne, en la laissant vide. La suppression n'est pas déclenchée simplement par l'installation ni par l'enregistrement des réglages.

Les anciens noms et numéros déjà enregistrés ne sont pas réécrits : il faut connaître le destinataire attendu pour corriger l'historique sans inventer d'informations.

## Vérifications réalisées

- Syntaxe de tous les fichiers PHP et du JavaScript.
- Tests JavaScript de la validation et de la bascule retrait/livraison.
- Tests PHP avec services WordPress et Google simulés : validation serveur, numéros unique et marketplace, nom et numéro exportés, disparition de la référence WhatsApp, lecture des trois schémas du fichier Excel fourni, réparation répétable et listes déroulantes correctement positionnées.
- Aucun message WhatsApp réel envoyé ; aucune donnée du classeur en ligne modifiée.

Cette version reste à vérifier sur le site de test avec ses produits et la connexion Google réelle. Les problèmes déjà identifiés de sauvegarde asynchrone et de doublons éventuels lors des reprises Google n'ont pas été refondus, pour respecter le périmètre demandé.

Le formulaire Elementor historique est indépendant de ce plugin. Si les deux s'ouvrent ensemble, leur coexistence devra être réglée sur le site ; ce ZIP ne modifie pas les modèles Elementor.
