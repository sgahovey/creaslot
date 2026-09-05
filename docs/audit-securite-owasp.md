# Audit de sécurité — CreaSlot (OWASP Top 10)

> Livrable de mémoire — Concepteur Développeur d'Applications (CDA).
> État **après remédiation** (US-8.3). Date d'exécution de référence : **07/06/2026 16:51**.
> Statuts mis à jour au **04/08/2026** : certificat Let's Encrypt de production **livré** (US-9.3) et **DT-19 résolue**.
> Statuts mis à jour au **27/08/2026** : **A02 partielle** (TLS applicatif vers MySQL livré, DT-42 ; chiffrement au repos absent) et **A09 corrigée**
> (rétention des journaux et alerte poussée sur blocage de connexion, DT-44), les deux vérifiées en production.

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
> au moment de chaque itération datée. Le total est de **336 tests** à cette date (suite de la branche d'intégration `develop`, relevé le 24/07/2026 ; voir section 9 du dossier).

**Constat initial** (avant remédiation) : `composer audit` signalait **38 avis de sécurité affectant 15
paquets**, exclusivement des composants **Symfony** (8.0.8/8.0.9) et **Twig** (3.24.0). Les correctifs étaient
publiés dans des versions **comprises dans les contraintes déjà déclarées** (`8.0.*`, `^3.0`).

> **Note de lecture, 05/09/2026** : les contraintes `8.0.*` citées dans ce paragraphe et dans le
> tableau des renvois traités décrivent l'état **au moment du constat**. Elles sont passées à
> `8.1.*` le 04/09/2026, la branche 8.0 n'étant plus maintenue depuis juillet 2026 (cf. DT-47).
> Le contrôle A06 du paragraphe 3 porte, lui, la contrainte en vigueur.

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
| **A02 — Cryptographic Failures** | Hachage des mots de passe en **argon2id** (`security.yaml`) ; secrets en `.env.local` **non versionné** (gitignoré) ; CSRF actif ; **HSTS + architecture TLS Caddy** (`Caddyfile`, dépôt `infra-proxy`), validée en local en `tls internal` (US-9.2), **certificat Let's Encrypt de production en place** (US-9.3) ; **TLS applicatif vers MySQL** sur les quatre services (`doctrine.yaml`, bloc `when@prod`, `driverOptions` + constantes PDO ; `ca.pem` monté en lecture seule) — **DT-42** | 🟡 Partiel | **Chiffrement au repos : absent**, limite assumée, cf. ci-dessous |
| **A03 — Injection** | Accès données via **Doctrine ORM paramétré** (aucun SQL natif concaténé) ; **auto-échappement Twig** (aucun `\|raw` sur donnée utilisateur) ; composant **Validator** sur les entrées | ✅ Couvert | — |
| **A04 — Insecure Design** | Verrou **`PESSIMISTIC_WRITE`** + re-vérification après `refresh` ; invariant **« ≤ 1 réservation ACTIVE par créneau »** ; **suppression logique** (statut ANNULEE) ; jeton de réinitialisation à **usage unique** + `session->migrate(true)` | ✅ Couvert | `ReservationService` extrait — **DT-19 résolue (18/06/2026)** |
| **A05 — Security Misconfiguration** | **CSP à nonce** posée par l'application (`CspResponseListener` + `csp_nonce()`) : `script-src 'self' 'nonce-…'` strict, sans `unsafe-inline`/`unsafe-eval` (US-9.2) ; en-têtes via Caddy : **HSTS**, **Permissions-Policy**, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, en-tête **`Server` masqué** (`Caddyfile`, dépôt `infra-proxy`) ; **`APP_ENV=prod` / `APP_DEBUG=0`** câblés (`.env.preprod`/`.env.prod`) ; web-profiler en `dev` uniquement ; bandeau d'environnement | ✅ Traité | — |
| **A06 — Vulnerable & Outdated Components** | Versions **pinnées** (`8.1.*`, `^3.0`) ; `composer.lock` versionné ; **`composer audit` en porte CI bloquante** (`--no-dev`, job `audit`) ; **remédiation `composer update`** (§2) | ✅ Corrigé | **0 avis** (était 38) |
| **A07 — Identification & Authentication Failures** | Politique de mot de passe centralisée `ContraintesMotDePasse` (≥ 12 + jeu de caractères) ; reset à usage unique ; **CSRF** sur les formulaires ; **messages d'authentification neutres** ; **`login_throttling: max_attempts: 5`** (anti-brute-force, ajouté en US-8.3) | ✅ Corrigé | Throttling **testé** (`LoginThrottlingTest`, suite à 247 tests). Journalisation des échecs de login **traitée en US-9.5** (channel Monolog `security`, cf. A09) |
| **A08 — Software & Data Integrity Failures** | **CI** GitHub Actions (4 jobs, dont la porte bloquante `composer audit`) sur `push`/`pull_request` ; `composer.lock` (intégrité des dépendances) ; aucune désérialisation de données non fiables dans le code applicatif | ✅ Couvert | — |
| **A09 — Security Logging & Monitoring** | **`JournalAdmin`** (trace immuable des actions d'administration sur les comptes, RGPD) ; Monolog sur réservation, annulation, profil, réinitialisation ; **évènements d'authentification** (connexion réussie, **échec**, compte désactivé, **blocage après plafonnement**) journalisés via un **channel Monolog `security` dédié**, écrit systématiquement (**hors `fingers_crossed`**) sur **deux destinations** : `php://stderr` et un **fichier rotatif** sur volume persistant, six mois glissants (US-9.5, **DT-44**) ; **alerte poussée** vers un moniteur Uptime Kuma dédié à chaque blocage, notifiée sur Discord (**DT-44**) | ✅ Corrigé | Corrélation multi-services (SIEM) : hors périmètre, cf. §4.5 |
| **A10 — Server-Side Request Forgery (SSRF)** | **Non applicable** : aucune requête sortante pilotée par l'utilisateur (l'unique sortie réseau est l'envoi d'email vers l'endpoint Brevo, fixe et configuré) | ✅ N/A | — |

**Synthèse** : **3 catégories corrigées** (**A06** et **A07** en US-8.3 ; **A09** au 27/08/2026),
**1 partielle** (**A02**), **A05 traité en US-9.2** (CSP à nonce + en-têtes Caddy), 4 catégories couvertes
(**A01**, **A03**, **A04**, **A08**), 1 non applicable (**A10**).

**Pourquoi A02 est partielle et non corrigée.** Le défaut trouvé, le transport en clair vers MySQL, est bel et
bien corrigé et vérifié en production. Mais la catégorie couvre aussi le **chiffrement des données au repos**,
qui n'existe pas : mesuré le 29/08/2026, zéro table en `ENCRYPTION=Y` et aucun périphérique chiffré sur l'hôte.
Marquer la catégorie corrigée reviendrait à faire disparaître ce résiduel derrière un défaut réglé. Le support
de soutenance, qui suit l'édition 2025, porte la même catégorie sous le numéro **A04** et le même statut
**partiel** : les deux livrables disent désormais la même chose.

Les deux corrections du 27/08/2026 sont **vérifiées en production**, pas seulement configurées : TLS applicatif
vers MySQL en `TLSv1.3` / `TLS_AES_256_GCM_SHA384` sur les quatre services (A02, DT-42), et pour A09 une trace
qui survit à la recréation d'un conteneur, doublée d'une alerte reçue lors d'un blocage réel provoqué en
production (DT-44). Le détail des preuves figure au §4.5.

**Ce résiduel est le seul du référentiel**, et c'est lui qui maintient A02 en partielle : le chiffrement des données au repos. Il est écarté en connaissance de cause, la
base et l'application vivant sur le même VPS : la clé maîtresse résiderait sur la machine même qu'elle est
censée protéger, et le chiffrement n'ajouterait de garantie que contre le vol du disque physique, scénario qui
relève de l'hébergeur. Ce point est tracé dans la rubrique « Hors périmètre » de **DT-42**.

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
| **A09** — journalisation explicite des **échecs** d'authentification | Channel Monolog **`security`** déclaré au niveau racine ; les listeners `LoginSuccessListener` / `LoginFailureListener` / `LogoutListener` y journalisent connexion réussie, échec, compte désactivé et déconnexion. **Deux** handlers sur le canal, tous deux **hors `fingers_crossed`** → écriture systématique, jamais bufferisée : `security` (`stream` → `php://stderr`, JSON) et, depuis DT-44, `security_fichier` (`rotating_file` → `%kernel.logs_dir%/security.log` sur volume persistant, `max_files: 180`, JSON) | Suite verte (274 tests à l'époque, **359** au 27/08/2026) ; `debug:container monolog.logger.security` présent ; en complément du `login_throttling` A07 |

### 4.5 Corrigé au 27/08/2026 (DT-42 et DT-44)

Contrairement aux sections précédentes, les vérifications ci-dessous ont été menées **sur l'environnement de
production**, et non seulement en local ou en intégration continue.

| Constat | Action | Vérification en production |
|---|---|---|
| **A02** — les quatre connexions applicatives vers MySQL circulaient **en clair** (`Ssl_cipher` et `Ssl_version` vides sur `app-prod`, `app-preprod`, `worker-prod`, `worker-preprod`) | Autorité `ca.pem` extraite du conteneur `db` et montée en lecture seule sur les quatre services ; options TLS via `driverOptions` et constantes PDO (`PDO::MYSQL_ATTR_SSL_CA`), dans le bloc **`when@prod` uniquement** pour ne pas casser la CI, qui monte son propre MySQL sans ce certificat — **DT-42** | `Ssl_version = TLSv1.3` et `Ssl_cipher = TLS_AES_256_GCM_SHA384` sur les quatre services. Aucune contrainte `REQUIRE SSL` posée sur les comptes : le retour arrière reste possible sans intervention en base |
| **A09** — les traces du canal `security` ne survivaient pas au conteneur : le driver `json-file` de Docker les détruit à chaque recréation, donc à chaque déploiement | Second handler `rotating_file` sur volume Docker nommé, **six mois glissants** (`max_files: 180`), en plus de `stderr` qui reste en place. Durée volontairement plus courte que les douze mois du journal d'administration : détection technique à valeur décroissante, journalisant l'adresse **tentée** y compris de personnes sans compte (minimisation, RGPD art. 5.1.c et 5.1.e) — **DT-44** | Ligne écrite, empreinte `9b75cccf…8af1486` relevée, `up -d --force-recreate app-prod`, conteneur remplacé, **fichier et empreinte identiques** après recréation, alors que `docker logs` ne conservait plus aucune ligne du canal |
| **A09** — aucune alerte : la trace existait, mais personne ne lit un fichier de journal en continu | `AlerteSecuriteService` sollicite un moniteur Uptime Kuma **push en mode inversé** à chaque `TooManyLoginAttemptsAuthenticationException`. Appel **isolé** (`try`/`catch` avalant, sur le modèle de `NotificationService`), **borné à 2 s**, sur le réseau Docker interne. Message limité au fait et à l'environnement : **aucune donnée personnelle** ne sort. Jeton en variable d'environnement hors dépôt, jamais journalisé — **DT-44** | Blocage réel provoqué en production le 27/08 à 12:43:25 (adresse fictive, IP de documentation RFC 5737) : ligne `ERROR` écrite, battement important reçu **15 ms** plus tard, notification Discord confirmée, **six réponses HTTP 302** dont la dernière à 26 ms contre 3 ms. 11 tests unitaires, dont l'isolation de l'échec d'appel |

---

## 5. Limites de l'audit

Cet audit est **statique et applicatif** ; il ne se substitue pas à :

- un **test d'intrusion (pentest) externe** par un tiers, avec exploitation active des vecteurs ;
- un **audit de configuration du serveur de production** (durcissement OS, pare-feu, TLS, sauvegardes,
  rotation des secrets) — relevant de l'itération de déploiement.

Ces activités sont **hors du périmètre académique** du mémoire MSP3. L'audit fournit néanmoins une couverture
méthodique du **OWASP Top 10**, une **remédiation effective** portant sur les dépendances (A06),
l'authentification (A07), la cryptographie en transit (A02) et la journalisation avec alerte (A09), ces deux
dernières **vérifiées en production** (§4.5), ainsi qu'un **suivi traçable** des points renvoyés (registre de
dette `docs/dette-technique.md`, itérations 9 et 14).

