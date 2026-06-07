# Plan de tests — CreaSlot

> Livrable de mémoire MSP3 — Concepteur Développeur d'Applications (CDA).
> Dernière exécution de référence : **07/06/2026 16:08**, environnement Docker (PHP 8.4.21, MySQL 8.0).

---

## 1. Objectif & périmètre

Ce document constitue le **plan de tests** de l'application CreaSlot au sens ISTQB/CFTL : il décrit la
stratégie, les niveaux de test, l'environnement d'exécution, la traçabilité des exigences vers les cas de
test, deux dossiers détaillés (jeu d'essai fonctionnel et test de sécurité), les limites assumées, et le
dossier de compte rendu de tests (résultats).

Il est rattaché à la compétence **CP9 « Préparer et exécuter les plans de tests d'une application »** (bloc 3
du référentiel CDA). Il mobilise également **CP2/CP3** (développement de composants, back-end sécurisé — via
le jeu d'essai fonctionnel) et **CP8** (composants métier sécurisés — via les tests de sécurité). Une
sous-section « Couverture CP9 » (fin de §1) relie chaque critère de performance reformulé à sa réalisation
dans le projet.

**Public visé** : le jury MSP3 (preuve d'une démarche de test outillée et traçable) et l'équipe projet
(référence opérationnelle pour maintenir la non-régression).

**Périmètre** : la **suite de tests automatisée** de CreaSlot — **245 cas de test**, **907 assertions**,
exécutés par PHPUnit 13.1.8, complétés par l'analyse statique (PHPStan 2.2.2) et le contrôle de style
(PHP-CS-Fixer), le tout vérifié en intégration continue (GitHub Actions, 3 jobs).

**Hors-périmètre** (assumé, cf. §7) : tests de **charge/performance**, **fuzzing**, et **recette
utilisateur formelle** (procès-verbal signé). En l'absence de cette dernière, le **jeu d'essai détaillé**
de §5, rejoué automatiquement à chaque exécution, **tient lieu de test d'acceptation** documenté et
reproductible.

### Couverture CP9 (critères de performance reformulés → réalisation projet)

| Critère de performance CP9 (reformulé, sans citation du REAC) | Où c'est traité |
|---|---|
| Le plan de tests couvre les fonctionnalités de l'application | Matrice de traçabilité US → tests (§4), cartographie (§3) |
| Un environnement de test est mis en place | Base de test dédiée `creaslot_test` + service MySQL 8 en CI (§2) |
| Les tests sont exécutés conformément au plan ; résultats attendus comparés aux obtenus | Jeu d'essai / cahier de recette (§5) + compte rendu (§8) |
| Les niveaux de test (unitaire, intégration, système) et de sécurité sont couverts | Pyramide chiffrée et cartographie (§2, §3) + dossier sécurité (§6) |
| Un dossier de compte rendu de tests est produit | Résultats datés et chiffrés (§8) |
| Les anomalies sont tracées et suivies | Registre de dette technique `docs/dette-technique.md` (DT-1 … DT-19) |

---

## 2. Stratégie de test

### 2.1 Niveaux de test et pyramide

La suite est structurée selon les **niveaux de test ISTQB** et respecte la forme d'une **pyramide**
(base large de tests rapides et isolés, sommet réduit de tests de bout en bout) :

| Niveau ISTQB | Réalisation technique | Cas | Part |
|---|---|---:|---:|
| **Test unitaire** (composant isolé, sans I/O) | `PHPUnit\Framework\TestCase`, doublures de test | **103** | 42 % |
| **Test d'intégration** (composant + BDD/ORM réels) | `KernelTestCase` + transaction/rollback | **48** | 20 % |
| **Test système / fonctionnel** (parcours HTTP de bout en bout) | `WebTestCase` (noyau HTTP simulé) | **94** | 38 % |
| **Total** | | **245** | 100 % |

**Justification de la forme** : la base unitaire (42 %) sécurise la logique métier et d'autorisation à
coût d'exécution quasi nul ; la couche fonctionnelle (38 %) reste volontairement nourrie car CreaSlot est
une application **à fort enjeu d'autorisation** (trois rôles, voters), où le contrôle d'accès n'est prouvé
qu'au niveau HTTP. La couche d'intégration (20 %) cible spécifiquement le **risque ORM/DQL** (cf. anomalies
DT-1/DT-2, régressions de requêtes non détectées par les tests à doublure).

### 2.2 Environnement de test

- **Base de données dédiée** : `creaslot_test`, obtenue par le suffixe `dbname_suffix: '_test'`
  (`config/packages/doctrine.yaml`) appliqué à `DATABASE_URL`. **Justification** : isolation stricte de la
  base de développement — aucun test ne peut altérer des données réelles.
- **Schéma** : reconstruit par les **migrations Doctrine** (`doctrine:migrations:migrate --env=test`),
  pas par `schema:create`. **Justification** : on teste le schéma **réellement déployé** (migrations
  versionnées), garantissant que la base de test est conforme à la production.
- **Données de référence** : **fixtures** (`doctrine:fixtures:load --env=test`) — comptes nommés
  (auditeur Xavier, personnels Marie/Jean/Sophie, super-admin), types de RDV, créneaux. **Justification** :
  jeu d'essai restaurable et déterministe, exigé par le référentiel (« base de test avec jeu d'essai
  complet et restaurable »).
- **Intégration continue** : GitHub Actions provisionne un **service MySQL 8** éphémère (healthcheck), crée
  la base, migre, charge les fixtures, puis exécute la suite. **Justification** : l'environnement de test est
  **reproductible hors poste de développement**, à chaque `push`/`pull_request`.

### 2.3 Outillage et critères qualité

| Outil | Version | Rôle | Réglage justifié |
|---|---|---|---|
| **PHPUnit** | 13.1.8 | Exécution des tests | `failOnDeprecation/Notice/Warning="true"` → **toute** alerte casse la suite : on interdit la dette silencieuse |
| **PHP-CS-Fixer** | — | Style de code | `@PSR12` + `@Symfony` + 4 surcharges maison ; **0 écart** exigé |
| **PHPStan** | 2.2.2 | Analyse statique | **Niveau 8, SANS baseline** |
| **GitHub Actions** | — | CI | **3 jobs** (style, analyse, tests) sur `develop`/`preprod`/`main` |

**Justification « PHPStan niveau 8 sans baseline »** : une *baseline* gèlerait les erreurs existantes pour
ne bloquer que les nouvelles. En **renonçant à la baseline**, on impose que **l'intégralité** du code
(`src/` + `tests/`) satisfasse le niveau maximal de rigueur de typage **dès maintenant** — il n'existe aucune
dette de typage tolérée. C'est un engagement de qualité plus fort, possible car la base de code est de taille
maîtrisée.

### 2.4 Isolation des tests

L'isolation diffère selon le niveau, et chaque choix est argumenté :

- **Intégration (`KernelTestCase`)** : chaque test ouvre une **transaction en `setUp` et la `rollback` en
  `tearDown`**. **Justification** : annulation atomique et instantanée de toutes les écritures, sans requête
  de nettoyage à maintenir ; les tests restent indépendants et l'ordre d'exécution est sans effet.
- **Fonctionnel (`WebTestCase`)** : le rollback transactionnel n'est **pas applicable** — la requête HTTP
  simulée ouvre et **valide** sa propre transaction dans le noyau, hors de portée du test. On recourt donc à
  des **comptes et données jetables** identifiés par un **marqueur d'email** (`…-test.local`,
  `…@reservation-test.local`), créés en `setUp` et **supprimés en `tearDown` dans l'ordre des clés
  étrangères** (Notification → Reservation → Creneau → Utilisateur). **Justification** : c'est la seule
  isolation fiable quand le commit échappe au test ; le marqueur garantit qu'aucune donnée de fixtures n'est
  mutée, et l'ordre FK évite les violations d'intégrité au nettoyage.
