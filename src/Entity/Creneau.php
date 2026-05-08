<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutReservation;
use App\Repository\CreneauRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CreneauRepository::class)]
#[ORM\Table(name: 'creneau')]
#[ORM\Index(columns: ['id_utilisateur', 'date_debut'], name: 'idx_creneau_utilisateur_debut')]
class Creneau
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(name: 'id_utilisateur', nullable: false)]
    private Utilisateur $utilisateur;

    #[ORM\ManyToOne(targetEntity: TypeRdv::class, inversedBy: 'creneaux')]
    #[ORM\JoinColumn(name: 'id_type_rdv', nullable: false)]
    private TypeRdv $typeRdv;

    #[ORM\Column(name: 'date_debut')]
    private \DateTimeImmutable $dateDebut;

    #[ORM\Column(name: 'date_fin')]
    private \DateTimeImmutable $dateFin;

    #[ORM\Column(name: 'commentaire_auditeur', type: 'text', nullable: true)]
    private ?string $commentaireAuditeur = null;

    #[ORM\Column(name: 'date_creation')]
    private \DateTimeImmutable $dateCreation;

    #[ORM\Column]
    private bool $estActif = true;

    #[ORM\OneToOne(targetEntity: Reservation::class, mappedBy: 'creneau')]
    private ?Reservation $reservation = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUtilisateur(): Utilisateur
    {
        return $this->utilisateur;
    }

    public function setUtilisateur(Utilisateur $utilisateur): static
    {
        $this->utilisateur = $utilisateur;

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

    public function getDateDebut(): \DateTimeImmutable
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTimeImmutable $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): \DateTimeImmutable
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTimeImmutable $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getCommentaireAuditeur(): ?string
    {
        return $this->commentaireAuditeur;
    }

    public function setCommentaireAuditeur(?string $commentaireAuditeur): static
    {
        $this->commentaireAuditeur = $commentaireAuditeur;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
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

    public function getReservation(): ?Reservation
    {
        return $this->reservation;
    }

    public function isPasse(): bool
    {
        return $this->dateFin < new \DateTimeImmutable();
    }

    public function isReserve(): bool
    {
        return $this->reservation !== null
            && $this->reservation->getStatut() === StatutReservation::ACTIVE;
    }

    public function isDisponible(): bool
    {
        return $this->estActif && !$this->isPasse() && !$this->isReserve();
    }

    public function getAuditeurReservation(): ?Utilisateur
    {
        if (!$this->isReserve()) {
            return null;
        }

        return $this->reservation->getUtilisateur();
    }
}
