<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\ChangementMotDePasse;
use App\Entity\Utilisateur;
use App\Form\ChangementMotDePasseType;
use App\Form\MonProfilType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Page self-service « Mon profil » de l'utilisateur connecté (US-6.1).
 *
 * L'utilisateur n'agit que sur SON propre compte (getUser(), jamais d'id en URL),
 * d'où l'absence de Voter (patron de /mes-preferences et /mes-donnees). Il peut
 * éditer prénom/nom et changer son mot de passe ; email, rôle et service sont en
 * lecture seule. Le formulaire d'édition ne mappe ni rôle ni service ni email :
 * garantie structurelle anti-escalade de privilège.
 */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class MonProfilController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/mon-profil', name: 'app_mon_profil', methods: ['GET'])]
    public function index(): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        return $this->afficherPage(
            $this->createForm(MonProfilType::class, $utilisateur),
            $this->createForm(ChangementMotDePasseType::class, new ChangementMotDePasse()),
        );
    }

    #[Route('/mon-profil/informations', name: 'app_mon_profil_informations', methods: ['POST'])]
    public function informations(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $formInformations = $this->createForm(MonProfilType::class, $utilisateur);
        $formInformations->handleRequest($request);

        if ($formInformations->isSubmitted() && $formInformations->isValid()) {
            $this->entityManager->flush();
            $this->logger->info('Profil mis à jour', ['user_id' => $utilisateur->getId()]);
            $this->addFlash('success', 'Vos informations ont été mises à jour.');

            return $this->redirectToRoute('app_mon_profil');
        }

        return $this->afficherPage(
            $formInformations,
            $this->createForm(ChangementMotDePasseType::class, new ChangementMotDePasse()),
        );
    }

    #[Route('/mon-profil/mot-de-passe', name: 'app_mon_profil_mot_de_passe', methods: ['POST'])]
    public function motDePasse(Request $request): Response
    {
        /** @var Utilisateur $utilisateur */
        $utilisateur = $this->getUser();

        $donnees       = new ChangementMotDePasse();
        $formMotDePasse = $this->createForm(ChangementMotDePasseType::class, $donnees);
        $formMotDePasse->handleRequest($request);

        if (!$formMotDePasse->isSubmitted() || !$formMotDePasse->isValid()) {
            return $this->afficherPage(
                $this->createForm(MonProfilType::class, $utilisateur),
                $formMotDePasse,
            );
        }

        // Re-authentification : on exige le mot de passe actuel avant tout changement
        // (défense contre un détournement de session). Message neutre, ciblé sur le champ.
        if (!$this->passwordHasher->isPasswordValid($utilisateur, (string) $donnees->motDePasseActuel)) {
            $formMotDePasse->get('motDePasseActuel')->addError(new FormError('Le mot de passe actuel est incorrect.'));

            return $this->afficherPage(
                $this->createForm(MonProfilType::class, $utilisateur),
                $formMotDePasse,
            );
        }

        // L'actuel vient d'être confirmé correct : comparer le nouveau au plaintext
        // actuel équivaut à « différent du mot de passe courant », sans re-hacher.
        if ($donnees->nouveauMotDePasse === $donnees->motDePasseActuel) {
            $formMotDePasse->get('nouveauMotDePasse')->get('first')->addError(new FormError('Le nouveau mot de passe doit être différent de l\'actuel.'));

            return $this->afficherPage(
                $this->createForm(MonProfilType::class, $utilisateur),
                $formMotDePasse,
            );
        }

        $utilisateur->setMotDePasseHash(
            $this->passwordHasher->hashPassword($utilisateur, (string) $donnees->nouveauMotDePasse),
        );
        $this->entityManager->flush();

        // L'utilisateur reste connecté ; on régénère l'identifiant de session
        // (protection contre la fixation de session après un changement d'identifiant).
        $request->getSession()->migrate(true);

        $this->logger->info('Mot de passe modifié', ['user_id' => $utilisateur->getId()]);
        $this->addFlash('success', 'Votre mot de passe a été modifié.');

        return $this->redirectToRoute('app_mon_profil');
    }

    /**
     * Rendu unique de la page (les deux sections partagent le même écran), pour
     * éviter de dupliquer l'appel render dans chaque action.
     */
    private function afficherPage(FormInterface $informations, FormInterface $motDePasse): Response
    {
        return $this->render('profil/index.html.twig', [
            'formInformations' => $informations,
            'formMotDePasse'   => $motDePasse,
        ]);
    }
}