- **Messagerie** : en environnement de test, `SendEmailMessage` est routé vers le transport **asynchrone**
  (file Doctrine), pas envoyé. Les assertions utilisent donc **`assertQueuedEmailCount`** (et non
  `assertEmailCount`). **Justification** : aucune dépendance réseau (SMTP) en test, et l'assertion reflète le
  **transport réel** de l'environnement — un test mail « trop facilement vert » masquerait une assertion
  inadaptée.

---

## 3. Cartographie quantifiée

### 3.1 Par niveau de test

| Niveau | Classe de base PHPUnit | Fichiers | Cas |
|---|---|---:|---:|
| Unitaire | `TestCase` | 15 | 103 |
| Intégration | `KernelTestCase` (+ transaction/rollback) | 11 | 48 |
| Fonctionnel | `WebTestCase` | 11 | 94 |
| **Total** | | **37** | **245** |

### 3.2 Par domaine fonctionnel

| Domaine | Fichiers (cas) | Σ |
|---|---|---:|
| Sécurité — Voters (autorisation) | CreneauVoter (7), ReservationVoter (9), UtilisateurVoter (16) | 32 |
| Services métier | NotificationService (22), SlotService (12), Statistiques (7), Dashboard (6), OccupationCalendarSerializer (5), DateFormatter (4), ExportDonnées (2), JournalAdmin (2) | 60 |
| Entités / Form / Twig / Command | CreneauValidation (3), Utilisateur (2), CreneauType (3), NotificationExtension (3), EnvoyerRappelsJ1Command (3) | 14 |
| Intégration repositories / DQL | OccupationGlobale (10), CreneauRepositoryQueries (7), StatistiquesQueries (7), UtilisateurRepositoryAdmin (6), DashboardKpi (4), JournalAdminRepo (3), NotificationRepo (3), OccupationParJour (3) | 43 |
| Intégration service + BDD réelle | NotificationServicePersist (1), ReservationRereservation (1) | 2 |
| Fonctionnel — Administration | Compte (26), Occupation (10), Export (8), Journal (6), Statistiques (5), Dashboard (4) | 59 |
| Fonctionnel — Authentification / self-service | MonProfil (9), ResetPassword (9), ExportSelfService (4) | 22 |
| Fonctionnel — Auditeur / réservation | ReservationParcours (9) | 9 |
| Fonctionnel — Personnel | Agenda (4) | 4 |
| **Total** | | **245** |

