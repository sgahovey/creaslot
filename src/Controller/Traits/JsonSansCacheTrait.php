<?php

declare(strict_types=1);

namespace App\Controller\Traits;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait JsonSansCacheTrait
{
    /**
     * Réponse JSON non mise en cache : un agenda d'administration doit refléter
     * l'état courant immédiatement après une édition. Un `max-age` positif faisait
     * resservir par le navigateur une copie périmée (ancien type/couleur) après
     * modification d'un créneau.
     *
     * @param array<int|string, mixed> $donnees
     */
    private function jsonSansCache(array $donnees, int $statut = Response::HTTP_OK): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
