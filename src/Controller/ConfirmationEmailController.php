<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\RenvoiConfirmationType;
use App\Repository\UtilisateurRepository;
use App\Service\VerificationEmailService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * Confirmation d'adresse email à l'auto-inscription auditeur (US-12.4).
 *
 * Toutes les routes sont sous le préfixe /inscription → publiques (règle
 * `^/inscription` de security.yaml, avant le catch-all `^/`). Anti-énumération :
 * lien invalide ou renvoi répondent toujours de manière neutre, sans révéler
 * l'existence d'un compte.
 */
final class ConfirmationEmailController extends AbstractController
{
    private const string MESSAGE_LIEN_INVALIDE = 'Ce lien de confirmation est invalide ou a expiré.';

    public function __construct(
        private readonly VerificationEmailService $verificationEmailService,
        private readonly UtilisateurRepository $utilisateurRepository,
    ) {
    }

    /**
     * Page neutre affichée après l'inscription ou un renvoi : « vérifiez votre boîte mail ».
     */
    #[Route('/inscription/email-envoye', name: 'app_inscription_email_envoye', methods: ['GET'])]
    public function emailEnvoye(): Response
    {
        return $this->render('auth/inscription_email_envoye.html.twig');
    }

    /**
     * Valide le lien signé reçu par email et active le compte (email vérifié).
     */
    #[Route('/inscription/verifier-email', name: 'app_verify_email', methods: ['GET'])]
    public function verifier(Request $request): Response
    {
        $identifiant = $request->query->get('id');
        if (!is_string($identifiant) || $identifiant === '') {
            return $this->refuserLien();
        }

        $utilisateur = $this->utilisateurRepository->find($identifiant);
        if ($utilisateur === null) {
            return $this->refuserLien();
        }

        try {
            $this->verificationEmailService->confirmer($request, $utilisateur);
        } catch (VerifyEmailExceptionInterface) {
            return $this->refuserLien();
        }

        $this->addFlash('success', 'Votre adresse email est confirmée. Vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }

    /**
     * Renvoie un email de confirmation. Réponse neutre systématique (anti-énumération) :
     * un email n'est réellement renvoyé que si un compte NON confirmé correspond.
     */
    #[Route('/inscription/renvoyer-confirmation', name: 'app_renvoyer_confirmation', methods: ['GET', 'POST'])]
    public function renvoyer(Request $request): Response
    {
        $formulaire = $this->createForm(RenvoiConfirmationType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var string $email */
            $email = $formulaire->get('email')->getData();

            $utilisateur = $this->utilisateurRepository->findOneBy(['email' => $email, 'emailVerifie' => false]);
            if ($utilisateur !== null) {
                $this->verificationEmailService->envoyerLienConfirmation($utilisateur);
            }

            return $this->redirectToRoute('app_inscription_email_envoye');
        }

        return $this->render('auth/renvoi_confirmation.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }

    private function refuserLien(): Response
    {
        $this->addFlash('error', self::MESSAGE_LIEN_INVALIDE);

        return $this->redirectToRoute('app_login');
    }
}
