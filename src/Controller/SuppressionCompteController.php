<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Exception\EngagementsFutursException;
use App\Form\SuppressionCompteType;
use App\Security\UtilisateurVoter;
use App\Service\AnonymisationCompteService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Suppression self-service de son propre compte par anonymisation (US-12.3, RGPD art. 17).
 *
 * L'utilisateur n'agit que sur SON compte (getUser(), jamais d'id en URL). Le Voter
 * ANONYMISER interdit l'action au SUPER_ADMIN. L'anonymisation effective vit dans
 * AnonymisationCompteService ; le contrôleur reste mince (reçoit, délègue, répond).
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class SuppressionCompteController extends AbstractController
{
    public function __construct(
        private readonly AnonymisationCompteService $anonymisationCompteService,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/mon-profil/supprimer', name: 'app_mon_profil_suppression', methods: ['GET'])]
    public function confirmer(): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $this->denyAccessUnlessGranted(UtilisateurVoter::ANONYMISER, $utilisateur);

        return $this->render('profil/suppression.html.twig', [
            'formSuppression' => $this->createForm(SuppressionCompteType::class)->createView(),
            'aDesEngagements' => $this->anonymisationCompteService->aDesEngagementsFuturs($utilisateur),
        ]);
    }

    #[Route('/mon-profil/supprimer', name: 'app_mon_profil_suppression_confirmer', methods: ['POST'])]
    public function supprimer(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();
        $this->denyAccessUnlessGranted(UtilisateurVoter::ANONYMISER, $utilisateur);

        $formulaire = $this->createForm(SuppressionCompteType::class);
        $formulaire->handleRequest($request);

        if (!$formulaire->isSubmitted() || !$formulaire->isValid()) {
            return $this->render('profil/suppression.html.twig', [
                'formSuppression' => $formulaire->createView(),
                'aDesEngagements' => $this->anonymisationCompteService->aDesEngagementsFuturs($utilisateur),
            ], new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $identifiant = $utilisateur->getId();

        try {
            $this->anonymisationCompteService->anonymiser($utilisateur);
        } catch (EngagementsFutursException) {
            $this->addFlash('warning', "Vous avez encore des rendez-vous ou des créneaux à venir. Veuillez d'abord les annuler avant de supprimer votre compte.");

            return $this->redirectToRoute('app_mon_profil_suppression');
        }

        $this->logger->info("Compte anonymisé (droit à l'effacement RGPD)", ['user_id' => $identifiant]);
        $this->addFlash('success', 'Votre compte a été supprimé. Vos données personnelles ont été anonymisées.');

        // La déconnexion Symfony vide la session (le flash survit : invalidate_session=false).
        return $this->redirectToRoute('app_logout');
    }
}
