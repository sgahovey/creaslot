<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

/**
 * Preuve anti-N+1 de CollegueService (DT-10) : le nombre de requêtes SQL de la page
 * « Collègues » NE dépend PAS du nombre de collègues. On compare le compteur de
 * requêtes (data collector Doctrine) entre deux rendus de la même page, le second
 * comptant davantage de collègues : avec l'ancien code (~3N+1) les deux compteurs
 * divergeraient ; avec l'optimisation par lot ils sont égaux.
 *
 * Comparatif (et non valeur absolue) : une requête de préchauffe amorce le firewall
 * pour que les deux mesures partagent le même coût d'authentification ; seul le
 * nombre de collègues diffère ensuite.
 *
 * Données jetables (marqueur `@dt10-querycount.local`), supprimées en tearDown.
 * WebTestCase ne rollback pas : aucune désactivation globale (qui polluerait la base
 * partagée), uniquement des ajouts entre les deux mesures.
 */
final class CollegueServiceQueryCountTest extends WebTestCase
{
    private const string MARQUEUR_EMAIL = '@dt10-querycount.local';
    private const string MARQUEUR_TYPE = 'DT10QC-';

    private KernelBrowser $client;
    private int $typeRdvId;
    private int $auditeurId;
    private int $compteur = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $entityManager = $this->em();

        $typeRdv = (new TypeRdv())
            ->setCode(self::MARQUEUR_TYPE . uniqid())
            ->setLibelle('Présentiel')
            ->setCouleurHex('#123456');
        $entityManager->persist($typeRdv);

        $auditeur = $this->creerPersonne($entityManager, RoleUtilisateur::AUDITEUR);
        $entityManager->flush();

        $this->typeRdvId = (int) $typeRdv->getId();
        $this->auditeurId = (int) $auditeur->getId();
    }

    protected function tearDown(): void
    {
        $entityManager = $this->em();
        $emailLike = '%' . self::MARQUEUR_EMAIL;
        $entityManager->createQuery(
            'DELETE App\Entity\Reservation r WHERE r.utilisateur IN (SELECT u.id FROM App\Entity\Utilisateur u WHERE u.email LIKE :m)',
        )->setParameter('m', $emailLike)->execute();
        $entityManager->createQuery(
            'DELETE App\Entity\Creneau c WHERE c.utilisateur IN (SELECT u2.id FROM App\Entity\Utilisateur u2 WHERE u2.email LIKE :m)',
        )->setParameter('m', $emailLike)->execute();
        $entityManager->createQuery('DELETE App\Entity\Utilisateur u WHERE u.email LIKE :m')
            ->setParameter('m', $emailLike)->execute();
        $entityManager->createQuery('DELETE App\Entity\TypeRdv t WHERE t.code LIKE :mt')
            ->setParameter('mt', self::MARQUEUR_TYPE . '%')->execute();

        parent::tearDown();
    }

    public function test_le_nombre_de_requetes_ne_depend_pas_du_nombre_de_collegues(): void
    {
        // Kernel conservé entre les requêtes ; le compteur Doctrine est cumulatif sur
        // le process, on le remet à zéro avant chaque mesure (cf. compterRequetes…).
        $this->client->disableReboot();

        $entityManager = $this->em();
        $current = $this->creerPersonne($entityManager, RoleUtilisateur::PERSONNEL);
        $this->creerColleguesVisibles($entityManager, 2);
        $entityManager->flush();
        $this->client->loginUser($current);

        // Préchauffe (non mesurée) : amorce le firewall pour égaliser le coût d'auth.
        $this->client->request('GET', '/collegues');

        $requetesAvecDeuxCollegues = $this->compterRequetesPageCollegues();

        // Chaque requête réinitialise l'EM (kernel.reset) : on en récupère un frais.
        $this->creerColleguesVisibles($this->em(), 3);
        $this->em()->flush();

        $requetesAvecCinqCollegues = $this->compterRequetesPageCollegues();

        self::assertSame(
            $requetesAvecDeuxCollegues,
            $requetesAvecCinqCollegues,
            'Le nombre de requêtes doit rester constant quel que soit le nombre de collègues (anti-N+1).',
        );
    }

    private function compterRequetesPageCollegues(): int
    {
        $detenteur = static::getContainer()->get('doctrine.debug_data_holder');
        $detenteur->reset();

        $this->client->enableProfiler();
        $this->client->request('GET', '/collegues');
        self::assertResponseIsSuccessful();

        $profil = $this->client->getProfile();
        self::assertInstanceOf(Profile::class, $profil, 'Profiler indisponible : impossible de compter les requêtes.');

        $collecteur = $profil->getCollector('db');
        self::assertInstanceOf(\Symfony\Bridge\Doctrine\DataCollector\DoctrineDataCollector::class, $collecteur);

        return $collecteur->getQueryCount();
    }

    private function creerColleguesVisibles(EntityManagerInterface $entityManager, int $nombre): void
    {
        $typeRdv = $entityManager->find(TypeRdv::class, $this->typeRdvId);
        $auditeur = $entityManager->find(Utilisateur::class, $this->auditeurId);
        self::assertInstanceOf(TypeRdv::class, $typeRdv);
        self::assertInstanceOf(Utilisateur::class, $auditeur);

        for ($i = 0; $i < $nombre; ++$i) {
            $collegue = $this->creerPersonne($entityManager, RoleUtilisateur::PERSONNEL);
            $debut = (new \DateTimeImmutable())->modify('+1 day');
            $creneau = (new Creneau())
                ->setUtilisateur($collegue)
                ->setTypeRdv($typeRdv)
                ->setDateDebut($debut)
                ->setDateFin($debut->modify('+1 hour'))
                ->setEstActif(true);
            $entityManager->persist($creneau);

            $reservation = (new Reservation())
                ->setCreneau($creneau)
                ->setUtilisateur($auditeur)
                ->setStatut(StatutReservation::ACTIVE);
            $entityManager->persist($reservation);
        }
    }

    private function creerPersonne(EntityManagerInterface $entityManager, RoleUtilisateur $role): Utilisateur
    {
        $personne = (new Utilisateur())
            ->setEmail(sprintf('dt10-%d-%s%s', ++$this->compteur, uniqid(), self::MARQUEUR_EMAIL))
            ->setNom('Nom')
            ->setPrenom('Prenom')
            ->setRole($role)
            ->setEstActif(true);
        $personne->setMotDePasseHash('hash-test');
        $entityManager->persist($personne);

        return $personne;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
