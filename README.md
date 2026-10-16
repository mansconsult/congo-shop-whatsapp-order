# Congo-Shop — Commande WhatsApp 3.0.9

## Mise à jour 3.0.9 — inscription marchand plus compacte

Sur grand écran, boutique/propriétaire, e-mail/WhatsApp et Facebook/Instagram sont présentés par paires. Les champs activité, présentation, photos et mot de passe restent sur toute la largeur. Une seule colonne sur mobile et tablette. Le formulaire dispose de davantage de place à côté de la connexion. Aucun champ ajouté ou supprimé, aucune modification de validation, de traitement ou de schéma de données.

Publication manuelle via GitHub Releases : voir PUBLICATION.md. Le test réel de mise à jour 3.0.8 → 3.0.9 et la vérification visuelle sur le site restent à effectuer.

# Congo-Shop — Commande WhatsApp 3.0.8

## Mise à jour 3.0.8 — GitHub public

Ajout isolé du mécanisme natif WordPress de mises à jour depuis les Releases GitHub. Publication manuelle du ZIP et du manifeste : voir [PUBLICATION.md](PUBLICATION.md). Aucun jeton requis. Aucun changement du schéma de données ou des fonctions métier. Recette WordPress de bout en bout encore à réaliser.

## Historique conservé

# Congo-Shop — Commande WhatsApp 3.0.7

## Modification 3.0.7 — photos dans l’e-mail de nouvelle inscription

La notification administrateur passe au format HTML. Elle présente le nom de la boutique, le propriétaire, l’activité ou les produits vendus, la présentation complète, l’adresse e-mail, le numéro WhatsApp et les liens Facebook/Instagram (ou « Non renseigné »). Le mot de passe n’est jamais communiqué.

Les photos du dossier sont affichées dans le corps du message sous forme d’aperçus cliquables. Chaque image possède également un lien textuel pour ouvrir la photo originale en grand. Les photos ne sont pas jointes au message : elles sont chargées depuis les URL de la médiathèque du site. Si la messagerie bloque les images distantes, autoriser leur affichage ou utiliser les liens sous les photos. Le site et ses images doivent être accessibles pour leur affichage. Aucune photo n’est envoyée à un service tiers supplémentaire par le plugin.

Le bouton d’approbation ouvre la liste des marchands dans WordPress et nécessite la connexion de l’administrateur ; il n’approuve pas automatiquement le compte. Les règles obligatoires et la limite de cinq photos de 3.0.6 restent inchangées. Les photos enregistrées avec les anciens champs sont également prises en charge.

Installation : remplacer l’extension par le ZIP 3.0.7. Le nouveau contenu concerne les prochaines notifications d’inscription ; les e-mails déjà reçus ne sont pas modifiés et aucun renvoi rétroactif n’est déclenché. Les photos des inscriptions précédentes restent consultables dans Commande WhatsApp → Marchands → Voir le dossier de la boutique.

Vérifications locales : syntaxe des 13 fichiers PHP pour PHP 7.4 ; 23 scénarios PHP 8.3 WebAssembly avec requêtes multipart réelles et services WordPress simulés, dont cinq aperçus et cinq liens originaux, présence de toutes les données hors mot de passe, texte échappé, repli sur la photo originale sans miniature, réseaux absents et échecs du transport. Aucun e-mail réel envoyé ni affichage vérifié dans Gmail/Outlook ou sur Kinbrique. Tester une nouvelle inscription après installation.

## Modification 3.0.6 — présentation obligatoire et galerie unique

Cette version remplace les règles facultatives de la 3.0.5 : « Dites-nous en plus sur votre activité » devient obligatoire (3000 caractères maximum), ainsi que les photos de la boutique. Un seul champ « Photos extérieures et intérieures de la boutique » permet de sélectionner de 1 à 5 images. JPEG, PNG ou WebP, 5 Mo et 25 mégapixels maximum par fichier. Facebook et Instagram restent facultatifs. Les obligations et limites sont vérifiées côté serveur, même si la validation du navigateur est contournée.

