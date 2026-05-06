<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutCreneau;
use App\Repository\CreneauRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreneauRepository::class)]
#[ORM\Table(name: 'creneau')]
#[ORM\Index(columns: ['id_personnel', 'debut_at'], name: 'idx_creneau_personnel_debut')]
#[ORM\Index(columns: ['statut'], name: 'idx_creneau_statut')]
#[ORM\HasLifecycleCallbacks]
class Creneau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(name: 'id_personnel', nullable: false)]
    private Utilisateur $personnel;

    #[ORM\ManyToOne(targetEntity: Service::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(name: 'id_service', nullable: false)]
    private Service $service;

    #[ORM\ManyToOne(targetEntity: TypeRdv::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(name: 'id_type_rdv', nullable: false)]
    private TypeRdv $typeRdv;

    #[ORM\Column]
    private \DateTimeImmutable $debutAt;

    #[ORM\Column]
    private \DateTimeImmutable $finAt;

    #[ORM\Column(length: 20, enumType: StatutCreneau::class)]
    private StatutCreneau $statut = StatutCreneau::DISPONIBLE;

    #[ORM\Column]
    private bool $estActif = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToOne(targetEntity: Reservation::class, mappedBy: 'creneau')]
    private ?Reservation $reservation = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function majUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPersonnel(): Utilisateur
    {
        return $this->personnel;
    }

    public function setPersonnel(Utilisateur $personnel): static
    {
        $this->personnel = $personnel;

        return $this;
    }

    public function getService(): Service
    {
        return $this->service;
    }

    public function setService(Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    public function getTypeRdv(): TypeRdv
    {
        return $this->typeRdv;
    }

    public function setTypeRdv(TypeRdv $typeRdv): static
    {
        $this->typeRdv = $typeRdv;

        return $this;
    }

    public function getDebutAt(): \DateTimeImmutable
    {
        return $this->debutAt;
    }

    public function setDebutAt(\DateTimeImmutable $debutAt): static
    {
        $this->debutAt = $debutAt;

        return $this;
    }

    public function getFinAt(): \DateTimeImmutable
    {
        return $this->finAt;
    }

    public function setFinAt(\DateTimeImmutable $finAt): static
    {
        $this->finAt = $finAt;

        return $this;
    }

    public function getStatut(): StatutCreneau
    {
        return $this->statut;
    }

    public function setStatut(StatutCreneau $statut): static
    {
        $this->statut = $statut;

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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function setReservation(?Reservation $reservation): static
    {
        $this->reservation = $reservation;

        return $this;
    }

    public function estDisponible(): bool
    {
        return $this->statut === StatutCreneau::DISPONIBLE && $this->estActif;
    }
}
