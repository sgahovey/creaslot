# ==============================================================================
# Stage builder : installation des dépendances Composer et compilation des assets
# ==============================================================================
FROM composer:2.7 AS builder

WORKDIR /app

# Copie des manifestes de dépendances en premier pour tirer parti du cache Docker.
# La couche n'est reconstruite que si composer.json ou composer.lock change.
COPY composer.json composer.lock* ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --ignore-platform-reqs

COPY . .

RUN composer dump-autoload \
    --optimize \
    --no-dev \
    --classmap-authoritative

# ==============================================================================
# Stage production : image légère PHP-FPM 8.4 Alpine
# ==============================================================================
FROM php:8.4-fpm-alpine AS production

# Bibliothèques runtime installées de façon permanente (nécessaires à l'exécution)
# Les headers de build sont groupés dans .build-deps et supprimés après compilation
RUN apk add --no-cache \
        icu-libs \
        icu-data-full \
        libzip \
        git \
        unzip \
    && apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
    && docker-php-ext-install \
        pdo_mysql \
        intl \
        zip \
    && docker-php-ext-enable opcache \
    && apk del --no-cache .build-deps

# Composer disponible dans le conteneur pour les commandes de développement
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Configuration PHP (OPcache, limites, etc.)
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

# Copie de l'application depuis le stage builder
COPY --from=builder --chown=www-data:www-data /app .

# PHP-FPM écoute sur le port 9000
EXPOSE 9000

CMD ["php-fpm"]
