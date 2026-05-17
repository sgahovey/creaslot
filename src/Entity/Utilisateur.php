<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RoleUtilisateur;
use App\Repository\UtilisateurRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UtilisateurRepository::class)]
#[ORM\Table(name: 'utilisateur')]
#[ORM\UniqueConstraint(name: 'UNIQ_utilisateur_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Une erreur est survenue, veuillez réessayer.')]
class Utilisateur implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre adresse email.')]
    #[Assert\Email(message: 'Veuillez saisir une adresse email valide.')]
    #[Assert\Length(max: 180)]
    private string $email;

    #[ORM\Column(name: 'mot_de_passe_hash', length: 255)]
    private string $motDePasseHash;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre nom.')]
    #[Assert\Length(min: 2, max: 100)]
    private string $nom;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Veuillez saisir votre prénom.')]
    #[Assert\Length(min: 2, max: 100)]
    private string $prenom;

    #[ORM\Column(length: 30, enumType: RoleUtilisateur::class)]
    private RoleUtilisateur $role;

    #[ORM\Column(name: 'date_creation')]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column(name: 'derniere_connexion', nullable: true)]
    private ?\DateTimeImmutable $derniereConnexion = null;

    #[ORM\Column]
    private bool $estActif = true;

    #[ORM\ManyToOne(targetEntity: Service::class, inversedBy: 'utilisateurs')]
    #[ORM\JoinColumn(name: 'id_service', nullable: true)]
    private ?Service $service = null;

    /** @var Collection<int, Creneau> */
    #[ORM\OneToMany(targetEntity: Creneau::class, mappedBy: 'utilisateur')]
    private Collection $creneaux;

    /** @var Collection<int, Reservation> */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'utilisateur')]
    private Collection $reservations;

    public function __construct()
    {
        $this->creneaux     = new ArrayCollection();
        $this->reservations = new ArrayCollection();
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    /**
     * Identifiant utilisé par Symfony Security pour authentifier l'utilisateur.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Retourne les rôles compatibles Symfony Security.
     * ROLE_USER est toujours inclus comme base.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return array_unique([$this->role->value, 'ROLE_USER']);
    }

    public function getRole(): RoleUtilisateur
    {
        return $this->role;
    }

    public function setRole(RoleUtilisateur $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->motDePasseHash;
    }

    public function getMotDePasseHash(): string
    {
        return $this->motDePasseHash;
    }

    public function setMotDePasseHash(string $motDePasseHash): static
    {
        $this->motDePasseHash = $motDePasseHash;

        return $this;
    }

    /**
     * Efface les données sensibles après authentification (pas de plaintext stocké).
     */
    public function eraseCredentials(): void
    {
        // Aucun mot de passe en clair stocké — rien à effacer.
    }

    public function getNom(): string
    {
        return $this->nom;
    }

    public function setNom(string $nom): static
    {
        $this->nom = $nom;

        return $this;
    }

    public function getPrenom(): string
    {
        return $this->prenom;
    }

    public function setPrenom(string $prenom): static
    {
        $this->prenom = $prenom;

        return $this;
    }

    /**
     * Retourne le nom complet de l'utilisateur sous la forme "Prénom Nom".
     *
     * Utilisé principalement dans les notifications email et les vues
     * où l'identité complète est requise (templates emails US-4.2,
     * agenda Personnel, futur dashboard Super-admin).
     *
     * @return string Le prénom et le nom concaténés par un espace simple.
     */
    public function getNomComplet(): string
    {
        return $this->prenom . ' ' . $this->nom;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
    }

    public function getDerniereConnexion(): ?\DateTimeImmutable
    {
        return $this->derniereConnexion;
    }

    public function setDerniereConnexion(?\DateTimeImmutable $derniereConnexion): static
    {
        $this->derniereConnexion = $derniereConnexion;

        return $this;
    }

    public function isEstActif(): bool
    {
        return $this->estActif;
    }

    public function setEstActif(bool $estActif): static
    {
        $this->estActif = $estActif;

        return $this;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(?Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    /** @return Collection<int, Creneau> */
    public function getCreneaux(): Collection
    {
        return $this->creneaux;
    }

    public function addCreneau(Creneau $creneau): static
    {
        if (!$this->creneaux->contains($creneau)) {
            $this->creneaux->add($creneau);
            $creneau->setUtilisateur($this);
        }

        return $this;
    }

    public function removeCreneau(Creneau $creneau): static
    {
        $this->creneaux->removeElement($creneau);

        return $this;
    }

    /** @return Collection<int, Reservation> */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setUtilisateur($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        $this->reservations->removeElement($reservation);

        return $this;
    }
}
