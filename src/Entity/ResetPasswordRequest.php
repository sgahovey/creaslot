<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ResetPasswordRequestRepository;
use Doctrine\ORM\Mapping as ORM;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestInterface;
use SymfonyCasts\Bundle\ResetPassword\Model\ResetPasswordRequestTrait;

/**
 * Demande de réinitialisation de mot de passe (US-6.2).
 *
 * Persistée par le ResetPasswordBundle : le jeton n'est jamais stocké en clair
 * (le trait conserve `selector` + `hashedToken`), avec `requestedAt`/`expiresAt`
 * pour la durée de vie et l'usage unique (la demande est supprimée après emploi).
 *
 * Le nom de méthode `getUser()` est imposé par ResetPasswordRequestInterface ; la
 * colonne FK suit en revanche la convention projet (`id_utilisateur`). PK/FK en
 * INT pour rester aligné sur le schéma réel (`utilisateur.id` est un INT).
 */
#[ORM\Entity(repositoryClass: ResetPasswordRequestRepository::class)]
#[ORM\Table(name: 'reset_password_request')]
class ResetPasswordRequest implements ResetPasswordRequestInterface
{
    use ResetPasswordRequestTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'id_utilisateur', nullable: false)]
    private ?Utilisateur $user = null;

    public function __construct(Utilisateur $user, \DateTimeInterface $expiresAt, string $selector, string $hashedToken)
    {
        $this->user = $user;
        $this->initialize($expiresAt, $selector, $hashedToken);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): Utilisateur
    {
        return $this->user;
    }
}
