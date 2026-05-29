<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Creneau;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Utilisateur;
use App\Enum\StatutReservation;
use App\Enum\TypeNotification;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Service d'envoi d'emails transactionnels via Brevo (US-4.1+).
 *
 * Centralise la construction des emails (from, replyTo, template Twig) pour que
 * les contrôleurs n'aient qu'à fournir le destinataire, le sujet, le template
 * et son contexte. Aucune logique métier ici : c'est uniquement un wrapper
 * RGPD-friendly autour de MailerInterface.
 *
 * ─ Choix RGPD ─────────────────────────────────────────────────────────────
 * Les logs n'enregistrent JAMAIS l'adresse email du destinataire ni le sujet
 * en clair. Seuls un hash partiel SHA-256 de l'adresse (8 premiers caractères,
 * non réversible en pratique sans dictionnaire ciblé) et le nom du template
 * sont tracés. Cela permet l'audit ("combien d'emails partis ?", "lequel a
 * échoué ?") sans exposer de donnée personnelle dans les fichiers de log,
 * qui peuvent être archivés ou consultés par des tiers (DevOps, hébergeur).
 *
 * ─ Redirection DEV ────────────────────────────────────────────────────────
 * Si la variable d'environnement APP_MAILER_REDIRECT_TO est définie (uniquement
 * prévue en environnement DEV, jamais en preprod/prod), TOUS les emails sont
 * redirigés vers cette adresse au lieu du destinataire d'origine. Le sujet
 * est alors préfixé "[DEV→destinataire-original@exemple.fr] " pour conserver
 * la traçabilité du destinataire prévu. La syntaxe %env(default::VAR)% du
 * conteneur Symfony fait que l'absence de la variable renvoie NULL sans
 * erreur : en preprod/prod, où la variable n'existe pas, le service envoie
 * normalement vers le destinataire d'origine. Côté logs RGPD, on ajoute
 * 'redirige' => true et un 'redirige_vers_hash' (hash partiel SHA-256 de
 * l'adresse de redirection) quand la redirection est active.
 */
readonly class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
        private DateFormatterService $dateFormatter,
        private EntityManagerInterface $entityManager,
        #[Autowire('%env(APP_NOTIFICATION_FROM)%')]
        private string $expediteur,
        #[Autowire('%env(APP_NOTIFICATION_REPLY_TO)%')]
        private string $replyTo,
        #[Autowire('%env(default::APP_MAILER_REDIRECT_TO)%')]
        private ?string $redirectionDev = null,
    ) {}

    /**
     * Envoie un email transactionnel à partir d'un template Twig.
     *
     * Si APP_MAILER_REDIRECT_TO est définie (cf. section "Redirection DEV" du
     * docblock de classe), $to est remplacé par l'adresse de redirection et
     * le sujet est préfixé "[DEV→destinataire-original]" avant construction
     * du TemplatedEmail.
     *
     * @param string               $to       Adresse email du destinataire (jamais loguée en clair)
     * @param string               $subject  Sujet affiché dans la boîte de réception (jamais logué)
     * @param string               $template Chemin du template Twig (ex: 'emails/reservation_confirmee.html.twig')
     * @param array<string, mixed> $context  Variables passées au template
     *
     * @throws \Throwable Si la construction ou l'envoi de l'email échoue.
     *                    L'exception est loguée puis relancée pour traitement par l'appelant.
     */
    public function envoyer(
        string $to,
        string $subject,
        string $template,
        array $context = [],
    ): void {
        $redirige = $this->redirectionDev !== null && $this->redirectionDev !== '';

        if ($redirige) {
            $subject = sprintf('[DEV→%s] %s', $to, $subject);
            $to      = $this->redirectionDev;
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($this->expediteur))
                ->replyTo(new Address($this->replyTo))
                ->to(new Address($to))
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $contexteErreur = [
                'to_hash'         => substr(hash('sha256', $to), 0, 8),
                'template'        => $template,
                'redirige'        => $redirige,
                'exception_class' => $e::class,
            ];
            if ($redirige) {
                $contexteErreur['redirige_vers_hash'] = substr(hash('sha256', $this->redirectionDev), 0, 8);
            }
            $this->logger->error('Envoi email échoué', $contexteErreur);

            throw $e;
        }

        $contexteSucces = [
            'to_hash'  => substr(hash('sha256', $to), 0, 8),
            'template' => $template,
            'redirige' => $redirige,
        ];
        if ($redirige) {
            $contexteSucces['redirige_vers_hash'] = substr(hash('sha256', $this->redirectionDev), 0, 8);
        }
        $this->logger->info('Email envoyé', $contexteSucces);
    }

    /**
     * Génère une URL absolue pour la route donnée.
     *
     * Le contexte HTTP (host, scheme) provient de framework.router.default_uri
     * en mode CLI/worker async, et du contexte HTTP courant en mode web.
     *
     * @param string $route Nom de la route Symfony (ex: app_mes_reservations)
     * @return string URL absolue (ex: https://creaslot.re/mes-reservations)
     */
    private function genererLienAbsolu(string $route): string
    {
        return $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Notifie l'Auditeur de la confirmation de sa réservation.
     *
     * Envoie l'email transactionnel reservation_confirmation_auditeur.html.twig
     * au destinataire principal de la Reservation. Les éventuelles erreurs SMTP
     * sont loguées mais NE SONT PAS propagées : la réservation reste valide en
     * BDD même si l'email échoue (politique Option B : retry géré par Messenger
     * via la file async en preprod/prod).
     *
     * @param Reservation $reservation La réservation venant d'être créée
     */
    public function notifierAuditeurReservation(Reservation $reservation): void
    {
        // Une Reservation::utilisateur représente l'Auditeur (règle métier).
        // Un Creneau::utilisateur représente le Personnel (règle métier).
        // Le typage Utilisateur reste neutre côté ORM.
        $auditeur  = $reservation->getUtilisateur();
        $creneau   = $reservation->getCreneau();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Rendez-vous confirmé — %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'auditeur_prenom'        => $auditeur->getPrenom(),
            'creneau_debut'          => $creneau->getDateDebut(),
            'creneau_fin'            => $creneau->getDateFin(),
            'personnel_nom_complet'  => $personnel->getNomComplet(),
            'service_nom'            => $personnel->getService()?->getNom(),
            'type_rdv_libelle'       => $creneau->getTypeRdv()->getLibelle(),
            'commentaire_auditeur'   => $reservation->getCommentaireAuditeur(),
            'lien_mes_reservations'  => $this->genererLienAbsolu('app_mes_reservations'),
        ];

        // US-4.7 : persiste la notification in-app AVANT l'envoi email (Q-US47-F).
        $this->persisterNotification(
            $auditeur,
            TypeNotification::CONFIRMATION_RESERVATION,
            'Réservation confirmée',
            sprintf(
                'Votre rendez-vous avec %s le %s a été confirmé.',
                $personnel->getNomComplet(),
                $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
            ),
            $reservation,
        );

        try {
            $this->envoyer(
                $auditeur->getEmail(),
                $subject,
                'emails/reservation_confirmation_auditeur.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification reservation auditeur', [
                'type'           => 'auditeur_reservation',
                'reservation_id' => $reservation->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le Personnel d'une nouvelle réservation sur l'un de ses créneaux.
     *
     * Envoie l'email transactionnel reservation_confirmation_personnel.html.twig
     * au Personnel propriétaire du créneau. Mêmes garanties d'isolation que
     * notifierAuditeurReservation() : erreurs loguées, non propagées.
     *
     * La variable auditeur_categorie est passée à null tant que le champ
     * categorie_auditeur n'est pas implémenté dans l'entité Utilisateur. Le
     * template gère ce cas via un {% if auditeur_categorie %} conditionnel.
     *
     * @param Reservation $reservation La réservation venant d'être créée
     */
    public function notifierPersonnelReservation(Reservation $reservation): void
    {
        // Une Reservation::utilisateur représente l'Auditeur (règle métier).
        // Un Creneau::utilisateur représente le Personnel (règle métier).
        // Le typage Utilisateur reste neutre côté ORM.
        $auditeur  = $reservation->getUtilisateur();
        $creneau   = $reservation->getCreneau();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Nouveau rendez-vous — %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'personnel_prenom'      => $personnel->getPrenom(),
            'auditeur_nom_complet'  => $auditeur->getNomComplet(),
            'auditeur_categorie'    => null, // Champ non implémenté en BDD (cf. décision A1, audit 3.1).
            'creneau_debut'         => $creneau->getDateDebut(),
            'creneau_fin'           => $creneau->getDateFin(),
            'type_rdv_libelle'      => $creneau->getTypeRdv()->getLibelle(),
            'commentaire_auditeur'  => $reservation->getCommentaireAuditeur(),
            'lien_mon_agenda'       => $this->genererLienAbsolu('app_creneau_agenda'),
        ];

        try {
            $this->envoyer(
                $personnel->getEmail(),
                $subject,
                'emails/reservation_confirmation_personnel.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification reservation personnel', [
                'type'           => 'personnel_reservation',
                'reservation_id' => $reservation->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie l'Auditeur de la confirmation d'annulation de sa réservation.
     *
     * Envoie l'email transactionnel reservation_annulation_auditeur.html.twig.
     * Politique Option B : erreurs SMTP loguées (sans PII) puis ignorées —
     * l'annulation reste valide en BDD même si l'email échoue.
     * Le motif d'annulation est transmis tel quel (peut être null, affichage
     * conditionnel côté template).
     *
     * @param Reservation $reservation La réservation venant d'être annulée
     */
    public function notifierAuditeurAnnulationReservation(Reservation $reservation): void
    {
        // Une Reservation::utilisateur représente l'Auditeur (règle métier).
        // Un Creneau::utilisateur représente le Personnel (règle métier).
        // Le typage Utilisateur reste neutre côté ORM.
        $auditeur  = $reservation->getUtilisateur();
        $creneau   = $reservation->getCreneau();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Votre rendez-vous a été annulé — %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'auditeur_prenom'       => $auditeur->getPrenom(),
            'creneau_debut'         => $creneau->getDateDebut(),
            'creneau_fin'           => $creneau->getDateFin(),
            'personnel_nom_complet' => $personnel->getNomComplet(),
            'service_nom'           => $personnel->getService()?->getNom(),
            'type_rdv_libelle'      => $creneau->getTypeRdv()->getLibelle(),
            'motif_annulation'      => $reservation->getMotifAnnulation(),
            'lien_mes_reservations' => $this->genererLienAbsolu('app_mes_reservations'),
        ];

        // US-4.7 : persiste la notification in-app AVANT l'envoi email (Q-US47-F).
        $message = sprintf(
            'Votre rendez-vous avec %s le %s a été annulé.',
            $personnel->getNomComplet(),
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $motif = $reservation->getMotifAnnulation();
        if ($motif !== null && $motif !== '') {
            $message .= ' Motif : ' . $motif;
        }

        $this->persisterNotification(
            $auditeur,
            TypeNotification::ANNULATION_RESERVATION,
            'Réservation annulée',
            $message,
            $reservation,
        );

        try {
            $this->envoyer(
                $auditeur->getEmail(),
                $subject,
                'emails/reservation_annulation_auditeur.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification annulation auditeur', [
                'type'           => 'auditeur_annulation',
                'reservation_id' => $reservation->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie le Personnel qu'un auditeur a annulé sa réservation sur l'un
     * de ses créneaux. Le créneau redevient disponible.
     *
     * Politique Option B : erreurs SMTP loguées (sans PII) puis ignorées.
     *
     * ── Asymétrie volontaire sur le motif d'annulation ──
     * - motif_annulation N'EST PAS dans le contexte (minimisation RGPD :
     *   peut contenir des données médicales ou personnelles sensibles ;
     *   aucune utilité opérationnelle pour le Personnel).
     * - Cohérence US-4.2 : aucune coordonnée Auditeur n'a été exposée
     *   côté Personnel ; le motif suit la même politique de minimisation.
     * Réf : US-4.3 audit section 3, dossier MSP3 section 6.6 Sécurité/RGPD.
     *
     * auditeur_categorie : passée à null (décision A1 audit 3.1 US-4.2,
     * cf. regression test NotificationServiceTest step 5.b).
     *
     * @param Reservation $reservation La réservation venant d'être annulée
     */
    public function notifierPersonnelAnnulationReservation(Reservation $reservation): void
    {
        // Une Reservation::utilisateur représente l'Auditeur (règle métier).
        // Un Creneau::utilisateur représente le Personnel (règle métier).
        // Le typage Utilisateur reste neutre côté ORM.
        $auditeur  = $reservation->getUtilisateur();
        $creneau   = $reservation->getCreneau();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Annulation par %s — %s',
            $auditeur->getNomComplet(),
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'personnel_prenom'     => $personnel->getPrenom(),
            'auditeur_nom_complet' => $auditeur->getNomComplet(),
            'auditeur_categorie'   => null, // décision A1 US-4.2 (cf. regression test step 5.b)
            'creneau_debut'        => $creneau->getDateDebut(),
            'creneau_fin'          => $creneau->getDateFin(),
            'type_rdv_libelle'     => $creneau->getTypeRdv()->getLibelle(),
            'lien_mon_agenda'      => $this->genererLienAbsolu('app_creneau_agenda'),
        ];

        try {
            $this->envoyer(
                $personnel->getEmail(),
                $subject,
                'emails/reservation_annulation_personnel.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification annulation personnel', [
                'type'           => 'personnel_annulation',
                'reservation_id' => $reservation->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie l'Auditeur quand le commentaire/motif de son créneau réservé
     * est modifié par le Personnel (US-4.4).
     *
     * Envoie l'email transactionnel reservation_modification_auditeur.html.twig.
     * Politique Option B : erreurs SMTP loguées (sans PII) puis ignorées —
     * la modification du créneau reste valide en BDD même si l'email échoue.
     *
     * Garde-fou defensive : si le créneau n'est plus réservé au moment de
     * l'appel (race-condition possible si l'auditeur annule entre-temps),
     * la méthode retourne sans rien faire.
     *
     * Note : la décision d'appeler ou non cette méthode (en fonction du
     * changement effectif du commentaire) revient au contrôleur appelant.
     * Le service ne re-vérifie PAS le delta commentaire.
     *
     * RGPD : le log error ne contient QUE les longueurs (avant/après) du
     * commentaire, jamais son contenu (potentiellement données sensibles).
     *
     * @param Creneau $creneau Le créneau venant d'être modifié
     * @param ?string $commentaireAvant Le commentaire AVANT modification (snapshot pris dans le contrôleur)
     */
    public function notifierAuditeurCommentaireCreneau(Creneau $creneau, ?string $commentaireAvant): void
    {
        // Garde-fou defensive (race-condition possible si auditeur annule en parallèle).
        if (!$creneau->isReserve()) {
            return;
        }

        $auditeur  = $creneau->getAuditeurReservation();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Mise à jour de votre rendez-vous — %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'auditeur_prenom'       => $auditeur->getPrenom(),
            'personnel_nom_complet' => $personnel->getNomComplet(),
            'service_nom'           => $personnel->getService()?->getNom(),
            'creneau_debut'         => $creneau->getDateDebut(),
            'creneau_fin'           => $creneau->getDateFin(),
            'commentaire_apres'     => $creneau->getCommentaireAuditeur(),
            'lien_mes_reservations' => $this->genererLienAbsolu('app_mes_reservations'),
        ];

        // US-4.7 : persiste la notification in-app AVANT l'envoi email (Q-US47-F).
        // Reservation liée = la résa ACTIVE courante (théoriquement non-null ici
        // grâce au garde-fou isReserve() en tête de méthode ; le helper tolère null).
        $this->persisterNotification(
            $auditeur,
            TypeNotification::MODIFICATION_COMMENTAIRE,
            'Modification du créneau',
            sprintf(
                'Le commentaire de votre rendez-vous du %s a été modifié par %s.',
                $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
                $personnel->getNomComplet(),
            ),
            $creneau->getReservationActive(),
        );

        // US-4.8 : envoi email conditionné par la préférence Auditeur (type confort).
        // Base légale RGPD art. 6.1.b. La notification in-app est déjà persistée
        // ci-dessus (audit trail B2) ; seul l'email est ici conditionné.
        if (!$auditeur->isEmailModificationCommentaire()) {
            return;
        }

        try {
            $this->envoyer(
                $auditeur->getEmail(),
                $subject,
                'emails/reservation_modification_auditeur.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification commentaire creneau', [
                'type'                  => 'auditeur_commentaire_creneau',
                'creneau_id'            => $creneau->getId(),
                'reservation_id'        => $creneau->getReservationActive()?->getId(),
                'auditeur_id'           => $auditeur->getId(),
                'commentaire_avant_len' => strlen($commentaireAvant ?? ''),
                'commentaire_apres_len' => strlen($creneau->getCommentaireAuditeur() ?? ''),
                'exception'             => $e::class,
                'message'               => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie l'Auditeur quand le Personnel supprime le créneau qu'il avait
     * réservé (US-4.5).
     *
     * Envoie l'email transactionnel creneau_suppression_auditeur.html.twig.
     * Politique Option B : erreurs SMTP loguées (sans PII) puis ignorées —
     * la suppression du créneau reste valide en BDD même si l'email échoue.
     *
     * Garde-fou defensive : la méthode exige que la Reservation passée
     * soit déjà au statut ANNULEE (le contrôleur appelant doit avoir
     * appelé annulerReservationLiee() avant cet appel).
     *
     * Architecture (DT-1) : la Reservation est passée explicitement par
     * le caller (CreneauController::supprimer()) qui la capture AVANT
     * l'annulation. Cela élimine l'ambiguïté liée à la relation OneToMany
     * (un Creneau peut avoir plusieurs Reservations ANNULEE historiques —
     * on notifie spécifiquement l'auditeur de CELLE qu'on vient d'annuler).
     *
     * RGPD : le log error ne contient AUCUN contenu sensible (pas de motif,
     * pas de nom en clair), uniquement les identifiants techniques.
     *
     * @param Creneau     $creneau     Le créneau venant d'être supprimé (estActif=false)
     * @param Reservation $reservation La Reservation associée, déjà annulée par annulerReservationLiee()
     */
    public function notifierAuditeurSuppressionCreneau(Creneau $creneau, Reservation $reservation): void
    {
        // Garde-fou : la Reservation doit être annulée (précondition du caller).
        // Statut différent => incohérence côté caller, retour silencieux.
        if ($reservation->getStatut() !== StatutReservation::ANNULEE) {
            return;
        }

        $auditeur  = $reservation->getUtilisateur();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Votre créneau a été supprimé par le Personnel — %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'auditeur_prenom'           => $auditeur->getPrenom(),
            'personnel_nom_complet'     => $personnel->getNomComplet(),
            'service_nom'               => $personnel->getService()?->getNom(),
            'creneau_debut'             => $creneau->getDateDebut(),
            'creneau_fin'               => $creneau->getDateFin(),
            'lien_creneaux_disponibles' => $this->genererLienAbsolu('app_creneaux_disponibles'),
        ];

        // US-4.7 : persiste la notification in-app AVANT l'envoi email (Q-US47-F).
        $this->persisterNotification(
            $auditeur,
            TypeNotification::SUPPRESSION_CRENEAU,
            'Créneau supprimé',
            sprintf(
                'Votre rendez-vous du %s avec %s a été annulé : le créneau a été supprimé.',
                $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
                $personnel->getNomComplet(),
            ),
            $reservation,
        );

        try {
            $this->envoyer(
                $auditeur->getEmail(),
                $subject,
                'emails/creneau_suppression_auditeur.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi notification suppression creneau', [
                'type'           => 'auditeur_suppression_creneau',
                'creneau_id'     => $creneau->getId(),
                'reservation_id' => $reservation->getId(),
                'auditeur_id'    => $auditeur->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notifie l'Auditeur de son rendez-vous prévu le lendemain (US-4.6).
     *
     * Envoie l'email transactionnel reservation_rappel_auditeur.html.twig
     * via la commande planifiée EnvoyerRappelsJ1Command, exécutée chaque
     * jour à 18h Réunion par cron Linux côté serveur.
     *
     * Politique Option B : erreurs SMTP loguées (sans PII) puis ignorées —
     * l'erreur d'envoi ne bloque pas le reste du batch côté Command, qui
     * continue avec la réservation suivante.
     *
     * Garde-fou defensive : la méthode exige Reservation au statut ACTIVE
     * (cohérent US-4.4/4.5). Si la réservation a été annulée entre la query
     * Repository et l'envoi (race condition), on skip silencieusement.
     *
     * RGPD : le log error ne contient AUCUN contenu sensible, uniquement
     * les identifiants techniques (creneau_id, reservation_id, auditeur_id).
     *
     * @param Reservation $reservation La réservation ACTIVE à rappeler
     */
    public function notifierAuditeurRappel(Reservation $reservation): void
    {
        // Garde-fou strict : Reservation ACTIVE uniquement.
        if ($reservation->getStatut() !== StatutReservation::ACTIVE) {
            return;
        }

        $creneau   = $reservation->getCreneau();
        $auditeur  = $reservation->getUtilisateur();
        $personnel = $creneau->getUtilisateur();

        $subject = sprintf(
            'Rappel : votre rendez-vous le %s',
            $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
        );

        $context = [
            'auditeur_prenom'       => $auditeur->getPrenom(),
            'personnel_nom_complet' => $personnel->getNomComplet(),
            'service_nom'           => $personnel->getService()?->getNom(),
            'creneau_debut'         => $creneau->getDateDebut(),
            'creneau_fin'           => $creneau->getDateFin(),
            'lien_mes_reservations' => $this->genererLienAbsolu('app_mes_reservations'),
        ];

        // US-4.7 : persiste la notification in-app AVANT l'envoi email (Q-US47-F).
        $this->persisterNotification(
            $auditeur,
            TypeNotification::RAPPEL_J1,
            'Rappel : rendez-vous demain',
            sprintf(
                "N'oubliez pas votre rendez-vous demain, le %s, avec %s.",
                $this->dateFormatter->pourSujetEmail($creneau->getDateDebut()),
                $personnel->getNomComplet(),
            ),
            $reservation,
        );

        // US-4.8 : envoi email conditionné par la préférence Auditeur (type confort).
        // Base légale RGPD art. 6.1.b. La notification in-app est déjà persistée
        // ci-dessus (audit trail B2) ; seul l'email est ici conditionné.
        if (!$auditeur->isEmailRappelJ1()) {
            return;
        }

        try {
            $this->envoyer(
                $auditeur->getEmail(),
                $subject,
                'emails/reservation_rappel_auditeur.html.twig',
                $context,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Echec envoi rappel J-1', [
                'type'           => 'auditeur_rappel_j1',
                'creneau_id'     => $creneau->getId(),
                'reservation_id' => $reservation->getId(),
                'auditeur_id'    => $auditeur->getId(),
                'exception'      => $e::class,
                'message'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persiste une Notification in-app pour le destinataire (US-4.7).
     *
     * Contrat d'appel : invoquée par les méthodes notifier* publiques APRÈS le
     * commit du flux métier (Controller ou Command). NE doit PAS être appelée
     * pendant une transaction PESSIMISTIC_WRITE active (cf. R7/R8 audit US-4.7),
     * car flush() vide tout l'UnitOfWork.
     *
     * persist + flush AVANT l'envoi email (Q-US47-F) : la Notification in-app est
     * indépendante de l'email et constitue le filet de traçabilité quand l'envoi
     * SMTP échoue. La persister d'abord garantit la trace même en cas d'échec.
     */
    private function persisterNotification(
        Utilisateur $destinataire,
        TypeNotification $type,
        string $titre,
        string $message,
        ?Reservation $reservation = null,
    ): void {
        $notification = (new Notification())
            ->setDestinataire($destinataire)
            ->setType($type)
            ->setTitre($titre)
            ->setMessage($message)
            ->setReservation($reservation);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
    }
}
