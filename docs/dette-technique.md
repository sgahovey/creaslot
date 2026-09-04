# Dette technique CreaSlot — Suivi

Date dernière mise à jour : 04/09/2026.
Convention : DT-N = Dette Technique numéro N.

---

## DT-1 — Architecture OneToOne Creneau↔Reservation (🔴 CRITIQUE) — ✅ RÉSOLUE 27/05/2026

> **✅ RÉSOLUE le 27/05/2026** sur branche `bugfix/reservation-onetomany-creneau`.
>
> **Résumé fix** : Refacto vers OneToMany (Stratégie S4 retenue). Migration Doctrine `Version20260527155759` drop l'index UNIQUE sur `reservation.id_creneau` et le remplace par un index non-unique. L'invariant "1 Reservation ACTIVE max par Creneau" est désormais garanti applicatif via le `PESSIMISTIC_WRITE` dans `ReservationService::reserver`.
>
> **Validations** :
> - ✅ 66/66 tests verts (65 existants + 1 nouveau test d'intégration `tests/Integration/ReservationRereservationApresAnnulationTest.php` qui fige la non-régression)
> - ✅ Smoke E2E manuel : Auditeur réserve → annule → re-réserve un créneau, le scénario qui causait HTTP 500 fonctionne désormais
>
> **Sous-correctifs notables** :
> - R7 (`CreneauRepository::findDisponibles`) : conversion `LEFT JOIN + (r.id IS NULL OR r.statut != ACTIVE)` en `NOT EXISTS` (anti-régression OneToMany — sans cela, un créneau avec `[ACTIVE + ANNULEE]` apparaîtrait à tort disponible)
> - Refacto signature `NotificationService::notifierAuditeurSuppressionCreneau(Creneau $c, Reservation $r)` : élimine le workaround documenté en PHPDoc (passage explicite de la Reservation par le caller, post-annulation)
> - Premier test d'intégration `KernelTestCase` du projet — pattern réutilisable pour futurs cas (a généré [[DT-6]])
> - Cleanup `findDisponiblesParUtilisateur` (méthode morte 0 consommateur)
>
> **Fichiers impactés** : 9 fichiers modifiés + 1 migration + 1 test d'intégration créé.

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 18/05/2026, lors validation US-4.6 (smoke test cron rappel J-1).

**Symptôme** :
- Erreur HTTP 500 "Duplicate entry '38' for key 'reservation.UNIQ_42C8495527FB222F'"
- Cas reproductible : annuler une réservation puis tenter d'en re-créer une sur le même créneau côté Auditeur

**Cause racine** :
- `Reservation::$creneau` est en `OneToOne(unique: true)` (cf. Entity/Reservation.php L21-23)
- L'annulation est un soft-delete (statut → ANNULEE, dateAnnulation/motifAnnulation peuplés)
- La ligne reste en BDD → impossible d'insérer une 2e Reservation sur le même créneau

**Incohérence design** :
- Intention métier (champs dateAnnulation/motifAnnulation) = "préserver historique"
- Contrainte schéma OneToOne = "1 seule Reservation par Creneau, à vie"

**Stratégie de fix retenue** : S4 — Migration vers OneToMany
- `Reservation::$creneau` → ManyToOne (drop unique sur join column)
- `Creneau::$reservation` → Collection au lieu de ?Reservation
- Unicité de la Reservation ACTIVE garantie applicatif via PESSIMISTIC_WRITE déjà en place

**Branche prévue** : `bugfix/reservation-onetomany-creneau` (sortie develop frais)
**Effort estimé** : 3-4h sur session dédiée
**Priorité** : 🔴 haute, à traiter APRÈS merge US-4.6 et AVANT US-4.7

---

## DT-2 — Validation horaire créneau manquante (🔴 ÉLEVÉ) — ✅ RÉSOLUE 28/05/2026

> **✅ RÉSOLUE le 28/05/2026** sur branche `bugfix/validation-horaire-creneau`.
>
> **Résumé fix** : Defense in depth 3 niveaux pour garantir `dateFin > dateDebut` :
> - **Niveau 2 (fix principal, serveur)** : extension `CreneauType::validerCoherenceHoraires` (hook POST_SUBMIT) — check `heureFin > heureDebut` strict (A1) en mode "Personnalisée".
> - **Niveau 1 (UX)** : HTML5 `min` dynamique JS sur l'input heureFin (synchronisé sur `change` de heureDebut) dans `nouveau.html.twig` + `modifier.html.twig`.
> - **Niveau 3 (filet documenté)** : `#[Assert\Callback] validerHoraires()` sur l'Entity `Creneau` (dateFin > dateDebut).
>
> **Subtilité architecturale documentée** : les champs date/heure du Form sont en `mapped:false` ; le Controller assemble dateDebut/dateFin APRÈS `$form->isValid()`. Conséquence : le niveau 3 n'est PAS déclenché par le flux form normal (filet dormant pour les voies non-form : API/console futures). Choix assumé : pas de `$validator->validate($creneau)` explicite dans le Controller pour éviter la duplication avec le niveau 2.
>
> **Validations** :
> - ✅ 79/79 tests verts (66 baseline + 3 D1 Form + 3 D3 Entity + 7 intégration DQL)
> - ✅ Smoke E2E manuel : cas 10h00→02h00 rejeté avec message exact ; cas 10h00→11h00 accepté
>
> **Co-correctif embarqué (hotfix DT-1 résiduel)** : 5 requêtes DQL de `CreneauRepository` référençaient encore `c.reservation` (singulier, association supprimée par le refacto OneToMany [[DT-1]]) → corrigées en `c.reservations`. HTTP 500 « has no association named reservation » détecté en E2E. Faille de couverture fermée par un test d'intégration dédié (`CreneauRepositoryQueriesTest`, 7 tests sur 8 méthodes DQL).
>
> **Fichiers impactés** : `CreneauType`, `Creneau`, 2 templates, `CreneauRepository` (hotfix) + 3 fichiers de tests.

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 18/05/2026, lors validation visuelle email US-4.6.

**Symptôme** : Email rappel J-1 affichait "Horaire : 10h00 – 02h00" pour un créneau test.

**Cause racine précisée par user** :
Le formulaire de création de créneau (option "Personnaliser l'heure de fin", probablement dans `CreneauType`) accepte une heure de fin antérieure à l'heure de début sans validation.

**Reproduction** :
1. Connexion Personnel
2. Créer un créneau
3. Cocher "Personnaliser l'heure de fin"
4. Saisir une heure < heure de début (ex : début 10h00, fin 02h00)
5. ✅ Soumission acceptée → créneau incohérent en BDD

**Impact** :
- Créneau incohérent affiché dans agendas (auditeur + personnel)
- Emails (US-4.2 à US-4.6) reproduisent l'absurdité
- N'importe quel Personnel peut produire ce bug en production

**Stratégie de fix proposée** : Defense in depth (3 niveaux)

1. **Niveau UI (HTML5)** : `<input type="time" min="...">` sur le champ heure de fin
2. **Niveau Symfony Form** : Contrainte `Callback` dans `CreneauType` comparant dateDebut/dateFin
3. **Niveau Entité** : `#[Assert\Callback]` sur `Creneau::validerHoraires()` 

Recommandation : appliquer les 3 niveaux (defense in depth, best practice Symfony).

**Branche prévue** : `bugfix/validation-horaire-creneau` (sortie develop frais)
**Effort estimé** : ~1h
**Priorité** : 🔴 élevée (UX prod), à traiter idéalement avec DT-1 (même domaine entity Creneau)

---

## DT-3 — PHPUnit Notices willReturnCallback (🟢 BAS) — ✅ RÉSOLUE 29/05/2026

> **✅ RÉSOLUE le 29/05/2026 (US-4.8)** : 30 notices → 0. La suite tourne désormais
> sans aucune notice (`phpunit.dist.xml` a `failOnNotice="true"`, donc la suite reste
> verte en intégrant ces tests).
>
> **Cause réelle précisée** : la notice PHPUnit 13 est *« No expectations were
> configured for the mock object ... Consider refactoring your test code to use a
> test stub instead »*. Elle apparaît dès qu'un `createMock()` est utilisé comme
> simple doublure (juste `->method()->willReturn()`) sans `->expects()`.
>
> **Solution retenue** : les helpers partagés (`repository`, `logger`) sont créés une
> fois en `setUp()` mais utilisés tantôt comme mocks (`->expects()`), tantôt comme
> stubs selon le test — le pattern `createStub()` par doublure n'était donc pas
> applicable sans dupliquer le `setUp()`. On a opté pour l'opt-out explicite et
> documenté `#[AllowMockObjectsWithoutExpectations]` au niveau classe, appliqué à
> `NotificationServiceTest` (US-4.7, 12 notices) puis `SlotServiceTest` (US-4.8,
> 18 notices restantes). Le pattern `createStub()` reste en vigueur pour les nouveaux
> tests à doublure unique (cf. `tests/Twig/NotificationExtensionTest.php`).
>
> **Validation** : suite complète verte, **0 notice** (90 tests).

**Détecté** : 18/05/2026, baseline US-4.2 à US-4.6.

**Symptôme** : 30 PHPUnit Notices à l'exécution (mocks utilisés comme stubs sans expectations).

**Stratégie de fix** : remplacer `createMock()` par `createStub()` pour les doublures sans `->expects()`.

**Priorité** : 🟢 basse, cosmétique, n'impacte pas la production.

---

## DT-4 — Dockerfile USER non-root (🟢 BAS) — ✅ RÉSOLUE (15/06/2026)

> **✅ RÉSOLUE le 15/06/2026** sur branche `feature/US-9.1-image-production`.
>
> **Résumé fix** : DT-4 résolu **à la source**. Le conteneur **DEV** tourne désormais en **uid 1000 (user `app`)** aligné sur l'utilisateur hôte WSL2 → les fichiers créés via bind-mount appartiennent à l'hôte, **plus de fichiers root**, le workaround `chown` disparaît. L'image de **PROD** (`runtime`) tourne **aussi** en non-root (uid 1000). Résolu par le refactor du Dockerfile en **4 stages** (`base`/`build`/`runtime`/`dev`) dans US-9.1 — le stage `base` crée l'utilisateur `app` 1000:1000 (commun à `runtime` et `dev`) et rend `var/cache`/`var/log` accessibles en écriture par `app` (ownership héritée par les volumes nommés du dev). **Commit `46d60c6`**.
>
> **Validations** : `docker compose exec app id` → `uid=1000(app)` ; preuve déterministe → un fichier créé depuis le conteneur via bind-mount appartient à l'utilisateur hôte (1000), pas à root ; dev fonctionnel (`/connexion` 200, composer + phpunit présents, `opcache.validate_timestamps=On`) ; non-régression prod (image `runtime` : `/connexion` 200, exécution non-root, sans NOTICE).

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 18/05/2026, incident permissions Git WSL2.

**Symptôme** : Dossier .git/objects/01/ à root après opérations Docker → "error: insufficient permission".

**Workaround actuel** : `sudo chown -R utilisateur:utilisateur ~/creaslot/.git/` préventif.

**Stratégie de fix** : Dockerfile `USER 1000:1000` pour aligner UID avec WSL2.

**Priorité** : 🟢 basse, à faire avant déploiement prod (itération 6) — close.

---

## DT-5 — `final` retiré de NotificationService pour testabilité (🟢 BAS) — ✅ CLÔTURÉE (DÉCISION) (23/06/2026)

**Détecté** : 19/05/2026, lors écriture EnvoyerRappelsJ1CommandTest.

**Contexte** : NotificationService était initialement déclaré `final readonly class` (best practice Symfony 8). L'écriture du test Command nécessitait de mocker NotificationService → PHPUnit\Framework\MockObject\ClassIsFinalException.

**Choix d'arbitrage** : drop `final`, garder `readonly`. NotificationService n'a pas vocation à être étendu dans l'architecture DI Symfony actuelle, le `final` était cosmétique.

**Alternative considérée** : extraction de `NotificationServiceInterface` (architecture plus propre via Dependency Inversion Principle). Reportée car scope creep par rapport à US-4.6.

**Stratégie future** :
- Quand US-4.7 (page Mes notifications) ou US-4.8 (préférences) sera traitée, envisager l'extraction de l'interface si plusieurs implémentations émergent
- Si pas de besoin futur, garder `readonly class` simple

**Décision** (23/06/2026) : **statu quo formalisé**, aucun changement de code. `NotificationService` reste `readonly class` **sans** `final`. La contrainte d'origine est toujours active et vérifiée : `EnvoyerRappelsJ1CommandTest` mocke le service via `createMock(NotificationService::class)`, et PHPUnit ne peut pas mocker une classe `final`. Remettre `final` exigerait soit l'extraction d'une `NotificationServiceInterface` (Dependency Inversion) — **sur-ingénierie** sans second implémenteur au volume Cnam — soit la réécriture de la stratégie de test (risque de régression pour un gain cosmétique). Le `final` était cosmétique ; son retrait est un choix d'architecture **assumé et documenté**, pas un défaut. À réexaminer uniquement si un second implémenteur de notification émerge.
**Priorité** : 🟢 basse, statu quo acceptable.

---

## DT-6 — Setup BDD test à automatiser (🟢 BAS) — ✅ CLÔTURÉE (DÉCISION) (23/06/2026)

**Détecté** : 27/05/2026, lors mise en place du 1er test d'intégration (cf. [[DT-1]]).

**Contexte** : La création de la BDD `creaslot_test` + GRANT user est actuellement manuelle one-shot, à rejouer après chaque `docker compose down -v` ou sur tout nouveau clone du repo :

```sql
CREATE DATABASE IF NOT EXISTS creaslot_test;
GRANT ALL PRIVILEGES ON creaslot_test.* TO 'creaslot'@'%';
FLUSH PRIVILEGES;
```

Puis :

```bash
docker compose exec app php bin/console doctrine:migrations:migrate -n --env=test
```

Sans ce setup, tout test d'intégration extending `KernelTestCase` échoue avec « Access denied for user 'creaslot'@'%' to database 'creaslot_test' » (le `dbname_suffix: '_test%env(default::TEST_TOKEN)%'` de `config/packages/doctrine.yaml` sous `when@test` ajoute le suffix `_test` à la BDD).

**Stratégie de fix proposée** :

- **Option A** : Script `bin/setup-test-db.sh` à exécuter une fois après `git clone`
- **Option B** : Commande Symfony custom `app:setup-test-db` (intégrée dans un `Makefile`)
- **Option C** : `init.sql` exécuté au démarrage du conteneur MySQL (via volume monté dans `/docker-entrypoint-initdb.d/`)

**Recommandation** : Option C (init MySQL au démarrage) — totalement transparent pour le dev, aucune commande supplémentaire à mémoriser. Option B si on veut plus de contrôle (ex : truncate sélectif entre suites).

**Décision** (23/06/2026) : **clôturée — le besoin critique est déjà couvert**. Le pipeline d'intégration continue (`.github/workflows/ci.yml`) provisionne la base de test à chaque exécution : `doctrine:database:create --env=test --if-not-exists`, puis `doctrine:migrations:migrate --env=test`, puis `doctrine:fixtures:load --env=test`. Les tests d'intégration tournent donc de manière reproductible en CI sans intervention manuelle. Le seul reliquat est le **confort du développeur en local** (rejouer les 3 commandes après un `docker compose down -v`), d'impact faible pour un projet solo. L'automatisation locale (`init.sql` ou `bin/setup-test-db.sh`) est **reportée à son déclencheur** : arrivée d'un second développeur sur le projet. Aucun code à ce stade.
**Priorité** : 🟢 basse, à faire avant si plusieurs devs rejoignent le projet OU avant déploiement CI/CD.

---

## DT-7 — Factorisation JS templates créneau (🟢 BAS) — ✅ CLÔTURÉE (DÉCISION) (23/06/2026)

**Détecté** : 28/05/2026, lors du fix [[DT-2]] (niveau 1 UX).

**Contexte** : Le JavaScript des templates `personnel/creneau/nouveau.html.twig` et `personnel/creneau/modifier.html.twig` est dupliqué (mise en valeur TypeRdv, visibilité conditionnelle heureFin, `required` dynamique, et désormais le `min` dynamique DT-2). Avec 2 templates, le DRY n'est pas critique ; mais un 3e point d'entrée (ex : modal de création rapide) ou un besoin de tester le JS rendrait la factorisation utile.

**Stratégie de fix proposée** :

- **Option A** : Fichier asset dédié `assets/js/creneau-form.js` (AssetMapper) importé dans les 2 templates
- **Option B** : Stimulus controller `creneau_form_controller.js` (pattern Symfony moderne, déjà présent dans la stack via StimulusBundle)
- **Option C** : Macro Twig `{% macro creneau_form_js() %}` (inline mais centralisé)

**Recommandation** : Option B (Stimulus) — la stack embarque déjà StimulusBundle + AssetMapper, et c'est testable/réutilisable.

**Décision** (23/06/2026) : **clôturée — factorisation non justifiée à ce jour, en attente du déclencheur**. État vérifié : seuls **deux** templates portent ce JS (`personnel/creneau/nouveau.html.twig` et `personnel/creneau/modifier.html.twig`) ; `agenda.html.twig` et `liste.html.twig` ne sont pas concernés. Avec deux points d'entrée seulement, extraire le JS partagé (Stimulus, asset dédié ou macro) ajouterait une indirection pour un gain de maintenabilité marginal — **optimisation prématurée** écartée (cf. *Coder proprement*, éviter l'abstraction spéculative). La factorisation sera traitée à son **déclencheur** : apparition d'un **3e point d'entrée** (ex. modale de création rapide) ou besoin de **tester ce JS** isolément. Aucun code à ce stade.
**Priorité** : 🟢 basse, à faire si 3e template apparaît OU besoin de tests JS.

---

## DT-8 — Migration FullCalendar CDN vers self-hosted (AssetMapper) (🟡 MOYEN) — ✅ RÉSOLUE 01/06/2026

> **✅ RÉSOLUE le 01/06/2026** (dette technique autonome) sur branche `feat/us-5.1-agenda-fullcalendar`.
>
> **Résumé fix** : L'agenda Personnel ne dépend plus d'un CDN tiers. FullCalendar
> est self-hosté via AssetMapper (bundle global officiel **6.1.20**) et le JS inline
> a été extrait dans un contrôleur Stimulus. La légende des types de RDV est désormais
> rendue dynamiquement depuis les `TypeRdv` en BDD (couleurs `couleur_hex`) au lieu d'être
> codée en dur côté front.
>
> **Validation** : agenda fonctionnel (rendu mois/semaine, locale FR), `WebTestCase`
> couvrant le chargement de la page agenda + endpoints JSON.