Le même champ est utilisé dans Ma boutique. Les photos déjà enregistrées, y compris dans les deux anciens champs de la 3.0.5, restent visibles et satisfont l’obligation sans nouvel envoi. Une nouvelle sélection remplace entièrement la galerie du dossier ; ne rien sélectionner conserve la galerie actuelle. Les anciennes images restent en médiathèque. Un ancien compte sans présentation ou sans photo doit compléter ces informations lorsqu’il enregistre Ma boutique ; la mise à jour ne suspend pas son compte ni ses produits.

Le dossier de l’administrateur affiche toutes les photos de la galerie. Les contrôles de fichiers, le nettoyage après échec d’envoi ou inscription refusée, les notifications administrateur et marchand et le retrait du bouton gris sont conservés.

Installation : remplacer l’extension actuelle avec le ZIP 3.0.6 et vider le cache. Tester une inscription en navigation privée : le formulaire doit afficher un seul champ photo multiple ; une inscription sans présentation ou sans photo doit être refusée, et une inscription avec une à cinq photos doit enregistrer le dossier. L’hébergement doit autoriser des envois correspondant aux fichiers choisis : pour cinq photos de 5 Mo, prévoir une limite PHP post_max_size supérieure à 25 Mo, upload_max_filesize d’au moins 5 Mo et max_file_uploads d’au moins 5. Le plugin ne modifie pas ces limites serveur.

Vérifications locales : syntaxe des 13 fichiers PHP validée pour PHP 7.4 ; 17 scénarios sous PHP 8.3 WebAssembly avec requêtes HTTP multipart réelles et stockage WordPress simulé. Couverture : absence de présentation/photo, présentation vide après nettoyage, 1/5/6 photos, taille supérieure à 5 Mo, faux PNG, extension exécutable, nettoyage après échec du deuxième stockage ou doublon de compte, forme HTML obligatoire, anciens médias conservés, remplacement de galerie, profils sans photo et validation des réseaux sociaux. Pas de test effectué sur le WordPress réel de Kinbrique.

## Modification 3.0.5 — dossier boutique et notification d’approbation

Le formulaire d’inscription ajoute cinq champs : photo extérieure, photo intérieure, « Dites-nous en plus sur votre activité » (3000 caractères maximum), lien Facebook et lien Instagram. Les liens des comptes ou pages sont facultatifs, contrôlés côté serveur et doivent commencer par http:// ou https:// sur le domaine du réseau concerné. Leur existence ou leur appartenance au marchand ne sont pas vérifiées automatiquement. Les photos et la présentation sont également facultatives, pour permettre les boutiques sans local physique ; les six champs obligatoires de la version 3.0.2 restent inchangés.

Les nouvelles informations sont enregistrées avec le compte, consultables par l’administrateur via « Voir le dossier de la boutique » dans Commande WhatsApp → Marchands et dans le profil utilisateur. Le marchand approuvé peut les modifier dans Ma boutique, y compris remplacer chaque photo. Les anciens comptes n’ont aucune obligation de les compléter. Le dossier n’est pas ajouté aux fiches produit publiques.

Photos : une image par champ, JPEG/PNG/WebP, au plus 5 Mo et 25 mégapixels chacune. Vérification du véritable téléversement HTTP, de l’extension et du contenu image. Aucun identifiant de média fourni par le navigateur n’est accepté. Les deux fichiers sont validés avant stockage ; les nouvelles pièces déjà stockées sont supprimées si le deuxième envoi ou la création du compte échoue. Les photos utilisent la médiathèque WordPress : leurs URL directes peuvent être publiques. Ne pas y mettre de pièces d’identité ni de documents confidentiels. Lors d’un remplacement, l’ancienne photo reste dans la médiathèque mais n’est plus référencée dans le dossier.

