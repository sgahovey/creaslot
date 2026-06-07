<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use App\Enum\TypeActionJournal;
use App\Form\UtilisateurAdminType;
use App\Repository\UtilisateurRepository;
use App\Security\UtilisateurVoter;
use App\Service\JournalAdminService;
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
        private readonly JournalAdminService $journalAdminService,
    ) {
    }

    #[Route('/admin/comptes', name: 'app_admin_comptes', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $recherche = trim($request->query->getString('recherche'));
        $comptes = $this->utilisateurRepository->findAllPourAdmin(
            $page,
            self::COMPTES_PAR_PAGE,
            $recherche !== '' ? $recherche : null,
        );
        $total = count($comptes);

        return $this->render('admin/compte/liste.html.twig', [
            'comptes'   => $comptes,
            'page'      => $page,
            'nbPages'   => max(1, (int) ceil($total / self::COMPTES_PAR_PAGE)),
            'total'     => $total,
            'recherche' => $recherche,
        ]);
    }

    #[Route('/admin/comptes/nouveau', name: 'app_admin_compte_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $compte = new Utilisateur();
        $formulaire = $this->createForm(UtilisateurAdminType::class, $compte, ['avec_mot_de_passe' => true]);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $motDePasseEnClair = $formulaire->get('motDePasse')->getData();
            $compte->setMotDePasseHash($this->passwordHasher->hashPassword($compte, $motDePasseEnClair));

            /** @var Utilisateur $administrateur */
            $administrateur = $this->getUser();

            // Compte + trace dans une même transaction. Un premier flush génère
            // l'id du compte (cibleId du journal), le second enregistre la trace.
            $this->entityManager->wrapInTransaction(function () use ($compte, $administrateur): void {
                $this->entityManager->persist($compte);
                $this->entityManager->flush();

                $this->journalAdminService->enregistrer(
                    TypeActionJournal::COMPTE_CREATION,
                    $administrateur,
                    $compte,
                    details: $compte->getEmail(),
                );
                $this->entityManager->flush();
            });

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

        $roleAvant = $compte->getRole();
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

            /** @var Utilisateur $administrateur */
            $administrateur = $this->getUser();

            // Trace persistée AVANT le flush : action + journal commités ensemble.
            $this->journalAdminService->enregistrer(
                $roleChange ? TypeActionJournal::COMPTE_CHANGEMENT_ROLE : TypeActionJournal::COMPTE_MODIFICATION,
                $administrateur,
                $compte,
            );

            $this->entityManager->flush();

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

    #[Route('/admin/comptes/{id}/activation', name: 'app_admin_compte_activation', methods: ['POST'])]
    public function basculerActivation(Utilisateur $compte, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('activation-' . $compte->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'Token de sécurité invalide. Veuillez réessayer.');

            return $this->redirectToRoute('app_admin_comptes');
        }

        $desactivation = $compte->isEstActif();

        // Garde anti lock-out AVANT l'autorisation : l'invariant « jamais 0
        // super-admin actif » porte le message métier le plus clair ; le Voter
        // DEACTIVATE (anti-soi) couvre ensuite les autres auto-désactivations.
        if ($desactivation && $this->estLeDernierSuperAdminActif($compte)) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver le dernier super-administrateur actif.');

            return $this->redirectToRoute('app_admin_comptes');
        }

        $this->denyAccessUnlessGranted(
            $desactivation ? UtilisateurVoter::DEACTIVATE : UtilisateurVoter::ACTIVATE,
            $compte,
        );

        $compte->setEstActif(!$desactivation);

        /** @var Utilisateur $administrateur */
        $administrateur = $this->getUser();

        // Trace persistée AVANT le flush : action + journal commités ensemble.
        $this->journalAdminService->enregistrer(
            $desactivation ? TypeActionJournal::COMPTE_DESACTIVATION : TypeActionJournal::COMPTE_ACTIVATION,
            $administrateur,
            $compte,
        );

        $this->entityManager->flush();

        $this->logger->info('Compte activation basculée', [
            'admin_id'  => $administrateur->getId(),
            'cible_id'  => $compte->getId(),
            'est_actif' => $compte->isEstActif(),
        ]);

        $this->addFlash('success', $desactivation ? 'Le compte a été désactivé.' : 'Le compte a été réactivé.');

        return $this->redirectToRoute('app_admin_comptes');
    }

    private function estLeDernierSuperAdminActif(Utilisateur $compte): bool
    {
        return $compte->getRole() === RoleUtilisateur::SUPER_ADMIN
            && $this->utilisateurRepository->countSuperAdminsActifs() <= 1;
    }

    /**
     * Vrai si le changement de rôle rétrograde le dernier super-administrateur
     * actif restant (passage de SUPER_ADMIN à autre chose alors qu'il n'en reste
     * qu'un de réellement utilisable). On compte les super-admins ACTIFS (US-5.4) :
     * un super-admin désactivé ne peut plus se connecter, il ne sert pas de repli.
     */
    private function retireLeDernierSuperAdmin(RoleUtilisateur $roleAvant, RoleUtilisateur $roleApres): bool
    {
        return $roleAvant === RoleUtilisateur::SUPER_ADMIN
            && $roleApres !== RoleUtilisateur::SUPER_ADMIN
            && $this->utilisateurRepository->countSuperAdminsActifs() <= 1;
    }
}
