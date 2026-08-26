# Audit de sécurité — CreaSlot (OWASP Top 10)

> Livrable de mémoire — Concepteur Développeur d'Applications (CDA).
> État **après remédiation** (US-8.3). Date d'exécution de référence : **07/06/2026 16:51**.
> Statuts mis à jour au **04/08/2026** : certificat Let's Encrypt de production **livré** (US-9.3) et **DT-19 résolue**.

---

## 1. En-tête & méthodologie

Ce document présente l'audit de sécurité applicatif de CreaSlot, conduit selon deux axes complémentaires :

1. **Analyse des dépendances** : `composer audit` (base d'avis de sécurité FriendsOfPHP /
   GitHub Advisory) pour détecter les composants tiers vulnérables (§2). Initialement lancé
   manuellement lors de la remédiation (US-8.3), il est désormais une **porte CI bloquante**
   sur `--no-dev` (job `audit` de `ci.yml`) : toute vulnérabilité d'une dépendance de
   production échoue la CI et bloque la fusion.
2. **Revue de la posture applicative** mappée sur le référentiel **OWASP Top 10 (2021)**, catégorie par
   catégorie : mécanisme en place, état, trou éventuel (§3).

**Périmètre** : le code applicatif (`src/`), la configuration de sécurité (`config/packages/security.yaml`),
les en-têtes du reverse proxy (`Caddyfile`, dépôt `infra-proxy`) et les dépendances (`composer.lock`).
**Hors périmètre** (§5) : test d'intrusion externe et audit de configuration du serveur de production.

**Rattachement CDA** : compétences **CP3 « Développer des composants métier »** (bloc 1) et **CP8
« Développer des composants d'accès aux données SQL et NoSQL »** (bloc 2) — toutes deux exigeant des
composants sécurisés et la réalisation de **tests de sécurité** — ainsi que les exigences transverses
**ANSSI** et **OWASP** du référentiel (reformulées ici, sans reproduction). Les **preuves automatisées** des contrôles de sécurité (anti-escalade,
autorisation par Voter, throttling, accès anonyme) ne sont **pas redétaillées ici** : voir
`docs/plan-de-tests.md` **§6 (test de sécurité détaillé)** et la suite `LoginThrottlingTest`,
`MonProfilControllerTest`, `ReservationParcoursControllerTest`.

---

## 2. Vulnérabilités de dépendances