Lors du passage effectif d’un compte en attente ou suspendu au rôle Marchand, le marchand reçoit un e-mail HTML contenant sa boutique, le nom du site, un bouton vers la page Espace marchand configurée et un rappel de connexion par e-mail et mot de passe choisi à l’inscription. Le mot de passe n’est jamais inclus. L’envoi est déclenché par le changement de rôle WordPress, depuis la liste des marchands ou le profil utilisateur. Conserver le même rôle approuvé ne renvoie pas l’e-mail ; une réactivation après suspension envoie une nouvelle notification. Aucun envoi rétroactif aux marchands déjà approuvés lors de la mise à jour. L’envoi de la notification administrateur à l’inscription reste actif et inclut la présentation et les liens sociaux.

Un échec de transport de l’e-mail d’approbation n’annule pas l’approbation : l’administration affiche un avertissement. Aucun renvoi automatique n’est prévu. Comme pour les notifications d’inscription, « transmis » signifie accepté par le système d’envoi, sans preuve de réception. Vérifier que la page Espace marchand est publiée et configurée avant l’approbation.

### Installation et vérification 3.0.5

Téléverser ce ZIP pour remplacer l’extension actuelle, puis vider le cache. En navigation privée, inscrire un marchand de test avec deux photos et des liens sociaux ; consulter le dossier dans l’administration, approuver et vérifier la réception du mail et son bouton. Tester ensuite la modification des informations et des photos dans Ma boutique. Le bouton gris retiré en 3.0.4 reste supprimé.

Contrôles locaux : syntaxe des 13 fichiers PHP compatible PHP 7.4 ; 88 vérifications sous PHP 8.3 WebAssembly avec frontières WordPress/WooCommerce simulées (régressions précédentes, formulaires, persistance des nouveaux champs, validation des liens, refus des fichiers invalides, notification et échecs d’envoi). Quatre scénarios supplémentaires utilisent de vrais corps HTTP multipart et les contrôles natifs PHP de téléversement/image, avec stockage WordPress simulé : deux photos acceptées, nettoyage après échec de la seconde, faux PNG refusé, extension exécutable refusée. Aucun e-mail réel envoyé ; aucune validation du thème, du stockage WordPress réel ni de la réception sur Kinbrique effectuée.

## Modification 3.0.4 — retrait du bouton gris en doublon

Le portail marchand n’ajoute plus automatiquement le bouton « Commander sur WhatsApp » dans le résumé de la fiche produit. Sur Kinbrique, le bouton vert existant « Commandez via WhatsApp » reste en place. Le mécanisme d’interception des boutons existants, le formulaire et l’enregistrement des commandes restent inchangés, ainsi que les notifications d’inscription de la version 3.0.3.

Cette version utilise le bouton WhatsApp déjà présent dans le thème ou une autre extension. Sur un site sans bouton existant, le shortcode `[cswa_button]` reste disponible pour en placer un manuellement.

Installation : téléverser le ZIP 3.0.4 et remplacer l’extension actuelle, puis vider le cache du site et du navigateur. Vérifier sur une fiche marchand qu’un seul bouton WhatsApp est affiché et que le bouton vert ouvre le formulaire. Le contrôle sur le site réel reste à effectuer.

## Modification 3.0.3 — notification des inscriptions marchands

Après chaque inscription réussie depuis le formulaire du plugin, un e-mail est automatiquement transmis au système d’envoi WordPress. Il contient le nom du site, la boutique, le propriétaire, l’activité ou les produits vendus, l’adresse e-mail du vendeur, son numéro WhatsApp et un lien vers Commande WhatsApp → Marchands. Aucun mot de passe n’est inclus. Le lien ouvre la liste des marchands : il n’approuve aucun compte automatiquement. L’approbation reste réservée à l’administrateur connecté.

**Destinataire :** dans Commande WhatsApp → Réglages, renseigner « E-mail de notification » (anciennement « Email d alerte »). La valeur déjà configurée est conservée. Si le champ est vide ou invalide, l’adresse d’administration définie dans Réglages → Général de WordPress est utilisée. Ce champ continue aussi de recevoir les alertes de synchronisation Google.

Les inscriptions invalides ou les tentatives avec un e-mail déjà utilisé ne génèrent pas cette notification. La mise à jour n’envoie pas de notifications rétroactives pour les comptes existants. Si WordPress signale un échec d’envoi, le compte reste créé et en attente ; une indication « Notification e-mail non transmise » apparaît dans la ligne du marchand. Il n’y a pas de renvoi automatique dans cette version.

