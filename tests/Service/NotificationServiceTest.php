<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\Notification;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Service\DateFormatterService;
use App\Service\NotificationService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tests d'intégration légers du NotificationService.
 *
 * Couverture actuelle : 18 tests (4 US-4.2 confirmation + 5 US-4.3 annulation
 * + 3 US-4.4 commentaire créneau + 3 US-4.5 suppression créneau
 * + 3 US-4.6 rappel J-1) sur notifier{Auditeur,Personnel}{,Annulation}Reservation()
 * / notifierAuditeurCommentaireCreneau() / notifierAuditeurSuppressionCreneau()
 * / notifierAuditeurRappel() et la politique Option B (capture exceptions).
 *
 * Dette technique tests identifiée — la branche if ($redirige) de envoyer()
 * (US-4.1) n'est pas couverte unitairement. Validation actuelle uniquement
 * visuelle via app:email:test. À couvrir dans une US ultérieure dédiée à
 * la consolidation des tests US-4.1.
 *
 * #[AllowMockObjectsWithoutExpectations] (US-4.8) : le setUp crée des mocks
 * partagés (mailer, urlGenerator, logger, entityManager) que chaque test
 * configure différemment — certains comme mocks (->expects()), d'autres comme
 * stubs (->method()/willReturnMap, ou no-op). Cet opt-out PHPUnit 13 supprime
 * la notice « no expectations configured » sans dégrader la rigueur (les
 * expects() restent vérifiés). Résout la dette DT-3.
 */
#[AllowMockObjectsWithoutExpectations]
final class NotificationServiceTest extends TestCase
{
    private MailerInterface&MockObject $mailer;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private EntityManagerInterface&MockObject $entityManager;

