# Fiche de résolution — US-9.3 Incidents de déploiement

Cinq incidents rencontrés lors du premier déploiement réel sur le VPS OVH
(`51.178.25.175`, domaine `creaslot.re`). Chaque section suit la même trame :
contexte, diagnostic, solution, alternatives écartées, résultat, critère CDA.

## 1. Collision de tag au build parallèle
- **Contexte / problème** : premier `docker compose -f compose.prod.yml build` sur le VPS → `failed to solve: image "creaslot:prod": already exists` (exit 1).
- **Diagnostic (cause racine)** : les 4 services applicatifs (`app-preprod`, `app-prod`, `worker-preprod`, `worker-prod`) partagent la même image via l'ancre YAML `*app-base` ; BuildKit lance 4 builds parallèles du même tag qui se télescopent à l'export. Ni OOM, ni échec composer (RAM 773 Mi / 11 Gi, image finale 63 MB).
- **Solution retenue** : (immédiat) builder un seul service `build app-prod` ; (durable) faire porter le `build:` par le seul service `app-prod`, les 3 autres en `image: creaslot:prod`. Committé (fix infra `9ed3140`).
- **Alternatives écartées** : `up -d` direct (image déjà présente, mais moins propre) ; build séquentiel via variable d'environnement compose (obscur).
- **Résultat / vérification** : image produite sans collision ; plus aucune collision sur les builds suivants.
- **Critère CDA touché** : démarche de résolution de problème ; DevOps / déploiement.

## 2. Hash basic_auth tronqué par l'interpolation docker compose
- **Contexte / problème** : staging fonctionnel mais warning `variable "..." is not set` ; la préprod renvoyait 401 même avec le bon mot de passe.
- **Diagnostic (cause racine)** : docker compose réinterprète les `$` des valeurs lues via `--env-file` ; le hash bcrypt `$2a$14$...` voit ses segments pris pour des variables → Caddy reçoit un hash tronqué → authentification toujours refusée.
- **Solution retenue** : échapper chaque `$` en `$$` dans `.env.deploy.local`, et le documenter dans le gabarit `.env.deploy.local.example`.
- **Alternatives écartées** : monter le hash via un fichier dédié (plus lourd) ; entourer la valeur de guillemets (sans effet sur l'interpolation `$`).
- **Résultat / vérification** : `docker compose config` montre le hash complet ; `curl -u admin:<mdp>` → 200.
- **Critère CDA touché** : démarche de résolution de problème ; composants sécurisés ; DevOps.

## 3. Sonde PHP non exécutée sous le dual-root Caddy
- **Contexte / problème** : un `_ip.php` déposé dans `public/` du conteneur app renvoyait une 404 Symfony au lieu d'afficher l'IP client.
- **Diagnostic (cause racine)** : `php_fastcgi` effectue son contrôle d'existence (`try_files`) sur le système de fichiers du conteneur **Caddy**, où `/var/www/html/public` n'existe pas (montage dual-root) → échec → repli inconditionnel sur `index.php` ; tout `.php` passe par le contrôleur frontal. C'est une bonne propriété de sécurité : aucun PHP arbitraire déposé dans le webroot n'est exécutable.
- **Solution retenue** : sonde via une route Caddy bas niveau (`reverse_proxy ... transport fastcgi`, sans `try_files`), temporaire et réversible (retirée après mesure).
- **Alternatives écartées** : route Symfony de debug (code + `cache:clear`) ; lecture des logs (n'expose pas `REMOTE_ADDR` côté PHP de façon fiable).
- **Résultat / vérification** : IP client réelle observée (`REMOTE_ADDR` = vraie IP externe, pas `172.x`) → `trusted_proxies` tranché **non nécessaire**.
- **Critère CDA touché** : démarche de résolution de problème ; composants sécurisés ; DevOps.

## 4. Sonde persistante après `caddy reload` (OPcache `validate_timestamps=0`)
- **Contexte / problème** : après retrait de la route temporaire, suppression de `_ip.php` et `caddy reload`, l'URL `/_ip.php` renvoyait toujours 200.
- **Diagnostic (cause racine)** : deux persistances cumulées — (1) le `caddy reload` lancé via `exec` n'a pas appliqué la config restaurée à l'instance en cours ; (2) l'OPcache de l'image prod (`validate_timestamps=0`, choisi en US-9.1 pour la performance) servait le bytecode compilé de `_ip.php` malgré la suppression du fichier sur disque.
- **Solution retenue** : `docker compose restart caddy app-prod` (config Caddy fraîche + OPcache php-fpm vidé).
- **Alternatives écartées** : se fier au seul `caddy reload` ; `cache:clear` Symfony (sans effet sur l'OPcache du bytecode d'un fichier hors framework).
- **Résultat / vérification** : `/_ip.php` → 404. Leçon : en prod (`validate_timestamps=0`), déployer du code = **rebuild image + recreate**, jamais un simple `git pull`.
- **Critère CDA touché** : démarche de résolution de problème ; DevOps ; composants sécurisés.

## 5. Smoke TLS en échec après bascule du domaine
- **Contexte / problème** : après build + `up -d`, les smokes sur `prod.51.178.25.175.sslip.io` renvoyaient 000 (erreur TLS 35), prod **et** préprod.
- **Diagnostic (cause racine)** : le `.env.deploy.local` du VPS avait été basculé vers `creaslot.re` / `preprod.creaslot.re` (domaine réel + CA production, DNS propagé) ; Caddy ne sert donc plus aucun site pour les `*.sslip.io` → pas de certificat correspondant au SNI → handshake refusé. La « panne » était dans l'hôte de test, pas dans le déploiement.
- **Solution retenue** : tester les hôtes réels (`creaslot.re`, `preprod.creaslot.re`).
- **Alternatives écartées** : recréer Caddy (fait, inutile) ; toucher au réseau Docker (sain, IP du conteneur inchangée).
- **Résultat / vérification** : `creaslot.re` → 200, `preprod.creaslot.re` → 401, issuer Let's Encrypt production.
- **Critère CDA touché** : démarche de résolution de problème ; DevOps.