> **Note** : les décomptes de tests indiqués dans ce document (247, 271, 274) correspondent à l'état du projet
> au moment de chaque itération datée. Le total final est de **336 tests** (suite de la branche d'intégration `develop`, relevé le 24/07/2026 ; voir section 9 du dossier).

**Constat initial** (avant remédiation) : `composer audit` signalait **38 avis de sécurité affectant 15
paquets**, exclusivement des composants **Symfony** (8.0.8/8.0.9) et **Twig** (3.24.0). Les correctifs étaient
publiés dans des versions **comprises dans les contraintes déjà déclarées** (`8.0.*`, `^3.0`).

**Remédiation** (US-8.3, morceau 1) : `composer update` **sans modification de `composer.json`** — 59 paquets
relevés dans leurs pins (Symfony → 8.0.10 à 8.0.13 ; Twig → 3.27.1). **Résultat : `composer audit` ne
remonte plus aucun avis.** Cette remédiation, ponctuelle à l'origine, est désormais **verrouillée en
continu** : `composer audit --no-dev` est une porte CI bloquante (cf. §1), qui échoue toute fusion
réintroduisant une dépendance de production vulnérable.

Avis les plus sévères corrigés (sévérité critique et haute uniquement nommées) :

| Paquet | Sévérité | CVE | Direct / Transitif | Correctif | Impact réel sur CreaSlot |
|---|---|---|---|---|---|
| **twig/twig** | 🔴 Critique | CVE-2026-46633 (+ 4 hautes) | **Direct** | 3.24.0 → **3.27.1** | Élevé — tous les gabarits passent par Twig |
| **symfony/mime** | 🟠 Haute | CVE-2026-45067 (injection CRLF / commande SMTP via `Address`) | Transitif (mailer) | → **8.0.13** | Moyen — emails dont l'adresse provient de données utilisateur (déjà validée) |
| **symfony/security-http** | 🟠 Haute | CVE-2026-45063 (+ 3 moyennes) | Transitif (security-bundle) | → **8.0.13** | Élevé — cœur de l'authentification |
| **symfony/monolog-bridge** | 🟠 Haute | CVE-2026-45077 (désérialisation via listener `server:log`) | Transitif | → **8.0.12** | Faible — outil de développement, non exposé en production |
| **symfony/http-kernel** | 🟡 Moyenne | CVE-2026-45075 (requête HEAD contourne `methods:['GET']` de `#[IsGranted]`) | Transitif | → **8.0.13** | Moyen — l'application utilise `#[IsGranted]` |

Les 33 avis restants (sévérités moyenne à basse : `cache`, `routing`, `http-foundation`, `dom-crawler`,
`validator`, `yaml`, `polyfill-intl-idn`, `web-profiler-bundle`, etc.) sont **tous corrigés par le même
`composer update`**. **Verdict : 38 → 0**, sans changement de contrainte, validé par la suite complète
(247 tests verts), PHPStan niveau 8 et PHP-CS-Fixer.

---

## 3. Mapping OWASP Top 10 (2021)

| Catégorie | Mesure en place (fichier / mécanisme) | État | Trou éventuel / renvoi |
|---|---|:--:|---|
| **A01 — Broken Access Control** | 3 Voters (`CreneauVoter`, `ReservationVoter`, `UtilisateurVoter`) ; `#[IsGranted]` au niveau classe ; `access_control` avec catch-all `^/ → IS_AUTHENTICATED_FULLY` ; `role_hierarchy` ; anti-escalade `allow_extra_fields: false` (rejet 422) prouvé par test | ✅ Couvert | — |
| **A02 — Cryptographic Failures** | Hachage des mots de passe en **argon2id** (`security.yaml`) ; secrets en `.env.local` **non versionné** (gitignoré) ; CSRF actif ; **HSTS + architecture TLS Caddy** (`Caddyfile`, dépôt `infra-proxy`), validée en local en `tls internal` (US-9.2), **certificat Let's Encrypt de production en place** (US-9.3) | ✅ Couvert | — |
| **A03 — Injection** | Accès données via **Doctrine ORM paramétré** (aucun SQL natif concaténé) ; **auto-échappement Twig** (aucun `\|raw` sur donnée utilisateur) ; composant **Validator** sur les entrées | ✅ Couvert | — |
| **A04 — Insecure Design** | Verrou **`PESSIMISTIC_WRITE`** + re-vérification après `refresh` ; invariant **« ≤ 1 réservation ACTIVE par créneau »** ; **suppression logique** (statut ANNULEE) ; jeton de réinitialisation à **usage unique** + `session->migrate(true)` | ✅ Couvert | `ReservationService` extrait — **DT-19 résolue (18/06/2026)** |
| **A05 — Security Misconfiguration** | **CSP à nonce** posée par l'application (`CspResponseListener` + `csp_nonce()`) : `script-src 'self' 'nonce-…'` strict, sans `unsafe-inline`/`unsafe-eval` (US-9.2) ; en-têtes via Caddy : **HSTS**, **Permissions-Policy**, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, en-tête **`Server` masqué** (`Caddyfile`, dépôt `infra-proxy`) ; **`APP_ENV=prod` / `APP_DEBUG=0`** câblés (`.env.preprod`/`.env.prod`) ; web-profiler en `dev` uniquement ; bandeau d'environnement | ✅ Traité | — |
| **A06 — Vulnerable & Outdated Components** | Versions **pinnées** (`8.0.*`, `^3.0`) ; `composer.lock` versionné ; **`composer audit` en porte CI bloquante** (`--no-dev`, job `audit`) ; **remédiation `composer update`** (§2) | ✅ Corrigé | **0 avis** (était 38) |
| **A07 — Identification & Authentication Failures** | Politique de mot de passe centralisée `ContraintesMotDePasse` (≥ 12 + jeu de caractères) ; reset à usage unique ; **CSRF** sur les formulaires ; **messages d'authentification neutres** ; **`login_throttling: max_attempts: 5`** (anti-brute-force, ajouté en US-8.3) | ✅ Corrigé | Throttling **testé** (`LoginThrottlingTest`, suite à 247 tests). Journalisation des échecs de login **traitée en US-9.5** (channel Monolog `security`, cf. A09) |
| **A08 — Software & Data Integrity Failures** | **CI** GitHub Actions (4 jobs, dont la porte bloquante `composer audit`) sur `push`/`pull_request` ; `composer.lock` (intégrité des dépendances) ; aucune désérialisation de données non fiables dans le code applicatif | ✅ Couvert | — |
| **A09 — Security Logging & Monitoring** | **`JournalAdmin`** (trace immuable des actions d'administration sur les comptes, RGPD) ; Monolog sur réservation, annulation, profil, réinitialisation ; **évènements d'authentification** (connexion réussie, **échec**, compte désactivé, déconnexion) journalisés via un **channel Monolog `security` dédié**, écrit systématiquement (handler `stream` **hors `fingers_crossed`** → non bufferisé), en complément du `login_throttling` A07 — **US-9.5** | ✅ Couvert | Alerting/supervision temps réel (SIEM) : évolution **hors périmètre projet** |
| **A10 — Server-Side Request Forgery (SSRF)** | **Non applicable** : aucune requête sortante pilotée par l'utilisateur (l'unique sortie réseau est l'envoi d'email vers l'endpoint Brevo, fixe et configuré) | ✅ N/A | — |

**Synthèse** : 8 catégories couvertes/traitées, 2 corrigées dans US-8.3 (**A06**, **A07**), **A05 traité
en US-9.2** (CSP à nonce + en-têtes Caddy), **A02 couvert** (HSTS/TLS et **certificat Let's Encrypt de production en place**, US-9.3),
**A09 complété en US-9.5** (journalisation des échecs d'authentification via le channel `security`),
1 non applicable (A10). Aucun résiduel de sécurité ouvert (certificat Let's Encrypt de production en place, US-9.3).

---

## 4. Plan de remédiation

### 4.1 Réalisé dans US-8.3

| Constat | Action | Vérification |
|---|---|---|
| **A06** — 38 avis de sécurité sur les dépendances | `composer update` dans les pins (`composer.json` inchangé) ; Symfony → 8.0.13, Twig → 3.27.1 | `composer audit` = **0** ; 247 tests verts ; PHPStan 8 = 0 ; CS-Fixer = 0 |
| **A07** — absence de protection anti-brute-force sur la connexion | Installation `symfony/rate-limiter` (pin `8.0.*`) ; activation `login_throttling: max_attempts: 5` sous le firewall `main` | `LoginThrottlingTest` : après 5 échecs, **un mot de passe correct est rejeté** (preuve déterministe) |

### 4.2 Renvoyé (justifié)

| Constat | Renvoi | Justification |
|---|---|---|
| **A02/A05** — **certificat TLS réel** (HTTPS/HSTS effectifs) | **US-9.3 (déploiement réel)** | ✅ **Livré** : déploiement réel effectué, **certificat Let's Encrypt de production en place** (`creaslot.re` → 200, `preprod.creaslot.re` → 401, issuer Let's Encrypt production) ; HSTS et architecture TLS Caddy en place |
| **A04** — extraction d'un `ReservationService` | **DT-19** (registre de dette) | ✅ **Résolue le 18/06/2026** : `ReservationService` extrait (transaction + verrou pessimiste + notifications hors transaction ; contrôleurs réduits à l'orchestration). Amélioration de qualité/architecture **sans impact sécuritaire** |

Ces renvois, désormais traités, étaient non bloquants : ils relevaient du déploiement réel (A02/A05, livré
en US-9.3) ou de la qualité de code (A04, DT-19 résolue), et aucun n'exposait de vulnérabilité active dans
l'environnement applicatif audité.

### 4.3 Traité dans US-9.2

| Constat | Action | Vérification |
|---|---|---|
| **A05** — en-tête **CSP** absent | **CSP à nonce par requête** : `CspNonceProvider` + extension Twig `csp_nonce()` + `CspResponseListener` (HTML uniquement, hors `dev`, hors JSON). `script-src 'self' 'nonce-…'` strict (sans `unsafe-inline`/`unsafe-eval`) ; front adapté (nonce sur importmap/scripts inline, `onclick` → contrôleur Stimulus, polyfill es-module-shims désactivé, CSS d'entrypoint en `<link>`) | `tests/Controller/CspHeaderTest.php` : CSP présente, nonce en-tête = nonce des `<script>`, **JSON sans CSP** ; suite à **271 tests** verts |
| **A05** — en-têtes durcis + masquage serveur | Via Caddy (`Caddyfile`, dépôt `infra-proxy`) : **HSTS**, **Permissions-Policy**, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, en-tête **`Server` masqué** | Vérifié en local (`curl -I`) sur les 2 sites |
| **A05** — `APP_ENV=prod` / `APP_DEBUG=0` | Câblés par environnement (`.env.preprod`, `.env.prod`), injectés via `env_file` (cf. `docs/architecture-deploiement.md` §3.7) | Conteneurs prod : `APP_ENV=prod` confirmé |

### 4.4 Traité dans US-9.5

| Constat | Action | Vérification |
|---|---|---|
| **A09** — journalisation explicite des **échecs** d'authentification | Channel Monolog **`security`** déclaré au niveau racine ; les listeners `LoginSuccessListener` / `LoginFailureListener` / `LogoutListener` y journalisent connexion réussie, échec, compte désactivé et déconnexion. Handler `security` (`stream` → `php://stderr`, JSON) **hors `fingers_crossed`** → écrit systématiquement, jamais bufferisé | Suite verte (274 tests) ; `debug:container monolog.logger.security` présent ; en complément du `login_throttling` A07 |

---

## 5. Limites de l'audit

Cet audit est **statique et applicatif** ; il ne se substitue pas à :

- un **test d'intrusion (pentest) externe** par un tiers, avec exploitation active des vecteurs ;
- un **audit de configuration du serveur de production** (durcissement OS, pare-feu, TLS, sauvegardes,
  rotation des secrets) — relevant de l'itération de déploiement.

Ces activités sont **hors du périmètre académique** du mémoire MSP3. L'audit fournit néanmoins une couverture
méthodique du **OWASP Top 10**, une **remédiation effective** des vulnérabilités de dépendances (A06) et de
l'authentification (A07), et un **suivi traçable** des points renvoyés (registre de dette `docs/dette-technique.md`,
itération 9 de déploiement).
