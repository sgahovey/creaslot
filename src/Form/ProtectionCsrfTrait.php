<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Mutualise la configuration CSRF commune à tous les formulaires de l'application.
 *
 * La protection CSRF est une politique transverse strictement identique partout : elle
 * reste toujours active, conformément à la stratégie de sécurité du projet qui interdit
 * de la désactiver. La répéter dans chaque configureOptions() produisait douze copies du
 * même couple d'options ; ce trait la centralise en un point unique, ce qui supprime la
 * duplication et garantit qu'un éventuel changement de politique reste cohérent partout.
 *
 * L'identifiant de jeton (csrf_token_id) reste en revanche PROPRE à chaque formulaire et
 * doit donc être fourni par l'appelant : ce jeton cantonne la validation CSRF au seul
 * formulaire qui l'a émis. Un identifiant distinct par formulaire empêche qu'un jeton
 * obtenu sur l'un puisse en valider un autre ; le mutualiser reviendrait à partager un
 * jeton unique et à affaiblir la protection. Il demeure donc un paramètre obligatoire,
 * jamais une valeur figée dans le trait.
 */
trait ProtectionCsrfTrait
{
    /**
     * Active la protection CSRF avec l'identifiant de jeton propre au formulaire appelant.
     *
     * @param string $csrfTokenId Identifiant de jeton unique à ce formulaire (jetons distincts)
     */
    private function configurerProtectionCsrf(OptionsResolver $resolver, string $csrfTokenId): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => $csrfTokenId,
        ]);
    }
}