L’envoi utilise `wp_mail` et la configuration de messagerie déjà présente sur le site. Un retour positif confirme la prise en charge par le système d’envoi, pas la réception en boîte de réception. Tester avec une nouvelle inscription après installation et vérifier la réception, y compris les indésirables. Aucun e-mail réel n’a été envoyé pendant les vérifications locales.

**Installation :** téléverser le ZIP 3.0.3 et remplacer l’extension actuelle, puis vider le cache. Les changements des versions précédentes sont conservés.

**Vérifications locales 3.0.3 :** 53 vérifications ciblées sous PHP 8.3 WebAssembly avec WordPress/WooCommerce et l’envoi e-mail simulés, dont les 37 vérifications précédentes. Couverture ajoutée : destinataire configuré et repli WordPress, contenu sans mot de passe, absence de notification pour inscription invalide/doublon, échec et exception du transport sans perte du compte, avertissement administrateur. Les 13 fichiers PHP passent le contrôle de syntaxe PHP 7.4. Aucune réception réelle ni installation sur un site client n’a été vérifiée.

## Modifications 3.0.2 — espace marchand et messages

- **Mes produits** : colonne « Lien produit » avec « Voir le produit » ouvrant la fiche publiée dans un nouvel onglet. Pour un brouillon ou une fiche en attente, le lien devient disponible après publication.
- **Messages WhatsApp reçus par le marchand** : suppression du numéro de commande et de la référence technique de la demande, dans le message serveur et l’aperçu navigateur. Les identifiants restent enregistrés dans WooCommerce et le journal de synchronisation. Le repère de livraison saisi par le client reste dans le message.
- **Mes commandes** : sélecteur de statut sur chaque ligne, puis bouton « Enregistrer ». Les transitions existantes restent contrôlées côté serveur ; un vendeur ne peut modifier que ses propres commandes. Les commandes livrées ou annulées ne proposent plus de changement. Un échec de sauvegarde est signalé au lieu d’afficher un succès.
- **Inscription** : six champs visibles — nom de la boutique, adresse e-mail utilisée pour se connecter, nom du propriétaire, activité ou produits vendus en texte libre, numéro WhatsApp et mot de passe (12 caractères minimum). L’approbation administrateur reste obligatoire. Aucun identifiant séparé ni confirmation du mot de passe n’est demandé.
- **Informations marchand** : propriétaire et activité affichés dans la liste des marchands de l’administrateur et dans « Ma boutique ». Ils sont aussi modifiables dans le profil WordPress. Pour les comptes existants, ces nouveaux champs restent facultatifs lors de la modification du profil ; leurs anciens identifiants de connexion restent utilisables.

### Installation et essai de la 3.0.2

Téléverser le ZIP dans Extensions → Ajouter une extension → Téléverser, puis remplacer l’extension actuelle. Le dossier du plugin reste `congo-shop-whatsapp-order`. Les comptes, réglages, identifiants Google, produits et commandes existants sont conservés ; aucune migration de base supplémentaire n’est requise.

Vider le cache après mise à jour. La page utilisant `[cswa_vendor_dashboard]` affiche automatiquement le nouveau formulaire aux visiteurs déconnectés. Conserver cette page hors cache.

1. En navigation privée, inscrire un compte de test avec les six champs ; vérifier les informations dans Commande WhatsApp → Marchands et approuver le compte.
2. Se connecter avec son e-mail, ouvrir « Mes produits » et vérifier le lien d’une fiche publiée.
3. En mode test, créer une demande et vérifier que l’aperçu du message ne contient ni numéro de commande ni référence technique.
4. Dans « Mes commandes », choisir « confirmée » puis « Enregistrer ». Vérifier le même statut dans WooCommerce. L’annulation reste possible avant livraison.

Les statuts restent gérés dans WooCommerce ; Google Sheets conserve le fonctionnement de journal de création décrit plus bas, sans synchronisation des changements de statut.

