# Version 3.0.15 — Résolution des attributions OneClick

- Lecture de toutes les entrées publiées correspondant au titre attribué.
- Les doublons sont acceptés uniquement si tous leurs numéros sont valides et identiques après normalisation.
- Une entrée vide, invalide ou contradictoire bloque la destination sans repli silencieux.
- Le nom privilégie le titre correspondant exactement à celui du produit.
- Priorités existantes et données conservées ; aucune modification des entrées OneClick ou des attributions.
- Tests WordPress/MySQL et WooCommerce : doublons, formats, ambiguïtés, priorités, formulaire et commande persistée. Les tests Google existants utilisent un transport simulé ; ils ne valident pas une livraison sur Google en production.
