# Version 2.2.0 — retrait, livraison et prestations KinPresta

## Fonctionnement
- Boutiques : retrait sélectionné à chaque ouverture. Nom et téléphone obligatoires, e-mail facultatif en retrait. Seuls les champs d’adresse sont masqués et ignorés. Le récapitulatif, le choix du mode et le bouton WhatsApp restent visibles.
- Livraison : les champs existants apparaissent. Nom, téléphone, ville, commune et adresse restent obligatoires ; e-mail, pays et référence restent facultatifs.
- KinPresta : formulaire direct, sans choix retrait/livraison. Les champs existants sont conservés et Description du besoin est ajoutée (obligatoire, 3 000 caractères maximum). Aucun champ date ajouté.
- Service concerné est issu de la fiche WooCommerce côté serveur. Le message inclut le besoin, la fiche et les coordonnées, sans quantité ni total commercial.
- Les fiches sans prix sont acceptées en mode prestation. Aucun montant fictif n'est enregistré.
- Les numéros sont lus depuis les affectations de l'intégration KinPresta existante (kpwa_number_for), puis depuis le champ du numéro OneClick. Aucune nouvelle affectation n'est créée. Un numéro manquant bloque la demande.
- Le parcours Automatique conserve le mode boutique ailleurs et détecte l'intégration KinPresta. Le réglage Parcours du formulaire permet aussi de choisir explicitement Produits ou Prestataires.
- Le mode Prestataires se limite à la catégorie WooCommerce artisant. Le bouton est intégré au bloc de contact de la fiche ; les boutons des cartes et de l'en-tête générés par le snippet existant sont retirés à son rendu. Purger les caches Elementor et de page lors de l'installation.
- Le réglage Administrateurs uniquement reste respecté et activé par défaut sur une nouvelle installation.

## Google Sheets partagé : ordre de déploiement
Le classeur existant doit être configuré dans le plugin sur KinPresta et la connexion Google autorisée. Ne pas laisser l'identifiant du classeur vide : l'ancien mécanisme créerait un autre fichier.

1. Mettre à jour le plugin sur les sites de vente utilisant le classeur partagé. Conserver leurs réglages et connexions.
2. Installer/configurer KinPresta en mode administrateurs uniquement pour les essais.
3. Une première demande de prestation synchronisée ajoute Service concerné et Description du besoin à la fin de l'onglet mensuel concerné. Les anciennes lignes et l'ordre des colonnes existantes sont préservés. Les autres anciens onglets ne sont pas modifiés sans nouvelle demande les concernant. Les nouveaux onglets mensuels incluent ces colonnes.
4. Vérifier le formulaire, le numéro destinataire et la ligne Google Sheets avant ouverture aux visiteurs.

IMPORTANT : les anciennes versions 2.1.x peuvent refuser les nouvelles colonnes. Mettre à jour tous les sites partageant le fichier avant la première synchronisation de prestation et avant la création d'un nouvel onglet mensuel. Les demandes de vente sur un onglet existant n'ajoutent pas à elles seules les deux colonnes.

## Vérifications effectuées
- Analyse syntaxique de tous les fichiers PHP et du JavaScript.
- Tests PHP simulés : coordonnées, routage OneClick, prestation sans prix, absence de numéro, catégorie exclue, messages et colonnes.
- Tests DOM sur le formulaire rendu : retrait par défaut, masquage des adresses, bascule livraison, champs obligatoires, réouverture et description du besoin.
- Tests Google Sheets avec réponses simulées : ordre historique des colonnes, ajout des deux titres en fin, répétition sans doublons, statuts et feuille mensuelle.

Aucun message WhatsApp réel ni aucune écriture dans le classeur de production n'a été effectué pendant ces tests. La sauvegarde conserve le fonctionnement existant : demande locale, synchronisation immédiate tentée, reprise Google en cas d'échec. Le client envoie lui-même le message depuis WhatsApp.

## Limites de validation
Ce ZIP est prêt pour validation sur les sites ; il n'a pas été installé en production lors de sa préparation. L'affichage réel du thème, les caches, l'autorisation Google et le parcours complet doivent être vérifiés après installation. Les problèmes d'affectations déjà présents sur d'autres sites ne sont pas corrigés par cette évolution.