**Note de numérotation** : ce document est mappé sur le **OWASP Top 10 (2021)**, tandis que le support de
soutenance suit l'édition **2025**, qui a renuméroté et renommé les catégories. La table de correspondance
figure au **§6**. En cas de doute, s'y référer par le **libellé** plutôt que par le numéro.

---

## 6. Correspondance avec l'édition 2025 du OWASP Top 10

Le corps de cet audit (§3) est mappé sur l'édition **2021**, celle en vigueur lorsqu'il a été conduit, et il
**n'est pas renuméroté** : c'est la version rendue en annexe du dossier. L'OWASP a depuis publié une nouvelle
édition, qui renumérote et renomme plusieurs catégories. Ce changement de référentiel a été relevé par le
dispositif de **veille** du projet (`docs/realisation/diagrammes/dispositif-veille.puml`), et le support de
soutenance a été construit sur l'édition **2025**. Les deux éditions coexistent donc volontairement dans les
livrables, et cette table les relie.

| Catégorie 2021 — corps de cet audit | Catégorie 2025 correspondante | Statut du projet |
|---|---|:--:|
| **A01 — Broken Access Control** | **A01 — Contrôle d'accès** | ✅ Couvert |
| **A02 — Cryptographic Failures** | **A04 — Défaillances cryptographiques** | 🟡 Partiel |
| **A03 — Injection** | **A05 — Injection** | ✅ Couvert |
| **A04 — Insecure Design** | **A06 — Conception non sécurisée** | ✅ Couvert |
| **A05 — Security Misconfiguration** | **A02 — Mauvaise configuration** | ✅ Traité |
| **A06 — Vulnerable & Outdated Components** | **A03 — Chaîne d'approvisionnement** *(périmètre élargi, cf. ci-dessous)* | ✅ Corrigé |
| **A07 — Identification & Authentication Failures** | **A07 — Défaillances d'authentification** | ✅ Corrigé |
| **A08 — Software & Data Integrity Failures** | **A08 — Manque d'intégrité** | ✅ Couvert |
| **A09 — Security Logging & Monitoring** | **A09 — Journalisation et alerte** *(renommée, cf. ci-dessous)* | ✅ Corrigé |
| **A10 — Server-Side Request Forgery (SSRF)** | *aucun équivalent direct* | ✅ N/A |
| *aucun équivalent 2021* | **A10 — Conditions exceptionnelles** | *hors du tableau 2021, cf. ci-dessous* |