**Détecté** : 01/06/2026, lors d'une revue de l'agenda FullCalendar (amélioration de l'agenda livré en US-2.5).

**Contexte** : L'agenda (US-2.5) chargeait FullCalendar **6.1.11** via le CDN jsDelivr,
accompagné d'environ **400 lignes de JavaScript inline** dans le template.

**Problème** :
- Dépendance CDN non maîtrisée : aucun contrôle d'intégrité (pas de SRI), disponibilité
  et version à la merci d'un tiers.
- JavaScript inline incompatible avec une politique CSP stricte (`script-src` sans `unsafe-inline`).
- Aucun suivi de version : la montée de version FullCalendar n'était ni tracée ni reproductible.

**Solution retenue** :
- **Self-hosting via AssetMapper** : vendorisation du bundle global officiel FullCalendar
  **6.1.20** (`index.global.min.js` + locale `fr.global.min.js`) dans `assets/vendor/`.
- **Extraction du JS** en contrôleur Stimulus (`assets/controllers/agenda_controller.js`),
  piloté par attributs `data-*` — plus de JS inline dans le template.
- **Légende dynamique** : les types de RDV et leurs couleurs sont lus depuis les `TypeRdv`
  en BDD au lieu d'être codés en dur côté front.
- Branchement `importmap('app')` dans `base.html.twig`.
- `turbo-core` désactivé dans `controllers.json` (Turbo était déjà inerte car `importmap('app')` n'était pas branché ; désactivation explicite pour garder la migration additive).
- Headers `no-store` (Cache-Control) sur les endpoints JSON de l'agenda pour éviter la
  mise en cache de données dépendantes de l'utilisateur.

**Décision technique (veille)** : l'option ESM jsDelivr (`@fullcalendar/*` + `preact`
éclatés) a été **écartée** car elle dédouble le runtime core de FullCalendar et casse le
rendu (`Class constructor component cannot be invoked without 'new'`). Confirmé par
l'issue FullCalendar **#7474** et la documentation SymfonyCasts. Le **bundle global**
(linking interne cohérent en un seul fichier) a été retenu, conformément à la consigne
FullCalendar de `CLAUDE.md`.

**Montée de version** : FullCalendar **6.1.11 → 6.1.20**.

**Priorité** : 🟡 moyenne (sécurité supply-chain + compatibilité CSP), traitée en dette technique autonome.

---

## DT-9 — Layout email Twig partagé (🟡 MOYEN) — ✅ RÉSOLUE (23/06/2026)

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (Clean Code R.C. Martin + critères CDA).

**Constat** : Les 8 templates d'email (`templates/emails/*.html.twig`) ne partagent aucune factorisation — aucun `{% extends %}` ni `{% include %}`. Chacun (~150 lignes) ré-écrit l'intégralité de la structure HTML : doctype, `<style>` inline, `<table>` de mise en page, en-tête et signature Cnam. Toute évolution de charte (couleur, logo, mention légale RGPD) impose 8 modifications identiques → coût de maintenance et risque de divergence élevés.

**Fichiers concernés** : `templates/emails/*.html.twig` (8 fichiers : confirmation/annulation/modification/rappel auditeur, confirmation/annulation personnel, suppression créneau, test).

**Action proposée** : créer `templates/emails/_layout.html.twig` portant la structure commune (head, styles, en-tête, signature), exposant un `{% block contenu %}` ; chaque email passe à `{% extends 'emails/_layout.html.twig' %}` et ne déclare plus que son contenu propre.

**Résolution** (23/06/2026) : création de `templates/emails/_layout.html.twig` portant la coque HTML commune (doctype, `<head>`, wrapper table centrant, header bleu marine `#1A3E6F` + « CreaSlot », footer Cnam), exposée via les blocs `body_html`, `titre`, `sous_titre` et `contenu`. Les **8 templates métier** passent à `{% extends 'emails/_layout.html.twig' %}` et ne portent plus que leur corps : confirmation/annulation/modification/rappel auditeur, confirmation/annulation personnel, suppression créneau, reset password (ajouté en US-6.2). Migration **incrémentale** : pilote (`reservation_confirmation_auditeur`) validé par envoi réel avant propagation aux 7 autres.

Le sujet reste construit côté PHP (`NotificationService`) : le layout ne porte volontairement pas de `block subject`. L'**asymétrie RGPD** des annulations est préservée — le template auditeur affiche le motif (saisi par l'auditeur lui-même), le template personnel ne le reçoit jamais.

`test.html.twig` reste **volontairement autonome** : email de diagnostic technique avec un `block subject`, un header sans sous-titre et un footer différent (« CreaSlot — Application de gestion des rendez-vous » / « © 2026 Cnam Réunion ») ; l'aligner sur le layout changerait son rendu pour aucun gain.

**Bilan** : `git diff` à +42 / −442 lignes (duplication résorbée), `lint:twig` 10/10, 274 tests verts, rendus confirmés par envois réels (confirmation, annulation auditeur+personnel, reset password). Commit de code : `1042bc6`.
**Priorité** : 🟡 moyenne, à traiter avant l'ajout d'un nouvel email OU avant un changement de charte email.

---

## DT-10 — CollegueService : requêtes en boucle (~3N+1) (🟡 MOYEN) — ✅ RÉSOLUE (14/06/2026)

> **✅ RÉSOLUE le 14/06/2026** sur branche `feature/DT-10-collegue-service-nplus1`.
>
> **Résumé fix** : le `~3N+1` est remplacé par **1 requête de chargement** (`findOtherPersonnel`, inchangée — tri et filtres préservés) **+ 3 agrégats par lot** sur `CreneauRepository`, assemblés par lookup. Nombre de requêtes désormais **constant, indépendant de N**.
> - **Agrégats par lot** (DQL paramétré, `IN (:ids)`, garde `IN` vide → aucune requête) : `findIdsAvecCreneauActifFuturOuEnCours` (visibilité, `DISTINCT IDENTITY`), `findFinsRdvEnCoursParUtilisateur` (statut + heure de fin, `EXISTS` résa ACTIVE), `findProchainsRdvParUtilisateur` (`MIN(dateDebut)` + `GROUP BY`, `EXISTS` résa ACTIVE).
> - **Prédicats répliqués à l'identique** des trois méthodes par ligne d'origine → comportement strictement inchangé (verrouillé par le test de caractérisation).
> - **`EXISTS` sans JOIN** sur `reservations` : aucun fan-out OneToMany [[DT-1]] et **aucune `Reservation` hydratée** (minimisation RGPD préservée).
> - **Code mort supprimé** : `CollegueService::aAuMoinsUnCreneauActif` et `construireDTO` (orphelins après refacto).
>
> **Observation (sans action)** : `creneau.date_fin` n'est pas indexé ; acceptable au volume Cnam (l'index `idx_creneau_utilisateur_debut` couvre déjà le préfixe `id_utilisateur`). Un index sur `date_fin` serait à envisager **si la volumétrie augmente** — pas de migration à ce stade.
>
> **Validations** : test de caractérisation vert **à l'identique** (comportement inchangé) + test compteur de requêtes (nombre **constant** pour N=2 et N=5, data collector Doctrine) ; suite complète verte (268 tests, 0 deprecation/notice/warning), PHPStan 8 = 0, CS-Fixer 0. **Trou de couverture de `CollegueService` fermé** (aucun test ne le couvrait auparavant).
>
> **Commits** : `a68c2f4` (Morceau 1 — test de caractérisation) · `b8c86c6` (Morceau 2 — perf : agrégats par lot + compteur de requêtes) · doc & clôture (Morceau 3).

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (éco-conception / performance).

**Constat** : `CollegueService::getCollegues()` itère sur la liste des collègues et déclenche **trois requêtes par collègue** (`existeCreneauActifFuturOuEnCours`, puis dans `construireDTO` : `findCreneauEnCoursAvecRdv` et `findNextReservedCreneau`), soit ~3N+1 requêtes pour N collègues. Pattern « boucle PHP qui interroge la BDD par ligne » — tolérable pour une petite équipe Cnam, mais contraire à l'éco-conception (RGESN) et non scalable.

**Fichiers concernés** : `src/Service/CollegueService.php` (`getCollegues`, `construireDTO`, `aAuMoinsUnCreneauActif`).

**Action proposée** : remplacer les requêtes par ligne par **une seule requête agrégée** (JOIN + `GROUP BY` sur le Personnel) ramenant statut courant + prochain RDV en un aller-retour, hydratée vers les `CollegueDTO`.

**Priorité** : 🟡 moyenne, à traiter quand la liste des collègues s'allonge OU dans une passe éco-conception (itération 6) — close.

---

## DT-11 — Centraliser le formatage de date d'affichage dans DateFormatterService (🟡 MOYEN) — ✅ RÉSOLUE (23/06/2026)

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (DRY).

**Constat** : `DateFormatterService` (créé pour éliminer la duplication post-US-4.5) n'expose qu'une seule méthode (`pourSujetEmail`). Le reste du formatage de date d'affichage est **dispersé en dur** dans plusieurs fichiers, et `AppEmailTestCommand` **ré-implémente à l'identique** le format de `pourSujetEmail`. Violation directe du « un mot par concept » et de la factorisation déjà amorcée.

**Fichiers concernés** : `src/Service/SlotService.php` (`construireMessageChevauchement` : `d/m/Y`, `H:i`), `src/Service/CollegueService.php` (`H\hi`), `src/Command/EnvoyerRappelsJ1Command.php` (`d/m/Y`), `src/Command/AppEmailTestCommand.php` (ré-implémentation de `d/m/Y \à H\hi`).

**Action proposée** : étendre `DateFormatterService` avec des méthodes centralisées (`pourAffichage` date, `pourHeure`, etc., timezone `Indian/Reunion` uniforme) et router **tout** le formatage d'affichage à travers le service ; supprimer les `->format(...)` en dur.

**Résolution** (23/06/2026) : `DateFormatterService` étendu de trois méthodes d'affichage, calquées sur `pourSujetEmail` (conversion immutable non mutante, timezone `Indian/Reunion` forcée) : `pourDate` (`d/m/Y`), `pourHeure` (`H:i`) et `pourHeureCompacte` (`H\hi`). `pourHeure` et `pourHeureCompacte` coexistent volontairement — elles ne diffèrent que par le séparateur (`:` vs `h`), les deux rendus existant réellement dans l'application.

Quatre sites routent désormais leur formatage d'affichage via le service : `SlotService` (message de chevauchement : `pourDate` + 2× `pourHeure`), `CollegueService` (heure de fin de RDV : `pourHeureCompacte`, `null` préservé), `EnvoyerRappelsJ1Command` (`pourDate`) et `AppEmailTestCommand` (qui réutilise `pourSujetEmail` au lieu de ré-implémenter le format à la main).

**Hors périmètre** : les `format(\DateTimeInterface::ATOM)` des logs de `SlotService::enregistrerChevauchementDetecte` restent inchangés — donnée machine (tri/parsing), pas de l'affichage humain.

**Bilan** : 286 tests verts (12 nouveaux couvrant les 3 méthodes sur 4 angles : conversion UTC→Réunion, stabilité si déjà en Réunion, zéro initial < 10h, compat `\DateTime` mutable), PHPStan 8 = 0, CS-Fixer 0. Rendu identique (tz conteneur = `Indian/Reunion`) ; date du rappel J-1 vérifiée en console (24/06/2026). Commit de code : `8fafb79`.
**Priorité** : 🟡 moyenne, à traiter au prochain ajout d'un format de date OU dans une passe DRY.

---

## DT-12 — NotificationService : factoriser le squelette des 7 méthodes notifier*() (🟡 MOYEN) — ✅ RÉSOLUE (23/06/2026)

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (DRY).

**Constat** : Les six méthodes publiques `notifier*()` partagent un squelette quasi identique répété : extraction `auditeur`/`creneau`/`personnel` (avec le même bloc de commentaire 3 lignes « Reservation::utilisateur = Auditeur… » dupliqué ~5×), puis un `try { envoyer(...) } catch (\Throwable $e) { logger->error(...) }` structurellement identique ×6 (seuls le `type` et les identifiants changent). 683 lignes au total dont une large part redondante.

**Fichiers concernés** : `src/Service/NotificationService.php` (méthodes `notifierAuditeurReservation`, `notifierPersonnelReservation`, `notifierAuditeurAnnulationReservation`, `notifierPersonnelAnnulationReservation`, `notifierAuditeurCommentaireCreneau`, `notifierAuditeurSuppressionCreneau`, `notifierAuditeurRappel`).

**Action proposée** : extraire un helper privé `envoyerOuLoguer(string $type, array $idsContexte, string $to, string $subject, string $template, array $context)` encapsulant le try/catch + log RGPD ; factoriser l'extraction des trois acteurs. Chaque `notifier*()` se réduit alors à : préparer le contexte → (persister notification in-app) → déléguer au helper.

