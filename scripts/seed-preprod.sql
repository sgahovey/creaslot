-- =============================================================================
-- CreaSlot — Seed SQL de préproduction
-- =============================================================================
-- OBJET
--   Peuple la base de préproduction avec les données de démonstration, en
--   reproduisant à l'identique le contenu des fixtures Doctrine :
--     - src/DataFixtures/ReferenceFixtures.php  (groupe « reference »)
--     - src/DataFixtures/DemoFixtures.php        (groupe « demo »)
--
-- POURQUOI CE SCRIPT
--   L'image de prod/préprod est construite avec `composer install --no-dev` :
--   doctrine-fixtures-bundle n'y est PAS présent. On ne peut donc pas lancer
--   `php bin/console doctrine:fixtures:load` en préproduction. Ce fichier est
--   l'équivalent SQL des fixtures, exécutable directement sur MySQL.
--
-- DONNÉES FICTIVES
--   Tous les comptes sont des comptes de démonstration (adresses
--   creaslotdemo+*@gmail.com). Le mot de passe de TOUS les comptes est
--   « password » (hash argon2id ci-dessous). NE JAMAIS UTILISER EN PRODUCTION.
--
-- CIBLE
--   Base `creaslot_preprod`. À exécuter par exemple :
--     docker compose exec -T db mysql -u root -p creaslot_preprod < scripts/seed-preprod.sql
--   (ou via phpMyAdmin, base creaslot_preprod sélectionnée).
--
-- IDEMPOTENCE (script rejouable sans doublon)
--   On reproduit le comportement de `doctrine:fixtures:load` (qui purge les
--   tables avant de charger). Le script commence donc par vider les tables
--   peuplées, puis réinsère avec des identifiants explicites déterministes.
--   Les DELETE sont faits dans l'ordre inverse des clés étrangères
--   (notification → reservation → creneau → utilisateur → type_rdv → service).
--   `reset_password_request` est également purgée : elle porte une FK NON NULL
--   vers `utilisateur`, donc re-seeder les comptes invaliderait de toute façon
--   les jetons en cours ; on la vide pour garder l'intégrité référentielle.
--   Les contrôles de FK sont désactivés le temps des DELETE, puis réactivés.
--   L'ensemble est encapsulé dans une transaction : tout ou rien.
--
-- DATES DES CRÉNEAUX
--   Les fixtures calculent les créneaux relativement à « maintenant »
--   (offsetJours / offsetHeures), puis fixent l'heure pile (setTime(H, 0)).
--   Pour que les créneaux restent futurs à chaque exécution, on reproduit ce
--   calcul dynamiquement en SQL avec CURDATE() comme base :
--       date_debut = CURDATE() + offsetHeures heures + offsetJours jours
--   CURDATE() (minuit du jour courant) reproduit fidèlement le setTime(H, 0)
--   des fixtures (minutes/secondes à zéro), là où NOW() embarquerait l'heure
--   courante. date_fin = date_debut + 1 heure.
-- =============================================================================

SET NAMES utf8mb4;
START TRANSACTION;

-- --- Purge idempotente (ordre inverse des FK, contrôles FK désactivés) --------
SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM notification;
DELETE FROM reservation;
DELETE FROM creneau;
DELETE FROM reset_password_request;
DELETE FROM utilisateur;
DELETE FROM type_rdv;
DELETE FROM service;
SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================================
-- 1. SERVICES  (ReferenceFixtures::creerServices)
-- =============================================================================
INSERT INTO service (id, nom, description, est_actif) VALUES
    (1, 'Service Commercial', 'Responsables commerciaux du Cnam',    1),
    (2, 'Service Alternance', 'Gestionnaires de l''alternance',       1),
    (3, 'Accueil',            'Accueil et orientation des auditeurs', 1);

-- =============================================================================
-- 2. TYPES DE RDV  (ReferenceFixtures::creerTypesRdv)
-- =============================================================================
INSERT INTO type_rdv (id, code, libelle, couleur_hex, icone, description, est_actif) VALUES
    (1, 'PRESENTIEL', 'Présentiel', '#28A745', 'bi-geo-alt',      'Rendez-vous en présentiel au Cnam Réunion', 1),
    (2, 'VISIO',      'Visio',      '#FD7E14', 'bi-camera-video', 'Rendez-vous en visioconférence',            1),
    (3, 'TELEPHONE',  'Téléphone',  '#007BFF', 'bi-telephone',    'Rendez-vous par téléphone',                 1);