### Vérifications locales 3.0.2

Syntaxe des 13 fichiers PHP vérifiée avec un analyseur configuré pour PHP 7.4 ; syntaxe JavaScript vérifiée avec Node. 37 vérifications ciblées exécutées sous PHP 8.3 WebAssembly, avec les fonctions WordPress/WooCommerce simulées : champs, validation des inscriptions, rôle en attente, accès aux commandes entre vendeurs, transitions, échec de sauvegarde, retrait des identifiants du message et rendu des modèles. Le rendu HTML des modèles a été contrôlé ; la vérification visuelle dans Chromium n’a pas pu être exécutée, son téléchargement ayant échoué. Ces vérifications ne remplacent pas un test d’intégration sur le WordPress réel, MySQL/MariaDB, Google Sheets et le thème du site.

Le correctif du sous-menu Marchands de la version 3.0.1 est inclus. Les trois points de la revue initiale (restriction administrateur sur l’API, mode test avec cache, routage historique des produits non attribués) ne font pas partie des quatre modifications demandées ici et restent ouverts.

## Correctif 3.0.1 — accès au menu Marchands

Le sous-menu Marchands est maintenant enregistré après son menu parent Commande WhatsApp (priorité 20 au lieu de 10). Cela corrige l'association de la page d'administration à son gestionnaire WordPress. L'adresse attendue est `/wp-admin/admin.php?page=cswa-vendors`.

Ce correctif ne modifie ni les réglages ni les commandes ni la synchronisation Google. Remplacer la version installée en téléversant le ZIP dans Extensions → Ajouter une extension, puis confirmer le remplacement. Vérifier ensuite les pages Commande WhatsApp et Marchands avec un compte administrateur.

Vérification du correctif : revue de l'ordre des hooks et du mécanisme WordPress, comparaison des fichiers et contrôle de l'archive. PHP et WordPress ne sont pas disponibles dans l'environnement de cette correction ; aucun test sur Kinbrique n'a été effectué. Les résultats d'intégration ci-dessous concernent la version 3.0.0 et proviennent du guide fourni. Les autres points signalés dans la revue 3.0.0 restent à traiter.

## Base fonctionnelle 3.0.0

Version de test du 14 octobre 2026. Basée sur le ZIP 2.2.3 fourni. Cette version ajoute les commandes WooCommerce et un espace marchand léger, sans Dokan.

## Deux modes dans le même plugin

| Réglage | Boutique unique : iPhone-Kin | Multimarchand : Congo Shop |
|---|---|---|
| Mode du site | Boutique unique | Marketplace / multimarchand |
| Espace marchand | Désactivé (ignoré dans ce mode) | À activer explicitement |
| Destination WhatsApp | Numéro configuré du site | Numéro du compte vendeur pour les produits attribués |
| Commandes | Toutes dans WooCommerce, gestion par le site | Toutes chez l’administrateur, uniquement les siennes chez chaque vendeur |
| Google Sheets | Journal existant conservé | Journal existant conservé |

Les réglages, les identifiants Google et les demandes historiques ne sont pas effacés lors de la mise à jour. L’espace marchand est désactivé par défaut, même sur une marketplace. Aucune page publique n’est créée sans action de l’administrateur.

## Installation de test

1. Utiliser d’abord une copie de préproduction avec sauvegarde des fichiers et de la base. WooCommerce 8.2 minimum ; PHP 7.4 minimum (la version WooCommerce utilisée peut exiger davantage).
2. Dans Extensions → Ajouter → Téléverser, importer le ZIP et remplacer la version précédente. Le dossier reste `congo-shop-whatsapp-order`.
3. Dans Commande WhatsApp, vérifier le mode du site, le nom du site et son numéro. Conserver le classeur Google existant.
4. Pendant les essais, garder le mode test activé. Il enregistre de vraies commandes marquées « test », mais n’ouvre pas WhatsApp.
5. Pour les essais visiteurs, décocher « Administrateurs uniquement ». Cette option historique reste inchangée par la mise à jour.
6. Vider les caches de page et d’assets après remplacement. Le nouveau script doit être chargé avec `ver=3.0.3`.

