<?php

declare(strict_types=1);

namespace App\Tests\Controller\Auditeur;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Repository\TypeRdvRepository;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Test fonctionnel de la protection anti open-redirect sur la redirection de
 * retour apres annulation d'une reservation (ReservationAnnulationController).
 *
 * La methode redirigerVersListe() ne doit jamais renvoyer vers l'hote porte par
 * le Referer : elle n'en conserve que le chemin (et sa chaine de requete), et
 * seulement si ce chemin commence par la base de la route /mes-reservations.
 * Trois cas sont couverts : Referer absent, Referer interne valide avec query
 * string, Referer forge sur un hote externe dont le chemin imite la liste.
 *
 * Donnees 100 % jetables (emails marqueurs `…@redirect-test.local`), creees en
 * setUp et supprimees en tearDown : aucune mutation des fixtures partagees.
 */
final class ReservationAnnulationRedirectionControllerTest extends WebTestCase
{
    /** Suffixe d'email des comptes crees par les tests (nettoyes en tearDown). */
    private const string MARQUEUR_TEST = '@redirect-test.local';

    private KernelBrowser $client;

    private string $emailAuditeur;
    private int $idCreneau;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $typeRdv = static::getContainer()->get(TypeRdvRepository::class)->findActifs()[0] ?? null;
        self::assertInstanceOf(TypeRdv::class, $typeRdv, 'Aucun type de RDV actif en fixtures.');

        $personnel = $this->creerUtilisateur(RoleUtilisateur::PERSONNEL);
        $auditeur = $this->creerUtilisateur(RoleUtilisateur::AUDITEUR);
        $this->emailAuditeur = $auditeur->getEmail();

        $creneau = $this->creerCreneau($personnel, $typeRdv);
        $entityManager->flush();

        $this->idCreneau = (int) $creneau->getId();
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $marqueur = '%' . self::MARQUEUR_TEST;

        // Ordre FK : notifications (destinataire), puis reservations, puis creneaux,
        // puis utilisateurs. L'annulation notifie auditeur et personnel (rows a purger).
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
    // Cas 1 — Referer absent : retour sur la liste nue
    // ---------------------------------------------------------------------

    public function test_annulation_sans_referer_redirige_vers_la_liste(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailAuditeur);
        $idReservation = $this->creerReservationActive($this->idCreneau, $auditeur);

        $this->client->loginUser($auditeur);
        $this->annulerAvecReferer($idReservation, null);

        self::assertResponseRedirects('/mes-reservations');
    }

    // ---------------------------------------------------------------------
    // Cas 2 — Referer interne valide : la query string (filtre + page) est conservee
    // ---------------------------------------------------------------------

    public function test_annulation_avec_referer_interne_conserve_la_query_string(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailAuditeur);
        $idReservation = $this->creerReservationActive($this->idCreneau, $auditeur);

        $this->client->loginUser($auditeur);
        $this->annulerAvecReferer(
            $idReservation,
            'http://localhost/mes-reservations?filtre=annulees&page=2',
        );

        self::assertResponseRedirects('/mes-reservations?filtre=annulees&page=2');
    }

    // ---------------------------------------------------------------------
    // Cas 3 — Referer forge sur un hote externe : le retour reste sur le domaine local
    // ---------------------------------------------------------------------

    public function test_annulation_avec_referer_hote_externe_reste_sur_le_domaine(): void
    {
        $auditeur = $this->utilisateurEnBase($this->emailAuditeur);
        $idReservation = $this->creerReservationActive($this->idCreneau, $auditeur);

        $this->client->loginUser($auditeur);
        $this->annulerAvecReferer(
            $idReservation,
            'https://domaine-tiers.example/mes-reservations?filtre=annulees',
        );

        // Seul le chemin local est conserve : jamais l'hote tiers.
        self::assertResponseRedirects('/mes-reservations?filtre=annulees');
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringNotContainsString('domaine-tiers.example', $location);
    }

    // ---------------------------------------------------------------------
    // Action HTTP : annulation avec un Referer maitrise (jeton CSRF via le crawler)
    // ---------------------------------------------------------------------

    private function annulerAvecReferer(int $idReservation, ?string $referer): void
    {
        $crawler = $this->client->request('GET', '/reservation/' . $idReservation);
        $jeton = (string) $crawler->filter('input[name="annulation_reservation[_token]"]')->attr('value');

        $serveur = $referer !== null ? ['HTTP_REFERER' => $referer] : [];
        $this->client->request(
            'POST',
            '/reservation/' . $idReservation . '/annuler',
            ['annulation_reservation' => ['motifAnnulation' => '', '_token' => $jeton]],
            [],
            $serveur,
        );
    }

    // ---------------------------------------------------------------------
    // Fabriques de donnees jetables
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

    private function creerCreneau(Utilisateur $personnel, TypeRdv $typeRdv): Creneau
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $debut = (new \DateTimeImmutable('+7 days'))->setTime(9, 0);
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

    private function utilisateurEnBase(string $email): Utilisateur
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $utilisateur = static::getContainer()->get(UtilisateurRepository::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(Utilisateur::class, $utilisateur);

        return $utilisateur;
    }
}