-- =============================================================================
-- 3. UTILISATEURS  (DemoFixtures::creerPersonnel / creerAuditeurs / creerAdmin)
-- -----------------------------------------------------------------------------
--   Mot de passe « password » pour tous — hash argon2id partagé.
--   date_creation = NOW() ; derniere_connexion = NULL ; est_actif = 1.
--   email_modification_commentaire / email_rappel_j1 : défaut 1 (opt-out RGPD).
--   RGPD (US-4.8) : Julie Potier a désactivé le rappel J-1 → email_rappel_j1 = 0.
-- =============================================================================
INSERT INTO utilisateur
    (id, email, mot_de_passe_hash, nom, prenom, role, date_creation, derniere_connexion, est_actif, email_modification_commentaire, email_rappel_j1, id_service)
VALUES
    -- Personnel (ROLE_PERSONNEL), rattaché à un service
    (1, 'creaslotdemo+marie@gmail.com',    '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Dupont',   'Marie',  'ROLE_PERSONNEL',   NOW(), NULL, 1, 1, 1, 1),
    (2, 'creaslotdemo+jean@gmail.com',     '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Martin',   'Jean',   'ROLE_PERSONNEL',   NOW(), NULL, 1, 1, 1, 2),
    (3, 'creaslotdemo+sophie@gmail.com',   '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Lefevre',  'Sophie', 'ROLE_PERSONNEL',   NOW(), NULL, 1, 1, 1, 3),
    -- Auditeurs (ROLE_AUDITEUR), sans service ; Julie (id 5) : email_rappel_j1 = 0
    (4, 'creaslotdemo+xavier@gmail.com',   '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Dijoux',   'Xavier', 'ROLE_AUDITEUR',    NOW(), NULL, 1, 1, 1, NULL),
    (5, 'creaslotdemo+julie@gmail.com',    '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Potier',   'Julie',  'ROLE_AUDITEUR',    NOW(), NULL, 1, 1, 0, NULL),
    (6, 'creaslotdemo+timothee@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Perez',    'Timothée', 'ROLE_AUDITEUR',  NOW(), NULL, 1, 1, 1, NULL),
    (7, 'creaslotdemo+celina@gmail.com',   '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Pasquier', 'Célina', 'ROLE_AUDITEUR',    NOW(), NULL, 1, 1, 1, NULL),
    (8, 'creaslotdemo+margot@gmail.com',   '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Robin',    'Margot', 'ROLE_AUDITEUR',    NOW(), NULL, 1, 1, 1, NULL),
    -- Super-administrateur de démo (ROLE_SUPER_ADMIN)
    (9, 'creaslotdemo+admin@gmail.com',    '$argon2id$v=19$m=65536,t=4,p=1$4W+bav4/SVQQAertE/QRFg$DeB0YiGOiU2SZAR6cj+E1GeNtHXIp/04FRIV32hmIHk', 'Admin',    'Super',  'ROLE_SUPER_ADMIN', NOW(), NULL, 1, 1, 1, NULL);

-- =============================================================================
-- 4. CRÉNEAUX  (DemoFixtures::creerCreneaux)
-- -----------------------------------------------------------------------------
--   Répartition cyclique des fixtures (index 0..9) :
--     type_rdv     = (index % 3) + 1   → 1=PRESENTIEL, 2=VISIO, 3=TELEPHONE
--     utilisateur  = (index % 3) + 1   → 1=Marie, 2=Jean, 3=Sophie
--   date_debut = CURDATE() + offsetHeures h + offsetJours j ; date_fin = +1 h.
--   Le dernier créneau (offsetJours = -3) est un créneau passé (archivé).
-- =============================================================================
INSERT INTO creneau
    (id, id_utilisateur, id_type_rdv, date_debut, date_fin, commentaire_auditeur, date_creation, est_actif)
