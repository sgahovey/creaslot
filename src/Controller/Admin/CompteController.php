<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Form\UtilisateurAdminType;
use App\Repository\UtilisateurRepository;
use App\Security\UtilisateurVoter;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des comptes par le Super-admin (US-5.3).
 *
 * Protégé au niveau classe par `ROLE_SUPER_ADMIN` (défense en profondeur : la
 * règle `access_control ^/admin` de security.yaml constitue la 2e barrière).
 * Manipule des données nominatives : aucune donnée sensible (hash de mot de passe)
 * n'est exposée aux vues.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CompteController extends AbstractController
{
    private const int COMPTES_PAR_PAGE = 20;

    public function __construct(
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/admin/comptes', name: 'app_admin_comptes', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $page     = max(1, $request->query->getInt('page', 1));
        $comptes  = $this->utilisateurRepository->findAllPourAdmin($page, self::COMPTES_PAR_PAGE);
        $total    = count($comptes);

        return $this->render('admin/compte/liste.html.twig', [
            'comptes' => $comptes,
            'page'    => $page,
            'nbPages' => max(1, (int) ceil($total / self::COMPTES_PAR_PAGE)),
            'total'   => $total,
        ]);
    }

    #[Route('/admin/comptes/nouveau', name: 'app_admin_compte_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $compte     = new Utilisateur();
        $formulaire = $this->createForm(UtilisateurAdminType::class, $compte, ['avec_mot_de_passe' => true]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $motDePasseEnClair = $formulaire->get('motDePasse')->getData();
            $compte->setMotDePasseHash($this->passwordHasher->hashPassword($compte, $motDePasseEnClair));

            $this->entityManager->persist($compte);
            $this->entityManager->flush();

            /** @var Utilisateur $administrateur */
            $administrateur = $this->getUser();
            $this->logger->info('Compte créé', [
                'admin_id' => $administrateur->getId(),
                'cible_id' => $compte->getId(),
            ]);

            $this->addFlash('success', 'Le compte a été créé.');

            return $this->redirectToRoute('app_admin_comptes');
        }

        return $this->render('admin/compte/nouveau.html.twig', [
            'formulaire' => $formulaire,
        ]);
    }

    #[Route('/admin/comptes/{id}/modifier', name: 'app_admin_compte_modifier', methods: ['GET', 'POST'])]
    public function modifier(Utilisateur $compte, Request $request): Response
    {
        $this->denyAccessUnlessGranted(UtilisateurVoter::EDIT, $compte);

        $roleAvant  = $compte->getRole();
        $formulaire = $this->createForm(UtilisateurAdminType::class, $compte, ['avec_mot_de_passe' => false]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $roleChange = $compte->getRole() !== $roleAvant;

            // Ordre des gardes anti-verrouillage (a avant b) : on vérifie d'abord
            // l'invariant système (« jamais 0 super-admin »), évalué sur l'écriture
            // réelle et porteur du message métier le plus clair ; le Voter CHANGE_ROLE
            // couvre ensuite les autres auto-changements de rôle.
            if ($roleChange && $this->retireLeDernierSuperAdmin($roleAvant, $compte->getRole())) {
                $this->addFlash('error', 'Vous ne pouvez pas retirer le dernier compte super-administrateur.');

                return $this->render('admin/compte/modifier.html.twig', [
                    'formulaire' => $formulaire,
                    'compte'     => $compte,
                ]);
            }

            if ($roleChange) {
                $this->denyAccessUnlessGranted(UtilisateurVoter::CHANGE_ROLE, $compte);
            }

            $this->entityManager->flush();

            /** @var Utilisateur $administrateur */
            $administrateur = $this->getUser();
            $this->logger->info('Compte modifié', [
                'admin_id'    => $administrateur->getId(),
                'cible_id'    => $compte->getId(),
                'role_change' => $roleChange,
            ]);

            $this->addFlash('success', 'Le compte a été modifié.');

            return $this->redirectToRoute('app_admin_comptes');
        }

        return $this->render('admin/compte/modifier.html.twig', [
            'formulaire' => $formulaire,
            'compte'     => $compte,
        ]);
    }

    /**
     * Vrai si le changement de rôle rétrograde le dernier super-administrateur
     * restant (passage de SUPER_ADMIN à autre chose alors qu'il n'en reste qu'un).
     */
    private function retireLeDernierSuperAdmin(RoleUtilisateur $roleAvant, RoleUtilisateur $roleApres): bool
    {
        return $roleAvant === RoleUtilisateur::SUPER_ADMIN
            && $roleApres !== RoleUtilisateur::SUPER_ADMIN
            && $this->utilisateurRepository->countSuperAdmins() <= 1;
    }
}
