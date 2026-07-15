<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Vérification d'email à l'auto-inscription auditeur (US-12.4).
 *
 * S'appuie sur SymfonyCasts VerifyEmailBundle : le lien de confirmation est signé
 * (id utilisateur + email + secret + expiration) et STATELESS — aucune table ni entité,
 * contrairement au reset-password. L'envoi passe par NotificationService (point d'entrée
 * unique des emails, logs sans PII).
 */
final readonly class VerificationEmailService
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private NotificationService $notificationService,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Génère le lien signé de confirmation et l'envoie par email.
     */
    public function envoyerLienConfirmation(Utilisateur $utilisateur): void
    {
        $identifiant = (string) $utilisateur->getId();

        $composants = $this->verifyEmailHelper->generateSignature(
            'app_verify_email',
            $identifiant,
            $utilisateur->getEmail(),
            ['id' => $identifiant],
        );

        $this->notificationService->envoyer(
            $utilisateur->getEmail(),
            'Confirmez votre adresse email',
            'emails/confirmation_inscription.html.twig',
            [
                'prenom'    => $utilisateur->getPrenom(),
                'signedUrl' => $composants->getSignedUrl(),
            ],
        );
    }

    /**
     * Valide le lien signé de la requête courante, puis marque l'email comme vérifié.
     *
     * Utilise validateEmailConfirmationFromRequest() (et non la variante par URL, dépréciée) :
     * la signature couvre l'hôte + la query, la vérification se fait donc sur la requête réelle.
     *
     * @throws \SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface
     *                                                                                  si la signature est invalide, expirée, ou si l'email ne correspond plus
     */
    public function confirmer(Request $request, Utilisateur $utilisateur): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest(
            $request,
            (string) $utilisateur->getId(),
            $utilisateur->getEmail(),
        );

        $utilisateur->setEmailVerifie(true);
        $this->entityManager->flush();
    }
}
