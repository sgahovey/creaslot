<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auditeur;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Repository\NotificationRepository;
use App\Repository\TypeRdvRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Test fonctionnel du parcours de réservation côté Auditeur (US-8.2) :
 * lister les créneaux disponibles → réserver → confirmation (email + notification
 * in-app) → annuler → re-réserver, plus les refus (accès, créneau indisponible,
 * annulation par un tiers).
 *
 * Données 100 % jetables (emails marqueurs `…@reservation-test.local`), créées en
 * setUp et supprimées en tearDown (ordre FK : Notification → Reservation → Creneau
 * → Utilisateur) : aucune mutation des fixtures partagées. Chaque test arrange sa
 * propre précondition. Formulaires soumis via le crawler (jetons CSRF inclus).
 *
 * Transport mail en test : async → les emails sont mis en file (assertQueuedEmailCount).
 */
final class ReservationParcoursControllerTest extends WebTestCase
{
    use MailerAssertionsTrait;

    /** Suffixe d'email des comptes créés par les tests (nettoyés en tearDown). */
    private const string MARQUEUR_TEST = '@reservation-test.local';

    private KernelBrowser $client;

    private string $emailPrincipal;
    private string $emailAutre;
    private string $emailPersonnel;
    private int $idCreneauDispo;
    private int $idCreneauPasse;
    private int $idCreneauReserve;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $typeRdv = static::getContainer()->get(TypeRdvRepository::class)->findActifs()[0] ?? null;
        self::assertInstanceOf(TypeRdv::class, $typeRdv, 'Aucun type de RDV actif en fixtures.');

        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $auditeurPrincipal = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $auditeurAutre = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $this->emailPersonnel = $personnel->getEmail();
        $this->emailPrincipal = $auditeurPrincipal->getEmail();
        $this->emailAutre = $auditeurAutre->getEmail();

        $creneauDispo = $this->creerCreneau($personnel, $typeRdv, '+7 days');
        $creneauPasse = $this->creerCreneau($personnel, $typeRdv, '-3 days');
        $creneauReserve = $this->creerCreneau($personnel, $typeRdv, '+8 days');

        // C_reserve est déjà occupé par l'AUTRE auditeur (réservation ACTIVE).
        $reservationExistante = (new Reservation())
            ->setCreneau($creneauReserve)
            ->setUtilisateur($auditeurAutre)
            ->setStatut(StatutReservation::ACTIVE);
        $entityManager->persist($reservationExistante);

        $entityManager->flush();

        $this->idCreneauDispo = (int) $creneauDispo->getId();
        $this->idCreneauPasse = (int) $creneauPasse->getId();
        $this->idCreneauReserve = (int) $creneauReserve->getId();
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $marqueur = '%' . self::MARQUEUR_TEST;

        // Ordre FK : notifications (destinataire), puis réservations, puis créneaux,
        // puis utilisateurs. La table n'est peuplée que par les tests pour ce marqueur.
        $entityManager->createQuery(
            'DELETE FROM App\Entity\Notification n WHERE n.destinataire IN '
            . '(SELECT u.id FROM App\Entity\Utilisateur u WHERE u.email LIKE :m)',
        )->setParameter('m', $marqueur)->execute();
        $entityManager->createQuery(
            'DELETE FROM App\Entity\Reservation r WHERE r.utilisateur IN '
            . '(SELECT u.id FROM App\Entity\Utilisateur u WHERE u.email LIKE :m)',
        )->setParameter('m', $marqueur)->execute();
        $entityManager->createQuery(
            'DELETE FROM App\Entity\Creneau c WHERE c.utilisateur IN '
            . '(SELECT u.id FROM App\Entity\Utilisateur u WHERE u.email LIKE :m)',
        )->setParameter('m', $marqueur)->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\Utilisateur u WHERE u.email LIKE :m')
            ->setParameter('m', $marqueur)
            ->execute();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // A1 — Lister les créneaux disponibles
    // ---------------------------------------------------------------------

    public function test_auditeur_voit_le_creneau_disponible_dans_la_liste(): void
    {
        $this->client->loginUser($this->utilisateurEnBase($this->emailPrincipal));

        $this->client->request('GET', '/creneaux-disponibles');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            '/creneau/' . $this->idCreneauDispo . '/reserver',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    // ---------------------------------------------------------------------
    // A2 + A3 — Réserver (succès) + effets de bord (emails + notification in-app)
    // ---------------------------------------------------------------------

    public function test_reservation_reussie_cree_la_reservation_et_declenche_les_notifications(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailPrincipal);
        $this->client->loginUser($auditeur);

        $this->reserver($this->idCreneauDispo, 'Question sur mon dossier.');

        self::assertResponseRedirects('/mes-reservations');
        self::assertSame(1, $this->compterReservationsActives($this->idCreneauDispo));
        // Effets de bord : 2 emails mis en file (auditeur + personnel) et 1 notification
        // in-app de confirmation persistée pour l'auditeur.
        self::assertQueuedEmailCount(2);
        self::assertSame(1, $this->notificationsNonLues($this->emailPrincipal));
    }

