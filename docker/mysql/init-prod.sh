#!/bin/bash
# Init MySQL prod (US-9.2) : 1 instance, 2 bases isolées + 2 users aux privilèges
# limités à leur base. Exécuté UNE FOIS par l'entrypoint MySQL au premier démarrage
# (monté dans /docker-entrypoint-initdb.d/). Script VERSIONNÉ : aucun secret en dur —
# les mots de passe proviennent de l'environnement du conteneur db (cf. .env.*.local).
set -e

mysql -u root -p"$MYSQL_ROOT_PASSWORD" <<-SQL
	CREATE DATABASE IF NOT EXISTS creaslot_preprod CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	CREATE DATABASE IF NOT EXISTS creaslot_prod     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
	CREATE USER IF NOT EXISTS 'creaslot_preprod'@'%' IDENTIFIED BY '${MYSQL_PREPROD_PASSWORD}';
	CREATE USER IF NOT EXISTS 'creaslot_prod'@'%'     IDENTIFIED BY '${MYSQL_PROD_PASSWORD}';
	GRANT ALL PRIVILEGES ON creaslot_preprod.* TO 'creaslot_preprod'@'%';
	GRANT ALL PRIVILEGES ON creaslot_prod.*     TO 'creaslot_prod'@'%';
	FLUSH PRIVILEGES;
SQL
