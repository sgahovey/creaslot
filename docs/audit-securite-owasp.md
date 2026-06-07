# Audit de sécurité — CreaSlot (OWASP Top 10)

> Livrable de mémoire MSP3 — Concepteur Développeur d'Applications (CDA).
> État **après remédiation** (US-8.3). Date d'exécution de référence : **07/06/2026 16:51**.

---

## 1. En-tête & méthodologie

Ce document présente l'audit de sécurité applicatif de CreaSlot, conduit selon deux axes complémentaires :

1. **Analyse des dépendances** : exécution de `composer audit` (base d'avis de sécurité FriendsOfPHP /
   GitHub Advisory) pour détecter les composants tiers vulnérables (§2).
2. **Revue de la posture applicative** mappée sur le référentiel **OWASP Top 10 (2021)**, catégorie par
   catégorie : mécanisme en place, état, trou éventuel (§3).

**Périmètre** : le code applicatif (`src/`), la configuration de sécurité (`config/packages/security.yaml`),
les en-têtes du reverse proxy (`docker/nginx/default.conf`) et les dépendances (`composer.lock`).
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

**Constat initial** (avant remédiation) : `composer audit` signalait **38 avis de sécurité affectant 15
paquets**, exclusivement des composants **Symfony** (8.0.8/8.0.9) et **Twig** (3.24.0). Les correctifs étaient
publiés dans des versions **comprises dans les contraintes déjà déclarées** (`8.0.*`, `^3.0`).

**Remédiation** (US-8.3, morceau 1) : `composer update` **sans modification de `composer.json`** — 59 paquets
relevés dans leurs pins (Symfony → 8.0.10 à 8.0.13 ; Twig → 3.27.1). **Résultat : `composer audit` ne
remonte plus aucun avis.**

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
| **A02 — Cryptographic Failures** | Hachage des mots de passe en **argon2id** (`security.yaml`) ; secrets en `.env.local` **non versionné** (gitignoré) ; CSRF actif | ⚠️ Partiel | **HTTPS / HSTS** → itinéraire de déploiement (itération 9) |
| **A03 — Injection** | Accès données via **Doctrine ORM paramétré** (aucun SQL natif concaténé) ; **auto-échappement Twig** (aucun `\|raw` sur donnée utilisateur) ; composant **Validator** sur les entrées | ✅ Couvert | — |
| **A04 — Insecure Design** | Verrou **`PESSIMISTIC_WRITE`** + re-vérification après `refresh` ; invariant **« ≤ 1 réservation ACTIVE par créneau »** ; **suppression logique** (statut ANNULEE) ; jeton de réinitialisation à **usage unique** + `session->migrate(true)` | ✅ Couvert | Refactor `ReservationService` (qualité, non sécuritaire) → **DT-19** |
| **A05 — Security Misconfiguration** | En-têtes nginx : **X-Frame-Options SAMEORIGIN**, **X-Content-Type-Options nosniff**, **Referrer-Policy**, **X-XSS-Protection** (`docker/nginx/default.conf`) ; web-profiler en `dev` uniquement ; bandeau d'environnement | ⚠️ Partiel | **CSP** absente (mineur) ; forcer **`APP_ENV=prod` / `APP_DEBUG=0`** au déploiement → itération 9 |
| **A06 — Vulnerable & Outdated Components** | Versions **pinnées** (`8.0.*`, `^3.0`) ; `composer.lock` versionné ; `composer audit` exécuté ; **remédiation `composer update`** (§2) | ✅ Corrigé | **0 avis** (était 38) |
| **A07 — Identification & Authentication Failures** | Politique de mot de passe centralisée `ContraintesMotDePasse` (≥ 12 + jeu de caractères) ; reset à usage unique ; **CSRF** sur les formulaires ; **messages d'authentification neutres** ; **`login_throttling: max_attempts: 5`** (anti-brute-force, ajouté en US-8.3) | ✅ Corrigé | Throttling **testé** (`LoginThrottlingTest`, suite à 247 tests). Journalisation des échecs de login → itération 9 (mineur) |
| **A08 — Software & Data Integrity Failures** | **CI** GitHub Actions (3 jobs) sur `push`/`pull_request` ; `composer.lock` (intégrité des dépendances) ; aucune désérialisation de données non fiables dans le code applicatif | ✅ Couvert | — |
| **A09 — Security Logging & Monitoring** | **`JournalAdmin`** (trace immuable des actions d'administration sur les comptes, RGPD) ; Monolog sur connexion réussie, déconnexion, réservation, annulation, profil, réinitialisation | ✅ Couvert | Journalisation explicite des **échecs** d'authentification → itération 9 (mineur) |
| **A10 — Server-Side Request Forgery (SSRF)** | **Non applicable** : aucune requête sortante pilotée par l'utilisateur (l'unique sortie réseau est l'envoi d'email vers l'endpoint Brevo, fixe et configuré) | ✅ N/A | — |

**Synthèse** : 7 catégories couvertes, 2 corrigées dans US-8.3 (**A06**, **A07**), 2 partielles dont les
compléments relèvent du **déploiement** (A02/A05, itération 9), 1 non applicable (A10).

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
| **A02/A05** — HTTPS, HSTS, en-tête **CSP** | **Itération 9 (déploiement)** | Relèvent de l'infrastructure et du certificat TLS de l'environnement cible, pas du code applicatif ; non reproductibles en environnement de développement Docker local |
| **A05** — `APP_ENV=prod` / `APP_DEBUG=0` | **Itération 9 (déploiement)** | Variable d'environnement positionnée sur le serveur cible ; en local la valeur `dev` est attendue |
| **A09** — journalisation explicite des **échecs** de login | **Itération 9** | Amélioration de la traçabilité ; non bloquante (le throttling A07 limite déjà l'exploitation, et les connexions réussies sont déjà tracées) |
| **A04** — extraction d'un `ReservationService` | **DT-19** (registre de dette) | Amélioration de **qualité/architecture** (contrôleur mince), **sans impact sécuritaire** ; le comportement est figé par 9 WebTests, refactor sûr ultérieur |

Tous les renvois sont **non bloquants** : ils relèvent du déploiement (A02/A05/A09) ou de la qualité de code
(A04), et aucun n'expose une vulnérabilité active dans l'environnement applicatif audité.

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
