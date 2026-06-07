# Dette technique CreaSlot — Suivi

Date dernière mise à jour : 3 juin 2026.
Convention : DT-N = Dette Technique numéro N.

---

## DT-1 — Architecture OneToOne Creneau↔Reservation (🔴 CRITIQUE) — ✅ RESOLVED 27/05/2026

> **✅ RESOLVED le 27/05/2026** sur branche `bugfix/reservation-onetomany-creneau`.
>
> **Résumé fix** : Refacto vers OneToMany (Stratégie S4 retenue). Migration Doctrine `Version20260527155759` drop l'index UNIQUE sur `reservation.id_creneau` et le remplace par un index non-unique. L'invariant "1 Reservation ACTIVE max par Creneau" est désormais garanti applicatif via le `PESSIMISTIC_WRITE` dans `ReservationController::enregistrerReservation`.
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

## DT-2 — Validation horaire créneau manquante (🔴 ÉLEVÉ) — ✅ RESOLVED 28/05/2026

> **✅ RESOLVED le 28/05/2026** sur branche `bugfix/validation-horaire-creneau`.
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

## DT-3 — PHPUnit Notices willReturnCallback (🟢 BAS) — ✅ RESOLVED 29/05/2026

> **✅ RESOLVED le 29/05/2026 (US-4.8)** : 30 notices → 0. La suite tourne désormais
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

## DT-4 — Dockerfile USER non-root (🟢 BAS)

**Détecté** : 18/05/2026, incident permissions Git WSL2.

**Symptôme** : Dossier .git/objects/01/ à root après opérations Docker → "error: insufficient permission".

**Workaround actuel** : `sudo chown -R utilisateur:utilisateur ~/creaslot/.git/` préventif.

**Stratégie de fix** : Dockerfile `USER 1000:1000` pour aligner UID avec WSL2.

**Priorité** : 🟢 basse, à faire avant déploiement prod (itération 6).

---

## DT-5 — `final` retiré de NotificationService pour testabilité (🟢 BAS)

**Détecté** : 19/05/2026, lors écriture EnvoyerRappelsJ1CommandTest.

**Contexte** : NotificationService était initialement déclaré `final readonly class` (best practice Symfony 8). L'écriture du test Command nécessitait de mocker NotificationService → PHPUnit\Framework\MockObject\ClassIsFinalException.

**Choix d'arbitrage** : drop `final`, garder `readonly`. NotificationService n'a pas vocation à être étendu dans l'architecture DI Symfony actuelle, le `final` était cosmétique.

**Alternative considérée** : extraction de `NotificationServiceInterface` (architecture plus propre via Dependency Inversion Principle). Reportée car scope creep par rapport à US-4.6.

**Stratégie future** :
- Quand US-4.7 (page Mes notifications) ou US-4.8 (préférences) sera traitée, envisager l'extraction de l'interface si plusieurs implémentations émergent
- Si pas de besoin futur, garder `readonly class` simple

**Priorité** : 🟢 basse, statu quo acceptable.

---

## DT-6 — Setup BDD test à automatiser (🟢 BAS)

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

**Priorité** : 🟢 basse, à faire avant si plusieurs devs rejoignent le projet OU avant déploiement CI/CD.

---

## DT-7 — Factorisation JS templates créneau (🟢 BAS)

**Détecté** : 28/05/2026, lors du fix [[DT-2]] (niveau 1 UX).

**Contexte** : Le JavaScript des templates `personnel/creneau/nouveau.html.twig` et `personnel/creneau/modifier.html.twig` est dupliqué (mise en valeur TypeRdv, visibilité conditionnelle heureFin, `required` dynamique, et désormais le `min` dynamique DT-2). Avec 2 templates, le DRY n'est pas critique ; mais un 3e point d'entrée (ex : modal de création rapide) ou un besoin de tester le JS rendrait la factorisation utile.

**Stratégie de fix proposée** :

- **Option A** : Fichier asset dédié `assets/js/creneau-form.js` (AssetMapper) importé dans les 2 templates
- **Option B** : Stimulus controller `creneau_form_controller.js` (pattern Symfony moderne, déjà présent dans la stack via StimulusBundle)
- **Option C** : Macro Twig `{% macro creneau_form_js() %}` (inline mais centralisé)

**Recommandation** : Option B (Stimulus) — la stack embarque déjà StimulusBundle + AssetMapper, et c'est testable/réutilisable.

**Priorité** : 🟢 basse, à faire si 3e template apparaît OU besoin de tests JS.

---

## DT-8 — Migration FullCalendar CDN vers self-hosted (AssetMapper) (🟡 MOYEN) — ✅ RESOLVED 01/06/2026

> **✅ RESOLVED le 01/06/2026** (dette technique autonome) sur branche `feat/us-5.1-agenda-fullcalendar`.
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

