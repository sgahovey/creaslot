<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Réinitialisation de mot de passe par email (US-6.2).
 *
 * Toutes les routes sont publiques (cf. règle `^/mot-de-passe-oublie` de
 * security.yaml). Anti-énumération : la demande répond toujours de la même
 * manière (« si un compte existe… ») que l'email existe, soit désactivé, soit
 * absent. L'email de réinitialisation passe par NotificationService (Brevo +
 * redirection DEV + journalisation), pas par un envoi direct. Le jeton est haché,
 * à durée limitée et à usage unique (supprimé après emploi).
 */
#[Route('/mot-de-passe-oublie')]
final class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationService $notificationService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Affiche et traite le formulaire de demande de réinitialisation.
     */
    #[Route('', name: 'app_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $formulaire = $this->createForm(ResetPasswordRequestFormType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var string $email */
            $email = $formulaire->get('email')->getData();

            return $this->envoyerEmailReinitialisation($email);
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $formulaire,
        ]);
    }

    /**
     * Page de confirmation après une demande (ne révèle jamais l'existence du compte).
     */
    #[Route('/email-envoye', name: 'app_mot_de_passe_oublie_email_envoye', methods: ['GET'])]
    public function checkEmail(): Response
    {
        // Jeton factice si l'utilisateur arrive directement ici : ne pas révéler
        // si un compte a été trouvé (anti-énumération).
        $resetToken = $this->getTokenObjectFromSession() ?? $this->resetPasswordHelper->generateFakeResetToken();

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
        ]);
    }

    /**
     * Valide le jeton du lien reçu par email et permet la saisie d'un nouveau mot de passe.
     */
    #[Route('/reinitialiser/{token}', name: 'app_mot_de_passe_oublie_reinitialiser', methods: ['GET', 'POST'])]
    public function reset(Request $request, UserPasswordHasherInterface $passwordHasher, TranslatorInterface $translator, ?string $token = null): Response
    {
        if ($token) {
            // Le jeton est rangé en session puis retiré de l'URL, pour éviter qu'il
            // ne fuite via le Referer ou un JavaScript tiers.
            $this->storeTokenInSession($token);

            return $this->redirectToRoute('app_mot_de_passe_oublie_reinitialiser');
        }

        $token = $this->getTokenFromSession();

        if (null === $token) {
            throw $this->createNotFoundException('Aucun jeton de réinitialisation dans l\'URL ou la session.');
        }

        try {
            /** @var Utilisateur $utilisateur */
            $utilisateur = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('reset_password_error', sprintf(
                '%s - %s',
                $translator->trans(ResetPasswordExceptionInterface::MESSAGE_PROBLEM_VALIDATE, [], 'ResetPasswordBundle'),
                $translator->trans($e->getReason(), [], 'ResetPasswordBundle'),
            ));

            return $this->redirectToRoute('app_mot_de_passe_oublie');
        }

        $formulaire = $this->createForm(ChangePasswordFormType::class);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            /** @var string $nouveauMotDePasse */
            $nouveauMotDePasse = $formulaire->get('plainPassword')->getData();

            // Cohérence avec le changement self-service (US-6.1) : interdire de
            // réinitialiser avec le mot de passe actuel. L'utilisateur ne saisit pas
            // son mot de passe actuel ici : on compare le nouveau au hash stocké.
            // Garde placée AVANT removeResetRequest pour que le jeton survive en
            // session et qu'un nouvel essai reste possible.
            if ($passwordHasher->isPasswordValid($utilisateur, $nouveauMotDePasse)) {
                $formulaire->get('plainPassword')->addError(new FormError('Le nouveau mot de passe doit être différent de l\'actuel.'));

                return $this->render('reset_password/reset.html.twig', [
                    'resetForm' => $formulaire,
                ]);
            }

            // Usage unique : le jeton est supprimé avant même le changement.
            $this->resetPasswordHelper->removeResetRequest($token);

            $utilisateur->setMotDePasseHash($passwordHasher->hashPassword($utilisateur, $nouveauMotDePasse));
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            $this->logger->info('Mot de passe réinitialisé', ['user_id' => $utilisateur->getId()]);
            $this->addFlash('success', 'Votre mot de passe a été réinitialisé. Vous pouvez maintenant vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $formulaire,
        ]);
    }

    /**
     * Génère le jeton et envoie le lien de réinitialisation, sans jamais révéler
     * si un compte (actif) correspond à l'email saisi.
     */
    private function envoyerEmailReinitialisation(string $emailSaisi): RedirectResponse
    {
        // Seuls les comptes ACTIFS peuvent réinitialiser : un compte désactivé est
        // traité comme inexistant (même réponse, pas de réouverture d'accès).
        $utilisateur = $this->entityManager->getRepository(Utilisateur::class)->findOneBy([
            'email'    => $emailSaisi,
            'estActif' => true,
        ]);

        if (null === $utilisateur) {
            return $this->redirectToRoute('app_mot_de_passe_oublie_email_envoye');
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($utilisateur);
        } catch (ResetPasswordExceptionInterface) {
            // Throttling ou demande déjà en cours : même réponse neutre.
            return $this->redirectToRoute('app_mot_de_passe_oublie_email_envoye');
        }

        $this->notificationService->envoyer(
            $utilisateur->getEmail(),
            'Réinitialisation de votre mot de passe',
            'emails/reset_password.html.twig',
            ['resetToken' => $resetToken],
        );

        $this->setTokenObjectInSession($resetToken);

        return $this->redirectToRoute('app_mot_de_passe_oublie_email_envoye');
    }
}
