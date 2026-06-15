# ==============================================================================
# Image CreaSlot (US-9.1) — stages : base -> build / runtime / dev.
#   base    : socle commun PHP-FPM (extensions + utilisateur non-root 1000).
#   build   : compilation deps Composer + assets + warmup (CLI, APP_ENV=prod).
#   runtime : image de PRODUCTION immuable (code + assets bakés, OPcache figé).
#   dev     : socle + Composer, code/php.ini/assets fournis par bind-mount.
# L'exécution non-root (uid 1000) absorbe DT-4 (alignement UID hôte WSL2).
# ==============================================================================

# ==============================================================================
# Stage base : socle commun à runtime et dev (extensions + user app + var/ writable).
# ==============================================================================
FROM php:8.4-fpm-alpine AS base

# Bibliothèques runtime permanentes + extensions (headers de build en .build-deps
# puis supprimés). Commun à runtime et dev : aucune duplication.
RUN apk add --no-cache \
        icu-libs \
        icu-data-full \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        zip \
    && docker-php-ext-enable opcache \
    && apk del --no-cache .build-deps

# Utilisateur non-root (uid/gid 1000) — aligné sur l'UID hôte WSL2 (DT-4).
# php-fpm tournant sous USER app, on COMMENTE les directives user/group du pool
# pour éviter le NOTICE « 'user' directive is ignored when FPM is not running as root ».
RUN addgroup -g 1000 app \
    && adduser -u 1000 -G app -D -H app \
    && sed -i 's/^user = www-data/;user = www-data/; s/^group = www-data/;group = www-data/' \
        /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# var/cache et var/log appartiennent à app : à la première création des volumes
# nommés (dev), Docker hérite de cette ownership -> app (1000) peut écrire le cache.
RUN mkdir -p var/cache var/log \
    && chown -R app:app var

# ==============================================================================
# Stage build — dépendances Composer, compilation des assets, warmup du cache.
# Base CLI (la console Symfony suffit ici ; php-fpm inutile pour compiler).
# ==============================================================================
FROM php:8.4-cli-alpine AS build

# Extensions nécessaires au boot de la console (intl, pdo_mysql, zip).
RUN apk add --no-cache \
        icu-libs \
        icu-data-full \
        libzip \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        zip \
    && apk del --no-cache .build-deps

COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # Composer tourne en root dans le stage de build : sans ceci, les plugins
    # (symfony/flex, symfony/runtime) sont désactivés et autoload_runtime.php
    # n'est jamais généré.
    COMPOSER_ALLOW_SUPERUSER=1

# Cache de couche : les dépendances ne sont réinstallées que si les manifestes changent.
COPY composer.json composer.lock symfony.lock ./
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-scripts \
    --no-autoloader

# Code source (le .dockerignore exclut vendor/, .env.local, public/assets/, var/, tests/, .git).
COPY . .

# Génère l'autoloader optimisé ET vendor/autoload_runtime.php (produit par le plugin
# symfony/runtime via l'événement post-autoload-dump ; plugins actifs grâce à
# COMPOSER_ALLOW_SUPERUSER). dump-autoload ne déclenche PAS les auto-scripts Flex :
# la compilation Symfony est lancée explicitement juste après.
RUN composer dump-autoload \
    --optimize \
    --classmap-authoritative \
    --no-dev

# Compilation explicite (et non via les auto-scripts Flex) :
#  - importmap:install  télécharge stimulus/turbo dans assets/vendor/ (nécessite le réseau)
#  - asset-map:compile  génère public/assets/ versionné
#  - cache:warmup       préchauffe le cache prod
# Les secrets FACTICES nécessaires au boot du kernel sont passés INLINE (jamais dans
# une couche ENV) ; ce stage n'est de toute façon pas dans l'image finale, et les
# vrais secrets sont injectés au runtime via variables d'environnement.
RUN APP_SECRET=build_only_not_a_real_secret \
    DATABASE_URL="mysql://build:build@127.0.0.1:3306/build?serverVersion=8.0" \
    sh -c 'php bin/console importmap:install && \
           php bin/console asset-map:compile && \
           php bin/console cache:warmup'

# ==============================================================================
# Stage runtime — image finale minimale de PRODUCTION (pas de xdebug, pas de composer).
# ==============================================================================
FROM base AS runtime

# Configuration PHP de production (OPcache figé, erreurs masquées).
COPY docker/php/php-prod.ini /usr/local/etc/php/conf.d/app.ini

# Application complète depuis le stage build (code + vendor + public/assets + var/cache préchauffé).
COPY --from=build --chown=app:app /var/www/html /var/www/html

USER app

# PHP-FPM écoute sur le port 9000 (> 1024 : aucun privilège root requis).
EXPOSE 9000

CMD ["php-fpm"]

# ==============================================================================
# Stage dev — socle + Composer. Code, vendor (avec deps dev), php.ini de dev et
# assets sont fournis par bind-mount (docker-compose.yml) : aucun COPY ni warmup ici.
# ==============================================================================
FROM base AS dev

# git/unzip + composer pour le workflow dev (composer install, make:migration, fixtures…).
RUN apk add --no-cache git unzip
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

USER app

EXPOSE 9000

CMD ["php-fpm"]
