<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Form\InscriptionType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class SecurityController extends AbstractController
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    #[Route('/connexion', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_home');
        }

        return $this->render('auth/connexion.html.twig', [
            'dernierEmail' => $authenticationUtils->getLastUsername(),
            'erreur'       => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/deconnexion', name: 'app_logout')]
    public function logout(): never
    {
        // Symfony Security intercepte cette route — ce code n'est jamais exécuté.
        throw new \LogicException('La déconnexion est gérée par le firewall Symfony Security.');
    }

    #[Route('/inscription', name: 'app_inscription', methods: ['GET', 'POST'])]
    public function inscription(Request $request): Response
    {
        $utilisateur = new Utilisateur();
        $formulaire  = $this->createForm(InscriptionType::class, $utilisateur);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            return $this->inscrireAuditeur($formulaire, $utilisateur);
        }

        return $this->render('auth/inscription.html.twig', [
            'formulaire' => $formulaire->createView(),
        ]);
    }

    private function inscrireAuditeur(
        \Symfony\Component\Form\FormInterface $formulaire,
        Utilisateur $utilisateur,
    ): Response {
        $motDePasseEnClair = $formulaire->get('motDePasse')->getData();

        $utilisateur
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true)
            ->setMotDePasseHash(
                $this->passwordHasher->hashPassword($utilisateur, $motDePasseEnClair)
            );

        try {
            $this->entityManager->persist($utilisateur);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            // Wording neutre : ne pas révéler si l'email existe déjà (OWASP)
            $this->addFlash('error', 'Une erreur est survenue, veuillez réessayer.');

            return $this->redirectToRoute('app_inscription');
        }

        $this->addFlash('success', 'Votre compte a été créé. Vous pouvez maintenant vous connecter.');

        return $this->redirectToRoute('app_login');
    }
}