## DT-9 — Layout email Twig partagé (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (Clean Code R.C. Martin + critères CDA).

**Constat** : Les 8 templates d'email (`templates/emails/*.html.twig`) ne partagent aucune factorisation — aucun `{% extends %}` ni `{% include %}`. Chacun (~150 lignes) ré-écrit l'intégralité de la structure HTML : doctype, `<style>` inline, `<table>` de mise en page, en-tête et signature Cnam. Toute évolution de charte (couleur, logo, mention légale RGPD) impose 8 modifications identiques → coût de maintenance et risque de divergence élevés.

**Fichiers concernés** : `templates/emails/*.html.twig` (8 fichiers : confirmation/annulation/modification/rappel auditeur, confirmation/annulation personnel, suppression créneau, test).

**Action proposée** : créer `templates/emails/_layout.html.twig` portant la structure commune (head, styles, en-tête, signature), exposant un `{% block contenu %}` ; chaque email passe à `{% extends 'emails/_layout.html.twig' %}` et ne déclare plus que son contenu propre.

**Priorité** : 🟡 moyenne, à traiter avant l'ajout d'un nouvel email OU avant un changement de charte email.

---

## DT-10 — CollegueService : requêtes en boucle (~3N+1) (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (éco-conception / performance).

**Constat** : `CollegueService::getCollegues()` itère sur la liste des collègues et déclenche **trois requêtes par collègue** (`existeCreneauActifFuturOuEnCours`, puis dans `construireDTO` : `findCreneauEnCoursAvecRdv` et `findNextReservedCreneau`), soit ~3N+1 requêtes pour N collègues. Pattern « boucle PHP qui interroge la BDD par ligne » — tolérable pour une petite équipe Cnam, mais contraire à l'éco-conception (RGESN) et non scalable.

**Fichiers concernés** : `src/Service/CollegueService.php` (`getCollegues`, `construireDTO`, `aAuMoinsUnCreneauActif`).

**Action proposée** : remplacer les requêtes par ligne par **une seule requête agrégée** (JOIN + `GROUP BY` sur le Personnel) ramenant statut courant + prochain RDV en un aller-retour, hydratée vers les `CollegueDTO`.

**Priorité** : 🟡 moyenne, à traiter quand la liste des collègues s'allonge OU dans une passe éco-conception (itération 6).

---

## DT-11 — Centraliser le formatage de date d'affichage dans DateFormatterService (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (DRY).

**Constat** : `DateFormatterService` (créé pour éliminer la duplication post-US-4.5) n'expose qu'une seule méthode (`pourSujetEmail`). Le reste du formatage de date d'affichage est **dispersé en dur** dans plusieurs fichiers, et `AppEmailTestCommand` **ré-implémente à l'identique** le format de `pourSujetEmail`. Violation directe du « un mot par concept » et de la factorisation déjà amorcée.

**Fichiers concernés** : `src/Service/SlotService.php` (`construireMessageChevauchement` : `d/m/Y`, `H:i`), `src/Service/CollegueService.php` (`H\hi`), `src/Command/EnvoyerRappelsJ1Command.php` (`d/m/Y`), `src/Command/AppEmailTestCommand.php` (ré-implémentation de `d/m/Y \à H\hi`).

**Action proposée** : étendre `DateFormatterService` avec des méthodes centralisées (`pourAffichage` date, `pourHeure`, etc., timezone `Indian/Reunion` uniforme) et router **tout** le formatage d'affichage à travers le service ; supprimer les `->format(...)` en dur.

**Priorité** : 🟡 moyenne, à traiter au prochain ajout d'un format de date OU dans une passe DRY.

---

## DT-12 — NotificationService : factoriser le squelette des 6 méthodes notifier*() (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (DRY).

**Constat** : Les six méthodes publiques `notifier*()` partagent un squelette quasi identique répété : extraction `auditeur`/`creneau`/`personnel` (avec le même bloc de commentaire 3 lignes « Reservation::utilisateur = Auditeur… » dupliqué ~5×), puis un `try { envoyer(...) } catch (\Throwable $e) { logger->error(...) }` structurellement identique ×6 (seuls le `type` et les identifiants changent). 683 lignes au total dont une large part redondante.

**Fichiers concernés** : `src/Service/NotificationService.php` (méthodes `notifierAuditeurReservation`, `notifierPersonnelReservation`, `notifierAuditeurAnnulationReservation`, `notifierPersonnelAnnulationReservation`, `notifierAuditeurCommentaireCreneau`, `notifierAuditeurSuppressionCreneau`, `notifierAuditeurRappel`).

**Action proposée** : extraire un helper privé `envoyerOuLoguer(string $type, array $idsContexte, string $to, string $subject, string $template, array $context)` encapsulant le try/catch + log RGPD ; factoriser l'extraction des trois acteurs. Chaque `notifier*()` se réduit alors à : préparer le contexte → (persister notification in-app) → déléguer au helper.

