<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Service\NotificationService;
use DateTimeImmutable;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Tests d'intégration légers du NotificationService.
 *
 * Couverture actuelle : 12 tests (4 US-4.2 confirmation + 5 US-4.3 annulation
 * + 3 US-4.4 commentaire créneau) sur notifier{Auditeur,Personnel}{,Annulation}
 * Reservation() / notifierAuditeurCommentaireCreneau() et la politique
 * Option B (capture exceptions).
 *
 * Dette technique tests identifiée — la branche if ($redirige) de envoyer()
 * (US-4.1) n'est pas couverte unitairement. Validation actuelle uniquement
 * visuelle via app:email:test. À couvrir dans une US ultérieure dédiée à
 * la consolidation des tests US-4.1.
 */
final class NotificationServiceTest extends TestCase
{
    private MailerInterface&MockObject $mailer;

    private UrlGeneratorInterface&MockObject $urlGenerator;

    private LoggerInterface&MockObject $logger;

    private NotificationService $service;

    protected function setUp(): void
    {
        $this->mailer       = $this->createMock(MailerInterface::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->logger       = $this->createMock(LoggerInterface::class);

        // URLs absolues retournées de façon déterministe pour les 2 routes utilisées.
        $this->urlGenerator->method('generate')
            ->willReturnMap([
                ['app_mes_reservations', [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://test.local/mes-reservations'],
                ['app_creneau_agenda',   [], UrlGeneratorInterface::ABSOLUTE_URL, 'https://test.local/creneau/agenda'],
            ]);

        $this->service = new NotificationService(
            mailer:         $this->mailer,
            urlGenerator:   $this->urlGenerator,
            logger:         $this->logger,
            expediteur:     'noreply@test.local',
            replyTo:        'contact@test.local',
            redirectionDev: null, // pas de redirection en tests : sujet et destinataire non modifiés
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Tests US-4.2 — Confirmation de réservation
    // ─────────────────────────────────────────────────────────────────────────

    public function test_notifierAuditeurReservation_envoie_un_email_avec_bon_template(): void
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
        self::assertStringContainsString('Rendez-vous confirmé', $emailCapture->getSubject());
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

    public function test_notifierPersonnelReservation_envoie_un_email_avec_bon_template(): void
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
        self::assertStringContainsString('Nouveau rendez-vous', $emailCapture->getSubject());
        self::assertSame('marie@test.local', $emailCapture->getTo()[0]->getAddress());

        $context = $emailCapture->getContext();
        self::assertSame('Marie', $context['personnel_prenom']);
        self::assertSame('Xavier Dijoux', $context['auditeur_nom_complet']);
        self::assertSame('Présentiel', $context['type_rdv_libelle']);
        self::assertSame('https://test.local/creneau/agenda', $context['lien_mon_agenda']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_debut']);
        self::assertInstanceOf(\DateTimeInterface::class, $context['creneau_fin']);
    }

    public function test_notifierAuditeurReservation_capture_les_exceptions_sans_propager(): void
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
    public function test_notifierPersonnelReservation_passe_auditeur_categorie_a_null(): void
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

    public function test_notifierAuditeurAnnulationReservation_envoie_un_email_avec_bon_template(): void
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
        self::assertStringContainsString('Votre rendez-vous a été annulé', $emailCapture->getSubject());
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

    public function test_notifierPersonnelAnnulationReservation_envoie_un_email_avec_bon_template(): void
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
        self::assertStringContainsString('Annulation par Xavier Dijoux', $emailCapture->getSubject());
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

    public function test_notifierAuditeurAnnulationReservation_capture_les_exceptions_sans_propager(): void
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
    public function test_notifierPersonnelAnnulationReservation_passe_auditeur_categorie_a_null(): void
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
    public function test_notifierAuditeurAnnulationReservation_affiche_motif_si_renseigne_et_passe_null_sinon(): void
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

    public function test_notifierAuditeurCommentaireCreneau_envoie_un_email_avec_bon_template_et_commentaire(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau     = $reservation->getCreneau();
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
        self::assertStringContainsString('Mise à jour de votre rendez-vous', $emailCapture->getSubject());
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

    public function test_notifierAuditeurCommentaireCreneau_ne_fait_rien_si_creneau_non_reserve(): void
    {
        // Créneau standalone (pas de Reservation associée) → isReserve() = false.
        $personnel = $this->creerUtilisateur(
            id:     1,
            role:   RoleUtilisateur::PERSONNEL,
            prenom: 'Marie',
            nom:    'Dupont',
            email:  'marie@test.local',
        );
        $creneau   = $this->creerCreneau(
            id:        10,
            personnel: $personnel,
            type:      $this->creerTypeRdv('Présentiel'),
            debut:     '2026-06-15 14:00',
            fin:       '2026-06-15 15:00',
        );

        // Sanity check : le créneau N'EST PAS réservé.
        self::assertFalse($creneau->isReserve());

        // Garde-fou : aucun envoi mail (return early sur isReserve() == false).
        $this->mailer->expects($this->never())->method('send');

        $this->service->notifierAuditeurCommentaireCreneau($creneau, 'commentaire bidon');
    }

    public function test_notifierAuditeurCommentaireCreneau_capture_les_exceptions_sans_propager(): void
    {
        $reservation = $this->creerReservationComplete();
        $creneau     = $reservation->getCreneau();
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

    // -------------------------------------------------------------------------
    // Helpers
    //
    // Anticipation refacto : si une 3e classe de test (ex: tests/Controller/
    // ou US-4.3 sur l'annulation) a besoin de fabriquer des Reservation
    // peuplées, extraire ces helpers dans tests/Fixture/ReservationFactory.php
    // pour DRY. Pour l'instant 12 tests ici → on garde local.
    // -------------------------------------------------------------------------

    private function creerReservationComplete(?string $commentaire = null): Reservation
    {
        $service   = $this->creerService('Service Commercial');
        $personnel = $this->creerUtilisateur(
            id:      1,
            role:    RoleUtilisateur::PERSONNEL,
            prenom:  'Marie',
            nom:     'Dupont',
            email:   'marie@test.local',
            service: $service,
        );
        $auditeur = $this->creerUtilisateur(
            id:     2,
            role:   RoleUtilisateur::AUDITEUR,
            prenom: 'Xavier',
            nom:    'Dijoux',
            email:  'xavier@test.local',
        );

        $typeRdv = $this->creerTypeRdv('Présentiel');
        $creneau = $this->creerCreneau(
            id:        10,
            personnel: $personnel,
            type:      $typeRdv,
            debut:     '2026-06-15 14:00',
            fin:       '2026-06-15 15:00',
        );

        return $this->creerReservation(
            id:          42,
            creneau:     $creneau,
            auditeur:    $auditeur,
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
        $c->setDateDebut(new DateTimeImmutable($debut));
        $c->setDateFin(new DateTimeImmutable($fin));

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

        // US-4.4 : Lier le côté inverse de la relation OneToOne Creneau↔Reservation.
        // Doctrine UnitOfWork synchronise cela automatiquement après flush en runtime,
        // mais en environnement de tests pur (sans persistance BDD), on doit le faire
        // manuellement pour que $creneau->isReserve() retourne true.
        $refReservation = new \ReflectionProperty(Creneau::class, 'reservation');
        $refReservation->setValue($creneau, $r);

        return $r;
    }
}
