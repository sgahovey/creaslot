-- =====================================================================
-- CreaSlot — Script de création de la base de données
-- 8 tables métier : service, type_rdv, utilisateur, creneau, reservation,
--                   notification, journal_admin, reset_password_request
--
-- État final du schéma, reconstitué à partir des migrations Doctrine
-- successives du projet appliquées cumulativement, puis consolidées en une création
-- unique et lisible.
--
-- Cible   : MySQL 8.0 / InnoDB / utf8mb4
-- Ordre   : tables référencées d'abord (dépendances de clés étrangères),
--           afin que le script s'exécute d'un seul bloc sans avoir à
--           désactiver les contraintes d'intégrité.
-- Exclu   : messenger_messages (table technique de file d'attente des
--           messages différés, générée par Symfony Messenger : elle ne
--           relève pas de la modélisation métier).
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;


-- ---------------------------------------------------------------------
-- 1. service — rattachement organisationnel du Personnel
--    Aucune dépendance : créée en premier.
-- ---------------------------------------------------------------------
CREATE TABLE service (
    id           INT          NOT NULL AUTO_INCREMENT,
    nom          VARCHAR(100) NOT NULL,
    description  LONGTEXT     DEFAULT NULL,
    est_actif    TINYINT(1)   NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 2. type_rdv — type de rendez-vous (présentiel, visio, téléphone)
--    Aucune dépendance.
--    Unicité métier sur le code : il identifie le type de façon stable
--    dans le code applicatif, indépendamment du libellé affiché.
--    couleur_hex : code couleur (#RRGGBB) lu dynamiquement par les vues
--    (calendrier, pastilles) — d'où VARCHAR(7).
-- ---------------------------------------------------------------------
CREATE TABLE type_rdv (
    id           INT          NOT NULL AUTO_INCREMENT,
    code         VARCHAR(20)  NOT NULL,
    libelle      VARCHAR(50)  NOT NULL,
    couleur_hex  VARCHAR(7)   NOT NULL,
    icone        VARCHAR(50)  DEFAULT NULL,
    description  LONGTEXT     DEFAULT NULL,
    est_actif    TINYINT(1)   NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY UNIQ_type_rdv_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 3. utilisateur — comptes et rôles (Auditeur, Personnel, Super-admin)
--    Dépend de : service.
--
--    email : unique, c'est l'identifiant de connexion (Symfony Security).
--    mot_de_passe_hash : empreinte argon2id, jamais de mot de passe en clair.
--    role : valeur de l'énumération applicative RoleUtilisateur.
--    id_service : NULLABLE — un Auditeur n'appartient à aucun service ;
--                 seul le Personnel y est rattaché. C'est ce qui justifie
--                 la cardinalité 0,1 côté utilisateur dans le MCD.
--    email_modification_commentaire / email_rappel_j1 : préférences de
--                 notification email en OPT-OUT (défaut 1). Base légale
--                 RGPD art. 6.1.b (exécution du contrat) et non 6.1.a
--                 (consentement), d'où l'activation par défaut. Seuls ces
--                 deux types « confort » sont désactivables ; les
--                 notifications critiques restent toujours envoyées.
-- ---------------------------------------------------------------------
CREATE TABLE utilisateur (
    id                              INT          NOT NULL AUTO_INCREMENT,
    email                           VARCHAR(180) NOT NULL,
    mot_de_passe_hash               VARCHAR(255) NOT NULL,
    nom                             VARCHAR(100) NOT NULL,
    prenom                          VARCHAR(100) NOT NULL,
    role                            VARCHAR(30)  NOT NULL,
    date_creation                   DATETIME     NOT NULL,
    derniere_connexion              DATETIME     DEFAULT NULL,
    est_actif                       TINYINT(1)   NOT NULL,
    email_verifie                   TINYINT(1)   NOT NULL DEFAULT 1,
    email_modification_commentaire  TINYINT(1)   NOT NULL DEFAULT 1,
    email_rappel_j1                 TINYINT(1)   NOT NULL DEFAULT 1,
    id_service                      INT          DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY UNIQ_utilisateur_email (email),
    KEY IDX_1D1C63B33F0033A2 (id_service),
    CONSTRAINT FK_1D1C63B33F0033A2
        FOREIGN KEY (id_service) REFERENCES service (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 4. creneau — plage horaire publiée par un membre du Personnel
--    Dépend de : utilisateur, type_rdv.
--
--    Index métier idx_creneau_utilisateur_debut (id_utilisateur, date_debut) :
--    couvre les deux requêtes les plus fréquentes du parcours Personnel,
--    l'affichage de l'agenda et la détection des chevauchements de créneaux
--    pour un même Personnel sur une plage donnée.
-- ---------------------------------------------------------------------
CREATE TABLE creneau (
    id                    INT        NOT NULL AUTO_INCREMENT,
    date_debut            DATETIME   NOT NULL,
    date_fin              DATETIME   NOT NULL,
    commentaire_auditeur  LONGTEXT   DEFAULT NULL,
    date_creation         DATETIME   NOT NULL,
    est_actif             TINYINT(1) NOT NULL,
    id_utilisateur        INT        NOT NULL,
    id_type_rdv           INT        NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_F9668B5F50EAE44 (id_utilisateur),
    KEY IDX_F9668B5F420F15F2 (id_type_rdv),
    KEY idx_creneau_utilisateur_debut (id_utilisateur, date_debut),
    CONSTRAINT FK_F9668B5F50EAE44
        FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id),
    CONSTRAINT FK_F9668B5F420F15F2
        FOREIGN KEY (id_type_rdv) REFERENCES type_rdv (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 5. reservation — prise d'un créneau par un Auditeur
--    Dépend de : creneau, utilisateur.
--
--    statut : valeur de l'énumération applicative StatutReservation
--             (ACTIVE / ANNULEE). Une réservation annulée n'est jamais
--             supprimée : elle est conservée à des fins d'historique.
--    Invariant applicatif : au plus une réservation ACTIVE par créneau,
--             garanti par un verrou pessimiste (SELECT ... FOR UPDATE)
--             posé dans ReservationService::reserver(), et non par une
--             contrainte SQL (le statut ANNULEE autorise plusieurs lignes
--             pour un même créneau).
--    Index métier idx_reservation_statut (statut) : filtrage des
--             réservations actives, requête la plus fréquente.
-- ---------------------------------------------------------------------
CREATE TABLE reservation (
    id                    INT         NOT NULL AUTO_INCREMENT,
    date_reservation      DATETIME    NOT NULL,
    commentaire_auditeur  LONGTEXT    DEFAULT NULL,
    statut                VARCHAR(20) NOT NULL,
    date_annulation       DATETIME    DEFAULT NULL,
    motif_annulation      LONGTEXT    DEFAULT NULL,
    rappel_envoye_at      DATETIME    DEFAULT NULL,
    id_creneau            INT         NOT NULL,
    id_utilisateur        INT         NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_42C8495527FB222F (id_creneau),
    KEY IDX_42C8495550EAE44 (id_utilisateur),
    KEY idx_reservation_statut (statut),
    CONSTRAINT FK_42C8495527FB222F
        FOREIGN KEY (id_creneau) REFERENCES creneau (id),
    CONSTRAINT FK_42C8495550EAE44
        FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 6. notification — message in-app destiné à un utilisateur
--    Dépend de : utilisateur, reservation.
--
--    id_reservation : NULLABLE + ON DELETE SET NULL.
--    C'est la SEULE clé étrangère du schéma dotée d'un comportement de
--    suppression explicite. Justification RGPD : lorsqu'une réservation
--    est supprimée (droit à l'effacement), la notification qui en découle
--    doit survivre, car ses champs titre et message sont autonomes et
--    restent lisibles sans la réservation d'origine. La référence est
--    simplement mise à nul plutôt que de bloquer la suppression ou
--    d'entraîner la notification dans une cascade.
--    Le caractère nullable autorise par ailleurs de futures notifications
--    non liées à une réservation.
--
--    Toutes les autres clés étrangères du schéma conservent le
--    comportement RESTRICT par défaut, qui interdit toute suppression en
--    cascade non maîtrisée.
--
--    Index métier idx_notification_destinataire_lu (id_destinataire, lu) :
--    alimente le compteur de notifications non lues affiché dans l'en-tête
--    à chaque page, pour tous les rôles connectés.
-- ---------------------------------------------------------------------
CREATE TABLE notification (
    id               INT          NOT NULL AUTO_INCREMENT,
    type             VARCHAR(30)  NOT NULL,
    titre            VARCHAR(255) NOT NULL,
    message          LONGTEXT     NOT NULL,
    lu               TINYINT(1)   NOT NULL,
    date_creation    DATETIME     NOT NULL,
    id_destinataire  INT          NOT NULL,
    id_reservation   INT          DEFAULT NULL,
    PRIMARY KEY (id),
    KEY IDX_BF5476CADD688AE0 (id_destinataire),
    KEY IDX_BF5476CA5ADA84A2 (id_reservation),
    KEY idx_notification_destinataire_lu (id_destinataire, lu),
    CONSTRAINT FK_BF5476CADD688AE0
        FOREIGN KEY (id_destinataire) REFERENCES utilisateur (id),
    CONSTRAINT FK_BF5476CA5ADA84A2
        FOREIGN KEY (id_reservation) REFERENCES reservation (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 7. reset_password_request — demande de réinitialisation de mot de passe
--    Dépend de : utilisateur.
--
--    Le jeton n'est JAMAIS stocké en clair : la table conserve un
--    sélecteur public (selector) et l'empreinte du jeton (hashed_token).
--    La demande est à usage unique et expire (expires_at) ; elle est
--    supprimée après emploi.
-- ---------------------------------------------------------------------
CREATE TABLE reset_password_request (
    id              INT          NOT NULL AUTO_INCREMENT,
    selector        VARCHAR(20)  NOT NULL,
    hashed_token    VARCHAR(100) NOT NULL,
    requested_at    DATETIME     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    id_utilisateur  INT          NOT NULL,
    PRIMARY KEY (id),
    KEY IDX_7CE748A50EAE44 (id_utilisateur),
    CONSTRAINT FK_7CE748A50EAE44
        FOREIGN KEY (id_utilisateur) REFERENCES utilisateur (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- ---------------------------------------------------------------------
-- 8. journal_admin — trace des actions d'administration sur les comptes
--    AUCUNE clé étrangère : c'est un choix d'architecture délibéré.
--
--    Enregistrement append-only (jamais modifié ni supprimé par
--    l'application) : la valeur de la trace réside dans son immuabilité.
--
--    acteur_id et cible_id sont des ENTIERS SCALAIRES, sans contrainte
--    référentielle, et acteur_libelle / cible_libelle figent le nom
--    complet au moment de l'action. La trace survit ainsi au renommage
--    comme à la suppression des comptes concernés, et son affichage ne
--    nécessite aucune jointure.
--
--    VARCHAR(201) = prenom (100) + 1 espace + nom (100). C'est la longueur
--    maximale exacte d'un nom complet tel que produit par la concaténation
--    « prénom espace nom » : aucun libellé ne peut être tronqué, aucun
--    octet n'est gaspillé.
--
--    RGPD : la table contient du nominatif (libellés). Finalité sécurité
--    et redevabilité, minimisation des données (aucun mot de passe, aucun
--    motif), conservation limitée à 12 mois appliquée par la purge
--    automatisée (commande app:purger-journal).
--
--    Index métier idx_journal_admin_date (date_action) : consultation
--    chronologique du journal, tri par défaut de la vue Super-admin.
-- ---------------------------------------------------------------------
CREATE TABLE journal_admin (
    id              INT          NOT NULL AUTO_INCREMENT,
    date_action     DATETIME     NOT NULL,
    type_action     VARCHAR(40)  NOT NULL,
    acteur_id       INT          NOT NULL,
    acteur_libelle  VARCHAR(201) NOT NULL,
    cible_id        INT          DEFAULT NULL,
    cible_libelle   VARCHAR(201) DEFAULT NULL,
    details         LONGTEXT     DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_journal_admin_date (date_action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;


-- =====================================================================
-- Récapitulatif des contraintes d'intégrité
--
-- 8 clés étrangères :
--   utilisateur.id_service              -> service(id)
--   creneau.id_utilisateur              -> utilisateur(id)
--   creneau.id_type_rdv                 -> type_rdv(id)
--   reservation.id_creneau              -> creneau(id)
--   reservation.id_utilisateur          -> utilisateur(id)
--   notification.id_destinataire        -> utilisateur(id)
--   notification.id_reservation         -> reservation(id)  ON DELETE SET NULL
--   reset_password_request.id_utilisateur -> utilisateur(id)
--   (les 7 autres sont en RESTRICT, comportement par défaut)
--
-- 2 contraintes d'unicité :
--   utilisateur.email   (identifiant de connexion)
--   type_rdv.code       (identifiant stable du type)
--
-- 4 index métier :
--   idx_creneau_utilisateur_debut     (id_utilisateur, date_debut)
--   idx_reservation_statut            (statut)
--   idx_notification_destinataire_lu  (id_destinataire, lu)
--   idx_journal_admin_date            (date_action)
--
-- Conventions de nommage : tables en snake_case singulier, clés étrangères
-- toujours nommées id_<entité>, clés primaires en INT AUTO_INCREMENT.
-- =====================================================================
