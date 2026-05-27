<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutReservation;
use App\Repository\CreneauRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    /**
     * Reservations associées à ce Creneau (historique complet).
     *
     * 1 Creneau peut avoir 0..N Reservations au fil du temps :
     * - 0..1 Reservation ACTIVE (invariant applicatif via PESSIMISTIC_WRITE
     *   dans ReservationController::enregistrerReservation)
     * - 0..N Reservations ANNULEE (historique RGPD, audit trail)
     *
     * Pour accéder rapidement à la Reservation ACTIVE (cas UI principal),
     * utiliser getReservationActive() : ?Reservation.
     *
     * Apparition : refacto DT-1 (OneToOne → OneToMany), 19/05/2026.
     *
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'creneau')]
    private Collection $reservations;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
        $this->reservations = new ArrayCollection();
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

    /**
     * Retourne toutes les Reservations associées à ce Creneau (historique complet,
     * incluant ACTIVE et ANNULEE).
     *
     * Note : 1 Creneau peut avoir 0..N Reservations au fil du temps :
     * - 0..1 Reservation ACTIVE (invariant applicatif via PESSIMISTIC_WRITE)
     * - 0..N Reservations ANNULEE (historique, RGPD audit trail)
     *
     * Pour récupérer la Reservation ACTIVE (cas UI le plus fréquent), utiliser
     * getReservationActive() qui retourne ?Reservation et filtre par statut.
     *
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    /**
     * Retourne la Reservation ACTIVE associée à ce Creneau, ou null si aucune
     * (créneau libre ou toutes les Reservations passées sont annulées).
     *
     * Invariant garanti : au plus 1 Reservation ACTIVE par Creneau, grâce au
     * lock PESSIMISTIC_WRITE dans ReservationController::enregistrerReservation
     * (cf. Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE).
     *
     * Utilisé par : isReserve(), getAuditeurReservation(), templates Twig,
     * controllers (suppression/annulation), services (NotificationService,
     * CreneauCalendarSerializer).
     *
     * Apparition : refacto DT-1 (OneToOne → OneToMany), 19/05/2026.
     */
    public function getReservationActive(): ?Reservation
    {
        foreach ($this->reservations as $reservation) {
            if ($reservation->getStatut() === StatutReservation::ACTIVE) {
                return $reservation;
            }
        }

        return null;
    }

    public function isPasse(): bool
    {
        return $this->dateFin < new \DateTimeImmutable();
    }

    public function isReserve(): bool
    {
        return $this->getReservationActive() !== null;
    }

    public function isDisponible(): bool
    {
        return $this->estActif && !$this->isPasse() && !$this->isReserve();
    }

    public function getAuditeurReservation(): ?Utilisateur
    {
        return $this->getReservationActive()?->getUtilisateur();
    }
}
