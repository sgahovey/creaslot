<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Aiguilleur de la racine du site.
 *
 * La page d'accueil n'affiche aucun contenu : elle redirige chaque utilisateur
 * vers son espace selon son rôle (du plus spécifique au plus général). C'est la
 * destination de `default_target_path` après connexion (cf. security.yaml).
 *
 * Aucune information technique n'est exposée (versions, extensions, mode debug) :
 * l'ancienne page de squelette divulguait la stack, vecteur de reconnaissance
 * (OWASP A05 — Security Misconfiguration).
 */
final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): RedirectResponse
    {
        // Ordre du plus spécifique au plus général : la hiérarchie des rôles rend
        // ROLE_AUDITEUR vrai pour tous, il doit donc être testé en dernier.
        $redirectionsParRole = [
            'ROLE_SUPER_ADMIN' => 'app_admin_dashboard',
            'ROLE_PERSONNEL'   => 'app_creneau_agenda',
            'ROLE_AUDITEUR'    => 'app_creneaux_disponibles',
        ];

        $route = 'app_login'; // fallback : utilisateur sans rôle connu
        foreach ($redirectionsParRole as $role => $routeCible) {
            if ($this->isGranted($role)) {
                $route = $routeCible;
                break;
            }
        }

        return $this->redirectToRoute($route);
    }
}