**Priorité** : 🟡 moyenne, à traiter lors de la prochaine évolution de NotificationService (nouveau type d'email).

---

## DT-13 — Self-host Bootstrap + Bootstrap Icons + Google Fonts (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 01/06/2026, lors d'une revue qualité en lecture seule (sécurité supply-chain / éco-conception / robustesse).

**Constat** : `templates/base.html.twig` charge encore par **CDN tiers** Bootstrap 5.3.3 (CSS + JS), Bootstrap Icons 1.11.3 et Google Fonts (Inter). Mêmes risques que [[DT-8]] avant correction : aucun contrôle d'intégrité (pas de SRI), dépendance à la disponibilité d'un tiers, incompatibilité CSP stricte, pas de fonctionnement hors-ligne, et requêtes externes contraires à l'éco-conception (RGESN).

**Fichiers concernés** : `templates/base.html.twig` (balises `<link>` / `<script>` lignes ~11-19 et ~56).

**Action proposée** : vendoriser ces dépendances via AssetMapper (même approche que FullCalendar en [[DT-8]]) — self-host CSS/JS/police, versions tracées. **À batcher avec US-5.2** (qui introduira le self-host de Chart.js pour les graphiques du dashboard), pour traiter tout le front CDN en une passe cohérente.

**Priorité** : 🟡 moyenne (supply-chain + CSP + RGESN), à planifier avec US-5.2.

---

## DT-14 — Invalidation immédiate de session à la désactivation (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 03/06/2026, lors de l'implémentation d'US-5.4 (activation / désactivation des comptes).

**Constat** : La désactivation d'un compte (US-5.4) bloque les **nouvelles** connexions — `UserChecker::checkPreAuth` lève `DisabledException` à l'**authentification** — mais une **session déjà ouverte survit** jusqu'à son expiration : `UserChecker` n'est **pas** réexécuté à chaque requête (il n'agit qu'au login, pas sur le `refreshUser` du firewall stateful).

**Impact** : un compte désactivé **en cours de session** conserve son accès jusqu'à déconnexion ou expiration de la session. Risque **faible** au volume Cnam (peu d'utilisateurs, désactivations rares), mais réel sur le plan sécurité.

**Fichiers concernés** : `src/Security/UserChecker.php`, `config/packages/security.yaml` (firewall `main` / provider `app_user_provider`).

**Action proposée** : re-vérifier `estActif` à **chaque requête** — soit (a) en faisant **échouer `refreshUser`** quand le compte est inactif (provider décorant `app_user_provider`, ou `Utilisateur` implémentant `EquatableInterface`/contrôle au refresh), soit (b) via un **listener `kernel.request`** qui invalide la session d'un utilisateur devenu inactif. Tâche **dédiée**, avec test fonctionnel : **session active → désactivation du compte → 302 vers login à la requête suivante**.

**Priorité** : 🟡 moyenne (sécurité ; risque faible au volume Cnam), à planifier en tâche dédiée.

---

## DT-15 — Purge automatisée du journal RGPD au-delà de la durée de conservation (🟡 MOYEN) — 🟠 OUVERTE

**Détecté** : 03/06/2026, lors de l'implémentation d'US-5.5 (journal RGPD).

**Constat** : Le journal d'administration (`journal_admin`, US-5.5) **grandit indéfiniment** : chaque action sensible sur un compte y ajoute une entrée, sans suppression. La **durée de conservation de 12 mois** est documentée (finalité accountability, registre des traitements) mais **n'est pas appliquée** techniquement — aucune purge des entrées expirées.

**Impact** : conservation de données nominatives **au-delà** de la durée annoncée (non-conformité RGPD au principe de **limitation de la conservation**, art. 5.1.e) ; croissance non bornée de la table. Risque faible à court terme (volume Cnam, peu d'actions admin), réel sur la durée.

**Fichiers concernés** : nouvelle commande console (`src/Command/`), `JournalAdminRepository` (méthode de suppression bornée), planification cron (`docs/cron-*` / infra).

**Action proposée** : **commande console** (ex. `app:purger-journal`) supprimant en DQL paramétré les entrées `date_action < now - 12 mois`, **planifiée par cron** (comme le rappel J-1). Avec **test** : insertion d'entrées anciennes + récentes → seules les anciennes sont purgées. Durée de conservation portée par une **constante nommée** (source unique).

**Priorité** : 🟡 moyenne (conformité RGPD ; croissance lente au volume Cnam), à planifier en tâche dédiée.

---

## DT-16 — Mutualisation des helpers FullCalendar et du JSON no-store (🟡 MOYEN) — 🟠 OUVERTE

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

## DT-17 — Mutualisation des helpers Chart.js entre les deux contrôleurs Stimulus (🟡 MOYEN) — 🟠 OUVERTE

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
