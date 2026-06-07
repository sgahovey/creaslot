<?php

declare(strict_types=1);

namespace App\Controller\Auditeur;

use App\Entity\Utilisateur;
use App\Form\PreferencesNotificationType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Préférences notifications email de l'Auditeur courant (US-4.8).
 *
 * GET affiche le formulaire prérempli avec les préférences actuelles ; POST
 * enregistre (flush) + flash succès + redirect (pattern POST-Redirect-GET).
 *
 * Base légale RGPD : art. 6.1.b (exécution du contrat). Périmètre métier
 * (2 types confort désactivables, 3 critiques toujours envoyés) défini dans
 * PreferencesNotificationType.
 *
 * Sécurité : pas de Voter — l'Auditeur édite ses PROPRES préférences via getUser().
 */
#[IsGranted('ROLE_AUDITEUR')]
final class PreferencesController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/mes-preferences', name: 'app_mes_preferences', methods: ['GET', 'POST'])]
    public function gerer(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $formulaire = $this->createForm(PreferencesNotificationType::class, $utilisateur);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Vos préférences de notifications ont été enregistrées.');

            return $this->redirectToRoute('app_mes_preferences');
        }

        return $this->render('auditeur/preferences/index.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }
}
