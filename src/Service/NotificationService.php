<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Reservation;
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
final readonly class NotificationService
{
    public function __construct(
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private LoggerInterface $logger,
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
            $creneau->getDateDebut()
                ->setTimezone(new \DateTimeZone('Indian/Reunion'))
                ->format('d/m/Y \à H\hi'),
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
            $creneau->getDateDebut()
                ->setTimezone(new \DateTimeZone('Indian/Reunion'))
                ->format('d/m/Y \à H\hi'),
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
            $creneau->getDateDebut()
                ->setTimezone(new \DateTimeZone('Indian/Reunion'))
                ->format('d/m/Y \à H\hi'),
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
            $creneau->getDateDebut()
                ->setTimezone(new \DateTimeZone('Indian/Reunion'))
                ->format('d/m/Y \à H\hi'),
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
}