**Résolution** (23/06/2026) : extraction d'un helper privé `envoyerEtTracer(string $to, string $subject, string $template, array $context, string $messageErreur, array $contexteErreur)` encapsulant le bloc `try { envoyer(...) } catch (\Throwable) { logger->error(...) }` dupliqué dans les **7** méthodes `notifier*()`. Le helper **avale** l'exception (politique Option B : le flux métier reste valide si l'email échoue, retry géré par Messenger en async) et complète le contexte d'erreur métier avec `exception`/`message` — par opposition à `envoyer()` qui re-propage après avoir logué (couche bas niveau, log RGPD/SMTP). Distinction documentée dans le PHPDoc du helper.

Chaque `notifier*()` passe son **message et ses identifiants métier propres** (`type`, `*_id`, et pour la méthode commentaire `commentaire_avant_len`/`commentaire_apres_len`, avec `reservation_id` issu de `getReservationActive()?->getId()`) : le **contenu des logs reste strictement inchangé** (mêmes clés, même ordre métier-puis-`exception`/`message`). Les **gardes** en tête de méthode (`if statut !== ... return`), la **logique de préférence email** (`if !isEmailRappelJ1() return`, etc.) et `persisterNotification()` sont **inchangées** — seul le bloc try/catch est factorisé.

**Bilan** : refacto pur, contrôle `grep "try {"` = 2 occurrences légitimes restantes (`envoyer` qui propage, `envoyerEtTracer` qui avale) — plus aucun try/catch dans les 7 `notifier*()`. 286 tests verts dont les 23 de `NotificationService` (filet de non-régression), PHPStan 8 = 0, CS-Fixer 0. Commit de code : `f09392e`.
**Priorité** : 🟡 moyenne, à traiter lors de la prochaine évolution de NotificationService (nouveau type d'email).

---

## DT-13 — Self-host Bootstrap + Bootstrap Icons + Google Fonts (🟡 MOYEN) — ✅ RÉSOLUE (15/06/2026)

> **✅ RÉSOLUE le 15/06/2026** sur branche `feature/US-9.2-environnements`.
>
> **Résumé fix** : les **4 ressources** chargées depuis des CDN tiers (Bootstrap **CSS/JS 5.3.8**, Bootstrap **Icons 1.11.3**, police **Inter**) sont désormais **self-hostées via AssetMapper, sans Node** :
> - **Bootstrap JS** via `importmap:require bootstrap` (+ **`@popperjs/core`**), importé dans `assets/app.js` ; re-téléchargé au build par `importmap:install` (ignoré de Git, reproductible).
> - **Bootstrap CSS**, **Bootstrap Icons** (CSS + polices woff2/woff) **vendorisés à la main** sous `assets/vendor/` (pattern FullCalendar/Chart.js [[DT-8]]), `url()` des polices réécrits par AssetMapper (query string `?hash` retiré du `@font-face` Icons).
> - **Inter** en **variable font latin** (48 Ko, graisses 400-700 couvertes par `font-weight: 100 900`), `@font-face` local, suppression des `<link>` Google (preconnect ×2 + css2).
> - **Plus aucun appel CDN actif** : `grep -rinE 'jsdelivr|googleapis|gstatic|unpkg' templates/ assets/` = **0** hors commentaires (en-têtes de provenance dans les JS vendorisés). **Cascade préservée** (`<link>` self-hostés placés avant `creaslot.css`).
> - **Prérequis d'une CSP stricte (OWASP A05) levé** (objet d'un Morceau ultérieur d'US-9.2).
>
> **Validations** : page rendue à l'identique (police Inter, icônes `bi-*`, composants JS data-api), polices/CSS servis depuis `/assets/...`, **0 appel** jsdelivr/googleapis/gstatic (onglet réseau) ; suite complète verte (268 tests), PHPStan 8 = 0, CS-Fixer 0.
>
> **Commit** : `b352308`.

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (sécurité supply-chain / éco-conception / robustesse).

**Constat** : `templates/base.html.twig` charge encore par **CDN tiers** Bootstrap 5.3.3 (CSS + JS), Bootstrap Icons 1.11.3 et Google Fonts (Inter). Mêmes risques que [[DT-8]] avant correction : aucun contrôle d'intégrité (pas de SRI), dépendance à la disponibilité d'un tiers, incompatibilité CSP stricte, pas de fonctionnement hors-ligne, et requêtes externes contraires à l'éco-conception (RGESN).

**Fichiers concernés** : `templates/base.html.twig` (balises `<link>` / `<script>` lignes ~11-19 et ~56).

**Action proposée** : vendoriser ces dépendances via AssetMapper (même approche que FullCalendar en [[DT-8]]) — self-host CSS/JS/police, versions tracées. **À batcher avec US-5.2** (qui introduira le self-host de Chart.js pour les graphiques du dashboard), pour traiter tout le front CDN en une passe cohérente.

**Priorité** : 🟡 moyenne (supply-chain + CSP + RGESN), à planifier avec US-5.2 — close.

---

## DT-14 — Invalidation immédiate de session à la désactivation (🟡 MOYEN) — ✅ RÉSOLUE (14/06/2026)

> **✅ RÉSOLUE le 14/06/2026** sur branche `feature/DT-14-invalidation-session`.
>
> **Résumé fix** : la désactivation d'un compte **déjà connecté** prend désormais effet à la requête suivante (rejet à la requête suivante — le périmètre exact demandé par la dette).
> - **`Utilisateur` implémente `EquatableInterface`** : `isEqualTo()` compare l'identifiant (email) + `estActif` + les rôles (comparaison stable, triée) — **pas le mot de passe**. Au refresh du token sur le firewall stateful, un état divergent (compte désactivé ou rétrogradé) dé-authentifie le token → **302 vers `/connexion`** à la requête suivante.
> - **`UserChecker` conservé** (défense en profondeur) : `checkPreAuth` continue de bloquer les comptes inactifs **au login** ; son PHPDoc documente le partage de responsabilité login (UserChecker) / en-cours-de-session (`isEqualTo`).
> - **Mot de passe exclu de `isEqualTo`** à dessein : un changement de mot de passe ne doit pas déconnecter l'utilisateur courant (préserve `MonProfilControllerTest`).
> - **Option kill server-side immédiat écartée** : sessions en **fichiers natifs** sans index par utilisateur → cibler/supprimer la session d'un utilisateur exigerait une migration vers des sessions en BDD (table + config infra + étape de déploiement) pour un risque qualifié faible → **sur-ingénierie** au volume Cnam.
> - **Aucun changement de schéma ni d'infra** : pure logique applicative, rien à planifier au déploiement.
>
> **Validations** : suite complète verte (258 tests, 0 deprecation/notice/warning), PHPStan 8 = 0, CS-Fixer 0. Tests dédiés : `tests/Controller/DesactivationSessionTest.php` (session active → désactivation → 302 ; utilisateur actif → 200/200) + `tests/Entity/UtilisateurIsEqualToTest.php` (égalité email/rôle/actif, divergences, non-`Utilisateur`). Non-régression `MonProfilControllerTest` verte (changement de mot de passe → reste connecté).
>
> **Commit** : `d196bd5` (Morceau 1 — `EquatableInterface` + `isEqualTo` + PHPDoc UserChecker + tests) · doc & clôture (Morceau 2).

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 03/06/2026, lors de l'implémentation d'US-5.4 (activation / désactivation des comptes).

**Constat** : La désactivation d'un compte (US-5.4) bloque les **nouvelles** connexions — `UserChecker::checkPreAuth` lève `DisabledException` à l'**authentification** — mais une **session déjà ouverte survit** jusqu'à son expiration : `UserChecker` n'est **pas** réexécuté à chaque requête (il n'agit qu'au login, pas sur le `refreshUser` du firewall stateful).

**Impact** : un compte désactivé **en cours de session** conserve son accès jusqu'à déconnexion ou expiration de la session. Risque **faible** au volume Cnam (peu d'utilisateurs, désactivations rares), mais réel sur le plan sécurité.

**Fichiers concernés** : `src/Security/UserChecker.php`, `config/packages/security.yaml` (firewall `main` / provider `app_user_provider`).

**Action proposée** : re-vérifier `estActif` à **chaque requête** — soit (a) en faisant **échouer `refreshUser`** quand le compte est inactif (provider décorant `app_user_provider`, ou `Utilisateur` implémentant `EquatableInterface`/contrôle au refresh), soit (b) via un **listener `kernel.request`** qui invalide la session d'un utilisateur devenu inactif. Tâche **dédiée**, avec test fonctionnel : **session active → désactivation du compte → 302 vers login à la requête suivante**.

**Priorité** : 🟡 moyenne (sécurité ; risque faible au volume Cnam) — close.

---

## DT-15 — Purge automatisée du journal RGPD au-delà de la durée de conservation (🟡 MOYEN) — ✅ RÉSOLUE (14/06/2026)

> **✅ RÉSOLUE le 14/06/2026** sur branche `feature/DT-15-purge-journal-rgpd`.
>
> **Résumé fix** : la durée de conservation est désormais **appliquée** par une purge automatisée, en trois couches :
> - **Source unique** : constante `JournalAdmin::DUREE_CONSERVATION_MOIS = 12` (le PHPDoc de l'entité y renvoie au lieu de « purge reportée »).
> - **Repository** : `JournalAdminRepository::purgerAvant(\DateTimeImmutable $seuil): int` (DELETE DQL paramétré, **borné par la seule date** `dateAction < :seuil` → append-only préservé) et `compterAvant(\DateTimeImmutable $seuil): int` (COUNT pour le dry-run).
> - **Commande** : `app:purger-journal` (`--mois=N` défaut 12 avec garde-fou `>= 1` → `INVALID` sinon, `--dry-run`), seuil calculé en `Indian/Reunion`, log Monolog `info` (mode + count + date seuil), sans auto-journalisation dans `journal_admin`.
> - **Planification cron** (mensuelle `0 3 1 * *`) **renvoyée au déploiement (itération 9)** — documentée dans `docs/cron-purger-journal.md`, non activée à ce stade.
>
> **Validations** : suite complète verte (252 tests, 0 deprecation/notice/warning), PHPStan 8 = 0, CS-Fixer 0. Tests dédiés : `tests/Integration/JournalAdminPurgeTest.php` (purge bornée) + `tests/Command/PurgerJournalCommandTest.php` (dry-run inerte, purge réelle, option `--mois`, `--mois=0` → INVALID).
>
> **Commits** : `7811fb7` (Morceau 1 — couche données : constante + `purgerAvant` + test d'intégration) · `30b00d8` (Morceau 2 — commande `app:purger-journal` + `compterAvant` + test de commande) · doc & clôture (Morceau 3).

---

### Contenu historique original (préservé pour traçabilité MSP3)

**Détecté** : 03/06/2026, lors de l'implémentation d'US-5.5 (journal RGPD).

**Constat** : Le journal d'administration (`journal_admin`, US-5.5) **grandit indéfiniment** : chaque action sensible sur un compte y ajoute une entrée, sans suppression. La **durée de conservation de 12 mois** est documentée (finalité accountability, registre des traitements) mais **n'est pas appliquée** techniquement — aucune purge des entrées expirées.

**Impact** : conservation de données nominatives **au-delà** de la durée annoncée (non-conformité RGPD au principe de **limitation de la conservation**, art. 5.1.e) ; croissance non bornée de la table. Risque faible à court terme (volume Cnam, peu d'actions admin), réel sur la durée.

**Fichiers concernés** : nouvelle commande console (`src/Command/`), `JournalAdminRepository` (méthode de suppression bornée), planification cron (`docs/cron-*` / infra).

**Action proposée** : **commande console** (ex. `app:purger-journal`) supprimant en DQL paramétré les entrées `date_action < now - 12 mois`, **planifiée par cron** (comme le rappel J-1). Avec **test** : insertion d'entrées anciennes + récentes → seules les anciennes sont purgées. Durée de conservation portée par une **constante nommée** (source unique).

**Priorité** : 🟡 moyenne (conformité RGPD ; croissance lente au volume Cnam) — close.

---

## DT-16 — Mutualisation des helpers FullCalendar et du JSON no-store (🟡 MOYEN) — ✅ RÉSOLUE (05/08/2026)

> **✅ RÉSOLUE le 05/08/2026.** Volets JS et PHP traités le 22/06/2026 (branche `feature/DT-16-helpers-fullcalendar-no-store`, commit `be20af4`) ; volet CSS restant traité le 05/08/2026 (cf. « Volet CSS » ci-dessous).
>
> **Volet JS (fait)** : les 4 helpers dupliqués (`escapeHtml`, `heureSlot`, `hexVersRgb`, `melangerBlanc`) sont extraits dans `assets/fullcalendar_helpers.js`, importé par `agenda_controller` et `occupation_controller` (`hexVersRgb` reste interne au module).
>
> **Volet PHP (fait)** : la réponse JSON no-store dupliquée (`jsonSansCache` / `repondreSansCache`) est extraite dans le trait `JsonSansCacheTrait` (`src/Controller/Traits`), composé par `CreneauApiController` et `OccupationController` (nom unifié `jsonSansCache`).
>
> **Volet écarté** : la mutualisation du rendu `eventContent` est abandonnée (contenus réellement différents, 3 vs 4 lignes, et piège de double-échappement sur la ligne « état » de l'agenda).
>
> **Volet CSS (fait le 05/08/2026)** : l'habillage de toolbar (prev | titre | next sur une ligne, boutons charte) et la base de pastille (contraste RGAA `#1a1a1a`, atténuation des créneaux passés) sont mutualisés dans une classe partagée **`.cs-fc-calendar`** (bloc unique de `public/css/creaslot.css`), ajoutée aux conteneurs racines des deux vues. Elle remplace le `<style>` inline d'`agenda.html.twig` et le bloc `.cs-occupation-page` dupliqué. Les réglages propres à chaque vue restent scopés sous `.cs-agenda-page` / `.cs-occupation-page` : tailles de lignes (pastille 3 lignes côté agenda, 4 lignes côté occupation avec ligne « personnel » et `overflow` de sécurité), états `fc-event-reservee` (agenda) / `fc-event-occupe` (occupation). Refacto sans changement de rendu : **vérification visuelle stricte** des deux calendriers — styles calculés (`getComputedStyle`) strictement identiques avant/après sur toolbar, boutons, titre et pastilles, et captures d'écran avant/après superposables. DoD verte (PHP-CS-Fixer 0, PHPStan niveau 8 = 0, 344 tests / 1223 assertions).
>
> **Validation** : PHP-CS-Fixer 0, PHPStan niveau 8 = 0, suite complète verte (274 tests, 1009 assertions) ; vérification navigateur (occupation Admin + agenda Personnel : calendriers et pastilles rendus, console propre).
>
> **Commit** : `be20af4`.

**Détecté** : 03/06/2026, lors de l'implémentation d'US-5.7 (vue globale occupé/libre).

**Constat** : trois duplications assumées sont introduites par la vue d'occupation, pour garder l'US auto-contenue et **ne pas modifier de code déjà livré** (agenda Personnel, API créneaux) :
- **Helpers JS** : `escapeHtml`, `hexVersRgb`, `melangerBlanc`, `heureSlot` existent à l'identique dans `assets/controllers/agenda_controller.js` et `assets/controllers/occupation_controller.js`.
- **Rendu d'event FullCalendar** : la structure d'`eventContent` (wrapper `fc-event-main-frame cs-fc-lines` + lignes `cs-fc-line-*`) et son habillage CSS sont communs aux deux calendriers. Le CSS de pastille est volontairement dupliqué entre le bloc `.cs-agenda-page` et le bloc `.cs-occupation-page` de `public/css/creaslot.css` (même typo, même troncature ; seule la ligne `cs-fc-line-personnel` et l'état Occupé/Libre diffèrent côté occupation).
- **Habillage de la toolbar FullCalendar** : les règles `.fc-toolbar`/`.fc-toolbar-chunk`/`.fc-toolbar-title`/`.fc-button` (prev | titre | next sur une ligne, boutons stylés charte) sont dupliquées : inline dans le `<style>` de `templates/personnel/creneau/agenda.html.twig` (scope `.cs-agenda-page`) **et** dans le bloc `.cs-occupation-page` de `public/css/creaslot.css`. Source unique souhaitable.
- **Réponse JSON no-store** : la méthode privée `repondreSansCache()` d'`OccupationController` duplique `jsonSansCache()` de `CreneauApiController` (corps identique).

**Impact** : faible (fonctions pures, peu volatiles), mais toute évolution (ex. ajustement du contraste, de la typo de pastille, en-tête de cache) doit être répercutée à deux endroits → risque de divergence silencieuse. Contraire au principe DRY.

**Fichiers concernés** : `assets/controllers/agenda_controller.js`, `assets/controllers/occupation_controller.js`, `public/css/creaslot.css`, `src/Controller/Api/CreneauApiController.php`, `src/Controller/Admin/OccupationController.php`.

**Action proposée** : extraire un module `assets/fullcalendar_helpers.js` (helpers purs partagés), **mutualiser le rendu d'event ET l'habillage de toolbar** (fonction `eventContent` paramétrable + bloc CSS commun pastille **et toolbar**, p. ex. classe partagée `.cs-fc-calendar` au lieu des scopes `.cs-agenda-page`/`.cs-occupation-page` et du `<style>` inline de l'agenda) et un **trait/utilitaire JSON no-store** partagé (ex. `RepondAvecJsonSansCacheTrait`). Refacto pur, sans changement de comportement, à valider par les suites existantes (agenda + occupation). À planifier en **passe DRY de l'itération 6** (extraction d'autant plus justifiée que le rendu d'event est désormais lui aussi dupliqué).

**Priorité** : 🟡 moyenne (qualité de code ; aucun impact fonctionnel), à regrouper avec les autres axes DRY.

---

## DT-17 — Mutualisation des helpers Chart.js entre les deux contrôleurs Stimulus (🟡 MOYEN) — ✅ RÉSOLUE (29/06/2026)

> **✅ RÉSOLUE le 29/06/2026**. **Constat de clôture** : la factorisation était déjà en place dans le code (module `assets/chartjs_helpers.js`, créé le 25/06/2026), mais l'entrée de suivi n'avait pas été fermée.
>
> **Résumé** : les deux helpers dupliqués sont extraits dans `assets/chartjs_helpers.js` et importés par `graphique_occupation_controller.js` et `statistiques_controller.js` : `couleurToken(nomToken, repli)` (lecture d'un token de charte `--cs-*` avec repli) et `chartEstDisponible()` (garde `window.Chart`, logue et retourne `false` si le bundle UMD n'est pas chargé).
>
> **Décision assumée** : le cycle `connect()`/`disconnect()` n'est **pas** factorisé dans une classe de base. Les deux contrôleurs gèrent un nombre différent de graphiques (1 pour le dashboard, 2 pour les statistiques) ; mutualiser le cycle de vie via une classe de base Stimulus ajouterait une abstraction pour un gain marginal (cohérent avec l'évitement de l'abstraction spéculative appliqué en DT-7 et DT-16). Seuls les helpers purs et réutilisables sont partagés.
>
> **Validation** : les deux contrôleurs importent bien le module (vérifié par grep), comportement inchangé (refacto pur), graphiques dashboard + statistiques rendus à l'identique.

**Détecté** : 04/06/2026, lors de l'implémentation d'US-5.8 (statistiques par service / type).

**Constat** : la page Statistiques introduit un second contrôleur Stimulus à base de Chart.js (`statistiques_controller.js`), à côté de celui du dashboard (`graphique_occupation_controller.js`, US-5.2). Trois éléments y sont dupliqués à l'identique, duplication assumée pour garder l'US auto-contenue et **ne pas modifier de code déjà livré** (le graphique du dashboard) :
- **Helper `couleurToken(nomToken, repli)`** : lecture d'un token de charte `--cs-*` avec repli — corps identique dans les deux contrôleurs.
- **Garde `window.Chart`** : même bloc `if (typeof window.Chart === 'undefined') { console.error(...); return; }` (le bundle UMD est chargé par `<script>` classique dans chaque template, pas par l'importmap — cf. DT-8).
- **Cycle `connect()`/`disconnect()`** : même schéma d'instanciation puis `destroy()` des graphiques Chart.js (ici deux instances, barres + doughnut).

**Impact** : faible (fonctions pures, peu volatiles), mais toute évolution (ex. ajustement des tokens de couleur, gestion d'erreur de chargement, cycle de vie) doit être répercutée à deux endroits → risque de divergence silencieuse. Contraire au principe DRY. Analogue à DT-7 (duplication de logique de présentation).

**Fichiers concernés** : `assets/controllers/graphique_occupation_controller.js`, `assets/controllers/statistiques_controller.js`.

**Action proposée** : extraire un module partagé (ex. `assets/chartjs_helpers.js` : `couleurToken`, garde `window.Chart`) voire une classe de base Stimulus mutualisant le cycle connect/disconnect des graphiques Chart.js. Refacto pur, sans changement de comportement, à valider par les WebTests existants (dashboard + statistiques) et une vérification visuelle. À planifier en **passe DRY de l'itération 6**, conjointement avec DT-16.

**Priorité** : 🟡 moyenne (qualité de code ; aucun impact fonctionnel), à regrouper avec les autres axes DRY.

---

## DT-18 — Réplication des contraintes de mot de passe entre formulaires (🟡 MOYEN) — ✅ RÉSOLUE (04/06/2026)

**Détecté** : 04/06/2026, lors de l'implémentation d'US-6.1 (page « Mon profil » self-service).

**Constat** : la politique de mot de passe — `NotBlank` + `Length(min: 12)` + `Regex` (au moins 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial) — ainsi que le texte d'aide associé sont **dupliqués à l'identique** dans trois formulaires :
- `src/Form/InscriptionType.php` (auto-inscription publique, US-1) ;
- `src/Form/UtilisateurAdminType.php` (création de compte par le Super-admin, US-5.3) ;
- `src/Form/ChangementMotDePasseType.php` (changement self-service, US-6.1).

Mêmes règles, mêmes messages, même `help` : toute évolution de la politique (longueur minimale, jeu de caractères exigés, wording) doit être **répercutée en trois endroits** → risque de divergence silencieuse. Contraire au principe DRY. Analogue à DT-7 (duplication de logique de présentation).

**Impact** : faible (contraintes pures et peu volatiles), mais une politique de sécurité incohérente entre les trois points d'entrée serait un défaut de sécurité difficile à repérer.

**Fichiers concernés** : `src/Form/InscriptionType.php`, `src/Form/UtilisateurAdminType.php`, `src/Form/ChangementMotDePasseType.php`.

**Action proposée** : extraire une **source unique** des règles — soit une fabrique `App\Validator\ContraintesMotDePasse::regles(): array` retournant le tableau de contraintes (et une constante pour le texte d'aide), soit une **contrainte composite** réutilisée par les trois `*Type`. Refacto pur, sans changement de comportement, couvert par les WebTests existants (inscription, admin, profil).

**Résolution** (04/06/2026, US-6.2 Morceau 1) : création de `src/Validator/ContraintesMotDePasse.php` — constante `AIDE` (texte d'aide) + méthode statique `regles(): array` (NotBlank + Length(min: 12) + Regex, mêmes messages). `InscriptionType`, `UtilisateurAdminType` et `ChangementMotDePasseType` consomment désormais cette source unique ; le futur `ChangePasswordFormType` (réinitialisation US-6.2) en sera le 4ᵉ consommateur. Comportement inchangé, validé par les WebTests existants (inscription / admin compte / mon profil).

**Priorité** : 🟡 moyenne (qualité de code ; aucun impact fonctionnel) — close.

---

## DT-19 — Logique de réservation dans le contrôleur au lieu d'un ReservationService (🟡 MOYEN) — ✅ RÉSOLUE (18/06/2026)

> **✅ RÉSOLUE le 18/06/2026** sur branche `feature/DT-19-reservation-service`.
> Logique de réservation (création + annulation) extraite dans `ReservationService` : transaction + verrou pessimiste + re-check après refresh + notifications hors transaction ; signalisation par exceptions métier (`CreneauIndisponibleException`, `ReservationNonAnnulableException`) et enum `MotifRefusReservation` ; contrôleurs réduits à l'orchestration HTTP. Non-régression : 274 tests verts, PHPStan niveau 8, PHP-CS-Fixer 0. Commits `42ac8eb` (création) et `149191f` (annulation).

**Détecté** : 07/06/2026, lors de l'audit de sécurité OWASP (US-8.3, A04 — Insecure Design).

**Constat** : la logique métier de réservation (transaction explicite + `lock(PESSIMISTIC_WRITE)` + `refresh` + re-vérification de disponibilité + `persist`/`flush`/`commit` + notifications) vit directement dans `ReservationController::enregistrerReservation` (`src/Controller/Auditeur/ReservationController.php`, cf. `beginTransaction` L108), et l'annulation dans `ReservationAnnulationController`. Cela viole la convention d'architecture du projet (CLAUDE.md : « Logique métier dans des Services (`src/Service/`), pas dans les contrôleurs ; un contrôleur reste mince ») : il n'existe **pas** de `src/Service/ReservationService.php`.

**Impact** : qualité/architecture, **sans impact sécuritaire ni fonctionnel** (comportement figé par 9 WebTests, `tests/Controller/Auditeur/ReservationParcoursControllerTest.php`). Contrôleur épais → testabilité unitaire moindre (couvert seulement par des WebTests, pas de test unitaire de service), réutilisabilité limitée (une API ou commande future devrait dupliquer le verrouillage).

**Action proposée** : extraire un `ReservationService` portant l'enregistrement et l'annulation ; les contrôleurs se réduisent à recevoir → déléguer → répondre. **Préserver impérativement** le pattern transaction explicite + `PESSIMISTIC_WRITE` + re-vérification après `refresh` (cf. CLAUDE.md « Concurrence sur les réservations »). Refacto pur, sans changement de comportement, validé par les 9 WebTests existants.

**Priorité** : 🟡 moyenne (qualité/architecture ; aucun impact sécuritaire ni fonctionnel), à traiter dans une passe d'alignement architectural.

---

## DT-20 — En-tête X-XSS-Protection déprécié dans le Caddyfile (🟢 BAS) — ✅ RÉSOLUE (19/06/2026)

> **✅ RÉSOLUE le 19/06/2026** sur branche `feature/DT-20-retirer-x-xss-protection`.
>
> **Résumé fix** : la ligne `X-XSS-Protection "1; mode=block"` est retirée du snippet `securite` de `docker/caddy/Caddyfile` (commit `a0688f8`, 19/06/2026). Aucune compensation : la protection contre le XSS est assurée par la CSP à nonce stricte (DT-13 / US-9.2). Les autres en-têtes (HSTS, X-Content-Type-Options, Referrer-Policy, Permissions-Policy) sont préservés.
>
> **Note (postérieure)** : ce fichier a depuis été déplacé hors du dépôt CreaSlot lors du découplage du proxy (PR #117, 22/07/2026). Le snippet `securite` vit désormais dans `Caddyfile` (dépôt `infra-proxy`) ; le chemin `docker/caddy/Caddyfile` cité ci-dessus correspond à son emplacement au moment du correctif.
>
> **Validation** : en-tête absent du fichier (grep = 0, voisins intacts) ; confirmation `curl -I` au prochain déploiement préprod via le pipeline.

**Détecté** : 15/06/2026, lors d'US-9.2 (revue des en-têtes derrière Caddy).

**Constat** : le snippet `securite` de `docker/caddy/Caddyfile` pose encore `X-XSS-Protection "1; mode=block"` (hérité de l'ancienne conf nginx). Cet en-tête est **déprécié** et ignoré par les navigateurs modernes ; il peut même introduire des comportements indésirables sur de vieux moteurs.

**Impact** : nul à négatif (faux signal de protection) ; le besoin est désormais couvert par la **CSP à nonce** (DT-13/US-9.2) qui neutralise le XSS bien plus efficacement.

**Action proposée** : retirer la ligne `X-XSS-Protection` du snippet `securite` (`docker/caddy/Caddyfile`) ; aucune compensation nécessaire (CSP en place). Vérifier l'absence de l'en-tête en `curl -I`.

**Priorité** : 🟢 basse (cosmétique sécurité ; aucun impact fonctionnel).

---

## DT-21 — Champ username caché absent du formulaire de changement de mot de passe (🟢 BAS) — ✅ RÉSOLUE (19/06/2026)

> **✅ RÉSOLUE le 19/06/2026** sur branche `feature/DT-21-username-cache-form-mdp`.
>
> **Résumé fix** : ajout d'un champ `<input type="text" name="username" autocomplete="username" hidden>` (valeur = identifiant de connexion via `app.user.userIdentifier`) juste après `form_start` dans `templates/profil/index.html.twig`. Les attributs `autocomplete` des champs (`current-password` / `new-password`) étaient déjà en place dans `ChangementMotDePasseType`. L'avertissement DevTools disparaît ; les gestionnaires de mots de passe associent correctement l'identifiant.
>
> **Validation** : `lint:twig` OK, suite complète verte (274 tests, 1009 assertions).
>
> **Commit** : `d6da3ac`.

**Détecté** : 15/06/2026, lors d'US-9.2 (tour de validation navigateur, console DevTools).

**Constat** : le formulaire de changement de mot de passe (`/mon-profil/mot-de-passe`) ne comporte pas de champ `username` caché (`autocomplete="username"`). Les navigateurs et gestionnaires de mots de passe émettent un avertissement DevTools (« password field is not contained in a form … missing username field ») et associent mal l'identifiant à la nouvelle entrée.

**Impact** : mineur — accessibilité / UX des gestionnaires de mots de passe (mémorisation et remplissage moins fiables) ; aucune faille de sécurité.

**Action proposée** : ajouter un `<input type="text" name="username" autocomplete="username" hidden>` (valeur = email de l'utilisateur connecté) dans le template du formulaire, et `autocomplete="new-password"` sur les champs concernés. Vérifier la disparition de l'avertissement DevTools.

**Priorité** : 🟢 basse (a11y / confort gestionnaires de mots de passe).

---

## DT-22 — Latence d'un handler de clic (~1,6 s) sur une page d'administration (🟢 BAS) — ✅ CLÔTURÉE (NON REPRODUITE) (22/06/2026)

> **✅ CLÔTURÉE (NON REPRODUITE) le 22/06/2026** sur branche `feature/DT-22-profiling-latence-admin`.
>
> **Démarche** : profiling de l'interaction (métrique INP, *Interaction to Next Paint*) sur les quatre pages d'administration (Occupation, Statistiques, Comptes, Journal), en local avec les données de démonstration (fixtures), puis mesure de contrôle en navigation privée (extensions désactivées).
>
> **Résultat** : aucune latence reproduite. INP relevés — Occupation 33 ms, Statistiques 13 ms, Comptes 18 ms, Journal 21 ms (seuil « bon » < 200 ms) ; contrôle Occupation en navigation privée : 22 ms. Le handler de clic ne dépasse jamais ~33 ms.
>
> **Analyse** : les ~1,6 s rapportés en US-9.2 sont attribués à un artefact de mesure — très probablement une extension navigateur (Trendtrack / Beezy, accrochées aux clics, ~221 ms de main-thread relevés) ou un premier clic à froid. L'hypothèse « effet volume » est écartée : FullCalendar borne le fetch à la fenêtre affichée (semaine/mois), et la volumétrie réelle au Cnam ne sature pas une vue.
>
> **Décision** : aucune optimisation entreprise (optimisation prématurée évitée, faute de coût mesurable à corriger). Dette clôturée ; à re-profiler en production si la perception de lenteur réapparaît avec du volume réel.

**Détecté** : 15/06/2026, lors d'US-9.2 (tour de validation navigateur, onglet Performance).

**Constat** : un handler de clic d'environ **1,6 s** a été observé sur une page d'administration (interaction longue rapportée par les DevTools). La cause exacte n'est pas encore identifiée (rendu, requête synchrone, traitement JS d'un contrôleur Stimulus ?).

**Impact** : mineur au volume actuel (interaction ponctuelle, pas de blocage fonctionnel), mais dégrade la réactivité perçue ; à surveiller si la volumétrie augmente.

**Action proposée** : **profiler** l'interaction (onglet Performance / `console.time`) pour isoler le coût (DOM, réseau, JS), puis optimiser la cause identifiée (ex. requête déférée, allègement du rendu). Reproduire avant/après pour mesurer le gain.

**Priorité** : 🟢 basse (perf perçue ; à profiler avant d'agir).

---

## DT-23 — Étiquette « DEV » en dur dans le template d'e-mail de test (🟢 BAS) — ✅ RÉSOLUE (19/06/2026)

> **✅ RÉSOLUE le 19/06/2026** sur branche `feature/DT-23-etiquette-env-email-test`.
>
> **Résumé fix** : la valeur n'était pas figée dans le template mais dans la commande `AppEmailTestCommand` (`'environnement' => 'dev'` codé en dur). Le template `test.html.twig` consomme désormais directement le global Twig `app_environment_label` (`{{ app_environment_label|upper }}`) — la même source que le bandeau d'environnement — et la variable figée est retirée du contexte de la commande. Plus aucune étiquette d'environnement en dur ; source unique partagée avec le bandeau.
>
> **Comportement** : en prod l'e-mail affiche « PROD », en préprod « PREPROD ». Le global vaut `%env(APP_ENVIRONMENT_LABEL)%` (défaut committé `preprod`, surchargé par l'environnement réel sur le VPS) — jamais vide, donc aucun repli nécessaire.
>
> **Validation** : suite complète verte (274 tests, 1009 assertions, 0 deprecation/notice). Vérification visuelle de l'e-mail au prochain envoi de test préprod/prod via Brevo.
>
> **Commit** : `531187d`.

**Détecté** : 16/06/2026, lors du test Brevo en production (US-9.3).

**Constat** : `templates/emails/test.html.twig` affiche une étiquette « DEV » **figée dans le template** au lieu de refléter l'environnement réel. L'e-mail reçu en production affichait « DEV » alors que l'application tourne en prod (`about` confirme `Environment=prod`, `Debug=false`). Purement cosmétique, sans impact fonctionnel ni sécurité.

**Impact** : nul (cosmétique) ; un e-mail de test peut induire en erreur sur l'environnement réel d'envoi.

**Action proposée** : remplacer l'étiquette codée en dur par la valeur dynamique de l'environnement (`app.environment` côté Twig, ou variable passée par la commande), ou retirer l'étiquette.

**Priorité** : 🟢 basse (cosmétique ; aucun impact fonctionnel ni sécurité).

---

## DT-24 — Préfixe de redirection mailer figé à « DEV » au lieu de l'environnement réel (🟢 BAS) — ✅ RÉSOLUE (19/06/2026)

> **✅ RÉSOLUE le 19/06/2026** sur branche `feature/DT-24-prefixe-redirection-env-reel`.
>
> **Résumé fix** : le mécanisme de redirection des e-mails hors-prod (`NotificationService::envoyer`) préfixait le sujet `[DEV→destinataire]` avec « DEV » codé en dur. Le préfixe consomme désormais `APP_ENVIRONMENT_LABEL` (injecté au constructeur, `strtoupper`) — la même source que le badge du corps (DT-23). Plus aucune étiquette d'environnement en dur (code + docblocks, exemples passés en `[<ENV>→…]`).
>
> **Comportement** : la redirection n'est active qu'en dev (`APP_MAILER_REDIRECT_TO` non définie en preprod/prod) ; le préfixe affiche le label réel de l'environnement (`[PREPROD→…]` en dev avec le défaut `.env`), cohérent avec le corps. En prod, aucun préfixe (redirection inactive).
>
> **Validation** : suite complète verte (274 tests, 1009 assertions), PHP-CS-Fixer 0. Non-régression `NotificationServiceTest` (paramètre `environmentLabel` ajouté à l'instanciation manuelle).
>
> **Commit** : `81ccf1d`.

**Détecté** : 19/06/2026, lors de la vérification de bout en bout de DT-23 (envoi d'un e-mail de test réel).

**Constat** : après le fix DT-23 (badge du corps), l'e-mail de test affichait toujours « DEV » dans le **sujet** (`[DEV→destinataire] Test CreaSlot…`). Ce préfixe est posé par `NotificationService::envoyer` (ni la commande, ni le template), avec « DEV » figé dans le `sprintf`. Trompeur : en préprod, le même préfixe afficherait aussi « DEV ».

**Impact** : nul (cosmétique) ; signalétique d'environnement incohérente entre le sujet et le corps des e-mails redirigés hors-prod.

**Action proposée** : brancher le préfixe sur `APP_ENVIRONMENT_LABEL` (source unique partagée avec le corps), via injection au constructeur de `NotificationService` ; mettre à jour les docblocks.

**Priorité** : 🟢 basse (cosmétique ; aucun impact fonctionnel ni sécurité).

---

## DT-25 — Absence d'indicateur visuel de chargement sur l'agenda (🟢 BAS) — ✅ RÉSOLUE (25/06/2026)

> **✅ RÉSOLUE le 25/06/2026** sur branche `feature/DT-25-spinner-agenda`.
>
> **Origine** : recommandation issue d'une revue (retour formateur) sur l'expérience utilisateur, pas un défaut détecté en audit interne.
>
> **Résumé fix** : ajout d'un spinner Bootstrap 5 (`.spinner-border`, natif, sans dépendance) en overlay du calendrier FullCalendar de l'agenda Personnel. Le hook `loading` de FullCalendar était déjà branché (il positionnait `aria-busy` pour l'accessibilité) mais n'avait aucun retour visuel : le spinner est désormais affiché pendant le chargement des créneaux et masqué à la fin.
>
> **Couverture** : tous les chargements de créneaux déclenchés par le hook `loading` (changements de vue jour/semaine/mois, aujourd'hui, prev/next) + l'appel réseau séparé du bouton « Mes prochains RDV » (géré manuellement, masquage en `.finally()` avec garde sur `aria-busy` pour ne pas couper un refetch FullCalendar déclenché par `changeView`).
>
> **Accessibilité (RGAA)** : `role=status`, `aria-live=polite`, libellé `.visually-hidden`, `spinner-border` natif Bootstrap.
>
> **Validation** : 288 tests verts (non-régression ; front pur sans test automatisé), validation visuelle navigateur (spinner affiché puis masqué proprement sur changement de période et sur Mes prochains RDV). Commit de code : `489c66a`.

**Détecté** : 25/06/2026, lors d'une revue UX (retour formateur).

**Constat** : le hook `loading` de FullCalendar dans `assets/controllers/agenda_controller.js` positionnait déjà `aria-busy` sur le calendrier, mais aucun retour visuel n'était présenté à l'utilisateur pendant le chargement des créneaux (calendrier figé sans indication).

**Fichiers concernés** : `templates/personnel/creneau/agenda.html.twig` (overlay spinner + règle CSS `.cs-agenda-loading-overlay`), `assets/controllers/agenda_controller.js` (cible Stimulus `loadingOverlay`, pilotage dans le hook `loading` et dans `allerVersProchainsRdvReserve`).

**Action réalisée** : overlay spinner Bootstrap piloté par le hook `loading` existant ; couverture de l'appel séparé Mes prochains RDV avec coordination anti-conflit via `aria-busy`.

**Hors périmètre** : les écrans d'administration (occupation, statistiques) qui chargent des données en asynchrone feront l'objet d'une dette dédiée (DT-26) si pertinent.

**Priorité** : 🟢 basse (amélioration UX ; aucun impact fonctionnel ni sécurité).

---

## DT-26 — Absence d'indicateur visuel de chargement sur le calendrier d'occupation (admin) (🟢 BAS) — ✅ RÉSOLUE (25/06/2026)

> **✅ RÉSOLUE le 25/06/2026** sur branche `feature/DT-26-spinner-admin`.
>
> **Origine** : extension du retour visuel introduit en DT-25 (spinner agenda) à la vue d'occupation admin, suite à la même revue UX (retour formateur).
>
> **Résumé fix** : ajout d'un spinner Bootstrap 5 (`.spinner-border`, natif) en overlay du calendrier FullCalendar de la vue d'occupation, en répliquant le pattern DT-25. Piloté par le hook `loading` déjà existant d'`occupation_controller.js` (qui positionnait déjà `aria-busy`) : affiché pendant le chargement des évènements d'occupation, masqué à la fin.
>
> **Périmètre cadré par audit** : seul le calendrier d'occupation charge en asynchrone (`eventSources` avec `url` + hook `loading`). Les graphiques Chart.js (statistiques par service/type, graphique d'occupation du dashboard) sont EXCLUS : leurs données sont rendues inline par Twig (`lignes.map` / `series.map`), sans aucun appel réseau asynchrone, donc aucun spinner n'est justifié. Aucun fetch séparé à couvrir (pas de bouton type Mes prochains RDV), donc pas de `.finally()` ni de garde `aria-busy` nécessaires (plus simple que DT-25).
>
> **Accessibilité (RGAA)** : `role=status`, `aria-live=polite`, libellé `.visually-hidden`, `spinner-border` natif Bootstrap.
>
> **Validation** : 288 tests verts (non-régression ; front pur sans test automatisé), validation visuelle navigateur (spinner affiché puis masqué proprement sur changement de période, throttling Slow 3G).

**Détecté** : 25/06/2026, lors de la même revue UX que DT-25 (retour formateur), en étendant la réflexion aux écrans admin.

**Constat** : le calendrier d'occupation (`occupation_controller.js`) positionnait déjà `aria-busy` via son hook `loading`, mais sans retour visuel pendant le chargement des évènements. À l'inverse, les deux contrôleurs Chart.js (`statistiques_controller.js`, `graphique_occupation_controller.js`) lisent des données déjà présentes inline (rendu Twig) : aucun chargement asynchrone, donc hors périmètre.

**Fichiers concernés** : `templates/admin/occupation/index.html.twig` (overlay spinner + wrapper `position-relative` + règle CSS `.cs-occupation-loading-overlay`, calquée sur `.cs-agenda-loading-overlay`), `assets/controllers/occupation_controller.js` (cible Stimulus `loadingOverlay`, pilotage dans le hook `loading`).

**Action réalisée** : overlay spinner Bootstrap piloté par le hook `loading` existant du contrôleur d'occupation, en répliquant le pattern DT-25 ; version simplifiée sans `.finally()` ni garde `aria-busy` (aucun fetch séparé à couvrir).

**Hors périmètre** : les graphiques Chart.js (statistiques par service/type, graphique d'occupation du dashboard), dont les données sont rendues inline par Twig sans appel réseau asynchrone — aucun spinner justifié.

**Priorité** : 🟢 basse (amélioration UX ; aucun impact fonctionnel ni sécurité).

---

## DT-27 — Page d'accueil de squelette exposant la stack technique (🟡 MOYEN) — ✅ RÉSOLUE (25/06/2026)

> **✅ RÉSOLUE le 25/06/2026** sur branche `feature/DT-27-page-accueil`.
>
> **Origine** : constat lors d'une vérification de la page d'accueil servie aux utilisateurs connectés.
>
> **Résumé fix** : la route racine `/` (`HomeController`) rendait une page de squelette Symfony affichant en clair la version PHP (8.4.22), la version Symfony (8.0.13), l'`APP_ENV`, le mode debug, les extensions PHP chargées et une mention « Prochaine étape : US-1.3 ». Cette page est la destination post-login (`default_target_path: /` dans security.yaml), donc vue par tout utilisateur connecté. `HomeController` est transformé en aiguilleur : `/` redirige selon le rôle, du plus spécifique au plus général (`ROLE_SUPER_ADMIN` → `app_admin_dashboard` `/admin` ; `ROLE_PERSONNEL` → `app_creneau_agenda` `/creneau/agenda` ; `ROLE_AUDITEUR` → `app_creneaux_disponibles` `/creneaux-disponibles` ; fallback → `app_login`). Le template `home/index.html.twig` et la méthode morte `collectExtensionsStatus()` sont supprimés.
>
> **Sécurité** : suppression d'une divulgation de la stack technique (OWASP A05 — Security Misconfiguration), qui facilitait la reconnaissance de versions vulnérables. Exposition limitée aux utilisateurs déjà authentifiés (la racine est derrière `access_control ^/ IS_AUTHENTICATED_FULLY`), risque donc faible, mais correction nette.
>
> **Validation** : 292 tests verts (288 + 4 nouveaux), PHPStan niveau 8 = 0, PHP-CS-Fixer 0. Test fonctionnel `tests/Controller/HomeRedirectionTest.php` couvrant les 3 rôles + le cas non authentifié.

**Détecté** : 25/06/2026, en vérifiant le contenu de la page d'accueil après connexion.

**Constat** : `HomeController::index` rendait `templates/home/index.html.twig`, une page de squelette Symfony exposant versions/extensions/mode debug et la mention « Prochaine étape : US-1.3 ». Page de chantier indigne d'une application finie ET divulgation de stack. Cette page est la cible de `default_target_path: /`.

**Fichiers concernés** : `src/Controller/HomeController.php` (aiguilleur par rôle), `templates/home/index.html.twig` (supprimé), `tests/Controller/HomeRedirectionTest.php` (créé).

**Action réalisée** : `HomeController` réduit à un aiguilleur de redirection par rôle (ordre du plus spécifique au plus général, car la hiérarchie `SUPER_ADMIN ⊃ PERSONNEL ⊃ AUDITEUR` rendrait `isGranted('ROLE_AUDITEUR')` vrai pour tous les rôles) ; suppression du template de chantier et de la méthode morte `collectExtensionsStatus()`.

**Hors périmètre** : la configuration du firewall et de `default_target_path` (inchangée) ; seul le comportement du contrôleur racine est modifié.

**Priorité** : 🟡 moyenne (divulgation de stack — OWASP A05 ; exposition limitée aux utilisateurs authentifiés, donc risque faible mais corrigé).

---

## DT-28 — Bouton afficher/masquer le mot de passe absent des pages connexion et inscription (🟢 BAS) — ✅ RÉSOLUE (25/06/2026)

> **✅ RÉSOLUE le 25/06/2026** sur branche `feature/DT-28-toggle-mot-de-passe`.
>
> **Origine** : constat d'incohérence UX. Le composant « œil » afficher/masquer le mot de passe (contrôleur Stimulus `afficher-mot-de-passe` + thème de formulaire `form/champ_mot_de_passe.html.twig`, US-6.1) existait déjà et était utilisé sur la réinitialisation de mot de passe et la page profil, mais PAS sur les pages connexion et inscription.
>
> **Résumé fix** : le composant existant est réutilisé sur les 2 pages auth, sans créer de nouveau code. Inscription (Symfony Form `RepeatedType`/`PasswordType`) : application du thème via `{% form_theme formulaire ... 'form/champ_mot_de_passe.html.twig' %}` → les 2 champs (saisie + confirmation) héritent du bouton œil. Connexion (champ HTML brut `name=password` lu par le firewall, donc hors Symfony Form) : câblage manuel d'un `input-group` identique au composant (`data-controller=afficher-mot-de-passe`, cibles `champ`/`icone`, action `basculer`).
>
> **Accessibilité (RGAA)** : bouton `type=button` (ne soumet pas le formulaire), `aria-label`, `aria-pressed` reflétant l'état, icône `aria-hidden`. Amélioration progressive : sans JS, le champ reste un mot de passe masqué normal.
>
> **Validation** : 297 tests verts (non-régression ; front pur), vérification visuelle navigateur (bascule sur connexion et sur les 2 champs d'inscription).

**Détecté** : 25/06/2026, constat d'absence du bouton œil sur connexion et inscription alors qu'il existe ailleurs.

**Constat** : le composant `afficher-mot-de-passe` (contrôleur Stimulus + thème de formulaire) était déjà livré (US-6.1) et utilisé sur `reset_password/reset.html.twig` et `profil/index.html.twig`, mais pas sur `templates/auth/connexion.html.twig` ni `templates/auth/inscription.html.twig`.

**Fichiers concernés** : `templates/auth/inscription.html.twig` (directive `form_theme`), `templates/auth/connexion.html.twig` (câblage manuel `input-group` sur le champ HTML brut). Aucun code JS/CSS ni le contrôleur modifiés (le composant existait).

**Action réalisée** : réutilisation du composant existant sur les 2 pages, selon leur nature (`form_theme` pour le Symfony Form d'inscription, HTML manuel pour le champ firewall de connexion).

**Hors périmètre** : le contrôleur Stimulus et le thème de formulaire (déjà en place, inchangés).

**Priorité** : 🟢 basse (cohérence UX ; aucun impact fonctionnel ni sécurité — le `name=password` de connexion est préservé pour le firewall).

## DT-29 — Libellé CGU dupliqué et lien mort sur la page d'inscription (🟢 BAS) — ✅ RÉSOLUE (25/06/2026)

> **✅ RÉSOLUE le 25/06/2026** sur branche `feature/DT-29-cgu-inscription`.
>
> **Origine** : constat lors de la vérification de la page d'inscription (après ajout du toggle mot de passe en DT-28).
>
> **Résumé fix** : le champ CGU présentait deux défauts. (1) Le libellé « J'accepte les conditions générales d'utilisation » s'affichait DEUX FOIS : une fois via le label du widget `CheckboxType` (option `label` dans `InscriptionType`) et une fois via un label manuel dans le template (qui porte le lien). (2) Le lien « conditions générales d'utilisation » pointait vers `href="#"` (lien mort), alors que la vraie page CGU existe depuis US-10.1 (route `app_cgu`).
>
> **Correction** : option `label` mise à `false` sur le champ `cgu` de `InscriptionType` (le widget ne rend plus son propre label, on garde le label manuel du template qui porte le lien) ; lien corrigé vers `{{ path('app_cgu') }}` avec `target="_blank"` et `rel="noopener"` (consultation des CGU sans perte de la saisie en cours). La contrainte `IsTrue` (case obligatoire) est préservée.
>
> **Validation** : 297 tests verts (le WebTest d'inscription reste vert, le `name` du champ étant inchangé), lint Twig OK, vérification visuelle (libellé unique, lien ouvrant la page CGU, case toujours obligatoire).

**Détecté** : 25/06/2026, sur la page d'inscription (libellé CGU affiché en double et lien non fonctionnel).

**Constat** : double définition du label (widget `CheckboxType` + label manuel du template) et lien `href="#"` alors que la route `app_cgu` existe désormais.

**Fichiers concernés** : `src/Form/InscriptionType.php` (champ `cgu` : `label` mis à `false`), `templates/auth/inscription.html.twig` (lien CGU corrigé vers `app_cgu`, ouverture nouvel onglet).

**Action réalisée** : suppression du doublon de libellé (label du widget désactivé au profit du label manuel cliquable) et câblage du lien vers la vraie page CGU.

**Hors périmètre** : la page CGU elle-même (livrée en US-10.1) ; la contrainte d'acceptation obligatoire (inchangée).

**Priorité** : 🟢 basse (UX / lien fonctionnel ; aucun impact sur la validation, la case reste obligatoire).

## DT-30 — Absence du bandeau d'environnement preprod dans le corps des emails (🟢 BAS) — ✅ RÉSOLUE (29/06/2026)

> **✅ RÉSOLUE le 29/06/2026** sur branche `feature/DT-30-bandeau-preprod-emails`.
>
> **Origine** : constat d'incohérence. L'interface web affiche un bandeau orange « PRÉ-PRODUCTION — Les données de cet environnement ne sont pas réelles » (`templates/_partials/bandeau_environnement.html.twig`) en preprod, mais les emails envoyés depuis la preprod n'avaient AUCUN marquage visuel dans leur corps (seul le sujet était préfixé `[PREPROD...]` côté PHP).
>
> **Résumé fix** : ajout d'un bandeau preprod dans le layout commun des emails (`templates/emails/_layout.html.twig`), placé après le header et avant le corps. Conditionné par la globale Twig `app_environment_label == 'preprod'` (même condition que le bandeau web). Une seule modif dans le layout couvre les 8 emails qui en héritent. Style inline (contrainte email : pas de CSS externe), couleur `#FD7E14` (token `--cs-warning` du bandeau web) et texte cohérents avec le web. En prod, aucun bandeau.
>
> **Validation** : 303 tests verts (front pur), lint Twig OK. Vérification visuelle réelle à faire en preprod (le bandeau ne s'affiche qu'avec `app_environment_label=preprod`).

**Détecté** : 29/06/2026, en consultant un email de notification envoyé depuis la preprod (bandeau présent sur le web, absent du corps de l'email).

**Constat** : le layout email `_layout.html.twig` ne portait pas de marquage d'environnement, alors que la globale Twig `app_environment_label` est accessible dans les emails (déjà utilisée dans `emails/test.html.twig`).

**Fichiers concernés** : `templates/emails/_layout.html.twig` (ajout d'une ligne de bandeau conditionnelle).

**Action réalisée** : réutilisation de la condition et de la couleur du bandeau web, en styles inline adaptés aux emails, dans le layout commun.

**Hors périmètre** : le bandeau web (inchangé) ; le préfixe `[PREPROD]` du sujet et la redirection des emails (mécanisme PHP existant, inchangé).

**Priorité** : 🟢 basse (cohérence du marquage preprod web↔email ; aucun impact fonctionnel ni sécurité).

## DT-31 — Fixtures Doctrine monolithiques : impossible de peupler prod et preprod différemment (🟢 BAS) — ✅ RÉSOLUE (30/06/2026)
> **✅ RÉSOLUE le 30/06/2026** sur branche `feature/DT-31-fixtures-groupes-reference-demo`.
>
> **Origine** : `AppFixtures` était une classe monolithique chargeant toutes les données d'un bloc (services, types de RDV, comptes fictifs, créneaux, réservations, notifications). Impossible de charger uniquement les données de référence métier (services + types de RDV) sans charger aussi les faux comptes de démonstration. Or la production ne doit recevoir que les données de référence, jamais les données de démo.
>
> **Résumé fix** : scission de `AppFixtures` en deux classes à groupes. `ReferenceFixtures` (groupe `reference`) : services + types de RDV, exposés via le système de références Doctrine (`addReference`, préfixes stables `PREFIXE_SERVICE` / `PREFIXE_TYPE`). `DemoFixtures` (groupe `demo`) : personnel, auditeurs, super-admin de démo, créneaux, réservations, notifications ; implémente `DependentFixtureInterface` (dépend de `ReferenceFixtures`) et récupère les données de référence via `getReference`. Refacto pur : données produites inchangées.
>
> **Chargement par environnement** : dev/preprod = `doctrine:fixtures:load` (complet) ; prod = `doctrine:fixtures:load --group=reference --append` (services + types uniquement, sans purge). Le super-admin de prod reste créé par la commande `app:creer-admin` (pas de doublon dans le groupe `reference`).
>
> **Validation** : chargement complet identique à l'avant-refacto (3 services, 3 types, 9 comptes, 10 créneaux) ; `--group=reference` seul vérifié à 3 services + 3 types + 0 compte + 0 créneau ; `--append` confirmé ne pas purger la base. `lint:container` OK, 306 tests verts. Couverture Sonar : `src/DataFixtures/**` exclu du calcul de couverture (données de démo non testables unitairement), Quality Gate au vert.
**Détecté** : 30/06/2026, en préparant le peuplement différencié preprod/prod.
**Constat** : une seule classe `AppFixtures` mêlait données de référence et données de démo, rendant impossible un chargement sélectif par environnement.
**Fichiers concernés** : `src/DataFixtures/ReferenceFixtures.php` (nouveau), `src/DataFixtures/DemoFixtures.php` (nouveau, ex-`AppFixtures`), `src/DataFixtures/AppFixtures.php` (supprimé), `sonar-project.properties` (exclusion de couverture).
**Action réalisée** : séparation en deux groupes de fixtures reliés par `DependentFixtureInterface` + système de références Doctrine ; documentation des commandes de chargement par environnement.
**Hors périmètre** : la logique des fixtures elle-même (données inchangées) ; le peuplement réel de preprod/prod (réalisé séparément après promotion, jamais en touchant les serveurs directement).
**Priorité** : 🟢 basse (amélioration de déployabilité ; aucun impact fonctionnel ni sécurité).

## DT-32 — Flag log-bin-trust-function-creators absent de compose.prod.yml (bloque la migration du trigger US-12.1) (🟡 MOYEN) — ✅ RÉSOLUE (01/07/2026)
> **✅ RÉSOLUE le 01/07/2026** (PR #96 mergée, commit `78774a8`).
>
> **Origine** : la migration `Version20260629120000` (US-12.1) créé un trigger SQL. MySQL refuse la création d'un trigger par un utilisateur non-SUPER quand le binary logging est actif (erreur **1419**). Le flag `--log-bin-trust-function-creators=1` était présent dans `docker-compose.yml` (dev) mais absent de `compose.prod.yml` (preprod/prod).
>
> **Résumé fix** : ajout de `command: --log-bin-trust-function-creators=1` au service `db` de `compose.prod.yml`.
>
> **Intervention manuelle preprod (assumée, documentée honnêtement)** : au premier déploiement preprod, la migration a échoué (1419) car le conteneur `db` tournait depuis 13 jours sans le flag et le pipeline ne l'avait pas recrée. Correction manuelle sur le VPS : mise à jour du repo (`git reset --hard origin/preprod`), recréation du conteneur `db` (`docker compose up -d --force-recreate db`, volume `mysql_data_prod` préservé, variable MySQL passée de OFF à ON), puis nettoyage d'un état de migration partiel (le 1er essai avait créé la table `historique_utilisateur` en auto-commit DDL avant de planter au trigger : table présente, trigger et procédure absents, migration non enregistrée côté Doctrine) via `DROP TABLE` de la table orpheline, avant relance réussie de la migration (table + trigger + procédure créés, vérifiés 1/1/1).

**Détecté** : 01/07/2026, lors du premier déploiement preprod de la migration US-12.1 (trigger).
**Constat** : le flag `--log-bin-trust-function-creators=1`, présent sur la db de dev (`docker-compose.yml`), était absent du service `db` de `compose.prod.yml` — d'ou l'erreur MySQL 1419 au moment de créer le trigger via un utilisateur non-SUPER avec binary logging actif.
**Fichiers concernés** : `compose.prod.yml` (service `db`).
**Action réalisée** : ajout du flag `--log-bin-trust-function-creators=1` au service `db` ; en complément, intervention manuelle ponctuelle sur le VPS preprod (recréation de `db` + nettoyage de l'état de migration partiel) pour débloquer le déploiement en cours.
**Hors périmètre** : la correction du pipeline lui-même (il ne synchronise pas `compose.prod.yml` sur le VPS et ne recrée pas `db`) — tracée en [[DT-34]].
**Priorité** : 🟡 moyenne (bloquant le déploiement du trigger ; contourné manuellement).

## DT-33 — Peuplement preprod impossible via doctrine:fixtures:load (image runtime sans Composer ni fixtures-bundle) (🟡 MOYEN) — ✅ RÉSOLUE (01/07/2026)
> **✅ RÉSOLUE le 01/07/2026** (PR #97 mergée).
>
> **Origine** : l'image de prod/preprod est construite en `composer --no-dev` (stage `build` du Dockerfile). Elle n'embarque ni Composer, ni doctrine-fixtures-bundle. La commande `doctrine:fixtures:load` est donc indisponible en preprod (erreur « no commands defined in `doctrine:fixtures` namespace », puis « composer: not found »). Il fallait néanmoins peupler `creaslot_preprod` avec les mêmes données de démo que les fixtures.
>
> **Résumé fix** : création de `scripts/seed-preprod.sql`, équivalent SQL de `ReferenceFixtures` + `DemoFixtures` : 3 services, 3 types de RDV, 9 comptes (3 personnels, 5 auditeurs, 1 super-admin), 10 créneaux, 3 réservations, 5 notifications. Idempotent (purge inverse-FK dans une transaction, `reset_password_request` incluse car FK NON NULL, rejouable), dates de créneaux relatives via `DATE_ADD`/`CURDATE()` (restent futures), mots de passe en argon2id (mot de passe démo : `password`) générés via `security:hash-password` en `APP_ENV=prod` (iso-config, pas la config dev qui est en bcrypt cost-4), préférence RGPD de Julie préservée (`email_rappel_j1 = 0`). Colonnes vérifiées via la `naming_strategy` underscore + les migrations.
>
> **Validation** : testé en local (chargement code retour 0, comptages 3/3/9/10/3/5 conformes, Julie a 0, hash argon2id confirmé en base). Exécution réelle sur le VPS preprod : à réaliser (promotion `develop`→`preprod` puis exécution manuelle du seed sur `creaslot_preprod`).

**Détecté** : 01/07/2026, en préparant le peuplement de la preprod (fixtures indisponibles sur l'image runtime).
**Constat** : l'image runtime `composer --no-dev` n'embarque ni Composer ni doctrine-fixtures-bundle, rendant `doctrine:fixtures:load` inutilisable en preprod ; il fallait un équivalent SQL des fixtures, exécutable directement sur MySQL.
**Fichiers concernés** : `scripts/seed-preprod.sql` (nouveau).
**Action réalisée** : écriture d'un seed SQL idempotent reproduisant à l'identique `ReferenceFixtures` + `DemoFixtures` (colonnes issues de la `naming_strategy` underscore + migrations, dates relatives `DATE_ADD`/`CURDATE()`, hash argon2id iso-config prod, préférence RGPD de Julie préservée).
**Hors périmètre** : l'automatisation du chargement du seed dans le pipeline (exécution manuelle voulue) ; le peuplement de la prod (qui ne reçoit jamais les données de démo, seulement le groupe `reference` via `--group=reference --append`, cf. [[DT-31]]).
**Priorité** : 🟡 moyenne (nécessaire à la démo preprod ; contourné).

## DT-34 — Le pipeline de déploiement ne synchronise pas compose.prod.yml sur le VPS et ne recrée pas le conteneur db (🟡 MOYEN) — ✅ RÉSOLUE (01/07/2026)
> **✅ RÉSOLUE le 01/07/2026** (PR #99 mergée, commit `cc521c9`).
>
> **Résumé fix** : ajout d'une étape de synchronisation git dans `scripts/deploy-ci.sh`, après le `cd` dans le repo et avant le pull de l'image : `git fetch --quiet origin` puis `git reset --hard --quiet "$TAG"` (le SHA déjà valide par la regex hexadécimale). Le working tree du VPS est désormais amené exactement sur le commit déployé avant tout `docker compose up`, donc les fichiers d'orchestration versionnés (compose.prod.yml, init-prod.sh) sont toujours à jour. `set -euo pipefail` garantit l'arrêt avant déploiement si la synchro échoue. Le `Caddyfile`, alors présent dans le dépôt à la date de ce correctif, en a depuis été retiré lors du découplage du proxy (PR #117) et vit désormais dans le dépôt `infra-proxy`.
>
> **Portée volontairement limitée** : seule la synchro du dépôt est automatisée. La recréation du conteneur `db` reste MANUELLE et documentée (geste rare, risque de micro-coupure de la prod partagée) — la meilleure pratique théorique (push de config immuable via scp/rsync) a été écartée car elle imposerait de revoir la forced command SSH, disproportionné au volume du projet.
>
> **Paradoxe de bootstrap (documenté honnêtement)** : le déploiement qui a livré ce correctif a lui-même tourne avec l'ANCIEN script (sans synchro), car le VPS n'avait pas encore le nouveau `deploy-ci.sh` au moment de son exécution. Une dernière synchro manuelle du VPS a donc été nécessaire pour installer le nouveau script. A partir du déploiement suivant, la synchro est automatique.
>
> **Validation** : `bash -n scripts/deploy-ci.sh` OK, CI verte (PR #99). Preuve en conditions réelles : la prochaine promotion vers preprod affichera les lignes « >>> Synchronisation du dépôt sur <sha> » dans les logs du pipeline.

**Détecté** : 01/07/2026, lors du déploiement preprod de [[DT-32]].
**Constat** : le pipeline `deploy-preprod.yml` (et par extension `deploy-prod.yml`) tire l'image applicative depuis GHCR et recrée uniquement les services `app`/`worker`. Il ne met PAS à jour le fichier `compose.prod.yml` présent sur le VPS (le repo du VPS restait sur un ancien commit) et ne recrée PAS le conteneur `db`. Conséquence : toute modification de la configuration du service `db` dans `compose.prod.yml` (comme le flag [[DT-32]]) n'est jamais appliquée automatiquement au déploiement ; il faut intervenir manuellement sur le serveur (`git reset` + `--force-recreate db`). Le problème se reproduira à chaque changement de config `db`.
**Impact** : les changements de configuration d'infrastructure `db` (flags MySQL, variables, volumes) nécessitent une intervention manuelle sur le VPS ; risque d'échec de déploiement silencieux (le pipeline réussit mais la config attendue n'est pas active).
**Action proposée** : faire en sorte que le pipeline (a) synchronise `compose.prod.yml` sur le VPS (via `git pull`/`reset` du repo de déploiement, ou copie du fichier), et (b) recrée le conteneur `db` quand sa configuration change (`docker compose up -d --force-recreate db`, volume préservé). A cadrer : détecter le changement de config `db` pour éviter une micro-coupure `db` à chaque déploiement.
**Hors périmètre** : la refonte complète de la stratégie de déploiement.
**Priorité** : 🟡 moyenne (fiabilité du déploiement ; contourné manuellement à ce jour).

## DT-35 — Constante morte `UtilisateurVoter::DELETE` jamais branchée (🟢 BAS) — ✅ RÉSOLUE (15/07/2026)

> **✅ RÉSOLUE le 15/07/2026** sur branche `chore/nettoyer-constante-delete-morte` (PR #107).
>
> **Origine** : la constante `UtilisateurVoter::DELETE` (`= 'UTILISATEUR_DELETE'`) avait été définie dès la mise en place du Voter, en anticipation d'une suppression de compte réservée au SUPER_ADMIN. Cette suppression n'a jamais été implémentée : le droit à l'effacement (US-12.3) a été réalisé par **anonymisation self-service** via la constante `ANONYMISER`. `DELETE` restait donc définie, avec sa règle dans le `match`, mais n'était invoquée par aucun `denyAccessUnlessGranted` — code mort résiduel.
>
> **Résumé fix** : suppression de la constante `DELETE`, de son entrée dans le tableau `ATTRIBUTS` et de son bras de `match`, après vérification par grep qu'elle n'était référencée nulle part dans `src/` ni `templates/`. Retrait des deux tests unitaires qui la couvraient (`test_super_admin_peut_supprimer_un_utilisateur`, `test_non_super_admin_ne_peut_pas_supprimer`).
>
> **Validation** : grep sans référence résiduelle (hors définition), 326 tests verts (les 2 tests de la constante morte retirés), PHPStan niveau 8 = 0, PHP-CS-Fixer conforme.

**Détecté** : 15/07/2026, lors de l'audit final de complétude (revue du code mort du Voter, après l'implémentation du droit à l'effacement US-12.3).

**Constat** : `UtilisateurVoter::DELETE` était définie mais jamais branchée ; l'effacement de compte passe par `ANONYMISER`, rendant `DELETE` inutilisée (code mort résiduel).

**Fichiers concernés** : `src/Security/UtilisateurVoter.php`, `tests/Security/UtilisateurVoterTest.php`.

**Action réalisée** : suppression de la constante, de son entrée `ATTRIBUTS`, de son bras de `match` et des deux tests associés, après vérification d'absence de référence.

**Hors périmètre** : les autres constantes du Voter (`VIEW`/`EDIT`/`DEACTIVATE`/`CHANGE_ROLE`/`ACTIVATE`/`ANONYMISER`, toutes utilisées) ; `CreneauVoter::DELETE` (autre Voter, bien utilisé — inchangé).

**Priorité** : 🟢 basse (nettoyage cosmétique de code mort ; aucun impact fonctionnel ni sécurité).

## DT-36 — Rectification d'email non self-service (art. 16 RGPD satisfait par voie administrative) (🟢 BAS) — ✅ CLÔTURÉE (LIMITE ASSUMÉE) (15/07/2026)

> **✅ CLÔTURÉE le 15/07/2026 (LIMITE ASSUMÉE)** — décision de NE PAS implémenter le changement d'email en self-service à ce stade.
>
> **Origine** : dans l'espace self-service `MonProfil`, l'utilisateur peut rectifier son prénom et son nom, mais **pas son adresse email** (lecture seule ; `MonProfilType` ne mappe que `prenom`/`nom`, choix documenté anti-escalade de privilège). Le changement d'email n'est possible que par un super-administrateur (`CompteController` / `UtilisateurAdminType`). Le droit de rectification (art. 16) est donc **partiellement** self-service.
>
> **Décision & justification** : (1) **Conformité** — l'art. 16 n'impose pas d'UI self-service ; il impose que le responsable de traitement rectifie sans délai **sur demande**. Le changement d'email **médié par l'admin** (Cnam, avec vérification d'identité) **satisfait l'art. 16** → pas de non-conformité, seulement une commodité UX non implémentée. (2) **Sécurité** — l'email est **l'identifiant de connexion** (`security.yaml` `property: email`, `getUserIdentifier()`) **et** le canal de reset password : un self-service naïf exposerait à la **prise de contrôle de compte** (session détournée → changement vers l'email de l'attaquant → reset) et au **verrouillage du vrai propriétaire** (email erroné → plus de connexion ni de reset). Sous deadline, le rapport risque/bénéfice est défavorable.
>
> **Évolution future (si un jour)** : changement d'email à **double confirmation**, en **réutilisant le pattern verify-email d'US-12.4** : ré-authentification par mot de passe, email de confirmation au **nouvel** email (application différée jusqu'au clic), **notification à l'ancien** email, contrôle d'unicité et gestion de la session (`isEqualTo`). Estimé ~10-14 fichiers + migration (colonne `email_en_attente`).

**Détecté** : 15/07/2026, lors de l'audit final RGPD (couverture des droits art. 15-22).

**Constat** : rectification nom/prénom = self-service ; rectification email = via admin uniquement.

**Fichiers concernés** : `src/Form/MonProfilType.php` (email non mappé, lecture seule) ; `src/Controller/Admin/CompteController.php` + `src/Form/UtilisateurAdminType.php` (changement d'email par l'admin).

**Action réalisée** : décision documentée de conserver l'email en lecture seule côté self-service ; **aucune modification de code**.

**Hors périmètre** : la rectification nom/prénom (déjà self-service) ; le changement d'email par l'admin (déjà fonctionnel).

**Priorité** : 🟢 basse (commodité UX ; conformité RGPD déjà assurée par voie administrative ; risque de sécurité si implémenté naïvement).

## DT-37 — Email journalisé en clair dans LoginFailureListener sur échec de connexion (🟢 BAS) — ⏳ OUVERTE (basse priorité) (15/07/2026)

> **⏳ OUVERTE (basse priorité)** — identifiée le 15/07/2026, correction différée.
>
> **Origine** : `LoginFailureListener` journalise l'adresse email saisie (`['email' => $email]`) sur les échecs de connexion (compte désactivé, identifiants invalides), pour la traçabilité des tentatives (OWASP A09). C'est une **donnée personnelle en clair** dans le canal de logs `security`, en **légère tension avec la minimisation** : le reste de l'application journalise l'identifiant numérique (jamais l'email), et `NotificationService` ne loggue qu'un **hash partiel** de l'adresse.
>
> **Nuance** : l'email est ici l'entrée d'une tentative (pas nécessairement un compte existant), et sa journalisation sert la détection d'attaques ; le risque est faible (canal `security` à accès restreint). Mais la cohérence avec le reste de l'app plaide pour une pseudonymisation.
>
> **Évolution proposée** : pseudonymiser l'email dans ce listener (hash partiel SHA-256 tronqué, comme `NotificationService`, ou troncature type `j***@domaine`), pour conserver la valeur de corrélation sans exposer l'adresse en clair.

**Détecté** : 15/07/2026, lors de l'audit final RGPD (revue de la journalisation).

**Constat** : `LoginFailureListener` écrit l'email en clair dans le canal `security`, contrairement au reste de l'app (identifiants numériques / hash partiel).

**Fichiers concernés** : `src/EventListener/LoginFailureListener.php`.

**Action proposée** : pseudonymiser l'email (hash partiel ou troncature) tout en conservant la traçabilité des tentatives.

**Évolution depuis DT-44 (27/08/2026)** : le canal `security` n'écrivait alors que sur `php://stderr`, capté par Docker et **détruit avec le conteneur**. L'adresse en clair ne survivait donc pas au déploiement suivant, ce qui bornait de fait la tension avec la minimisation. **Ce n'est plus le cas** : DT-44 a ajouté un second handler `rotating_file` sur volume persistant, avec une rétention de **six mois glissants** (`max_files: 180`). L'adresse tentée est désormais conservée six mois, y compris celle de personnes qui n'ont pas de compte. La nature du défaut est inchangée, sa durée ne l'est pas, et cet écart n'avait pas été instruit au moment de DT-44.

**Hors périmètre** : la journalisation des autres événements (déjà sur identifiants numériques) ; le mécanisme de throttling (inchangé).

**Condition de levée** : la dette sera close lorsque `LoginFailureListener` n'écrira plus l'adresse en clair dans le canal `security`, mais une forme pseudonymisée conservant la corrélation entre tentatives d'une même adresse, sur le modèle du hash partiel SHA-256 tronqué déjà employé par `NotificationService`. Deux conditions accompagnent la levée : les tests de l'écouteur (`tests/EventListener/LoginFailureListenerTest.php`, cinq tests dont un porte sur le contenu exact du contexte journalisé) doivent être mis à jour pour vérifier l'absence d'adresse en clair, et la valeur journalisée doit rester stable dans le temps pour qu'une attaque répartie sur plusieurs jours reste corrélable.

**Priorité** : 🟢 basse (tension mineure avec la minimisation ; logs à accès restreint ; aucun impact fonctionnel).

## DT-38 — Faux positif schema:validate sur la table historique_utilisateur (trigger US-12.1, non mappée Doctrine) (🟢 BAS) — ✅ CLÔTURÉE (NOTE TECHNIQUE) (15/07/2026)

> **✅ CLÔTURÉE le 15/07/2026 (NOTE TECHNIQUE)** — comportement attendu, documenté pour lever toute ambiguïté (notamment au jury).
>
> **Origine** : `php bin/console doctrine:schema:validate` signale « The database schema is not in sync with the current mapping file », et `doctrine:schema:update --dump-sql` propose un unique `DROP TABLE historique_utilisateur`. Ce n'est **pas** un désalignement réel : la table `historique_utilisateur` est créée par la migration `Version20260629120000` (US-12.1) avec un **trigger** + une **procédure stockée**, et elle est **volontairement non mappée** par l'ORM (alimentée par le trigger SQL, jamais par Doctrine).
>
> **Conséquence** : Doctrine, ne connaissant pas cette table côté mapping, la considère « en trop » et propose de la supprimer. Il ne faut **jamais** appliquer ce `DROP` (il détruirait la traçabilité US-12.1). Le seul écart de `schema:validate` est ce faux positif ; le mapping des entités est par ailleurs déclaré correct (« mapping files are correct »).
>
> **Décision** : aucune action de code. Vigilance à la génération des migrations : `make:migration` inclut ce `DROP TABLE historique_utilisateur` parasite → il doit être **retiré manuellement** de toute migration générée (fait pour la migration US-12.4, cf. son en-tête).

**Détecté** : 29/06/2026 (mise en place du trigger US-12.1), re-confirmé le 15/07/2026 lors des audits.

**Constat** : `schema:validate` « not in sync » = uniquement `DROP TABLE historique_utilisateur` (table du trigger, non mappée par choix).

**Fichiers concernés** : `migrations/Version20260629120000.php` (création table + trigger + procédure) ; note applicable à toute future `make:migration`.

**Action réalisée** : note technique documentée ; retrait systématique du `DROP` parasite dans les migrations générées.

**Hors périmètre** : le mapping des 8 entités métier (correct) ; la logique du trigger (inchangée).

**Priorité** : 🟢 basse (faux positif cosmétique ; aucun impact — sauf à appliquer le `DROP` par erreur).

## DT-39 — Deux lacunes de test sur la couche d'accès aux données de la réservation (🟢 BAS) — ✅ RÉSOLUE (16/07/2026)

> **✅ RÉSOLUE le 16/07/2026** — identifiée puis comblée le même jour, lors de la rédaction de la section 7.3 du dossier CDA (revue de la couverture de tests de la couche d'accès aux données).
>
> **Origine** : deux méthodes d'accès aux données de la réservation sont exercées en production mais insuffisamment couvertes en test unitaire.
> 1. `ReservationRepository::existeReservationActiveEnChevauchement()` (`src/Repository/ReservationRepository.php:47`) : **pas de test dédié**. Elle est appelée en production via `ReservationService::aReservationChevauchante()`, elle-même invoquée par `ReservationController`, mais aucun test n'asserte spécifiquement le **rejet du chevauchement de deux réservations du même auditeur**. Le parcours fonctionnel couvre un cas voisin mais distinct (créneau déjà réservé par un tiers, via `isReserve()`).
> 2. Le test de `CreneauRepository::findDisponibles()` (`tests/Integration/CreneauRepositoryQueriesTest.php:117`, `test_find_disponibles_executes_sans_erreur_dql`) est un **test de validité DQL** : il vérifie que la requête, dont la sous-requête `NOT EXISTS`, s'exécute sans erreur de grammaire (valeur de non-régression : il fige la syntaxe d'une requête complexe), **sans asserter exhaustivement chaque cas de résultat métier**.
>
> **Nuance** : aucune de ces deux méthodes n'est défaillante ; elles sont correctes et exercées de bout en bout par le parcours fonctionnel. Il s'agit d'une lacune de **couverture de test unitaire ciblé**, pas d'un défaut fonctionnel.
>
> **Résumé fix** : (1) nouveau fichier `tests/Integration/ReservationRepositoryQueriesTest.php` (6 tests) assertant le résultat de `existeReservationActiveEnChevauchement()` : chevauchement partiel et plage identique → vrai ; absence de chevauchement, adjacence bord à bord, réservation ANNULEE, réservation d'un autre auditeur → faux. (2) `CreneauRepositoryQueriesTest` enrichi d'un test de résultat de `findDisponibles()` (créneau réservé ACTIVE exclu, redevenu disponible après annulation, passé exclu, propriétaire inactif exclu), isolé des fixtures par un filtre `serviceId` dédié. Aucune modification du code métier (repositories inchangés).

**Détecté** : 16/07/2026, lors de la rédaction de la section 7.3 du dossier CDA.

**Constat** : `existeReservationActiveEnChevauchement()` sans test dédié (exercée seulement via le flux) ; test de `findDisponibles()` limité à la validité DQL, sans assertions de résultat métier exhaustives.

**Fichiers concernés** : `tests/Integration/ReservationRepositoryQueriesTest.php` (nouveau) ; `tests/Integration/CreneauRepositoryQueriesTest.php` (test de résultat ajouté). Méthodes testées, inchangées : `ReservationRepository::existeReservationActiveEnChevauchement` et `CreneauRepository::findDisponibles`.

**Action réalisée** : tests ciblés ajoutés (rejet du chevauchement auditeur dans un fichier dédié ; cas de résultat métier de `findDisponibles` dans le test d'intégration existant). DoD verte (PHPUnit, PHPStan niveau 8, PHP-CS-Fixer).

**Hors périmètre** : la logique des requêtes elle-même (correcte, paramétrée, exercée en production) ; le parcours fonctionnel existant (`ReservationParcoursControllerTest`), qui reste la couverture end-to-end.

**Priorité** : 🟢 basse (les deux méthodes sont exercées en production ; lacune de couverture de test unitaire, aucun impact fonctionnel ni sécurité).

## DT-40 — Cron de rappels J-1 cassé par une commande erronée, et absence de supervision des crons CreaSlot (🟡 MOYEN) — ✅ RÉSOLUE (22/07/2026)

> **✅ RÉSOLUE le 22/07/2026**, détectée et corrigée le même jour lors d'une vérification du mécanisme de notifications aux utilisateurs.
>
> **Origine** : la ligne du crontab de production appelait `app:prets:rappels-j1`, une commande qui n'existe dans aucun des deux projets hébergés. Le nom mélange la commande CreaPret `app:prets:rappels` et le suffixe CreaSlot `-j1` ; la commande réelle est `app:envoyer-rappels-j1`. La faute a été introduite le 18/07/2026, lors de l'édition manuelle du crontab pour y ajouter les tâches planifiées de CreaPret.
>
> **Résumé fix** : ligne du crontab corrigée vers `app:envoyer-rappels-j1`, après sauvegarde horodatée du crontab. Vérification par exécution manuelle dans le conteneur de production : code de sortie 0, sortie `Rappels J-1 : 0 envoyés, 0 erreurs`, plus aucune exception de commande inconnue.
>
> **Mesure préventive** : trois sondes Uptime Kuma de type push ajoutées sur les crons CreaSlot (sauvegarde de la base, rappels J-1, purge du journal d'administration), sur le modèle déjà en place côté CreaPret. Chaque ligne cron se termine par un `curl` de battement précédé de `&&`, si bien que le battement n'est émis que lorsque la commande sort en succès. L'ajout a nécessité d'exempter le chemin `/api/push/` du `basic_auth` dans le Caddyfile du proxy mutualisé, par alignement sur le bloc CreaPret existant. Caddyfile validé par `caddy validate`, puis rechargé à chaud par `caddy reload` : aucun redémarrage de conteneur, aucune coupure sur les 7 sites servis. Modification reportée et commitée dans le dépôt versionné `infra-proxy`. **État actuel** : le dispositif a depuis été complété et compte désormais **huit moniteurs**, répartis en **trois types** — trois interrogations directes (état de santé applicatif, préproduction, production publique) ; trois sondes en attente de signal (sauvegarde de la base, rappels J-1, purge du journal d'administration), où c'est l'absence du battement qui alerte ; et deux sondes déclenchées par évènement (blocages de connexion en préproduction et en production, DT-44), en **mode inversé**, où c'est à l'inverse la présence d'une sollicitation qui alerte.

**Détecté** : 22/07/2026, lors d'une vérification du mécanisme de notifications aux utilisateurs.

**Constat** : les rappels J-1 n'ont plus été envoyés du 18/07 au 22/07/2026, soit cinq exécutions en échec, une par soir à 14h00 UTC. Le fichier `~/cron-logs/rappels-j1.log` contenait à chaque fois `There are no commands defined in the "app:prets" namespace`. Les 31 exécutions précédentes, du 18/06 au 17/07, s'étaient déroulées normalement.

**Cause de la détection tardive** : aucune sonde ne surveillait les crons CreaSlot, alors que les trois crons CreaPret poussaient déjà un battement vers Uptime Kuma à chaque exécution. La tâche échouait en silence dans un fichier de log que personne ne consultait, pendant cinq jours. À la date des faits, l'instance Uptime Kuma de CreaSlot ne comptait qu'un seul moniteur, une interrogation directe sur l'endpoint `/health`.

**Fichiers concernés** : aucun fichier applicatif. Le crontab vit uniquement sur le VPS et n'est versionné nulle part ; `docs/cron-rappels-j1.md` documentait déjà la bonne commande depuis l'origine, seul le crontab du serveur avait dérivé. Le `Caddyfile` du dépôt `infra-proxy` a été modifié pour la mesure préventive.

**Action réalisée** : correction du crontab, vérification par exécution manuelle, ajout de trois sondes Kuma avec validation immédiate de chaque jeton de push, exemption du chemin de push dans le Caddyfile avec rechargement à chaud et contrôle des 7 sites.

**Constat associé** : la base de production ne contient aucune réservation (4 comptes de test, 0 rendez-vous). Aucun rappel réel n'a donc été manqué pendant la panne. La validation de la chaîne d'envoi repose sur la préproduction, qui dispose d'un jeu d'essai, et sur les tests Brevo documentés en [[DT-23]] et [[DT-24]].

**Hors périmètre** : la commande `app:envoyer-rappels-j1` elle-même, correcte et couverte par trois tests unitaires ; le worker Messenger et la file d'envoi, vérifiés sains (aucun message bloqué ni en échec) ; la supervision des crons CreaPret, déjà en place.

**Priorité** : 🟡 moyenne (fonctionnalité de production inopérante pendant cinq jours ; impact réel nul faute de données en base, mais le défaut de supervision qui l'a masquée couvrait aussi la sauvegarde quotidienne).

## DT-41 — Configuration CSRF dupliquée dans les douze form types (🟢 BAS) — ✅ RÉSOLUE (05/08/2026)

> **✅ RÉSOLUE le 05/08/2026** — découverte le 04/08/2026 via la porte de qualité SonarCloud de la demande d'intégration #132, puis factorisée dans la foulée.
>
> **Origine** : la protection CSRF est une politique de sécurité transverse strictement identique partout (toujours active, jamais désactivée). Elle était pourtant exprimée par copier-coller : chaque `configureOptions()` de formulaire redéfinissait le même couple `csrf_protection => true` / `csrf_token_id => '<jeton>'`, seule la valeur de l'identifiant de jeton variant légitimement d'un formulaire à l'autre. Duplication **structurelle** (même règle recopiée), et non accidentelle.
>
> **Ampleur réelle** : la porte SonarCloud « Duplication on New Code » avait d'abord signalé le bloc sur **deux** formulaires (`ReservationType` et `AnnulationReservationType`, à l'origine du blocage de la demande #132). L'inspection du reste de `src/Form/` a révélé que **douze** formulaires portaient le même bloc, pas deux ; la factorisation a donc été menée sur l'ensemble.
>
> **Résumé fix** : nouveau trait `App\Form\ProtectionCsrfTrait` (`src/Form/ProtectionCsrfTrait.php`) exposant `configurerProtectionCsrf(OptionsResolver $resolver, string $csrfTokenId)`. Il mutualise l'unique constante (`csrf_protection => true`) et **exige** le `csrf_token_id` en paramètre : l'identifiant de jeton reste propre à chaque formulaire (isolation des jetons — un jeton émis sur un formulaire ne doit pas en valider un autre). Les douze form types délèguent désormais au trait tout en conservant leurs options spécifiques (`data_class`, `creneau_reserve`, `avec_mot_de_passe` et leurs `setAllowedTypes`). Aucune logique de champ ni de validation modifiée.
>
> **Alternatives écartées** : (1) **classe de base abstraite** (`AbstractCsrfType extends AbstractType` avec un `getCsrfTokenId()` abstrait) — écartée car elle imposait de changer la hiérarchie des douze classes (leur `extends`, leur annotation générique `@extends AbstractType<X>`), compliquait le `data_class` via `parent::configureOptions()` et gênait les classes déclarées `final` ; le trait est chirurgical, les classes restent `extends AbstractType`. (2) **jeton CSRF mutualisé** (un `csrf_token_id` unique partagé) — écartée car elle aurait **affaibli la protection** : un jeton unique validerait plusieurs formulaires. L'identifiant demeure donc distinct par formulaire.

**Détecté** : 04/08/2026, via la porte de qualité SonarCloud (« Duplication on New Code ») sur la demande d'intégration #132.

**Constat** : le couple `csrf_protection => true` / `csrf_token_id => '<jeton>'` était recopié à l'identique dans les douze `configureOptions()` des form types ; seule la valeur du jeton différait. SonarCloud a d'abord flaggé deux formulaires, l'analyse en a révélé douze.

**Fichiers concernés** : `src/Form/ProtectionCsrfTrait.php` (nouveau) ; les douze form types adaptés (`AnnulationReservationType`, `ChangePasswordFormType`, `ChangementMotDePasseType`, `CreneauType`, `InscriptionType`, `MonProfilType`, `PreferencesNotificationType`, `RenvoiConfirmationType`, `ReservationType`, `ResetPasswordRequestFormType`, `SuppressionCompteType`, `UtilisateurAdminType`). Le même lot a embarqué la documentation de `ReservationType` (PHPDoc de classe + commentaire auditeur), retenue lors de #132 à cause de cette duplication.

**Action réalisée** : extraction du bloc CSRF dans le trait `ProtectionCsrfTrait`, délégation depuis les douze formulaires avec conservation de leur jeton propre et de leurs options spécifiques. Publié dans la demande #133, fusionnée en regroupement dans `develop`.

**Vérification** : PHP-CS-Fixer 0 écart, PHPStan niveau 8 sans erreur, PHPUnit 336 tests / 1184 assertions. `csrf_protection` n'apparaît plus qu'en un seul point (le trait) ; les douze `csrf_token_id` restent distincts (vérifié par extraction).

**Constat associé** : en touchant `ReservationType` et `AnnulationReservationType`, la refactorisation a transformé leurs lignes en « code neuf » et fait remonter une **seconde** duplication, préexistante et distincte du CSRF : les deux formulaires construisent un même champ `TextareaType` optionnel (mêmes `attr`, `help` RGPD identique au mot près, contrainte `Length(max: 500)`), seuls le nom du champ, le libellé et le message différant. Elle n'a **pas** été factorisée : un helper couvrant ces deux usages exigerait cinq à six paramètres (nom, libellé, placeholder, message, nombre de lignes…), soit de la sur-ingénierie contraire à la sobriété visée par le projet. SonarCloud est donc resté rouge sur #133 (duplication à 9,2 % sur code neuf), mais les quatre portes requises (cs-fixer, phpstan, phpunit, audit) étaient vertes et la porte SonarCloud n'est pas bloquante ; la demande a été fusionnée en connaissance de cause.

**Hors périmètre** : la logique de construction des champs et les contraintes de validation (inchangées) ; la duplication résiduelle du champ textarea RGPD ci-dessus (assumée) ; les autres mesures de qualité de #133, au vert (couverture 94,1 %, notes de fiabilité, sécurité et maintenabilité au niveau maximal).

**Priorité** : 🟢 basse (duplication de configuration, sans impact fonctionnel ni sécurité ; la protection CSRF elle-même n'a jamais été affaiblie, chaque formulaire conservant son jeton propre).

---

## DT-42 — Connexion applicative vers MySQL en clair sur les quatre services (🟡 MOYEN) — ✅ RÉSOLUE (27/08/2026)

> **✅ RÉSOLUE le 27/08/2026**, après un diagnostic en lecture seule mené le 23/08 puis un déploiement en deux temps, préproduction le 26/08 et production le 27/08.
>
> **Origine** : le serveur MySQL savait déjà faire du TLS (`have_ssl = YES`, `have_openssl = YES`) et ses certificats auto-signés étaient présents dans le datadir depuis le premier démarrage, le 16/06/2026. Rien ne manquait côté serveur. Côté client, PDO sous mysqlnd **ne négocie pas TLS spontanément** : sans option explicite, il ouvre une session en clair, même face à un serveur qui accepte le chiffrement.
>
> **Résumé fix** : l'autorité `ca.pem` est extraite du conteneur `db` et versionnée dans `docker/mysql/ca.pem` (clé publique, pas un secret), puis montée en lecture seule sur `/etc/mysql/ca.pem` dans les **quatre** services applicatifs. Les options TLS sont posées via `driverOptions` et les constantes PDO (`PDO::MYSQL_ATTR_SSL_CA`, et `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` à `false`), dans le bloc **`when@prod` uniquement**. Commit de référence sur `main` : **`8a9d6ae`** (demande d'intégration #144).

**Détecté** : 23/08/2026, lors d'un état des lieux de la catégorie cryptographique de l'audit OWASP.

**Constat** : les quatre connexions applicatives vers MySQL circulaient **en clair** sur le réseau Docker interne. Mesuré depuis les applications elles-mêmes, et non depuis le conteneur MySQL : `Ssl_cipher` et `Ssl_version` étaient **vides** sur `app-prod`, `app-preprod`, `worker-prod` et `worker-preprod`. C'est la limite que `docs/audit-securite-owasp.md` déclarait en toutes lettres, et qui maintenait la catégorie cryptographique en **partiel** dans le tableau de synthèse.

**Cause racine** : PDO sous mysqlnd n'active pas TLS sans option explicite. Le paramètre nommé `ssl_ca` de DBAL n'est lu que par le driver `mysqli` ; le projet utilisant `pdo_mysql`, il fallait passer par `driverOptions` et les constantes PDO. La vérification du nom du serveur est désactivée parce que le certificat auto-signé porte le CN `MySQL_Server_8.0.46_Auto_Generated_CA_Certificate`, qui ne correspond pas à l'hôte `db` ; le chiffrement du transport reste effectif.

**Fichiers concernés** : `docker/mysql/ca.pem` (nouveau), `compose.prod.yml` (montage sur les quatre services), `config/packages/doctrine.yaml` (bloc `when@prod`), et `docs/runbook-deploiement.md` (nouvelle section 2.1 : procédure, vérification, retour arrière et réserve).

**Action réalisée** : déploiement en deux temps par le pipeline, préproduction d'abord. Résultat mesuré sur les quatre services, après recréation des conteneurs : `Ssl_cipher = TLS_AES_256_GCM_SHA384` et `Ssl_version = TLSv1.3`. Le worker de production a bien été recréé, son `RestartCount` passant de 505 à 0, et ses journaux affichent `[OK] Consuming messages from transport "async"` sans aucune occurrence de `SQLSTATE`, `Connection refused`, `certificate` ni `exception` : la preuve que sa connexion chiffrée s'ouvre, son transport étant `doctrine://default`.

**Traçabilité, effet de DT-43** : le correctif a d'abord été livré sur `develop` par le commit `5416b2e` (demande d'intégration #141), mais **ce SHA n'existe pas sur `main`**. La promotion de `preprod` vers `main` se fait en fusion écrasée, qui recrée un commit unique : les identifiants d'origine n'y survivent pas. Chercher `5416b2e` sur `main` ne donne donc rien, alors que le correctif y est bien présent, dans `8a9d6ae`. C'est un effet de **DT-43** sur la traçabilité, et il vaut pour toute entrée de ce registre citant un commit de `develop`. La règle est de citer le SHA de `main` comme référence, et celui de `develop` comme origine.

**Point de vigilance** : les workers ont été inclus dans le montage parce qu'ils ouvrent la **même** connexion Doctrine. Les omettre aurait activé le TLS pour tout le monde via `when@prod` tout en les laissant sans certificat, donc incapables de démarrer, et les rappels J-1 auraient cessé.

**Réserve** : l'autorité est liée au volume de données. Un `down -v` sur la stack de production détruirait `mysql_data_prod`, MySQL régénérerait une **nouvelle** autorité au démarrage suivant, et le `ca.pem` versionné deviendrait obsolète : plus aucune connexion applicative ne s'établirait. La remise en état consiste à réextraire l'autorité et à la recommiter.

**Hors périmètre** : le **chiffrement au repos**, écarté délibérément. Sur un serveur unique, la clé maîtresse vivrait sur la même machine que les données qu'elle protège : le gain serait de façade. Écarté également, toute contrainte `REQUIRE SSL` sur les comptes MySQL : `require_secure_transport` reste à `OFF` et aucun compte ne porte cette contrainte, ce qui maintient le retour arrière à une seule ligne de configuration à retirer. C'est le seul geste de ce chantier qui ne se déferait pas sans intervention en base.

**Priorité** : 🟡 moyenne (défense en profondeur sur un réseau Docker interne non exposé ; aucune porte ouverte n'était refermée, mais la limite était déclarée dans l'audit et affaiblissait la catégorie cryptographique).

---

## DT-43 — La fusion en squash à chaque promotion efface l'ancêtre commun entre `develop` et `preprod` (🟡 MOYEN) — ⏳ OUVERTE (26/08/2026)

> **⏳ OUVERTE le 26/08/2026** — découverte en tentant de promouvoir `develop` vers `preprod` pour
> le déploiement du TLS applicatif (demande d'intégration #142, refusée par GitHub avec
> `mergeable: CONFLICTING`).
>
> **Origine** : le ruleset des branches protégées impose la fusion en **squash**
> (`allowed_merge_methods: ["squash"]`). À chaque promotion, l'intégralité de `develop` arrive
> donc sur `preprod` sous la forme d'**un seul commit neuf**, sans lien de parenté avec les
> commits d'origine. Les deux branches se retrouvent avec le même contenu mais **plus aucun
> commit en commun**. À la promotion suivante, Git remonte jusqu'au dernier ancêtre réellement
> partagé et traite comme divergent tout ce qui a bougé des deux côtés depuis.
>
> **Le symptôme s'aggrave à chaque cycle** : plus les promotions s'espacent, plus l'ancêtre
> commun recule et plus le nombre de fichiers vus comme divergents augmente.

**Détecté** : 26/08/2026, lors de la promotion `develop` vers `preprod` portant DT-42.

**Constat** : le défaut touche les **deux arêtes** de la chaîne de promotion. Mesuré le 26/08/2026 sur
`develop` vers `preprod`, puis le même jour sur `preprod` vers `main`, à quelques heures
d'intervalle et sans aucun lien entre les deux mesures.

| Arête | Ancêtre commun réel | Divergence | Conflits | Dont faux | Fichiers dupliqués en silence |
|---|---|---:|---:|---:|---:|
| `develop` vers `preprod` | `cc521c9` (demande #99) | 37 commits contre 3 | **8** | **7** | **7** |
| `preprod` vers `main` | `afa9f14` (US-7.5, demande #82) | 21 commits contre 4 | **9** | 8 | **7** |

Les deux arêtes présentent le **même profil** : une écrasante majorité de faux conflits, où la
branche amont contient strictement le contenu de la branche aval, augmenté, la divergence n'étant
qu'une reformulation ou un ajout. Les rares conflits réels se tranchent sans perte : `.env` porte
en aval `APP_ENVIRONMENT_LABEL` et `APP_PREPROD` figés, vestige d'une approche remplacée depuis par
`.env.preprod` et `.env.prod`, injectés par `env_file` et donc prioritaires. Vérifié dans le
conteneur de production : bien que le `.env` de `main` déclare `APP_PREPROD=true` et
`APP_ENVIRONMENT_LABEL=preprod`, les valeurs effectives sont `false` et `prod`, et la page de
connexion publique ne porte ni bandeau de préproduction ni préfixe dans son titre.

Plus grave que les conflits eux-mêmes : **7 fichiers passent en fusion automatique, sans conflit,
et seraient dupliqués**. Ce sont **exactement les mêmes sept** sur les deux arêtes : les six
diagrammes de cas d'utilisation et le script de création de base, déplacés vers `docs/conception/`
en amont, restés à l'ancien chemin `docs/diagrammes/` en aval. Git, ne voyant pas la parenté,
conclut à un ajout côté aval et réintroduit chaque figure **en double, à deux chemins différents**.
Une fusion ordinaire, même avec tous les conflits correctement résolus, produirait donc un dépôt
incorrect **sans qu'aucun signal ne l'indique**.

C'est ce qui établit le **caractère structurel** du défaut : deux arêtes indépendantes, deux
ancêtres communs différents, deux volumes de divergence différents, et pourtant le même profil de
conflits et rigoureusement les mêmes sept fichiers dupliqués. Il ne s'agit pas d'un incident de
fusion mal résolu une fois, mais du comportement déterministe de l'outil dans cette configuration
de branches.

**Cause racine** : la fusion en squash sur une branche de longue durée est incompatible avec la
notion d'ancêtre commun de Git. Elle convient à l'intégration de branches de fonctionnalité
éphémères, qui disparaissent après fusion, mais pas à la promotion entre deux branches
permanentes qui doivent continuer à se comparer entre elles. Ce n'est pas un incident, c'est le
comportement attendu de l'outil dans cette configuration.

**Fichiers concernés** : sans objet, la dette porte sur la topologie des branches et non sur des fichiers.

**Contournement appliqué** : le 26/08/2026, sur l'arête `develop` vers `preprod`, fusion
`git merge -s ours origin/preprod` dans `develop`, qui restaure la parenté entre les deux branches
sans modifier l'arbre. Contrôle effectué avant poussée : arbre identique à celui
d'`origin/develop`, même empreinte `62d52e06`. La promotion, jusque-là refusée avec
`mergeable: CONFLICTING`, est passée à `MERGEABLE` sans autre intervention.

Ce geste a demandé une **levée temporaire du ruleset** : la branche n'autorisait que la fusion en
squash, laquelle aurait supprimé le second parent et rendu l'opération sans effet. Le ruleset a été
rétabli aussitôt après. La même levée sera nécessaire sur `preprod` pour l'arête suivante.

Ces contournements réparent l'état du moment, ils ne traitent pas la cause : le problème se
reproduira à chaque cycle de promotion, sur les deux arêtes.

**Effet collatéral sur la traçabilité** : la fusion écrasée ne détruit pas seulement l'ancêtre commun, elle détruit aussi les identifiants de commit. Un correctif livré sur `develop` sous un SHA donné arrive sur `main` sous un autre, et l'original n'y est pas atteignable. Toute entrée de ce registre qui cite un commit de `develop` est donc introuvable pour qui la cherche sur `main`. Constaté sur **DT-42**, dont l'entrée porte désormais les deux identifiants et l'explication.

**Condition de levée** : la dette sera close lorsque les promotions entre branches permanentes ne
produiront plus de divergence structurelle, c'est-à-dire lorsque deux promotions consécutives
s'enchaîneront sans conflit ni fichier dupliqué, sans intervention manuelle et sans fusion
`-s ours` de rattrapage.

**Hors périmètre** : le choix d'une solution. Plusieurs voies existent, elles engagent la
politique de branches et le ruleset, et méritent d'être arbitrées à froid plutôt que sous la
contrainte d'un déploiement.

**Priorité** : 🟡 moyenne. Sans impact sur le code livré ni sur la production, mais bloquant à
chaque déploiement et porteur d'un risque silencieux de duplication de fichiers.

---

## DT-44 — Aucune rétention des journaux applicatifs : le canal de sécurité disparaît avec le conteneur (🟡 MOYEN) — ✅ RÉSOLUE (27/08/2026)

> **✅ RÉSOLUE le 27/08/2026**, ouverte et close le même jour, après un diagnostic mené en instruisant la faisabilité d'une alerte automatique sur les évènements de sécurité.
>
> **Origine** : le canal `security` est correctement isolé et toujours écrit, hors `fingers_crossed` (US-9.5, cf. DT-42 pour le contexte OWASP A09). Mais son handler écrivait sur `php://stderr` et **rien ne recueillait cette sortie au delà de Docker**. Il n'existait aucun fichier de journal applicatif sur le disque du VPS : ni dans `var/log`, ni dans `~/cron-logs`, qui ne contient que les sorties des tâches planifiées.
>
> **Résumé fix** : un second handler `rotating_file` sur volume Docker nommé, six mois glissants, en plus de `stderr` qui reste en place. Demande d'intégration #149, promue en production par #153, commit `c42003a`.

**Détecté** : 27/08/2026, lors du diagnostic de faisabilité d'une alerte sur les évènements de sécurité.

**Constat** : la sortie standard d'erreur est captée par le driver `json-file` de Docker, configuré dans `compose.prod.yml` avec `max-size: 10m` et `max-file: 3`, soit **30 Mo au maximum et aucune conservation au delà**. Le fichier physique vit sous `/var/lib/docker/containers/<id>/<id>-json.log` et **est détruit avec le conteneur**. Preuve mesurée : après la recréation du conteneur de production du 27/08/2026 à 01h45, lors du déploiement du TLS, une recherche sur **trente jours** de journaux n'a remonté **aucune ligne** portant `"channel":"security"`. L'historique d'avant la recréation n'existait plus.

**Cause racine** : la journalisation applicative s'arrêtait au conteneur. Écrire sur la sortie standard d'erreur est le bon choix pour une application conteneurisée, mais il suppose un collecteur en aval qui persiste. Ce collecteur n'existait pas : le cycle de vie des journaux était donc celui du conteneur, alors qu'une trace de sécurité doit survivre au redéploiement qui suit l'incident.

**Fichiers concernés** : `config/packages/monolog.yaml` (handlers `security` et `security_fichier`) ; `compose.prod.yml` (volumes `logs_preprod` et `logs_prod`, politique `x-logging`) ; `docs/runbook-deploiement.md` (sections 3.3 et 6.1). Aucun code applicatif n'était en cause pour la rétention elle-même.

**Action réalisée, rétention** : un second handler `security_fichier` a été ajouté au canal, en plus de celui sur `stderr` qui reste en place. Type `rotating_file`, rotation quotidienne, `max_files: 180`, soit **six mois glissants**, écrivant dans `%kernel.logs_dir%/security.log` sur un volume Docker nommé (`logs_preprod`, `logs_prod`). Le point de montage `/var/www/html/var/log` existe déjà dans l'image en `app:app`, le volume hérite donc des droits sans modification du Dockerfile, sur le modèle éprouvé de `/srv-assets`.

**Pourquoi six mois et non douze** : la durée est volontairement plus courte que celle du journal d'administration (`JournalAdmin::DUREE_CONSERVATION_MOIS = 12`), et cet écart est assumé. Les deux traces n'ont pas la même finalité. Le journal d'administration sert l'**accountability** (RGPD art. 5.2) sur des actes administratifs accomplis par des personnes habilitées : sa valeur ne décroît pas. Le canal `security` est une trace de **détection technique**, dont la valeur décroît vite et qui journalise l'adresse **tentée**, y compris celle de personnes qui n'ont pas de compte et ne sauront jamais qu'elle a été enregistrée. Une durée plus courte sert donc la minimisation (art. 5.1.c) et la limitation de la conservation (art. 5.1.e). Six mois est par ailleurs la durée de référence pour les journaux de connexion.

**Preuve de survie, préproduction (27/08/2026)** : ligne écrite par le chemin applicatif réel, empreinte `2adc442e753c0c2003fa390ddbe8db10b7311ad703358b76a7b33e9b98464ad8` relevée, `up -d --force-recreate app-preprod` exécuté, conteneur `07d559fcab9977fc` remplacé par `06ef303f1122c90e`, fichier et empreinte identiques après recréation alors que `docker compose logs` ne comptait plus aucune ligne du canal.

**Preuve de survie, production (27/08/2026)** : même protocole sur `app-prod`, ligne écrite après le déploiement de `c42003a`, empreinte `9b75cccf896089641edb2a84c85e6a92275714abb63447856d57844a18af1486`, conteneur `2abf7cf4bd498396` remplacé par `41689b0397e27c09`, fichier et empreinte identiques, zéro ligne du canal restante dans `docker logs`. Smoke à 200 et TLS 1.3 vers MySQL intacts après l'opération.

**Ce que cette dette conditionnait** : une alerte temps réel sans rétention n'aurait pas eu de sens. Le signal serait parti, mais l'enquête qui suit n'aurait **rien eu à lire** une fois le conteneur recréé. La rétention étant acquise, l'alerte a été construite dans la foulée (demande d'intégration #154) : `AlerteSecuriteService` sollicite un moniteur push Uptime Kuma en mode inversé quand `LoginFailureListener` rencontre une `TooManyLoginAttemptsAuthenticationException`. L'appel est isolé par un `try`/`catch` qui avale, borné à deux secondes, emprunte le réseau Docker interne, et ne transmet aucune donnée personnelle. Éprouvée en préproduction le 27/08 : six tentatives à travers la pile de sécurité complète, cinq échecs puis un blocage, ligne `ERROR` écrite, battement important reçu par la sonde, six réponses HTTP 302 dont la dernière à 21 ms. Sa promotion en production reste à faire.

**Point de vigilance** : toute commande `docker compose` qui résout une image et vise un seul service exige l'export préalable du tag, faute de quoi elle échoue sur `ghcr.io/sgahovey/creaslot:latest`, absent du registre. Constaté pendant la preuve de préproduction, documenté en section 3.3 du runbook.

**Réserve** : le volume n'est pas sauvegardé. `scripts/backup-db.sh` ne couvre que la base. Les journaux survivent désormais à la recréation d'un conteneur, ce qui était l'objet de cette dette, mais pas à la perte du VPS. Par ailleurs, le volume `logs_prod` a été créé vide : les traces antérieures de la production, qui n'existaient que sur `stderr`, ne sont pas récupérables.

**Condition de levée** : satisfaite. Une ligne du canal `security` écrite avant une recréation de conteneur reste consultable après cette recréation, en production, et la fenêtre de rétention est fixée à six mois et documentée.

**Hors périmètre** : le passage à un collecteur externe (indexation, corrélation, recherche sur plusieurs services), qui n'engage ni le même coût d'exploitation ni la même surface à sauvegarder. L'arbitrage mérite d'être fait à froid, hors de la fenêtre de soutenance.

**Priorité** : 🟡 moyenne (aucun impact sur le service rendu ; mais la capacité d'investigation après incident était limitée à la durée de vie du conteneur, et elle conditionnait l'alerte automatique).

---

## DT-45 — Contrastes insuffisants dans la charte graphique (🟡 MOYEN) — ✅ RÉSOLUE (27/08/2026)

> **✅ RÉSOLUE le 27/08/2026**, ouverte et close le même jour. Constatée en mesurant l'ensemble des couples texte sur fond de l'interface au regard du RGAA, à la demande de la revue d'accessibilité.
>
> **Commits de référence sur `main`** : **`d7e79d1`** (demande d'intégration #170, les sept corrections de contraste) et **`1623556`** (demande #173, clôture du bleu téléphone). Origines sur `develop` : `c6d4390` (demande #168) et `8b817c8` (demande #171), qui n'existent pas sur `main`, cf. l'effet de DT-43 documenté en DT-42.
>
> **Origine** : la charte n'avait jamais été mesurée. Les couleurs ont été choisies pour leur cohérence visuelle, pas contre un seuil de contraste. Sur 44 couples réellement appliqués, **10 échouaient**.

**Détecté** : 27/08/2026, lors d'un diagnostic de contraste mené couple par couple sur `public/css/creaslot.css`, les templates et les couleurs de type de rendez-vous lues en base.

**Constat** : mesures selon la formule WCAG 2.1 (linéarisation sRGB au seuil 0,04045, exposant 2,4, luminance pondérée 0,2126 / 0,7152 / 0,0722). Calculateur vérifié au préalable sur deux paires de référence, noir sur blanc à 21,00 et `#767676` sur blanc à 4,54.

| Élément | Couple mesuré | Mesure | Seuil |
|---|---|---:|---:|
| Texte secondaire sur fond de page | `#6C757D` / `#F8F9FA` | 4,45 | 4,5 |
| Lien Bootstrap sur fond de page | `#0D6EFD` / `#F8F9FA` | 4,27 | 4,5 |
| Mention de copyright | `rgba(255,255,255,.45)` / `#1F4E79` | 3,10 | 4,5 |
| Bandeau de préproduction | `#FFFFFF` / `#FD7E14` | 2,57 | 4,5 |
| Bandeau de développement | `#FFFFFF` / `#17A2B8` | 3,04 | 4,5 |
| Bordure de champ de saisie | `#CED4DA` / `#FFFFFF` | 1,49 | 3,0 |
| Créneau passé, présentiel | voile 0,62 sur blanc | 2,44 | 4,5 |
| Créneau passé, visio | voile 0,62 sur blanc | 2,69 | 4,5 |
| Créneau passé, téléphone | voile 0,62 sur blanc | 2,08 | 4,5 |
| **Événement téléphone** | `#1A1A1A` / `#007BFF` | **4,37** | 4,5 |

**Cause racine** : deux mécanismes distincts, et c'est ce qui explique que le défaut ait échappé à la relecture.

1. **Aucune mesure n'était faite.** Les teintes ont été retenues à l'œil. Quatre des dix échecs se jouent à moins de 0,25 point du seuil, écart qu'aucune relecture visuelle ne peut détecter.
2. **L'atténuation des créneaux passés porte sur le texte autant que sur le fond.** `opacity: 0.62` éclaircit les deux, si bien que le rapport s'effondre alors que la règle visait seulement à faire reculer le passé. Le défaut est né d'une intention de lisibilité.

S'y ajoute un facteur aggravant : **CLAUDE.md désigne `docs/design-tokens.md` comme source de vérité unique de la charte, or ce fichier n'existe pas dans le dépôt.** La charte réelle est `public/css/creaslot.css`. Toute revue qui suivrait la consigne chercherait au mauvais endroit.

**Fichiers concernés** : `public/css/creaslot.css` (jetons et deux règles), `tests/Accessibilite/ContrasteChartTest.php` (nouveau).

**Action réalisée** : sept corrections, chacune calculée en conservant teinte et saturation en HSL et en ne faisant varier que la luminosité, jusqu'au franchissement du seuil **vérifié sur la valeur arrondie en hexadécimal**.

| Jeton ou règle | Avant | Après | Mesure obtenue |
|---|---|---|---:|
| `--cs-text-secondary` | `#6C757D` | `#6B747C` | 4,51 |
| `--bs-link-color` et `--bs-link-color-rgb` | `#0D6EFD` | `#0368FD` | 4,53 |
| `--bs-secondary-rgb` | `108, 117, 125` | `107, 116, 124` | 4,51 |
| Opacité de `.cs-footer-copyright` | `0.45` | `0.63` | 4,52 |
| `--cs-warning` | `#FD7E14` | `#C05802` | 4,53 |
| `--cs-info` | `#17A2B8` | `#128294` | 4,52 |
| `--cs-border-input` | `#CED4DA` | `#8896A5` | 3,02 |
| Opacité des créneaux passés | `0.62` | `0.89` | 4,57 et 5,39 |

**Point de vigilance** : `--cs-warning` et `--cs-orange-visio` portaient la même valeur `#FD7E14` sans être le même concept. Seul le premier est assombri ; la couleur de type visio, lue en base, n'est pas touchée. De même, la couleur de lien se surcharge par `--bs-link-color-rgb` et non par `--bs-primary`, déjà surchargée dans la charte **sans effet sur les liens** : la règle `a { color: rgba(var(--bs-link-color-rgb), …) }` de Bootstrap ignore `--bs-primary`.

**Résultat mesuré** : 44 couples recalculés après correction, **39 conformes**. Les cinq restants se répartissent ainsi : deux relèvent du bleu téléphone (cf. condition de levée), et trois sont exemptés sur vérification, à savoir les pastilles de couleur (porteuses de `aria-hidden` et systématiquement doublées du libellé en clair, vérifié aux six emplacements), la bordure de carte (décorative, la carte étant déjà distinguée par son fond) et le jeton `--cs-text-disabled` (déclaré mais appliqué nulle part, jeton mort).

**Point de contrôle** : `tests/Accessibilite/ContrasteChartTest.php` **extrait les jetons de la charte** et recalcule chaque couple à l'exécution, plutôt que de comparer à une liste figée. Modifier une couleur sans vérifier son contraste fait désormais échouer la suite. Le test fige aussi l'écart du bleu téléphone par une assertion inversée, qui échouera le jour où la teinte sera corrigée : la dette ne peut donc pas se refermer en silence.

**Arbitrage du bleu téléphone, rendu le 27/08/2026** : le couple `#1A1A1A` sur `#007BFF` plafonnait à **4,37**, et aucune opacité ne le sauvait. Trois issues ont été mesurées avant décision, la distance colorimétrique étant calculée en **CIEDE2000** (implémentation validée sur neuf paires du jeu de référence Sharma, Wu et Dalal, concordantes au dix-millième).

| Issue | Règle les quatre cas | Écrans touchés | Donnée de production |
|---|:--:|:--:|:--:|
| 1, texte assombri à `#171717` | **non**, créneau passé à 3,81 | 2 | non |
| 1 bis, texte assombri à `#090909` | **oui** | 2 | non |
| 2, couleur de base changée (`#2E93FF`, `#458EFD` ou `#35A0C5`) | oui | 12 | **oui** |
| 3, inversion seule | **non**, les six cas échouent | 2 | non |
| 3 bis, inversion et fonds assombris | oui | 2 | non |

**Issue retenue : la 1 bis**, texte porté à `#090909`. Meilleur rapport entre le résultat et le périmètre : un seul fichier, deux écrans, aucune donnée de production. L'issue 2 donnait plus de marge mais touchait douze écrans et imposait une migration de données ; l'issue 3 bis rapprochait le fond téléphone du bleu principal de la charte, de `ΔE00 = 20,9` à `12,0`.

**Mesures avant et après sur les quatre cas critiques**, texte `#1A1A1A` puis `#090909`, atténuation à 0,89 :

| Cas | Avant | Après | Seuil |
|---|---:|---:|---:|
| Événement téléphone | 4,37 | **5,00** | 4,5 |
| Créneau passé téléphone | 3,66 | **4,51** | 4,5 |
| Événement présentiel | 5,56 | 6,36 | 4,5 |
| Créneau passé présentiel | 4,57 | 5,63 | 4,5 |
| Événement visio | 6,77 | 7,75 | 4,5 |
| Créneau passé visio | 5,39 | 6,64 | 4,5 |

**Pourquoi `#090909` exactement** : c'est le gris **le plus clair** qui fasse passer les quatre cas. Le facteur limitant est le créneau passé téléphone, à **4,5067** pour un seuil de 4,5, soit une marge de **0,0067**. Dès `#0A0A0A` il retombe à 4,4564, sous le seuil.

**La marge est assumée, et outillée** : `tests/Accessibilite/ContrasteChartTest.php` relit désormais **la couleur du texte et l'opacité d'atténuation depuis la feuille de style**, puis recalcule les six couples du calendrier. Retoucher l'une ou l'autre fait échouer la suite. L'assertion inversée qui figeait l'écart du bleu téléphone est retirée : ce cas rejoint les couples protégés au même titre que les autres.

**Hors périmètre** : la reprise des captures d'écran du dossier, que le changement de teintes rend nécessaire sur les écrans listés dans la demande d'intégration. Hors périmètre également, la création du `docs/design-tokens.md` annoncé par CLAUDE.md, qui relève d'une décision documentaire distincte.

**Résultat mesuré** : **41 couples conformes sur 44**. Les trois restants sont exemptés sur vérification (pastilles décoratives, bordure de carte décorative, jeton `--cs-text-disabled` déclaré et jamais appliqué). Plus aucun écart de contraste ouvert.

**Ce qui reste ouvert par ricochet** : les badges de type sont codés en dur dans la feuille de style alors que les jetons de couleur existent et ne sont utilisés nulle part. Découvert en instruisant l'issue 2, sans effet sur le contraste, tracé séparément en **DT-46**.

**Priorité** : 🟡 moyenne (aucun impact fonctionnel ; mais le RGAA est une exigence réglementaire du référentiel CDA).

---

## DT-46 — Duplication inerte des couleurs de type dans la charte, dont les jetons ne servent à rien (🟢 BAS) — ⏳ OUVERTE (27/08/2026)

> **⏳ OUVERTE le 27/08/2026** — découverte en instruisant l'issue 2 de DT-45, qui envisageait de changer la couleur de type téléphone en base.
>
> **Origine** : la couleur d'un type de rendez-vous est une **donnée**, portée par `type_rdv.couleur_hex`. Elle est lue depuis la base partout où elle s'affiche. Trois règles de la feuille de style recopient pourtant ces mêmes couleurs **en dur**, sans qu'aucun élément de l'interface ne les porte.
>
> **Requalifiée le 28/08/2026** : l'entrée affirmait initialement qu'un changement en base désynchroniserait le badge de la pastille. C'était **faux**, et la vérification n'avait pas été faite. Les trois règles sont mortes, la duplication est **inerte**, et le défaut est de maintenabilité et non de comportement.

**Détecté** : 27/08/2026, en mesurant le périmètre d'un changement de couleur en base.

**Constat** : trois règles de la feuille de style dupliquent les valeurs de la base.

| Règle | Valeurs codées en dur | Correspond à |
|---|---|---|
| `.cs-badge-presentiel` | `rgba(40, 167, 69, 0.12)` et `#1A6E2E` | `#28A745` |
| `.cs-badge-visio` | `rgba(253, 126, 20, 0.12)` et `#A85200` | `#FD7E14` |
| `.cs-badge-telephone` | `rgba(0, 123, 255, 0.12)` et `#004A99` | `#007BFF` |

Symétriquement, les trois jetons prévus pour cela, `--cs-green-presentiel`, `--cs-orange-visio` et `--cs-blue-telephone`, sont **déclarés et utilisés zéro fois** dans la feuille de style. Ils ne servent aujourd'hui qu'au test de contraste, qui les lit pour recalculer les couples.

**Cause racine** : la charte a été écrite avant que la couleur de type ne devienne une donnée. Les règles de badge et leurs jetons datent de ce moment, et ni les unes ni les autres n'ont été retirées quand le rendu est passé à la lecture en base. Il reste donc une seconde source de vérité, que rien ne synchronise et que rien ne signale, mais que rien n'affiche non plus.

**Portée réelle, vérifiée le 28/08/2026** : la duplication est **inerte**. Les trois classes `.cs-badge-presentiel`, `.cs-badge-visio` et `.cs-badge-telephone` **ne sont posées sur aucun élément** de l'interface. La recherche de `cs-badge` dans `templates/`, `assets/` et `src/` ne remonte que `cs-badge-libre` et `cs-badge-en-rdv`, qui portent la disponibilité d'un collègue et non un type de rendez-vous. Aucune construction dynamique de nom de classe ne peut les atteindre, et elles n'existent dans aucune autre feuille de style. Ce sont des **règles mortes**.

Changer `type_rdv.couleur_hex` ne produit donc **aucune désynchronisation visible** : les 27 références au champ, dans les templates, le contrôleur Stimulus des statistiques, les deux sérialiseurs de calendrier et le formulaire de créneau, lisent toutes la donnée. Le badge visible sur une carte de créneau est un badge de **statut** (`text-bg-danger`, `text-bg-secondary`, `text-bg-info`, `text-bg-success`, cf. `templates/components/carte_creneau.html.twig` lignes 12 à 28), il n'a jamais porté la couleur d'un type.

**Ce que cela change pour l'arbitrage de DT-45** : la crainte d'une désynchronisation avait pesé contre l'issue 2, celle du changement de couleur en base. Cette crainte n'était pas fondée. L'arbitrage retenu reste le bon pour ses autres raisons, un fichier contre douze écrans et aucune donnée de production touchée, mais il ne doit plus être justifié par ce motif.

**Aucun impact de contraste** : les trois règles, si elles étaient un jour appliquées, donneraient 5,58, 4,84 et 7,38 pour un seuil de 4,5. Elles sont donc conformes autant qu'inutilisées.

**Fichiers concernés** : `public/css/creaslot.css` (déclaration des trois jetons, trois règles de badge).

**Piste, non instruite** : faire porter aux badges les jetons existants, en dérivant le voile de fond par `color-mix()` ou une variable de teinte assombrie, de sorte qu'une seule valeur gouverne les trois usages. La couleur de texte du badge (`#1A6E2E` et ses pareilles) devrait alors être dérivée ou conservée en jeton dédié, sa valeur ayant été choisie pour le contraste et non par simple assombrissement.

**Condition de levée** : les trois règles mortes et les trois jetons inutilisés sont retirés de la feuille de style, ou bien ils sont conservés à dessein et un commentaire dit lequel, pour qu'une relecture ultérieure ne les prenne pas pour une source de vérité active.

**Hors périmètre** : la refonte du système de couleurs de type, et la création du `docs/design-tokens.md` que CLAUDE.md annonce sans qu'il existe (cf. DT-45).

**Priorité** : 🟢 basse (aucun impact fonctionnel ni d'accessibilité ; le coût se paierait le jour d'un changement de charte, qui n'est pas prévu avant la soutenance).

---

## DT-47 — Symfony 8.0 n'est plus maintenue depuis juillet 2026 (🟠 HAUT) — ✅ RÉSOLUE (04/09/2026)

> **✅ RÉSOLUE le 04/09/2026**, ouverte et close le même jour. Montée de **Symfony 8.0.13 vers 8.1.6**.
>
> **Commit de référence sur `main`** : **`767ec4e`** (demande d'intégration #192). Origine sur `develop` : `40cc079` (demande #190), qui n'existe pas sur `main`, cf. l'effet de DT-43 documenté en DT-42.
>
> **Origine** : la dette n'est pas née d'un défaut du code mais du calendrier de l'éditeur. Une version qui cesse d'être maintenue ne reçoit plus de correctif de sécurité : la laisser en place transforme le temps qui passe en risque.

**Détecté** : par le dispositif de veille, qui a relevé la fin de maintenance de la branche 8.0 en **juillet 2026**. Vérifié ensuite sur la page officielle des versions de Symfony.

**Constat** : `composer.json` déclarait la contrainte `8.0.*` sur **33 déclarations**, `extra.symfony.require` compris, et `composer.lock` figeait **73 paquets `symfony/*`** sur la branche 8.0. La 8.1 exige le même PHP 8.4, déjà en place : aucun changement de plateforme n'était nécessaire.

**Cause racine** : aucune. C'est une dette de maintenance et non de conception, et elle se reformerait d'elle-même à chaque fin de branche si rien ne la surveillait. Ce qui l'a rendue visible n'est pas une relecture du code mais le dispositif de veille, ce qui est précisément sa raison d'être.

**Décision d'arbitrage : montée ciblée plutôt que globale.** Les deux périmètres ont été mesurés par une résolution à blanc, menée sur une copie de `composer.json` hors du dépôt, avant toute modification.

| | `composer update` | `composer update "symfony/*" --with-dependencies` |
|---|---:|---:|
| Paquets montés | 97 | **72** |
| Dont `symfony/*` | 69 | 69 |
| Dont tiers | 28 | **3** |

L'écart tient à ce que l'update global embarquait toute la chaîne d'outillage de tests, dont **PHPUnit 13.1.14 vers 13.3.2** et **`sebastian/diff` 8.3.0 vers 9.0.1**, qui est un changement de version majeure. Monter le framework et l'outillage qui le mesure dans le même mouvement rend les deux risques indiscernables : si la suite rougit, plus rien ne dit lequel des deux en est la cause. Le périmètre ciblé a donc été retenu, et l'outillage vérifié figé version par version dans le lock : PHPUnit `13.1.14`, PHPStan `2.2.2`, PHP-CS-Fixer `v3.95.4`, Twig `v3.27.1`.

**Prérequis levé séparément.** La confrontation du `UPGRADE-8.1.md` officiel au code, ligne à ligne, avait identifié **un seul point d'impact certain** : l'option `framework.profiler.collect_serializer_data`, dépréciée en 8.1 et posée sous `when@dev` et `when@test`. `phpunit.dist.xml` portant `failOnDeprecation="true"`, elle aurait fait tomber tout test démarrant un noyau, soit **42 fichiers sur 61**. Elle a été retirée par la demande d'intégration **#187** et promue jusqu'en production **avant** la montée, pour que les deux changements ne se mélangent pas.

Les quatre autres dépréciations du guide qui auraient pu porter tombent à côté du code, chacune vérifiée par recherche : les méthodes dépréciées de `SameOriginCsrfTokenManager` ne sont appelées nulle part, `security.erase_credentials` n'est pas configuré, les tests de formulaire n'utilisent pas `TypeTestCase`, et le seul `ChoiceType` à placeholder porte déjà `'required' => false`, forme que le guide donne lui-même comme conforme.

**Fichiers concernés** : `composer.json` (33 contraintes), `composer.lock` (72 paquets), `config/reference.php` (régénéré, il décrit la surface de configuration de la version installée).

**Action réalisée** : montée ciblée, 72 paquets modifiés, **1 installé, 0 supprimé, 0 rétrogradé**. Les 3 montées tierces sont `doctrine/dbal` 4.4.3 vers 4.4.4, `monolog/monolog` 3.10.0 vers 3.11.0 et `nikic/php-parser` v5.7.0 vers v5.8.0. Le paquet installé est `symfony/polyfill-deepclone`, exigé par la dépréciation VarExporter de la 8.1.

**Vérification : zéro dépréciation sur trois surfaces distinctes.** La configuration de tests n'intercepte que ce qui est émis pendant l'exécution des tests ; une dépréciation de configuration serait émise à la compilation du conteneur, donc en dehors. Les trois surfaces ont été contrôlées séparément.

| Surface | Mesure |
|---|---|
| Sortie PHPUnit | `OK (390 tests, 1428 assertions)`, rejouée deux fois. Aucune occurrence de `deprecat`, `risky`, `warning`, `notice` |
| Compilation du conteneur | caches `test` et `dev` détruits puis reconstruits : zéro dépréciation à l'une comme à l'autre |
| Journaux applicatifs | `var/log/test.log` : zéro. Les 1243 entrées de `var/log/dev.log` sont **toutes antérieures au 05/08/2026** et sans rapport (DBAL « MySQL < 8 », `trim(null)`, transactions Doctrine Migrations) |

S'y ajoutent PHPStan niveau 8 sans baseline (`[OK] No errors`), PHP-CS-Fixer (`0 of 166 files`), l'audit Composer (aucun avis de vulnérabilité), `lint:container` et `lint:twig` conformes.

**Vérification en production**, après déploiement du commit `767ec4e` :

| Contrôle | Résultat |
|---|---|
| Version réellement en service | `Symfony v8.1.6 (env: prod, debug: false)` |
| Image en service | `ghcr.io/sgahovey/creaslot:767ec4ec…` sur `app-prod` et `worker-prod` |
| `https://creaslot.re/connexion` | 200 |
| `https://creaslot.re/health` | 200, `{"status":"ok","checks":{"database":"ok"}}` |
| TLS de la connexion applicative à MySQL | `Ssl_version` = **TLSv1.3**, `Ssl_cipher` = **TLS_AES_256_GCM_SHA384** |

Le contrôle TLS a été mené sur la connexion réellement ouverte par la configuration DBAL de l'application en production, interrogée via `performance_schema.session_status`, et non sur la configuration déclarée. Il confirme que **DT-42 tient après la montée**, ce qui n'allait pas de soi puisque `doctrine/dbal` est l'un des trois paquets tiers montés au passage.

**Condition de levée** : atteinte. La branche 8.1 est maintenue, l'application y tourne en production, et les cinq contrôles bloquants sont au vert.

**Reste ouvert, hors périmètre de cette entrée** : la 8.1 cessera à son tour d'être maintenue. La vraie réponse durable n'est pas cette montée mais le dispositif qui l'a déclenchée. Aucune alerte automatisée ne surveille aujourd'hui la fin de maintenance des branches Symfony : c'est la lecture humaine de la veille qui a joué ce rôle.

**Priorité** : 🟠 haute au moment de l'ouverture (absence de correctifs de sécurité sur une application exposée), sans objet depuis la clôture.
