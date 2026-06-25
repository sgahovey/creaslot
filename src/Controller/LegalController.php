<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légales publiques (US-10.1) : mentions légales, CGU, politique de
 * confidentialité et déclaration d'accessibilité RGAA. Accessibles sans
 * authentification (cf. access_control dans security.yaml).
 */
final class LegalController extends AbstractController
{
    #[Route('/mentions-legales', name: 'app_mentions_legales', methods: ['GET'])]
    public function mentionsLegales(): Response
    {
        return $this->render('legal/mentions_legales.html.twig');
    }

    #[Route('/conditions-generales', name: 'app_cgu', methods: ['GET'])]
    public function cgu(): Response
    {
        return $this->render('legal/cgu.html.twig');
    }

    #[Route('/confidentialite', name: 'app_confidentialite', methods: ['GET'])]
    public function confidentialite(): Response
    {
        return $this->render('legal/confidentialite.html.twig');
    }

    #[Route('/accessibilite', name: 'app_accessibilite', methods: ['GET'])]
    public function accessibilite(): Response
    {
        return $this->render('legal/accessibilite.html.twig');
    }
}