### 6.1 Ce qui ne se correspond pas terme à terme

Quatre lignes de la table ci-dessus ne sont pas de simples changements de numéro. Les signaler évite de forcer
une équivalence qui n'existe pas.

- **A06 (2021) → A03 (2025) : périmètre élargi.** « Vulnerable and Outdated Components » ne visait que les
  composants tiers vulnérables ou périmés. « Software Supply Chain Failures » couvre l'ensemble de la chaîne
  d'approvisionnement logicielle, ce qui déborde la seule liste des dépendances. La mesure du projet, une porte
  CI bloquante sur `composer audit` (§2), traite le cœur de la catégorie 2021 ; elle n'en épuise pas le
  périmètre 2025.
- **A09 (2021) → A09 (2025) : même numéro, intitulé différent.** « Security Logging **& Monitoring** » devient
  « Logging **& Alerting** Failures ». Le déplacement de l'accent est réel : observer ne suffit plus, il faut
  réagir. C'est précisément ce qu'a apporté **DT-44** (§4.5), et c'est ce qui rend le statut « corrigé »
  défendable sous les deux éditions plutôt que sous la seule édition 2021.
- **A10 (2021) : SSRF n'est plus une catégorie de premier niveau en 2025.** Elle ne figure pas dans la liste
  2025. Le statut « non applicable » du projet reste inchangé sur le fond : aucune requête sortante n'est
  pilotée par l'utilisateur.
- **A10 (2025) : « Mishandling of Exceptional Conditions » est une catégorie nouvelle**, sans équivalent dans
  l'édition 2021. Elle n'a donc **aucune ligne** dans le corps de cet audit, qui ne pouvait pas l'auditer. Elle
  est en revanche traitée dans le support de soutenance.

### 6.2 Portée de cette table

Cette table est un **outil de lecture**, pas une réévaluation. La colonne « Statut du projet » reprend, sans les
modifier, les statuts établis au §3 sous l'édition 2021. Aucune conclusion de l'audit n'est révisée à l'aune du
référentiel 2025 : le faire supposerait de réauditer chaque catégorie contre ses critères actuels, ce qui n'a
pas été fait.