> Réconciliation : 32 + 60 + 14 + 43 + 2 + 59 + 22 + 9 + 4 = **245**, identique au total par niveau.

---

## 4. Matrice de traçabilité des exigences (US → tests)

Niveau(x) : **U** = unitaire, **I** = intégration, **F** = fonctionnel.

| User Story / exigence | Fichiers de tests | Niveau(x) | Statut |
|---|---|:--:|:--:|
| Authentification / inscription publique | *(aucun test dédié inscription)* | — | ⚠️ Trou (§7) |
| Création/édition de créneaux (Personnel) | CreneauValidationTest, CreneauTypeTest, SlotServiceTest, CreneauRepositoryQueriesTest, AgendaControllerTest | U, I, F | ✅ |
| Notifications & rappels (US-4.2→4.6) | NotificationServiceTest, NotificationServicePersistTest, NotificationRepositoryTest, NotificationExtensionTest, EnvoyerRappelsJ1CommandTest, DateFormatterServiceTest | U, I | ✅ |
| Notifications in-app (US-4.7) | NotificationServiceTest, NotificationRepositoryTest | U, I | ✅ |
| Préférences notifications (US-4.8) | *(couvert via NotificationService ; pas de WebTest PreferencesController)* | U | ⚠️ Partiel (§7) |
| Tableau de bord / KPIs (US-5.1/5.2) | DashboardServiceTest, DashboardControllerTest, DashboardKpiQueriesTest | U, I, F | ✅ |
| Gestion des comptes (US-5.3/5.4) | CompteControllerTest, UtilisateurVoterTest, UtilisateurRepositoryAdminTest | U, I, F | ✅ |
| Journal RGPD (US-5.5) | JournalControllerTest, JournalAdminServiceTest, JournalAdminRepositoryTest | U, I, F | ✅ |
| Export RGPD (US-5.6) | ExportControllerTest, ExportSelfServiceTest, ExportDonneesPersonnellesServiceTest | U, F | ✅ |
| Occupation globale (US-5.7) | OccupationControllerTest, OccupationGlobaleQueriesTest, OccupationParJourQueriesTest, OccupationCalendarSerializerTest | U, I, F | ✅ |
| Statistiques (US-5.8) | StatistiquesControllerTest, StatistiquesServiceTest, StatistiquesQueriesTest | U, I, F | ✅ |
| Mon profil self-service (US-6.1) | MonProfilControllerTest | F | ✅ |
| Mot de passe oublié (US-6.2) | ResetPasswordControllerTest | F | ✅ |
| **Parcours de réservation (Auditeur)** | ReservationParcoursControllerTest, ReservationVoterTest, ReservationRereservationApresAnnulationTest, CreneauVoterTest | U, I, F | ✅ |

---

## 5. Jeu d'essai détaillé — Réservation d'un créneau (cahier de recette)

**Fonctionnalité** : parcours nominal de réservation côté Auditeur, fonction centrale de CreaSlot, protégée
par un verrouillage pessimiste (`PESSIMISTIC_WRITE`).

**Environnement** : base `creaslot_test`, transport mail asynchrone.
**Données d'essai** (jetables, marqueur `@reservation-test.local`, créées en `setUp`) :

