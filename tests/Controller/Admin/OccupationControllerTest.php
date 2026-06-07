<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Creneau;
use App\Entity\Reservation;
use App\Entity\Service;
use App\Entity\TypeRdv;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\StatutReservation;
use App\Repository\UtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Test fonctionnel de la vue globale occupé/libre du Super-admin (US-5.7).
 *
 * Couvre la sécurité (accès inter-rôles), le rendu (calendrier + table RGAA +
 * filtres + légende), le filtre serveur, et la minimisation RGPD de l'endpoint
 * JSON (jamais d'auditeur). Le jeu de données contrôlé est nettoyé en tearDown
 * (suppression par id, ordre FK-safe).
 */
final class OccupationControllerTest extends WebTestCase
{
    private const EMAIL_SUPER_ADMIN = 'creaslotdemo+admin@gmail.com';
    private const EMAIL_PERSONNEL = 'creaslotdemo+marie@gmail.com';
    private const EMAIL_AUDITEUR = 'creaslotdemo+xavier@gmail.com';

    private KernelBrowser $client;
    private UtilisateurRepository $utilisateurRepository;

    /** @var list<int> */
    private array $idsReservation = [];
    /** @var list<int> */
    private array $idsCreneau = [];
    /** @var list<int> */
    private array $idsUtilisateur = [];
    /** @var list<int> */
    private array $idsService = [];
    /** @var list<int> */
    private array $idsTypeRdv = [];

    protected function setUp(): void
    {
        $this->client = static::createClient(['environment' => 'test']);
        $this->utilisateurRepository = static::getContainer()->get(UtilisateurRepository::class);
    }

    protected function tearDown(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $this->supprimerParIds($entityManager, Reservation::class, $this->idsReservation);
        $this->supprimerParIds($entityManager, Creneau::class, $this->idsCreneau);
        $this->supprimerParIds($entityManager, Utilisateur::class, $this->idsUtilisateur);
        $this->supprimerParIds($entityManager, Service::class, $this->idsService);
        $this->supprimerParIds($entityManager, TypeRdv::class, $this->idsTypeRdv);

        parent::tearDown();
    }

    // ---------------------------------------------------------------------
    // Sécurité
    // ---------------------------------------------------------------------

    public function test_page_accessible_au_super_admin_affiche_calendrier_table_et_filtres(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation');

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('data-controller="occupation"', $contenu);
        self::assertStringContainsString("Calendrier d'occupation globale", $contenu);
        self::assertStringContainsString('<caption', $contenu);
        self::assertStringContainsString('scope="col"', $contenu);
        self::assertStringContainsString('name="service"', $contenu);
        self::assertStringContainsString('name="type"', $contenu);
        self::assertStringContainsString('Légende', $contenu);
    }

    public function test_page_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));
        $this->client->request('GET', '/admin/occupation');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_page_refuse_a_l_auditeur(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_AUDITEUR));
        $this->client->request('GET', '/admin/occupation');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function test_page_redirige_si_non_authentifie(): void
    {
        $this->client->request('GET', '/admin/occupation');

        self::assertResponseStatusCodeSame(Response::HTTP_FOUND);
        self::assertResponseRedirects();
    }

    // ---------------------------------------------------------------------
    // Filtre serveur (table RGAA)
    // ---------------------------------------------------------------------

    public function test_filtre_par_service_ne_liste_que_ce_service_dans_la_table(): void
    {
        $jeu = $this->creerJeuSemaineCourante();

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation?service=' . $jeu['serviceAId']);

        self::assertResponseIsSuccessful();
        $contenu = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString($jeu['personnelANom'], $contenu);
        self::assertStringNotContainsString($jeu['personnelBNom'], $contenu);
    }

    // ---------------------------------------------------------------------
    // Endpoint JSON
    // ---------------------------------------------------------------------

    public function test_endpoint_json_super_admin_expose_occupation_sans_auditeur(): void
    {
        $jeu = $this->creerJeuSemaineCourante();

        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation/evenements?start=' . urlencode($jeu['start']) . '&end=' . urlencode($jeu['end']));

        self::assertResponseIsSuccessful();
        $reponse = $this->client->getResponse();

        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));

        $corps = (string) $reponse->getContent();
        self::assertStringContainsString('personnelNom', $corps);
        self::assertStringContainsString('occupe', $corps);
        self::assertStringContainsString($jeu['personnelANom'], $corps);
        // RGPD : l'identité de l'auditeur réservant n'apparaît jamais.
        self::assertStringNotContainsString('auditeurNom', $corps);
    }

    public function test_endpoint_json_reproduit_la_vraie_requete_fullcalendar(): void
    {
        $jeu = $this->creerJeuSemaineCourante();

        // Forme FIDÈLE au navigateur : params via tableau (BrowserKit encode « + » en
        // « %2B », comme encodeURIComponent), avec les extraParams de FullCalendar
        // TOUJOURS présents mais VIDES (« service= », « type= »). C'est exactement ce
        // cas qui levait un 400 (getInt sur chaîne vide présente) ; il doit renvoyer 200.
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation/evenements', [
            'service' => '',
            'type'    => '',
            'start'   => $jeu['start'],
            'end'     => $jeu['end'],
        ]);

        self::assertResponseIsSuccessful();
        $corps = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($jeu['personnelANom'], $corps);
        self::assertStringNotContainsString('auditeurNom', $corps);
    }

    public function test_page_avec_filtres_vides_ne_leve_pas(): void
    {
        // Même bug latent côté PAGE : « Tous les services » soumet service= vide.
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation', [
            'service' => '',
            'type'    => '',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function test_endpoint_json_date_invalide_renvoie_400(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_SUPER_ADMIN));
        $this->client->request('GET', '/admin/occupation/evenements?start=pas-une-date&end=2026-06-06T00:00:00');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function test_endpoint_json_refuse_au_personnel(): void
    {
        $this->client->loginUser($this->recupererUtilisateur(self::EMAIL_PERSONNEL));
        $this->client->request('GET', '/admin/occupation/evenements?start=2026-06-01T00:00:00&end=2026-06-07T00:00:00');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ---------------------------------------------------------------------
    // Jeu de données
    // ---------------------------------------------------------------------

    /**
     * Crée deux services, chacun avec un Personnel et un créneau placé dans la
     * semaine courante (donc présent dans la table de référence) ; le créneau du
     * service A porte une réservation ACTIVE (occupé). Retourne les libellés et
     * la fenêtre ISO englobant la semaine.
     *
     * @return array{serviceAId: int, personnelANom: string, personnelBNom: string, start: string, end: string}
     */
    private function creerJeuSemaineCourante(): array
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $fuseau = new \DateTimeZone('Indian/Reunion');

        $lundi = (new \DateTimeImmutable('monday this week', $fuseau))->setTime(0, 0);
        $debutC = $lundi->setTime(10, 0);

        $type = $this->creerTypeRdv($entityManager);
        $serviceA = $this->creerService($entityManager);
        $serviceB = $this->creerService($entityManager);
        $suffixe = strtoupper(substr(uniqid(), -6));
        $prenomA = 'ZzOccA' . $suffixe;
        $prenomB = 'ZzOccB' . $suffixe;
        $personnelA = $this->creerUtilisateur($entityManager, RoleUtilisateur::PERSONNEL, $prenomA, $serviceA);
        $personnelB = $this->creerUtilisateur($entityManager, RoleUtilisateur::PERSONNEL, $prenomB, $serviceB);
        $auditeur = $this->creerUtilisateur($entityManager, RoleUtilisateur::AUDITEUR, 'ZzOccAud' . $suffixe, null);

        $creneauA = $this->creerCreneau($entityManager, $personnelA, $type, $debutC);
        $this->creerCreneau($entityManager, $personnelB, $type, $debutC);
        $this->creerReservation($entityManager, $creneauA, $auditeur);

        $entityManager->flush();

        $this->idsTypeRdv[] = (int) $type->getId();
        $this->idsService[] = (int) $serviceA->getId();
        $this->idsService[] = (int) $serviceB->getId();

        return [
            'serviceAId'    => (int) $serviceA->getId(),
            'personnelANom' => $personnelA->getNomComplet(),
            'personnelBNom' => $personnelB->getNomComplet(),
            'start'         => $lundi->format(\DateTimeInterface::ATOM),
            'end'           => $lundi->modify('+5 days')->format(\DateTimeInterface::ATOM),
        ];
    }

    private function creerCreneau(
        EntityManagerInterface $entityManager,
        Utilisateur $personnel,
        TypeRdv $typeRdv,
        \DateTimeImmutable $dateDebut,
    ): Creneau {
        $creneau = (new Creneau())
            ->setUtilisateur($personnel)
            ->setTypeRdv($typeRdv)
            ->setDateDebut($dateDebut)
            ->setDateFin($dateDebut->modify('+1 hour'))
            ->setEstActif(true);

        $entityManager->persist($creneau);
        $entityManager->flush();
        $this->idsCreneau[] = (int) $creneau->getId();

        return $creneau;
    }

    private function creerReservation(
        EntityManagerInterface $entityManager,
        Creneau $creneau,
        Utilisateur $auditeur,
    ): void {
        $reservation = (new Reservation())
            ->setCreneau($creneau)
            ->setUtilisateur($auditeur)
            ->setStatut(StatutReservation::ACTIVE);

        $entityManager->persist($reservation);
        $entityManager->flush();
        $this->idsReservation[] = (int) $reservation->getId();
    }

    private function creerService(EntityManagerInterface $entityManager): Service
    {
        $service = (new Service())
            ->setNom('Service Occ Web ' . uniqid())
            ->setEstActif(true);

        $entityManager->persist($service);
        $entityManager->flush();

        return $service;
    }

    private function creerUtilisateur(
        EntityManagerInterface $entityManager,
        RoleUtilisateur $role,
        string $prenom,
        ?Service $service,
    ): Utilisateur {
        $utilisateur = (new Utilisateur())
            ->setEmail('occ-web-' . uniqid() . '@test.local')
            ->setPrenom($prenom)
            ->setNom('Test')
            ->setRole($role)
            ->setEstActif(true)
            ->setService($service)
            ->setMotDePasseHash('placeholder-not-real');

        $entityManager->persist($utilisateur);
        $entityManager->flush();
        $this->idsUtilisateur[] = (int) $utilisateur->getId();

        return $utilisateur;
    }

    private function creerTypeRdv(EntityManagerInterface $entityManager): TypeRdv
    {
        $typeRdv = (new TypeRdv())
            ->setCode('OWB' . strtoupper(substr(uniqid(), -8)))
            ->setLibelle('Type Occ Web')
            ->setCouleurHex('#123456')
            ->setEstActif(true);

        $entityManager->persist($typeRdv);
        $entityManager->flush();

        return $typeRdv;
    }

    /**
     * @param class-string $classe
     * @param list<int>    $ids
     */
    private function supprimerParIds(EntityManagerInterface $entityManager, string $classe, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $entityManager->createQuery(
            sprintf('DELETE FROM %s e WHERE e.id IN (:ids)', $classe),
        )->setParameter('ids', $ids)->execute();
    }

    private function recupererUtilisateur(string $email): Utilisateur
    {
        $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email]);

        self::assertInstanceOf(
            Utilisateur::class,
            $utilisateur,
            "Compte de fixtures introuvable : {$email}. Charger les fixtures sur la BDD test (cf. DT-6).",
        );

        return $utilisateur;
    }
}