### iPhone-Kin et autres boutiques uniques

Sélectionner « Boutique unique », vérifier le numéro WhatsApp du site, laisser l’espace marchand désactivé. Aucune création de compte vendeur n’est nécessaire. Les nouvelles demandes apparaissent dans WooCommerce → Commandes avec le statut « WhatsApp — à confirmer ».

### Congo Shop et autres sites multimarchands

1. Sélectionner le mode multimarchand et activer « Espace marchand » dans les réglages.
2. Ouvrir Commande WhatsApp → Marchands et cliquer sur « Créer / retrouver la page Espace marchand ».
3. Ajouter cette page au menu du site. Son contenu est `[cswa_vendor_dashboard]`.
4. Le marchand s’inscrit avec le nom de sa boutique, son e-mail de connexion, le nom du propriétaire, son activité ou ses produits vendus, son numéro WhatsApp et son mot de passe. Son compte reste « à approuver ».
5. Approuver le marchand dans Commande WhatsApp → Marchands. On peut aussi le suspendre ensuite. Les comptes ne reçoivent pas les droits administrateur ou gestionnaire WooCommerce.
6. Pour un produit déjà présent : Produits → Modifier → encadré « Marchand WhatsApp » → choisir le compte vendeur. Cette attribution est volontaire : aucun rapprochement automatique par nom ou numéro ne donne accès à des données clients.
7. Les produits créés depuis l’espace vendeur sont automatiquement attribués à leur créateur. Une fois approuvé, il peut les publier directement.
8. Exclure la page de l’espace marchand du cache serveur/CDN. Le plugin envoie des en-têtes de non-cache et le signal WordPress habituel ; une règle CDN qui ignore ces signaux doit être ajustée.

Un produit non attribué garde son routage WhatsApp historique ; sa commande reste visible chez l’administrateur, sans être exposée à un vendeur. Une nouvelle commande pour un vendeur suspendu ou sans numéro valide est refusée. Les commandes déjà reçues restent rattachées au vendeur d’origine, même si le produit change ensuite de propriétaire.

## Espace vendeur

- Vue d’ensemble : demandes à confirmer, confirmées, prêtes et livrées.
- Mes produits : création et modification des produits simples, titre, description, prix normal, catégorie, disponibilité, brouillon/publication.
- Photos : JPEG, PNG ou WebP, 5 maximum par produit, 5 Mo par fichier. Les formats sont contrôlés côté serveur.
- Mes commandes : liste paginée, filtre par statut, coordonnées client, détail et contact WhatsApp.
- Ma boutique : nom et numéro WhatsApp.
- Les produits à variantes restent commandables comme auparavant ; leur édition reste dans l’administration WooCommerce dans cette première version.
- Les promotions et quantités de stock gérées dans WooCommerce sont conservées. Leur configuration avancée reste dans l’administration.

Le bouton de commande est ajouté aux fiches des produits attribués via le hook standard WooCommerce. Pour un modèle Elementor entièrement personnalisé qui omet ce hook, utiliser le shortcode existant `[cswa_button]` dans le modèle.

## Enregistrement des commandes

1. Le serveur valide le produit, le prix, la destination et les coordonnées.
2. Il réserve une référence dans le journal technique `PREFIX_cswa_requests`.
3. Il crée la commande WooCommerce et ses lignes, associe le vendeur et confirme sa sauvegarde.
4. Le navigateur n’ouvre WhatsApp qu’après réception de cette confirmation, avec le message et la référence renvoyés par le serveur.
5. Google Sheets est alimenté en arrière-plan par Action Scheduler, avec reprise WP-Cron.

La table technique reste une file de synchronisation et un dispositif anti-doublon ; elle n’est plus le seul registre de commandes. Les commandes sont de vrais objets WooCommerce, écrits via ses API et consultables avec le stockage classique ou HPOS.

Les réessais d’une même demande réutilisent la même clé. En cas de perte de réponse, le client voit l’erreur et peut réessayer avec les mêmes informations. La création WooCommerce utilise une transaction et un verrou ; une commande interrompue peut être retrouvée par sa référence technique. Les transactions nécessitent des tables transactionnelles, comme InnoDB sur les installations WooCommerce habituelles.

