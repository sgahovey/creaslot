<?php

declare(strict_types=1);

namespace App\Controller\Personnel;

use App\Entity\Creneau;
use App\Entity\Utilisateur;
use App\Form\CreneauType;
use App\Repository\CreneauRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_PERSONNEL')]
class CreneauController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/creneau', name: 'app_creneau_liste', methods: ['GET'])]
    public function liste(Request $request, CreneauRepository $creneauRepository): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur   = $this->getUser();
        $filtre        = $request->query->getString('filtre', 'tous');
        $page          = max(1, $request->query->getInt('page', 1));
        $creneaux      = $creneauRepository->findByPersonnelWithFilters($utilisateur, $filtre, $page, 10);
        $totalCreneaux = count($creneaux);

        return $this->render('personnel/creneau/liste.html.twig', [
            'creneaux'      => $creneaux,
            'filtre'        => $filtre,
            'page'          => $page,
            'nbPages'       => max(1, (int) ceil($totalCreneaux / 10)),
            'totalCreneaux' => $totalCreneaux,
        ]);
    }

    #[Route('/creneau/nouveau', name: 'app_creneau_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $creneau    = new Creneau();
        $formulaire = $this->createForm(CreneauType::class, $creneau);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var Utilisateur $utilisateur */
            $utilisateur = $this->getUser();

            $dateDebut = $this->construireDateDebut(
                $formulaire->get('date')->getData(),
                $formulaire->get('heureDebut')->getData(),
            );
            $dateFin = $this->calculerDateFin(
                $dateDebut,
                $formulaire->get('duree')->getData(),
                $formulaire->get('heureFin')->getData(),
            );

            $creneau
                ->setUtilisateur($utilisateur)
                ->setDateDebut($dateDebut)
                ->setDateFin($dateFin);

            $this->entityManager->persist($creneau);
            $this->entityManager->flush();

            $this->logger->info('Créneau créé', [
                'creneau_id' => $creneau->getId(),
                'user_id'    => $utilisateur->getId(),
            ]);

            $this->addFlash('success', 'Le créneau a été créé.');

            return $this->redirectToRoute('app_creneau_liste');
        }

        return $this->render('personnel/creneau/nouveau.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }

    private function construireDateDebut(
        \DateTimeImmutable $date,
        \DateTimeImmutable $heure,
    ): \DateTimeImmutable {
        return \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $date->format('Y-m-d') . ' ' . $heure->format('H:i'),
        );
    }

    private function calculerDateFin(
        \DateTimeImmutable $dateDebut,
        string $duree,
        ?\DateTimeImmutable $heureFinPersonnalisee,
    ): \DateTimeImmutable {
        if ($duree === 'custom' && $heureFinPersonnalisee !== null) {
            return \DateTimeImmutable::createFromFormat(
                'Y-m-d H:i',
                $dateDebut->format('Y-m-d') . ' ' . $heureFinPersonnalisee->format('H:i'),
            );
        }

        $minutes = is_numeric($duree) ? (int) $duree : 60;

        return $dateDebut->add(new \DateInterval("PT{$minutes}M"));
    }
}