VALUES
    (1,  1, 1, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9  HOUR), INTERVAL 7  DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL 7  DAY),  'Disponible pour questions sur votre dossier d''alternance', NOW(), 1),
    (2,  2, 2, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 11 HOUR), INTERVAL 7  DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 12 HOUR), INTERVAL 7  DAY),  NULL,                                                        NOW(), 1),
    (3,  3, 3, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9  HOUR), INTERVAL 8  DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL 8  DAY),  'Premier entretien de suivi',                                NOW(), 1),
    (4,  1, 1, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 14 HOUR), INTERVAL 8  DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 15 HOUR), INTERVAL 8  DAY),  NULL,                                                        NOW(), 1),
    (5,  2, 2, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL 10 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 11 HOUR), INTERVAL 10 DAY),  'Rendez-vous de bilan de fin de module',                     NOW(), 1),
    (6,  3, 3, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9  HOUR), INTERVAL 14 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL 14 DAY),  NULL,                                                        NOW(), 1),
    (7,  1, 1, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 14 HOUR), INTERVAL 14 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 15 HOUR), INTERVAL 14 DAY),  'Entretien sur les modalités de financement',                NOW(), 1),
    (8,  2, 2, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 9  HOUR), INTERVAL 21 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL 21 DAY),  NULL,                                                        NOW(), 1),
    (9,  3, 3, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 11 HOUR), INTERVAL 21 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 12 HOUR), INTERVAL 21 DAY),  'Questions administratives diverses',                        NOW(), 1),
    (10, 1, 1, DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 10 HOUR), INTERVAL -3 DAY),  DATE_ADD(DATE_ADD(CURDATE(), INTERVAL 11 HOUR), INTERVAL -3 DAY),  'Créneau passé — archivé',                                   NOW(), 1);

-- =============================================================================
-- 5. RÉSERVATIONS  (DemoFixtures::creerReservations)
-- -----------------------------------------------------------------------------
--   R1 : créneau 1  (Marie/PRESENTIEL)  réservé par Xavier (id 4)   — ACTIVE
--   R2 : créneau 3  (Sophie/TELEPHONE)  réservé par Julie (id 5)    — ACTIVE
--   R3 : créneau 5  (Jean/VISIO)        réservé par Timothée (id 6) — ANNULEE
--        date_annulation = hier (NOW() - 1 jour), avec motif.
-- =============================================================================
INSERT INTO reservation
    (id, id_creneau, id_utilisateur, date_reservation, commentaire_auditeur, statut, date_annulation, motif_annulation, rappel_envoye_at)
VALUES
    (1, 1, 4, NOW(), 'Je souhaite faire le point sur mon dossier d''alternance.', 'ACTIVE',  NULL,                              NULL,                                            NULL),
    (2, 3, 5, NOW(), NULL,                                                        'ACTIVE',  NULL,                              NULL,                                            NULL),
    (3, 5, 6, NOW(), 'Demande de bilan de fin de module.',                        'ANNULEE', DATE_SUB(NOW(), INTERVAL 1 DAY), 'Indisponibilité imprévue de l''auditeur.',      NULL);

-- =============================================================================
-- 6. NOTIFICATIONS in-app  (DemoFixtures::creerNotifications, US-4.7)
-- -----------------------------------------------------------------------------
--   Xavier (id 4) : 4 notifications (2 non lues, 2 lues).
--   Julie  (id 5) : 1 notification (non lue).
--   id_reservation = NULL (données illustratives non liées à une réservation).
-- =============================================================================
INSERT INTO notification
    (id, id_destinataire, type, titre, message, lu, date_creation, id_reservation)
VALUES
    (1, 4, 'CONFIRMATION_RESERVATION', 'Réservation confirmée',       'Votre rendez-vous avec Marie Dupont le 06/06/2026 à 10h00 a été confirmé.',                                              0, NOW(), NULL),
    (2, 4, 'RAPPEL_J1',                'Rappel : rendez-vous demain', 'N''oubliez pas votre rendez-vous demain, le 06/06/2026 à 10h00, avec Marie Dupont.',                                     0, NOW(), NULL),
    (3, 4, 'MODIFICATION_COMMENTAIRE', 'Modification du créneau',     'Le commentaire de votre rendez-vous du 06/06/2026 à 10h00 a été modifié par Marie Dupont.',                              1, NOW(), NULL),
    (4, 4, 'ANNULATION_RESERVATION',   'Réservation annulée',         'Votre rendez-vous avec Jean Martin le 05/06/2026 à 14h00 a été annulé. Motif : Indisponibilité du conseiller.',           1, NOW(), NULL),
    (5, 5, 'SUPPRESSION_CRENEAU',      'Créneau supprimé',            'Votre rendez-vous du 04/06/2026 à 09h00 avec Sophie Lefevre a été annulé : le créneau a été supprimé.',                  0, NOW(), NULL);

COMMIT;

-- =============================================================================
-- Fin du seed. Comptes de démo (mot de passe « password ») :
--   Personnel      : creaslotdemo+marie@gmail.com / +jean / +sophie
--   Auditeurs      : creaslotdemo+xavier@gmail.com / +julie / +timothee / +celina / +margot
--   Super-admin    : creaslotdemo+admin@gmail.com
-- =============================================================================
