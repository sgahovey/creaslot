# Les six familles de contraintes

Aide-mémoire de soutenance. Objectif : pouvoir répondre trente secondes sur n'importe
laquelle des six familles, pas réciter un chapitre.

**Source** : dossier CreaSlot, section **5.2.1 Les contraintes**, tableau 5.1. Le texte des
exigences ci-dessous est repris **mot pour mot** du dossier. Ce qui l'accompagne, sous
« traduction » et « limite », vient du dépôt et n'était pas dans le dossier.

Phrase d'introduction du dossier, verbatim :

> Six familles de contraintes encadrent la conception. Elles sont énoncées ici sous forme
> d'exigences vérifiables, chacune trouvant sa traduction dans les sous-parties qui suivent.

**Deux points de vocabulaire pour l'oral.** Le dossier écrit ces exigences **au futur**,
« couvrira », « devra garantir », « fonctionnera », parce qu'elles ont été posées avant la
conception. À l'oral, les énoncer au passé composé : ce qui était une exigence est devenu
un fait. Et la sécurité n'est pas une famille comme les autres, le dossier la dit
**transverse** : c'est la réponse si on demande pourquoi six et pas cinq.

---

## 1. Fonctionnelles

> Trois catégories d'utilisateurs aux droits distincts, l'auditeur, le personnel et le
> super-administrateur, selon une hiérarchie où chaque rôle englobe le précédent.
> Publication de créneaux selon trois modalités, présentiel, visioconférence ou téléphone.
> Réservation puis annulation par l'auditeur. Le cœur du système sera la réservation, qui
> devra garantir qu'un créneau ne reçoive jamais deux réservations actives simultanées,
> deux auditeurs pouvant le viser au même instant.

**Traduction dans le projet**

- Les trois rôles sont un type énuméré, `src/Enum/RoleUtilisateur.php`. La hiérarchie est
  déclarée dans `config/packages/security.yaml` : `ROLE_SUPER_ADMIN: [ROLE_PERSONNEL, ROLE_AUDITEUR]`.
- Les trois modalités sont une **donnée** et non du code : table `type_rdv`, codes
  `PRESENTIEL`, `VISIO`, `TELEPHONE`. On ajoute un type sans toucher au code de présentation.
- L'invariant est tenu dans `src/Service/ReservationService.php` : transaction, verrou
  `PESSIMISTIC_WRITE`, puis **revérification de la disponibilité une fois le verrou obtenu**.
  C'est la revérification qui garantit l'invariant, pas le contrôle préalable.

**Si on creuse** : c'est la famille qui porte le point le plus difficile du projet. Deux
tests figent le cycle complet, en intégration pour l'accès concurrent et en parcours pour
le cheminement de l'auditeur.

---

## 2. Conformité

> Respect du règlement sur la protection des données dès la conception : accès de chacun à
> ses données, portabilité par export, effacement. Base légale tirée de l'exécution du
> contrat de formation, ce qui justifiera l'activation par défaut de certaines
> notifications avec possibilité de refus. Aucune donnée sensible collectée, durée de
> conservation des traces limitée, minimisation guidant le modèle. Conformité visée au
> référentiel d'accessibilité applicable aux services publics, par balisage sémantique,
> contrastes maîtrisés et navigation au clavier, sans audit formel à ce stade.

**Traduction dans le projet**

- Accès, export et effacement sont trois parcours self-service livrés, US-5.6 et US-12.3.
- La **base légale** justifie l'activation par défaut des notifications avec possibilité de
  refus : c'est l'écran des préférences, US-4.8.
- Minimisation : adresse, nom, prénom. Téléphone et adresse postale écartés faute de
  finalité. Collecter par précaution serait une sur-collecte.

**Attention à un décalage** : le dossier range **l'accessibilité dans cette famille**, alors
que la diapositive 8 la présente ailleurs. Si un jury suit le dossier, c'est ici qu'il
l'attend.

**Limite assumée** : « sans audit formel à ce stade ». Le contraste a été mesuré et corrigé,
44 couples recalculés, mais ce n'est **qu'un critère du RGAA parmi une centaine**. Il ne
faut pas laisser croire à un audit complet.

---

## 3. Sécurité

> Contrainte transverse à toute la conception, appuyée sur des référentiels publics de
> risques applicatifs et sur les recommandations de l'agence nationale compétente. Couvrira
> l'authentification, l'autorisation par ressource, la protection contre les vulnérabilités
> les plus courantes et le stockage des secrets hors du code.

**Traduction dans le projet**

- Le référentiel public de risques applicatifs se traduit par un audit versionné,
  `docs/audit-securite-owasp.md`, une catégorie par ligne avec son traitement.
