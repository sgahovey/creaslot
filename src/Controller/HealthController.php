<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Traits\JsonSansCacheTrait;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Healthcheck public consommé par la supervision (Uptime Kuma).
 *
 * Il vérifie deux choses : la « liveness » (l'application répond) et la
 * « readiness » de la base de données (un `SELECT 1` aboutit). Il renvoie 503
 * (Service Unavailable) dès que la base est injoignable, ce qui permet à la
 * sonde de distinguer « application debout mais BDD KO » d'un simple « app KO ».
 *
 * L'endpoint ne divulgue volontairement aucun détail technique (pas de version,
 * pas de message d'exception, pas de stack trace) : exposé publiquement, il ne
 * doit pas servir de vecteur de reconnaissance pour un attaquant.
 */
final class HealthController extends AbstractController
{
    use JsonSansCacheTrait;

    public function __construct(private readonly Connection $connection)
    {
    }

    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        try {
            $this->connection->executeQuery('SELECT 1');
            $bdd = 'ok';
        } catch (\Throwable) {
            $bdd = 'error';
        }

        $statut = $bdd === 'ok' ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return $this->jsonSansCache([
            'status' => $bdd === 'ok' ? 'ok' : 'error',
            'checks' => ['database' => $bdd],
        ], $statut);
    }
}