    // ---------------------------------------------------------------------
    // A4 — Annuler sa réservation
    // ---------------------------------------------------------------------

    public function test_auditeur_annule_sa_reservation(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailPrincipal);
        $idReservation = $this->creerReservationActive($this->idCreneauDispo, $auditeur);

        $this->client->loginUser($auditeur);
        $this->annuler($idReservation, 'Empêchement de dernière minute.');

        self::assertResponseRedirects();
        $reservation = $this->reservationEnBase($idReservation);
        self::assertSame(StatutReservation::ANNULEE, $reservation->getStatut());
        self::assertNotNull($reservation->getDateAnnulation());
        self::assertSame('Empêchement de dernière minute.', $reservation->getMotifAnnulation());
    }

    // ---------------------------------------------------------------------
    // A5 — Re-réserver après annulation (le créneau redevient disponible)
    // ---------------------------------------------------------------------

    public function test_creneau_re_reservable_apres_annulation(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailPrincipal);
        $this->client->loginUser($auditeur);

        // Réserver → annuler → re-réserver le MÊME créneau.
        $this->reserver($this->idCreneauDispo, null);
        $idReservation = $this->derniereReservationActive($this->idCreneauDispo);
        $this->annuler($idReservation, 'Changement de programme.');
        $this->reserver($this->idCreneauDispo, null);

        self::assertResponseRedirects('/mes-reservations');
        // Une réservation ACTIVE (la nouvelle) et une ANNULEE (l'ancienne).
        self::assertSame(1, $this->compterReservationsActives($this->idCreneauDispo));
        self::assertSame(2, $this->compterReservationsTotales($this->idCreneauDispo));
    }

    // ---------------------------------------------------------------------
    // A6 + A7 — Contrôle d'accès
    // ---------------------------------------------------------------------

    public function test_personnel_ne_peut_pas_annuler_une_reservation(): void
    {
        // Note : la hiérarchie de rôles (ROLE_PERSONNEL ⊃ ROLE_AUDITEUR) fait que
        // tout authentifié peut lister/réserver ; le vrai garde-fou d'autorisation
        // côté annulation est le Voter RESERVATION_CANCEL, qui refuse le Personnel.
        $auditeur = $this->utilisateurEnBase($this->emailPrincipal);
        $idReservation = $this->creerReservationActive($this->idCreneauDispo, $auditeur);

        $this->client->loginUser($this->utilisateurEnBase($this->emailPersonnel));
        $this->client->request('POST', '/reservation/' . $idReservation . '/annuler');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(StatutReservation::ACTIVE, $this->reservationEnBase($idReservation)->getStatut());
    }

    public function test_anonyme_est_redirige_vers_la_connexion(): void
    {
        $this->client->request('GET', '/creneaux-disponibles');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    // ---------------------------------------------------------------------
    // A8 — Créneau déjà réservé : refus + invariant « ≤ 1 réservation ACTIVE »
    // ---------------------------------------------------------------------

    public function test_creneau_deja_reserve_est_refuse_sans_sur_reservation(): void
    {
        $this->client->loginUser($this->utilisateurEnBase($this->emailPrincipal));

        // La garde refusSiNonDisponible (isReserve) court-circuite avant tout traitement.
        $this->client->request('POST', '/creneau/' . $this->idCreneauReserve . '/reserver');

        self::assertResponseRedirects('/creneaux-disponibles');
        $session = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertStringContainsString('déjà', implode(' ', $session->getFlashBag()->peek('error')));
        // Toujours exactement la réservation initiale : pas de sur-réservation.
        self::assertSame(1, $this->compterReservationsActives($this->idCreneauReserve));
    }

    // ---------------------------------------------------------------------
    // A9 — Annulation par un autre auditeur : refusée par le Voter
    // ---------------------------------------------------------------------

    public function test_un_autre_auditeur_ne_peut_pas_annuler_la_reservation(): void
    {
        $auditeurPrincipal = $this->utilisateurEnBase($this->emailPrincipal);
        $idReservation = $this->creerReservationActive($this->idCreneauDispo, $auditeurPrincipal);

        // L'autre auditeur tente d'annuler : le Voter RESERVATION_CANCEL s'exécute
        // AVANT le formulaire → 403 (aucun jeton CSRF nécessaire pour le prouver).
        $this->client->loginUser($this->utilisateurEnBase($this->emailAutre));
        $this->client->request('POST', '/reservation/' . $idReservation . '/annuler');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(StatutReservation::ACTIVE, $this->reservationEnBase($idReservation)->getStatut());
    }

    // ---------------------------------------------------------------------
    // A10 — Créneau passé : refusé
    // ---------------------------------------------------------------------

    public function test_creneau_passe_est_refuse(): void
    {
        $this->client->loginUser($this->utilisateurEnBase($this->emailPrincipal));

        $this->client->request('POST', '/creneau/' . $this->idCreneauPasse . '/reserver');

        self::assertResponseRedirects('/creneaux-disponibles');
        self::assertSame(0, $this->compterReservationsActives($this->idCreneauPasse));
    }

    // ---------------------------------------------------------------------
    // Actions HTTP (via le crawler → jetons CSRF)
    // ---------------------------------------------------------------------

    private function reserver(int $idCreneau, ?string $commentaire): void
    {
        $this->client->request('GET', '/creneau/' . $idCreneau . '/reserver');
        $this->client->submitForm('Confirmer la réservation', [
            'reservation[commentaireAuditeur]' => $commentaire ?? '',
        ]);
    }

    private function annuler(int $idReservation, string $motif): void
    {
        $this->client->request('GET', '/reservation/' . $idReservation);
        $this->client->submitForm("Confirmer l'annulation", [
            'annulation_reservation[motifAnnulation]' => $motif,
        ]);
    }

    // ---------------------------------------------------------------------
    // Fabriques de données jetables
    // ---------------------------------------------------------------------

    private function creerUtilisateur(RoleUtilisateur $role): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $utilisateur = (new Utilisateur())
            ->setEmail(strtolower($role->name) . '-' . uniqid() . self::MARQUEUR_TEST)
            ->setPrenom('Test')
            ->setNom(ucfirst(strtolower($role->name)))
            ->setRole($role)
            ->setEstActif(true)
            ->setMotDePasseHash('placeholder-not-real');

        $entityManager->persist($utilisateur);

        return $utilisateur;
    }

    private function creerCreneau(Utilisateur $personnel, TypeRdv $typeRdv, string $offset): Creneau
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $debut = (new \DateTimeImmutable($offset))->setTime(9, 0);
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($debut)
            ->setDateFin($debut->modify('+1 hour'))
            ->setEstActif(true);

        $entityManager->persist($creneau);

        return $creneau;
    }

    private function creerReservationActive(int $idCreneau, Utilisateur $auditeur): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $creneau = $entityManager->find(Creneau::class, $idCreneau);
        self::assertInstanceOf(Creneau::class, $creneau);

        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut(StatutReservation::ACTIVE);

        $entityManager->persist($reservation);
        $entityManager->flush();

        return (int) $reservation->getId();
    }

    // ---------------------------------------------------------------------
    // Accès BDD (identity map vidée pour relire l'état réel)
    // ---------------------------------------------------------------------

    private function utilisateurEnBase(string $email): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }

    private function reservationEnBase(int $id): Reservation
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $reservation = $entityManager->getRepository(Reservation::class)->find($id);
        self::assertInstanceOf(Reservation::class, $reservation);

        return $reservation;
    }

    private function compterReservationsActives(int $idCreneau): int
    {
        return $this->compterReservations($idCreneau, StatutReservation::ACTIVE);
    }

    private function compterReservationsTotales(int $idCreneau): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return (int) $entityManager->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\Reservation r WHERE r.creneau = :creneau',
        )->setParameter('creneau', $idCreneau)->getSingleScalarResult();
    }

    private function compterReservations(int $idCreneau, StatutReservation $statut): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return (int) $entityManager->createQuery(
            'SELECT COUNT(r.id) FROM App\Entity\Reservation r WHERE r.creneau = :creneau AND r.statut = :statut',
        )->setParameter('creneau', $idCreneau)->setParameter('statut', $statut)->getSingleScalarResult();
    }

    private function derniereReservationActive(int $idCreneau): int
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        return (int) $entityManager->createQuery(
            'SELECT r.id FROM App\Entity\Reservation r '
            . 'WHERE r.creneau = :creneau AND r.statut = :statut ORDER BY r.id DESC',
        )->setParameter('creneau', $idCreneau)
            ->setParameter('statut', StatutReservation::ACTIVE)
            ->setMaxResults(1)
            ->getSingleScalarResult();
    }

    private function notificationsNonLues(string $email): int
    {
        return static::getContainer()->get(NotificationRepository::class)
            ->countNonLues($this->utilisateurEnBase($email));
    }
}