- **L'autorisation par ressource**, ce sont les trois Voters : `CreneauVoter`,
  `ReservationVoter`, `UtilisateurVoter`. C'est le seul niveau capable de dire « cette
  réservation oui, celle-là non », qu'un simple contrôle de rôle ne peut pas exprimer.
- Secrets hors du code : `.env.local` et `.env.*.local` sont ignorés par `.gitignore`.
  Aucun secret n'est versionné.

**Si on creuse** : la porte `composer audit` en intégration continue empêche de
réintroduire une dépendance vulnérable. La différence entre corriger un défaut et rendre
le défaut impossible est ce que le projet a le plus appris.

---

## 4. Techniques transverses

> Interface adaptée à son support, ordinateur comme mobile. Architecture en couches
> séparant présentation, contrôle des requêtes, logique métier, persistance et
> infrastructure d'exécution, la sécurité les traversant toutes.

**Traduction dans le projet**

- Les couches existent physiquement dans l'arborescence : `src/Controller` pour le contrôle
  des requêtes, `src/Service` pour la logique métier, `src/Repository` et `src/Entity` pour
  la persistance, `templates/` pour la présentation.
- L'adaptation au support est une **grille qui passe de trois colonnes à une seule**, sans
  version mobile séparée. C'est la même page.
- Les maquettes mobiles le montrent dès la conception, `docs/realisation/maquettes-hifi/mobile/`.

**Limite à connaître** : c'est la famille **la plus brève du tableau**, deux phrases. Si un
jury creuse, il y a plus à dire que le dossier n'en écrit, ce qui est un avantage et non
un manque.

---

## 5. Contexte

> Projet mené en autonomie, du recueil au déploiement. L'application fonctionnera sur des
> données de démonstration, sans intégration au système d'information officiel du centre,
> évolution envisagée hors périmètre de cette version.

**Traduction dans le projet**

- Autonomie complète : du recueil par entretiens semi-directifs jusqu'au déploiement sur
  un serveur réel, en passant par la chaîne d'intégration.
- Données de démonstration : `src/DataFixtures/`, plus un équivalent SQL pour la
  préproduction, l'image de préproduction étant construite sans les dépendances de
  développement.
- Aucune intégration au système d'information du centre : c'est une **exclusion motivée**,
  pas un manque.

**Comment la retourner** : cette famille énonce des limites plutôt que des réalisations,
c'est la plus inconfortable à l'oral. Mais l'autonomie complète du recueil au déploiement
est exactement ce que le référentiel demande de démontrer. L'absence d'intégration au
système d'information est une décision de périmètre, au même titre que les exclusions de
la diapositive 8, chacune avec son motif.

---

## 6. Éco-conception

> Refus des composants superflus, hébergement local des ressources sans appel à des
> services externes, limitation des journaux techniques, optimisation des requêtes pour
> éviter la multiplication des accès, absence de chaîne de construction lourde pour
> l'interface, minimisation des données collectées.

**Traduction dans le projet**

- **Hébergement local** : huit paquets vendorisés dans `assets/vendor/`, dont la police et
  les icônes. Aucun appel à un service externe au chargement d'une page.
- **Journaux limités** : les journaux Docker sont bornés en production, `max-size: 10m` et
  `max-file: 3` dans `compose.prod.yml`.
- **Aucune chaîne de construction lourde** : AssetMapper et une carte d'imports, pas de
  Node ni de compilateur front.
- **Requêtes optimisées** : cinq index métier, et des jointures explicites pour éviter la
  multiplication des accès.

**Si on creuse** : le typage au plus juste des colonnes est présenté dans le dossier comme
le premier geste d'éco-conception, et la minimisation des données relie cette famille à la
conformité.

---

## Pense-bête, une ligne par famille

| Famille | La phrase à dire si le temps manque |
|---|---|
| Fonctionnelles | Trois rôles hiérarchisés, trois modalités de rendez-vous, et un invariant : jamais deux réservations actives sur un créneau. |
| Conformité | RGPD dès la conception, accès, export, effacement, minimisation, plus l'accessibilité visée sans audit formel. |
| Sécurité | Transverse, pas une famille parmi d'autres : authentification, autorisation par ressource, vulnérabilités courantes, secrets hors du code. |
| Techniques transverses | Interface adaptée au support et architecture en couches, la sécurité les traversant toutes. |
| Contexte | Projet en autonomie du recueil au déploiement, sur données de démonstration, sans intégration au système d'information. |
| Éco-conception | Rien de superflu, ressources hébergées localement, journaux bornés, requêtes optimisées, aucune chaîne de construction. |