La page ne conserve aucune donnée client dans localStorage. Si le navigateur est fermé avant la confirmation, WhatsApp n’a pas été ouvert par ce parcours. Si le serveur avait déjà enregistré la demande, elle reste dans WooCommerce ; une nouvelle demande volontaire après rechargement peut naturellement créer une autre commande.

## Statuts, paiements et stocks

Les statuts du module sont : à confirmer → confirmée → prête → livrée ; annulation possible avant livraison. Ils suivent le traitement de la demande, sans enregistrer de paiement, déclencher d’e-mail de paiement ou déplacer automatiquement le stock. Le vendeur ne peut pas imposer un statut WooCommerce arbitraire via son formulaire.

Le total initial reprend le montant annoncé sur la fiche produit. Les frais de livraison et la ventilation fiscale ne sont pas calculés par ce formulaire simplifié : l’administrateur doit les vérifier/recalculer dans WooCommerce avant facturation ou passage au parcours de paiement. Une demande de prestation sans prix reste un devis à confirmer, pas une prestation gratuite.

Le plugin ne confirme pas que le message WhatsApp a été envoyé, lu ou payé. Le client doit appuyer sur « Envoyer » dans WhatsApp. Aucun abonnement WhatsApp Business API ni envoi automatique de message n’est ajouté.

L’espace vendeur suit les commandes créées par ce formulaire WhatsApp. Il ne répartit pas les paniers du checkout WooCommerce standard entre plusieurs vendeurs. Les comptes sont propres à chaque site ; aucun portail global entre sites indépendants n’est créé.

## Google Sheets et historique

- La connexion, le classeur et les colonnes existants sont conservés.
- La présence de la référence est vérifiée avant chaque ajout, y compris après interruption d’un processus.
- Les échecs d’API restent en file d’attente. WP-Cron / Action Scheduler doivent fonctionner sur l’hébergement.
- La synchronisation reste un journal de création. Les changements de statut vendeur se consultent dans WooCommerce ; cette version ne remplace pas les statuts saisis manuellement dans Sheets et ne synchronise pas les modifications dans les deux sens.
- Les anciennes demandes 2.x sont conservées et peuvent poursuivre leur synchronisation. Elles ne sont pas converties en masse en commandes WooCommerce ni attribuées automatiquement aux nouveaux comptes.

## Vérification locale réalisée

WordPress 7.1.3, WooCommerce 11.2.1, PHP 8.4.1 et base SQLite de test via l’intégration WordPress. Les parcours ont été exécutés séparément en stockage classique et HPOS. Les API Google ont été simulées pour reproduire les erreurs et interruptions ; aucune connexion à un classeur réel ni installation sur un site client n’a été effectuée.

Tests : inscription en attente, publication après approbation, refus des accès entre vendeurs, destination protégée contre un numéro de bouton modifié, suspension, commandes réelles, reprise idempotente, récupération après interruption, vues du tableau de bord, boutique unique, verrous, blocage WhatsApp sans confirmation, mode test, panne Google et déduplication.

Résultat : 41 vérifications d’intégration réussies dans chacun des deux modes de stockage, 6 vérifications du navigateur simulé et 8 scénarios de synchronisation Google. Une exception injectée pendant l’écriture d’une ligne de commande provoque bien une erreur visible, un retour arrière de la transaction et une reprise sans doublon. Les exceptions de sauvegarde WooCommerce sont propagées au traitement de la demande pour empêcher une confirmation prématurée.

Le navigateur local a également servi à vérifier la connexion, la publication d’un produit avec photo, la création d’une commande par le formulaire et son apparition dans le compte marchand.

Avant mise en production, valider sur une copie du site réel le thème, Elementor, les plugins de numéro WhatsApp, MySQL/MariaDB, les caches, l’authentification Google et les tâches planifiées. Le ZIP livré est une version de test, pas une validation du fonctionnement sur les hébergements clients.