| Donnée | Valeur concrète |
|---|---|
| Auditeur principal | `auditeur-<uniqid>@reservation-test.local`, rôle `ROLE_AUDITEUR`, actif |
| Auditeur tiers | `auditeur-<uniqid>@reservation-test.local` (cas d'autorisation) |
| Personnel propriétaire | `personnel-<uniqid>@reservation-test.local`, rôle `ROLE_PERSONNEL` |
| Créneau disponible (C_dispo) | début **J+7 à 09:00**, fin 10:00, type de RDV actif, actif, non réservé |
| Créneau passé (C_passe) | début **J−3 à 09:00** (test de refus) |
| Créneau réservé (C_reserve) | début **J+8 à 09:00**, déjà réservé (ACTIVE) par l'auditeur tiers |

**Cahier de recette** (résultats obtenus = exécution automatisée verte de `ReservationParcoursControllerTest`,
9 cas) :

| N | Action | Donnée d'entrée | Résultat attendu | Résultat obtenu | Statut |
|:--:|---|---|---|---|:--:|
| 1 | Lister les créneaux disponibles | Auditeur principal connecté ; `GET /creneaux-disponibles` | HTTP 200 ; C_dispo présent (lien `/creneau/{id}/reserver`) | Conforme | ✅ OK |
| 2 | Réserver C_dispo | `POST /creneau/{C_dispo}/reserver` (formulaire CSRF, commentaire « Question sur mon dossier. ») | Redirection vers `/mes-reservations` ; **1 réservation ACTIVE** créée | Conforme | ✅ OK |
| 3 | Vérifier les effets de bord | (après l'étape 2) | **2 emails** mis en file (auditeur + personnel) ; **1 notification in-app** `CONFIRMATION_RESERVATION` persistée (`countNonLues` = 1) | Conforme | ✅ OK |
| 4 | Annuler la réservation | `POST /reservation/{id}/annuler` (CSRF, motif « Empêchement de dernière minute. ») | Redirection vers la liste ; statut **ANNULEE** ; `dateAnnulation` + motif renseignés | Conforme | ✅ OK |
| 5 | Re-réserver le même créneau | `POST /creneau/{C_dispo}/reserver` après annulation | Nouvelle réservation **ACTIVE** ; total sur le créneau = **1 ACTIVE + 1 ANNULEE** | Conforme | ✅ OK |
| 6 | **Invariant** ≤ 1 réservation ACTIVE | `POST /creneau/{C_reserve}/reserver` (créneau déjà réservé) | Refus (garde `isReserve`) → redirection `/creneaux-disponibles` + message « déjà réservé » ; **toujours exactement 1** réservation ACTIVE | Conforme | ✅ OK |

**Justification du test déterministe de l'invariant (étape 6)** : la double-réservation simultanée relève de
la **concurrence parallèle**, non reproductible de façon fiable dans un exécuteur PHPUnit mono-processus. On
**ne teste donc pas la course réelle** ; on teste l'**invariant métier qu'elle menace** — « au plus une
réservation ACTIVE par créneau » — de manière **déterministe** : une seconde tentative sur un créneau déjà
réservé est rejetée et le compteur reste à 1. Le mécanisme de concurrence lui-même (transaction +
`lock(PESSIMISTIC_WRITE)` + `refresh` + re-vérification) est couvert **par conception** et par vérification
manuelle (cf. §7 et DT-19).

---

## 6. Test de sécurité détaillé

Démarche rattachée aux pratiques **OWASP** (guide de test reconnu, cohérent avec les exigences ANSSI/OWASP du
référentiel). Deux vecteurs représentatifs sont prouvés par des tests automatisés.

| Vecteur d'attaque | Mécanisme de défense | Preuve (test) | Résultat |
|---|---|---|---|
| **Élévation de privilège par champs forgés** : un utilisateur soumet, sur l'édition self-service de son profil, des champs `role`, `service`, `email` non prévus par le formulaire, pour s'attribuer un rôle supérieur ou usurper une identité | Le formulaire `MonProfilType` ne mappe que `prénom`/`nom` ; **`allow_extra_fields: false`** (défaut Symfony) **rejette en bloc** toute requête contenant des champs supplémentaires (HTTP **422**), sans aucune écriture | `MonProfilControllerTest::test_anti_escalade_le_payload_forge_n_altere_ni_role_ni_service_ni_email` | **422** ; `role`/`service`/`email` **inchangés** en base ✅ |
| **Annulation d'une réservation par un tiers** : un utilisateur tente d'annuler une réservation qui ne lui appartient pas | Autorisation contextuelle par **Voter `RESERVATION_CANCEL`**, exécuté **avant** tout traitement du formulaire (`denyAccessUnlessGranted`) : seuls le propriétaire (Auditeur) et le Super-admin sont autorisés | `ReservationParcoursControllerTest::test_un_autre_auditeur_ne_peut_pas_annuler_la_reservation` et `::test_personnel_ne_peut_pas_annuler_une_reservation` | **403** dans les deux cas ; réservation **toujours ACTIVE** ✅ |
| **Accès anonyme** aux parcours protégés | Pare-feu Symfony + `IsGranted` au niveau classe | `ReservationParcoursControllerTest::test_anonyme_est_redirige_vers_la_connexion` | **302** vers la page de connexion ✅ |

**Précision d'architecture de sécurité** : la hiérarchie de rôles
**`ROLE_PERSONNEL ⊃ ROLE_AUDITEUR`** (et `ROLE_SUPER_ADMIN ⊃ ROLE_PERSONNEL`) implique que **tout utilisateur
authentifié possède `ROLE_AUDITEUR`**. Il n'existe donc pas de refus « non-auditeur » sur les routes de
réservation : le contrôle d'accès pertinent y est l'**accès anonyme (302)** et le **Voter d'annulation
(403)**. Cette analyse, vérifiée contre `security.yaml`, a corrigé un présupposé initial du plan et est
consignée pour le jury (honnêteté de la démarche). La couverture d'autorisation **pure** (toutes combinaisons
rôle × ressource) est par ailleurs assurée par les 32 tests unitaires de Voters (§3.2).

---

## 7. Limites connues / éléments non testés

Déclarés explicitement (démarche d'honnêteté attendue d'un dossier de tests) :

1. **Concurrence parallèle réelle** (deux réservations simultanées déclenchant le verrou pessimiste et la
   re-vérification post-lock) : non reproductible de façon déterministe en PHPUnit mono-processus.
   **Mitigation** : invariant « ≤ 1 ACTIVE » testé déterministement (§5, étape 6) ; mécanisme couvert **par
   conception** (transaction + `PESSIMISTIC_WRITE` + `refresh` + re-check) et vérification manuelle ; dette de
   refactoring tracée en **DT-19** (extraction d'un `ReservationService`, qui rendra l'invariant testable hors
   HTTP).
2. **`PreferencesController`** (préférences de notifications) : pas de test fonctionnel dédié ; la logique
   sous-jacente est couverte unitairement par `NotificationServiceTest`. Trou assumé, faible risque.
3. **Inscription publique** (`SecurityController::inscription`) : pas de WebTest dédié ; la validation du
   formulaire d'inscription est couverte indirectement (contraintes partagées `ContraintesMotDePasse`).
4. **Cas limites de réservation** : propriétaire devenant inactif entre l'affichage et la réservation ;
   chevauchement horaire pour un même auditeur — testés au niveau repository/service mais pas en parcours HTTP.

**Hors-périmètre méthodologique** (cf. §1) : tests de **charge/performance**, **fuzzing**, et **recette
utilisateur formelle** (PV signé). Ces activités relèvent d'un dispositif distinct ; le jeu d'essai
automatisé de §5 fournit une **preuve d'acceptation reproductible** en leur absence.

---

## 8. Résultats (dossier de compte rendu de tests)

Exécution de référence du **07/06/2026 16:08** (environnement Docker, PHP 8.4.21, MySQL 8.0) :

| Contrôle | Outil | Résultat |
|---|---|---|
| Tests automatisés | PHPUnit 13.1.8 | **OK — 245 tests, 907 assertions** |
| Alertes | PHPUnit (`failOn…="true"`) | **0 deprecation / 0 notice / 0 warning** |
| Analyse statique | PHPStan 2.2.2, niveau 8, sans baseline | **No errors** |
| Style de code | PHP-CS-Fixer (`@PSR12` + `@Symfony` + surcharges) | **0 fichier à corriger / 119** |
| Intégration continue | GitHub Actions — jobs `cs-fixer`, `phpstan`, `phpunit` | **3 jobs verts** (push + pull_request `develop`/`preprod`/`main`) |

**Conclusion** : la totalité des critères qualité est satisfaite, sans dette de typage ni de style tolérée,
et la non-régression est garantie en continu par la CI à chaque modification. Le suivi des anomalies est tenu
dans `docs/dette-technique.md` (DT-1 … DT-19).
