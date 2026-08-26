<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Form\InscriptionType;
use App\Security\EmailNonVerifieException;
use App\Service\VerificationEmailService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Parcours d'authentification public : connexion, déconnexion et inscription (US-1.x,
 * US-12.4).
 *
 * Seules routes accessibles sans être connecté. Les messages restent neutres pour ne
 * pas révéler l'existence d'un compte (anti-énumération, OWASP).
 */
class SecurityController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly VerificationEmailService $verificationEmailService,
    ) {
    }

    /**
     * Page de connexion. Redirige un utilisateur déjà authentifié, et distingue le cas
     * « e-mail non confirmé » (message ciblé + lien de renvoi) du générique
     * « Identifiants incorrects ».
     */
    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        $erreur = $authenticationUtils->getLastAuthenticationError();

        return $this->render('auth/connexion.html.twig', [
            'dernierEmail' => $authenticationUtils->getLastUsername(),
            'erreur'       => $erreur,
            // Cas « email non confirmé » (US-12.4) : la page affiche un message ciblé
            // + un lien de renvoi, au lieu du générique « Identifiants incorrects. ».
            'emailNonVerifie' => $erreur instanceof EmailNonVerifieException,
        ]);
    }

    /**
     * Point d'entrée de déconnexion, intercepté par le firewall Symfony Security :
     * le corps n'est jamais exécuté.
     */
    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        // Symfony Security intercepte cette route — ce code n'est jamais exécuté.
        throw new \LogicException('La déconnexion est gérée par le firewall Symfony Security.');
    }

    /**
     * Inscription d'un nouvel Auditeur. Le compte est créé actif mais non vérifié : la
     * connexion reste bloquée jusqu'à confirmation de l'e-mail (voir inscrireAuditeur).
     */
    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request): Response
    {
        $utilisateur = new Utilisateur();
        $formulaire = $this->createForm(InscriptionType::class, $utilisateur);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            return $this->inscrireAuditeur($formulaire, $utilisateur);
        }

        return $this->render('auth/inscription.html.twig', [
            'formulaire' => $formulaire->createView(),
        ]);
    }

    /**
     * @param \Symfony\Component\Form\FormInterface<Utilisateur> $formulaire
     */
    private function inscrireAuditeur(
        \Symfony\Component\Form\FormInterface $formulaire,
        Utilisateur $utilisateur,
    ): Response {
        $motDePasseEnClair = $formulaire->get('motDePasse')->getData();

        $utilisateur
            ->setRole(RoleUtilisateur::AUDITEUR)
            // Compte créé actif mais NON confirmé (US-12.4) : la connexion reste
            // bloquée par UserChecker tant que l'email n'est pas vérifié. est_actif
            // reste vrai (réservé au contrôle admin), email_verifie porte l'état.
            ->setEstActif(true)
            ->setEmailVerifie(false)
            ->setMotDePasseHash(
                $this->passwordHasher->hashPassword($utilisateur, $motDePasseEnClair),
            );

        try {
            $this->entityManager->persist($utilisateur);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Wording neutre : ne pas révéler si l'email existe déjà (OWASP)
            $this->addFlash('error', 'Une erreur est survenue, veuillez réessayer.');

            return $this->redirectToRoute('app_inscription');
        }

        // Envoi du lien signé de confirmation, puis page neutre « vérifiez votre boîte
        // mail » (anti-énumération : aucune révélation de l'existence du compte).
        $this->verificationEmailService->envoyerLienConfirmation($utilisateur);

        return $this->redirectToRoute('app_inscription_email_envoye');
    }
}
