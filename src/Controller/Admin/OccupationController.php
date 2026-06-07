<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CreneauRepository;
use App\Repository\ServiceRepository;
use App\Repository\TypeRdvRepository;
use App\Service\OccupationCalendarSerializer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Vue globale occupé/libre de l'organisation, réservée au Super-admin (US-5.7).
 *
 * Lecture seule : aucune édition de créneau ici. Protégée au niveau classe par
 * `ROLE_SUPER_ADMIN` (défense en profondeur ; la règle `access_control ^/admin`
 * de security.yaml constitue la 2e barrière). Les créneaux sont restitués sans
 * jamais exposer l'identité des auditeurs (RGPD, cf. OccupationCalendarSerializer).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class OccupationController extends AbstractController
{
    private const string FUSEAU_REUNION = 'Indian/Reunion';

    public function __construct(
        private readonly CreneauRepository $creneauRepository,
        private readonly OccupationCalendarSerializer $serializer,
        private readonly TypeRdvRepository $typeRdvRepository,
        private readonly ServiceRepository $serviceRepository,
    ) {
    }

    #[Route('/admin/occupation', name: 'app_admin_occupation', methods: ['GET'])]
    public function page(Request $request): Response
    {
        $serviceId = $this->idFiltre($request, 'service');
        $typeId = $this->idFiltre($request, 'type');

        [$debutSemaine, $finSemaine] = $this->semaineDeReference();

        $creneaux = $this->creneauRepository->findDansPlageGlobale($debutSemaine, $finSemaine, $serviceId, $typeId);
        $idsOccupes = $this->creneauRepository->findIdsCreneauxOccupesDansPlage($debutSemaine, $finSemaine, $serviceId, $typeId);

        return $this->render('admin/occupation/index.html.twig', [
            'creneaux'     => $creneaux,
            'idsOccupes'   => $idsOccupes,
            'typesRdv'     => $this->typeRdvRepository->findAll(),
            'services'     => $this->serviceRepository->findBy([], ['nom' => 'ASC']),
            'serviceActif' => $serviceId,
            'typeActif'    => $typeId,
            'debutSemaine' => $debutSemaine,
            'finSemaine'   => $finSemaine,
        ]);
    }

    #[Route('/admin/occupation/evenements', name: 'app_admin_occupation_evenements', methods: ['GET'])]
    public function evenements(Request $request): JsonResponse
    {
        $debutRaw = $request->query->getString('start');
        $finRaw = $request->query->getString('end');

        if ($debutRaw === '' || $finRaw === '') {
            return $this->repondreSansCache([]);
        }

        $debutPlage = $this->analyserDateIso($debutRaw);
        $finPlage = $this->analyserDateIso($finRaw);

        if ($debutPlage === null || $finPlage === null) {
            return $this->repondreSansCache([], Response::HTTP_BAD_REQUEST);
        }

        $serviceId = $this->idFiltre($request, 'service');
        $typeId = $this->idFiltre($request, 'type');

        $creneaux = $this->creneauRepository->findDansPlageGlobale($debutPlage, $finPlage, $serviceId, $typeId);
        $idsOccupes = $this->creneauRepository->findIdsCreneauxOccupesDansPlage($debutPlage, $finPlage, $serviceId, $typeId);

        return $this->repondreSansCache($this->serializer->toCalendarEvents($creneaux, $idsOccupes));
    }

    /**
     * Analyse une date ISO 8601 transmise par FullCalendar (paramètres start/end).
     *
     * FullCalendar encode correctement l'offset (« +04:00 » → « %2B04:00 ») ; Symfony
     * le décode donc en « + » avant d'arriver ici (mesuré sur le vrai navigateur).
     * On parse en forme tolérante (avec ou sans offset), dans l'esprit de
     * CreneauApiController, et on renvoie null sur toute valeur non analysable.
     */
    private function analyserDateIso(string $valeur): ?\DateTimeImmutable
    {
        $valeur = trim($valeur);

        if ($valeur === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($valeur);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Identifiant de filtre positif depuis la requête, ou null si absent, vide ou
     * non numérique (« tous »). On lit la valeur brute SANS `getInt()` : FullCalendar
     * transmet ses extraParams toujours présents mais vides (« service= »), et
     * `getInt()` lève une BadRequestException sur une chaîne vide PRÉSENTE (Symfony 7+).
     */
    private function idFiltre(Request $request, string $cle): ?int
    {
        $valeur = $request->query->get($cle);

        if ($valeur === null || $valeur === '' || !ctype_digit($valeur)) {
            return null;
        }

        $id = (int) $valeur;

        return $id > 0 ? $id : null;
    }

    /**
     * Bornes de la semaine courante (lundi 00:00 → vendredi 23:59:59) en heure
     * Réunion. Les créneaux étant du lundi au vendredi, ces bornes cadrent la table
     * RGAA rendue côté serveur ; la navigation fine reste assurée par le calendrier.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function semaineDeReference(): array
    {
        $maintenant = new \DateTimeImmutable('now', new \DateTimeZone(self::FUSEAU_REUNION));

        $debut = $maintenant->modify('monday this week')->setTime(0, 0);
        $fin = $maintenant->modify('friday this week')->setTime(23, 59, 59);

        return [$debut, $fin];
    }

    /**
     * Réponse JSON non mise en cache : la vue d'occupation doit refléter l'état
     * courant immédiatement (mêmes raisons que CreneauApiController::jsonSansCache).
     * Helper local volontairement dupliqué pour garder l'US auto-contenue [[DT-16]].
     *
     * @param array<int|string, mixed> $donnees
     */
    private function repondreSansCache(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