    private NotificationService $service;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        // URLs absolues retournées de façon déterministe pour les 3 routes utilisées.
        $this->urlGenerator->method('generate')
            ->willReturnMap([
                ['app_mes_reservations',     [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://test.local/mes-reservations'],
                ['app_creneau_agenda',       [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://test.local/creneau/agenda'],
                ['app_creneaux_disponibles', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://test.local/creneaux-disponibles'],
            ]);

        $this->service = new NotificationService(
            mailer: $this->mailer,
            urlGenerator: $this->urlGenerator,
            logger: $this->logger,
            dateFormatter: new DateFormatterService(), // instance réelle, service stateless
            entityManager: $this->entityManager,
            expediteur: 'noreply@test.local',
            replyTo: 'contact@test.local',
            redirectionDev: null, // pas de redirection en tests : sujet et destinataire non modifiés
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.2 — Confirmation de réservation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_reservation_envoie_un_email_avec_bon_template(): void
    {
        $reservation = $this->creerReservationComplete(commentaire: 'Je veux discuter financement.');

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierAuditeurReservation($reservation);

        self::assertNotNull($emailCapture);
        self::assertSame(
            'emails/reservation_confirmation_auditeur.html.twig',
            $emailCapture->getHtmlTemplate(),
        );
        self::assertStringContainsString('Rendez-vous confirmé', $this->sujet($emailCapture));
        self::assertSame('xavier@test.local', $emailCapture->getTo()[0]->getAddress());

        $context = $emailCapture->getContext();
        self::assertSame('Xavier', $context['auditeur_prenom']);
        self::assertSame('Marie Dupont', $context['personnel_nom_complet']);
        self::assertSame('Service Commercial', $context['service_nom']);
        self::assertSame('Présentiel', $context['type_rdv_libelle']);
        self::assertSame('Je veux discuter financement.', $context['commentaire_auditeur']);
        self::assertSame('https://test.local/mes-reservations', $context['lien_mes_reservations']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_personnel_reservation_envoie_un_email_avec_bon_template(): void
    {
        $reservation = $this->creerReservationComplete();

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierPersonnelReservation($reservation);

        self::assertNotNull($emailCapture);
        self::assertSame(
            'emails/reservation_confirmation_personnel.html.twig',
            $emailCapture->getHtmlTemplate(),
        );
        self::assertStringContainsString('Nouveau rendez-vous', $this->sujet($emailCapture));
        self::assertSame('marie@test.local', $emailCapture->getTo()[0]->getAddress());

        $context = $emailCapture->getContext();
        self::assertSame('Marie', $context['personnel_prenom']);
        self::assertSame('Xavier Dijoux', $context['auditeur_nom_complet']);
        self::assertSame('Présentiel', $context['type_rdv_libelle']);
        self::assertSame('https://test.local/creneau/agenda', $context['lien_mon_agenda']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_auditeur_reservation_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationComplete();

        $this->mailer->method('send')
            ->willThrowException(new \RuntimeException('Panne SMTP simulée'));

        // Capture des 2 appels logger->error attendus :
        //   1) depuis envoyer()                            : 'Envoi email échoué'
        //   2) depuis notifierAuditeurReservation()         : 'Echec envoi notification reservation auditeur'
        $errorCalls = [];
        $this->logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) use (&$errorCalls): void {
                $errorCalls[] = ['message' => $message, 'context' => $context];
            });

        // L'appel NE DOIT PAS lever d'exception malgré le throw du mailer.
        $this->service->notifierAuditeurReservation($reservation);

        self::assertCount(2, $errorCalls);

        // 1er log : envoyer() RGPD-friendly (to_hash, template, exception_class)
        self::assertSame('Envoi email échoué', $errorCalls[0]['message']);
        self::assertSame(
            'emails/reservation_confirmation_auditeur.html.twig',
            $errorCalls[0]['context']['template'],
        );
        self::assertSame(\RuntimeException::class, $errorCalls[0]['context']['exception_class']);

        // 2e log : contexte métier additionnel (reservation_id, type)
        self::assertSame('Echec envoi notification reservation auditeur', $errorCalls[1]['message']);
        self::assertSame('auditeur_reservation', $errorCalls[1]['context']['type']);
        self::assertSame(42, $errorCalls[1]['context']['reservation_id']);
        self::assertSame(\RuntimeException::class, $errorCalls[1]['context']['exception']);
        self::assertSame('Panne SMTP simulée', $errorCalls[1]['context']['message']);
    }

    /**
     * Regression test — décision A1 actée à l'audit 3.1 de l'US-4.2.
     *
     * Le champ categorie_auditeur n'est PAS implémenté dans l'entité Utilisateur
     * (vérifié exhaustivement par grep, aucun champ ni getter). Le template
     * Personnel attend néanmoins la clé `auditeur_categorie` dans son contexte,
     * que NotificationService::notifierPersonnelReservation() doit donc
     * passer explicitement à `null`. Le template gère ce cas via un
     * `{% if auditeur_categorie %}` conditionnel.
     *
     * Ce test fige ce comportement pour détecter un futur branchement partiel :
     * si quelqu'un branche getCategorieAuditeur() dans le service SANS ajouter
     * le champ en BDD, ce test pétera et alertera sur l'incohérence.
     *
     * Le jour où categorie_auditeur sera implémentée en BDD, ce test devra
     * être modifié pour vérifier la valeur attendue (string non null).
     */
    public function test_notifier_personnel_reservation_passe_auditeur_categorie_a_null(): void
    {
        $reservation = $this->creerReservationComplete();

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierPersonnelReservation($reservation);

        self::assertNotNull($emailCapture);
        $context = $emailCapture->getContext();
        self::assertArrayHasKey('auditeur_categorie', $context);
        self::assertNull($context['auditeur_categorie']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.3 — Annulation de réservation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_annulation_reservation_envoie_un_email_avec_bon_template(): void
    {
        $reservation = $this->creerReservationAnnulee(motif: 'Empêchement de dernière minute.');

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierAuditeurAnnulationReservation($reservation);

        self::assertNotNull($emailCapture);
        self::assertSame(
            'emails/reservation_annulation_auditeur.html.twig',
            $emailCapture->getHtmlTemplate(),
        );
        self::assertStringContainsString('Votre rendez-vous a été annulé', $this->sujet($emailCapture));
        self::assertSame('xavier@test.local', $emailCapture->getTo()[0]->getAddress());

        $context = $emailCapture->getContext();
        self::assertSame('Xavier', $context['auditeur_prenom']);
        self::assertSame('Marie Dupont', $context['personnel_nom_complet']);
        self::assertSame('Service Commercial', $context['service_nom']);
        self::assertSame('Présentiel', $context['type_rdv_libelle']);
        self::assertSame('Empêchement de dernière minute.', $context['motif_annulation']);
        self::assertSame('https://test.local/mes-reservations', $context['lien_mes_reservations']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_personnel_annulation_reservation_envoie_un_email_avec_bon_template(): void
    {
        // Note : on passe volontairement un motif renseigné à la fixture pour
        // prouver qu'il N'EST PAS transmis au contexte du template Personnel
        // (asymétrie RGPD volontaire, cf. PHPDoc des 2 templates US-4.3).
        $reservation = $this->creerReservationAnnulee(motif: 'Données sensibles bidons.');

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierPersonnelAnnulationReservation($reservation);

        self::assertNotNull($emailCapture);
        self::assertSame(
            'emails/reservation_annulation_personnel.html.twig',
            $emailCapture->getHtmlTemplate(),
        );
        self::assertStringContainsString('Annulation par Xavier Dijoux', $this->sujet($emailCapture));
        self::assertSame('marie@test.local', $emailCapture->getTo()[0]->getAddress());

        $context = $emailCapture->getContext();
        self::assertSame('Marie', $context['personnel_prenom']);
        self::assertSame('Xavier Dijoux', $context['auditeur_nom_complet']);
        self::assertSame('Présentiel', $context['type_rdv_libelle']);
        self::assertSame('https://test.local/creneau/agenda', $context['lien_mon_agenda']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);

        // Asymétrie RGPD : motif_annulation NE DOIT PAS être dans le contexte
        // (cf. PHPDoc reservation_annulation_personnel.html.twig — minimisation).
        self::assertArrayNotHasKey('motif_annulation', $context);
    }

    public function test_notifier_auditeur_annulation_reservation_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationAnnulee();

        $this->mailer->method('send')
            ->willThrowException(new \RuntimeException('Panne SMTP simulée'));

        // Capture des 2 appels logger->error attendus :
        //   1) depuis envoyer()                                      : 'Envoi email échoué'
        //   2) depuis notifierAuditeurAnnulationReservation()         : 'Echec envoi notification annulation auditeur'
        $errorCalls = [];
        $this->logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) use (&$errorCalls): void {
                $errorCalls[] = ['message' => $message, 'context' => $context];
            });

        // L'appel NE DOIT PAS lever d'exception malgré le throw du mailer.
        $this->service->notifierAuditeurAnnulationReservation($reservation);

        self::assertCount(2, $errorCalls);

        // 1er log : envoyer() RGPD-friendly (to_hash, template, exception_class)
        self::assertSame('Envoi email échoué', $errorCalls[0]['message']);
        self::assertSame(
            'emails/reservation_annulation_auditeur.html.twig',
            $errorCalls[0]['context']['template'],
        );
        self::assertSame(\RuntimeException::class, $errorCalls[0]['context']['exception_class']);

        // 2e log : contexte métier additionnel (reservation_id, type)
        self::assertSame('Echec envoi notification annulation auditeur', $errorCalls[1]['message']);
        self::assertSame('auditeur_annulation', $errorCalls[1]['context']['type']);
        self::assertSame(42, $errorCalls[1]['context']['reservation_id']);
        self::assertSame(\RuntimeException::class, $errorCalls[1]['context']['exception']);
        self::assertSame('Panne SMTP simulée', $errorCalls[1]['context']['message']);
    }

    /**
     * Regression test — décision A1 actée à l'audit 3.1 de l'US-4.2, étendue à US-4.3.
     *
     * Symétrique du test_notifierPersonnelReservation_passe_auditeur_categorie_a_null
     * (US-4.2) mais appliqué au flow d'annulation : le champ categorie_auditeur
     * n'est PAS implémenté dans l'entité Utilisateur. Le template Personnel
     * d'annulation attend néanmoins la clé `auditeur_categorie` dans son contexte,
     * que NotificationService::notifierPersonnelAnnulationReservation() doit
     * donc passer explicitement à `null`. Le template gère ce cas via un
     * `{% if auditeur_categorie %}` conditionnel.
     *
     * Le jour où categorie_auditeur sera implémentée en BDD, ce test ET son
     * homologue US-4.2 devront être modifiés pour vérifier la valeur attendue.
     */
    public function test_notifier_personnel_annulation_reservation_passe_auditeur_categorie_a_null(): void
    {
        $reservation = $this->creerReservationAnnulee();

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierPersonnelAnnulationReservation($reservation);

        self::assertNotNull($emailCapture);
        $context = $emailCapture->getContext();
        self::assertArrayHasKey('auditeur_categorie', $context);
        self::assertNull($context['auditeur_categorie']);
    }

    /**
     * Regression test — décision RGPD US-4.3 audit section 3.
     *
     * Vérifie le comportement conditionnel du motif d'annulation côté
     * Auditeur (asymétrie volontaire avec le template Personnel qui ne le
     * reçoit JAMAIS — cf. PHPDoc reservation_annulation_{auditeur,personnel}.html.twig).
     *
     * 2 sub-cases dans un même test via `exactly(2)` + capture en tableau :
     *   - motif renseigné : transmis tel quel au contexte (template l'affiche)
     *   - motif null       : transmis tel quel (null), template skip l'encadré
     *
     * Le jour où la politique d'affichage du motif change (ex: filtrage,
     * troncature, anonymisation), ce test devra être ajusté.
     */
    public function test_notifier_auditeur_annulation_reservation_affiche_motif_si_renseigne_et_passe_null_sinon(): void
    {
        $emailsCaptured = [];
        $this->mailer->expects($this->exactly(2))
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailsCaptured): void {
                $emailsCaptured[] = $email;
            });

        // 1er appel : motif renseigné
        $this->service->notifierAuditeurAnnulationReservation(
            $this->creerReservationAnnulee(motif: 'Empêchement médical'),
        );

        // 2e appel : motif null (équivalent à motif vide non saisi par l'auditeur)
        $this->service->notifierAuditeurAnnulationReservation(
            $this->creerReservationAnnulee(),
        );

        self::assertCount(2, $emailsCaptured);

        // 1er email : motif transmis tel quel
        self::assertSame(
            'Empêchement médical',
            $emailsCaptured[0]->getContext()['motif_annulation'],
        );

        // 2e email : motif null transmis tel quel
        self::assertNull($emailsCaptured[1]->getContext()['motif_annulation']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.4 — Commentaire créneau
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_commentaire_creneau_envoie_un_email_avec_bon_template_et_commentaire(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        // Le commentaire est porté par Creneau (cf. CreneauType data_class = Creneau).
        $creneau->setCommentaireAuditeur('Nouveau message du personnel');
        $commentaireAvant = 'Ancien commentaire';

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierAuditeurCommentaireCreneau($creneau, $commentaireAvant);

        self::assertNotNull($emailCapture);

        // Destinataire = l'auditeur de la réservation.
        $to = $emailCapture->getTo();
        self::assertCount(1, $to);
        self::assertSame('xavier@test.local', $to[0]->getAddress());

        // Subject + template.
        self::assertStringContainsString('Mise à jour de votre rendez-vous', $this->sujet($emailCapture));
        self::assertSame(
            'emails/reservation_modification_auditeur.html.twig',
            $emailCapture->getHtmlTemplate(),
        );

        // Context (7 clés attendues par le template).
        $context = $emailCapture->getContext();
        self::assertSame('Xavier', $context['auditeur_prenom']);
        self::assertSame('Marie Dupont', $context['personnel_nom_complet']);
        self::assertSame('Service Commercial', $context['service_nom']);
        self::assertSame('Nouveau message du personnel', $context['commentaire_apres']);
        self::assertSame('https://test.local/mes-reservations', $context['lien_mes_reservations']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_auditeur_commentaire_creneau_ne_fait_rien_si_creneau_non_reserve(): void
    {
        // Créneau standalone (pas de Reservation associée) → isReserve() = false.
        $personnel = $this->creerUtilisateur(
            id: 1,
            role: RoleUtilisateur::PERSONNEL,
            prenom: 'Marie',
            nom: 'Dupont',
            email: 'marie@test.local',
        );
        $creneau = $this->creerCreneau(
            id: 10,
            personnel: $personnel,
            type: $this->creerTypeRdv('Présentiel'),
            debut: '2026-06-15 14:00',
            fin: '2026-06-15 15:00',
        );

        // Sanity check : le créneau N'EST PAS réservé.
        self::assertFalse($creneau->isReserve());

        // Garde-fou : aucun envoi mail (return early sur isReserve() == false).
        $this->mailer->expects($this->never())->method('send');

        $this->service->notifierAuditeurCommentaireCreneau($creneau, 'commentaire bidon');
    }

    public function test_notifier_auditeur_commentaire_creneau_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        $creneau->setCommentaireAuditeur('Nouveau message');
        $commentaireAvant = 'Ancien';

        $this->mailer->method('send')
            ->willThrowException(new \RuntimeException('Panne SMTP simulée'));

        // Capture des 2 appels logger->error attendus :
        //   1) depuis envoyer()                                  : 'Envoi email échoué'
        //   2) depuis notifierAuditeurCommentaireCreneau()       : 'Echec envoi notification commentaire creneau'
        $errorCalls = [];
        $this->logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) use (&$errorCalls): void {
                $errorCalls[] = ['message' => $message, 'context' => $context];
            });

        // L'appel NE DOIT PAS lever d'exception malgré le throw du mailer.
        $this->service->notifierAuditeurCommentaireCreneau($creneau, $commentaireAvant);

        self::assertCount(2, $errorCalls);

        // 1er log : envoyer() RGPD-friendly (template, exception_class).
        self::assertSame('Envoi email échoué', $errorCalls[0]['message']);
        self::assertSame(
            'emails/reservation_modification_auditeur.html.twig',
            $errorCalls[0]['context']['template'],
        );
        self::assertSame(\RuntimeException::class, $errorCalls[0]['context']['exception_class']);

        // 2e log : contexte métier (type, creneau_id) + longueurs RGPD-friendly du commentaire.
        self::assertSame('Echec envoi notification commentaire creneau', $errorCalls[1]['message']);
        self::assertSame('auditeur_commentaire_creneau', $errorCalls[1]['context']['type']);
        self::assertSame(10, $errorCalls[1]['context']['creneau_id']);
        self::assertArrayHasKey('commentaire_avant_len', $errorCalls[1]['context']);
        self::assertArrayHasKey('commentaire_apres_len', $errorCalls[1]['context']);
        self::assertSame(strlen('Ancien'), $errorCalls[1]['context']['commentaire_avant_len']);
        self::assertSame(strlen('Nouveau message'), $errorCalls[1]['context']['commentaire_apres_len']);
        self::assertArrayHasKey('exception', $errorCalls[1]['context']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.5 — Suppression créneau
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_suppression_creneau_envoie_un_email_avec_bon_template(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        // Reproduit annulerReservationLiee() côté controller (set statut ANNULEE).
        $reservation->annuler('Créneau supprimé par le Personnel');

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierAuditeurSuppressionCreneau($creneau, $reservation);

        self::assertNotNull($emailCapture);

        // Destinataire = Auditeur.
        $to = $emailCapture->getTo();
        self::assertCount(1, $to);
        self::assertSame('xavier@test.local', $to[0]->getAddress());

        // Subject + template.
        self::assertStringContainsString('Votre créneau a été supprimé par le Personnel', $this->sujet($emailCapture));
        self::assertSame(
            'emails/creneau_suppression_auditeur.html.twig',
            $emailCapture->getHtmlTemplate(),
        );

        // Context (6 clés attendues par le template).
        $context = $emailCapture->getContext();
        self::assertSame('Xavier', $context['auditeur_prenom']);
        self::assertSame('Marie Dupont', $context['personnel_nom_complet']);
        self::assertSame('Service Commercial', $context['service_nom']);
        self::assertSame('https://test.local/creneaux-disponibles', $context['lien_creneaux_disponibles']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_auditeur_suppression_creneau_ne_fait_rien_si_reservation_non_annulee(): void
    {
        // Réservation créée mais PAS annulée → garde-fou refuse (statut === ACTIVE).
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();

        // Sanity check : la réservation est ACTIVE.
        self::assertSame(StatutReservation::ACTIVE, $reservation->getStatut());

        // Garde-fou : aucun envoi mail.
        $this->mailer->expects($this->never())->method('send');

        $this->service->notifierAuditeurSuppressionCreneau($creneau, $reservation);
    }

    public function test_notifier_auditeur_suppression_creneau_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        $reservation->annuler('Créneau supprimé par le Personnel');

        $this->mailer->method('send')
            ->willThrowException(new \RuntimeException('Panne SMTP simulée'));

        // Capture des 2 appels logger->error attendus :
        //   1) depuis envoyer()                                  : 'Envoi email échoué'
        //   2) depuis notifierAuditeurSuppressionCreneau()       : 'Echec envoi notification suppression creneau'
        $errorCalls = [];
        $this->logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) use (&$errorCalls): void {
                $errorCalls[] = ['message' => $message, 'context' => $context];
            });

        // L'appel NE DOIT PAS lever d'exception malgré le throw du mailer.
        $this->service->notifierAuditeurSuppressionCreneau($creneau, $reservation);

        self::assertCount(2, $errorCalls);

        // 1er log : envoyer() RGPD-friendly (template, exception_class).
        self::assertSame('Envoi email échoué', $errorCalls[0]['message']);
        self::assertSame(
            'emails/creneau_suppression_auditeur.html.twig',
            $errorCalls[0]['context']['template'],
        );
        self::assertSame(\RuntimeException::class, $errorCalls[0]['context']['exception_class']);

        // 2e log : contexte métier suppression (type, creneau_id) + clés présentes (reservation_id, auditeur_id, exception).
        self::assertSame('Echec envoi notification suppression creneau', $errorCalls[1]['message']);
        self::assertSame('auditeur_suppression_creneau', $errorCalls[1]['context']['type']);
        self::assertSame(10, $errorCalls[1]['context']['creneau_id']);
        self::assertArrayHasKey('reservation_id', $errorCalls[1]['context']);
        self::assertArrayHasKey('auditeur_id', $errorCalls[1]['context']);
        self::assertArrayHasKey('exception', $errorCalls[1]['context']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.6 — Rappel J-1
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_rappel_envoie_un_email_avec_bon_template(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();

        $emailCapture = null;
        $this->mailer->expects($this->once())
            ->method('send')
            ->willReturnCallback(function (TemplatedEmail $email) use (&$emailCapture): void {
                $emailCapture = $email;
            });

        $this->service->notifierAuditeurRappel($reservation);

        self::assertNotNull($emailCapture);

        // Destinataire = Auditeur.
        $to = $emailCapture->getTo();
        self::assertCount(1, $to);
        self::assertSame('xavier@test.local', $to[0]->getAddress());

        // Subject + template.
        self::assertStringContainsString('Rappel : votre rendez-vous le', $this->sujet($emailCapture));
        self::assertSame(
            'emails/reservation_rappel_auditeur.html.twig',
            $emailCapture->getHtmlTemplate(),
        );

        // Context (6 clés attendues par le template).
        $context = $emailCapture->getContext();
        self::assertSame('Xavier', $context['auditeur_prenom']);
        self::assertSame('Marie Dupont', $context['personnel_nom_complet']);
        self::assertSame('Service Commercial', $context['service_nom']);
        self::assertSame('https://test.local/mes-reservations', $context['lien_mes_reservations']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifier_auditeur_rappel_ne_fait_rien_si_reservation_non_active(): void
    {
        // Réservation annulée → garde-fou refuse (statut !== ACTIVE).
        $reservation = $this->creerReservationComplete();
        $reservation->annuler('Test annulation');

        // Sanity check : la réservation n'est PAS ACTIVE.
        self::assertSame(StatutReservation::ANNULEE, $reservation->getStatut());

        // Garde-fou : aucun envoi mail.
        $this->mailer->expects($this->never())->method('send');

        $this->service->notifierAuditeurRappel($reservation);
    }

    public function test_notifier_auditeur_rappel_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationComplete();

        $this->mailer->method('send')
            ->willThrowException(new \RuntimeException('Panne SMTP simulée'));

        // Capture des 2 appels logger->error attendus :
        //   1) depuis envoyer()                          : 'Envoi email échoué'
        //   2) depuis notifierAuditeurRappel()           : 'Echec envoi rappel J-1'
        $errorCalls = [];
        $this->logger->method('error')
            ->willReturnCallback(function (string $message, array $context = []) use (&$errorCalls): void {
                $errorCalls[] = ['message' => $message, 'context' => $context];
            });

        // L'appel NE DOIT PAS lever d'exception malgré le throw du mailer.
        $this->service->notifierAuditeurRappel($reservation);

        self::assertCount(2, $errorCalls);

        // 1er log : envoyer() RGPD-friendly (template, exception_class).
        self::assertSame('Envoi email échoué', $errorCalls[0]['message']);
        self::assertSame(
            'emails/reservation_rappel_auditeur.html.twig',
            $errorCalls[0]['context']['template'],
        );
        self::assertSame(\RuntimeException::class, $errorCalls[0]['context']['exception_class']);

        // 2e log : contexte métier rappel (type, creneau_id) + clés présentes (reservation_id, auditeur_id, exception).
        self::assertSame('Echec envoi rappel J-1', $errorCalls[1]['message']);
        self::assertSame('auditeur_rappel_j1', $errorCalls[1]['context']['type']);
        self::assertSame(10, $errorCalls[1]['context']['creneau_id']);
        self::assertArrayHasKey('reservation_id', $errorCalls[1]['context']);
        self::assertArrayHasKey('auditeur_id', $errorCalls[1]['context']);
        self::assertArrayHasKey('exception', $errorCalls[1]['context']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.8 — Préférences notifications (canal email, types confort)
    //
    // Invariant RGPD prouvé : préférence email OFF ⇒ aucun email envoyé MAIS la
    // notification in-app reste persistée (audit trail B2). Préférence ON ⇒
    // comportement antérieur (email + in-app). Base légale art. 6.1.b.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifier_auditeur_commentaire_creneau_pref_email_off_persiste_inapp_sans_email(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        $reservation->getUtilisateur()->setEmailModificationCommentaire(false);

        // Aucun email, mais la notification in-app est persistée (B2).
        $this->mailer->expects(self::never())->method('send');
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->notifierAuditeurCommentaireCreneau($creneau, 'Ancien commentaire');
    }

    public function test_notifier_auditeur_commentaire_creneau_pref_email_on_envoie_email_et_persiste(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau = $reservation->getCreneau();
        $reservation->getUtilisateur()->setEmailModificationCommentaire(true);

        $this->mailer->expects(self::once())->method('send');
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->notifierAuditeurCommentaireCreneau($creneau, 'Ancien commentaire');
    }

    public function test_notifier_auditeur_rappel_pref_email_off_persiste_inapp_sans_email(): void
    {
        $reservation = $this->creerReservationComplete();
        $reservation->getUtilisateur()->setEmailRappelJ1(false);

        $this->mailer->expects(self::never())->method('send');
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->notifierAuditeurRappel($reservation);
    }

    public function test_notifier_auditeur_rappel_pref_email_on_envoie_email_et_persiste(): void
    {
        $reservation = $this->creerReservationComplete();
        $reservation->getUtilisateur()->setEmailRappelJ1(true);

        $this->mailer->expects(self::once())->method('send');
        $this->entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(Notification::class));
        $this->entityManager->expects(self::once())->method('flush');

        $this->service->notifierAuditeurRappel($reservation);
    }

    // -------------------------------------------------------------------------
    // Helpers
    //
    // Anticipation refacto : si une 3e classe de test (ex: tests/Controller/
    // ou US-4.3 sur l'annulation) a besoin de fabriquer des Reservation
    // peuplées, extraire ces helpers dans tests/Fixture/ReservationFactory.php
    // pour DRY. Pour l'instant 18 tests ici → on garde local.
    // -------------------------------------------------------------------------

    private function creerReservationComplete(?string $commentaire = null): Reservation
    {
        $service = $this->creerService('Service Commercial');
        $personnel = $this->creerUtilisateur(
            id: 1,
            role: RoleUtilisateur::PERSONNEL,
            prenom: 'Marie',
            nom: 'Dupont',
            email: 'marie@test.local',
            service: $service,
        );
        $auditeur = $this->creerUtilisateur(
            id: 2,
            role: RoleUtilisateur::AUDITEUR,
            prenom: 'Xavier',
            nom: 'Dijoux',
            email: 'xavier@test.local',
        );

        $typeRdv = $this->creerTypeRdv('Présentiel');
        $creneau = $this->creerCreneau(
            id: 10,
            personnel: $personnel,
            type: $typeRdv,
            debut: '2026-06-15 14:00',
            fin: '2026-06-15 15:00',
        );

        return $this->creerReservation(
            id: 42,
            creneau: $creneau,
            auditeur: $auditeur,
            commentaire: $commentaire,
        );
    }

    private function creerReservationAnnulee(?string $motif = null): Reservation
    {
        $reservation = $this->creerReservationComplete();
        $reservation->annuler($motif);

        return $reservation;
    }

    private function creerUtilisateur(
        int $id,
        RoleUtilisateur $role,
        string $prenom,
        string $nom,
        string $email,
        ?Service $service = null,
    ): Utilisateur {
        $u = new Utilisateur();
        $u->setRole($role);
        $u->setPrenom($prenom);
        $u->setNom($nom);
        $u->setEmail($email);
        $u->setEstActif(true);
        if ($service !== null) {
            $u->setService($service);
        }

        $p = new \ReflectionProperty(Utilisateur::class, 'id');
        $p->setValue($u, $id);

        return $u;
    }

    private function creerService(string $nom): Service
    {
        $s = new Service();
        $s->setNom($nom);
        $s->setEstActif(true);

        return $s;
    }

    /** Sujet de l'email, en garantissant qu'il est bien renseigné (getSubject() est nullable). */
    private function sujet(TemplatedEmail $email): string
    {
        $sujet = $email->getSubject();
        self::assertNotNull($sujet);

        return $sujet;
    }

    private function creerTypeRdv(string $libelle): TypeRdv
    {
        $t = new TypeRdv();
        $t->setCode('TEST'); // code fixe : contrainte unique non vérifiée hors BDD
        $t->setLibelle($libelle);
        $t->setCouleurHex('#1A3E6F');

        return $t;
    }

    private function creerCreneau(
        int $id,
        Utilisateur $personnel,
        TypeRdv $type,
        string $debut,
        string $fin,
    ): Creneau {
        $c = new Creneau();
        $c->setUtilisateur($personnel);
        $c->setTypeRdv($type);
        $c->setDateDebut(new \DateTimeImmutable($debut));
        $c->setDateFin(new \DateTimeImmutable($fin));

        $p = new \ReflectionProperty(Creneau::class, 'id');
        $p->setValue($c, $id);

        return $c;
    }

    private function creerReservation(
        int $id,
        Creneau $creneau,
        Utilisateur $auditeur,
        ?string $commentaire = null,
    ): Reservation {
        $r = new Reservation();
        $r->setCreneau($creneau);
        $r->setUtilisateur($auditeur);
        $r->setCommentaireAuditeur($commentaire);

        $p = new \ReflectionProperty(Reservation::class, 'id');
        $p->setValue($r, $id);

        // DT-1 : injecte la Reservation dans la Collection OneToMany via Reflection.
        // Doctrine UnitOfWork synchronise cela automatiquement après flush en runtime,
        // mais en environnement de tests pur (sans persistance BDD), on doit le faire
        // manuellement pour que $creneau->getReservationActive() / isReserve() retournent
        // la Reservation injectée. La propriété est `reservations` (Collection<Reservation>).
        $refReservation = new \ReflectionProperty(Creneau::class, 'reservations');
        $refReservation->setValue($creneau, new ArrayCollection([$r]));

        return $r;
    }
}
