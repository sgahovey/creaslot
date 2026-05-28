<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StatutReservation;
use App\Repository\ReservationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
#[ORM\Table(name: 'reservation')]
#[ORM\Index(columns: ['statut'], name: 'idx_reservation_statut')]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Creneau::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'id_creneau', nullable: false)]
    private Creneau $creneau;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class, inversedBy: 'reservations')]
    #[ORM\JoinColumn(name: 'id_utilisateur', nullable: false)]
    private Utilisateur $utilisateur;

    #[ORM\Column(name: 'date_reservation')]
    private \DateTimeImmutable $dateReservation;

    #[ORM\Column(name: 'commentaire_auditeur', type: 'text', nullable: true)]
    private ?string $commentaireAuditeur = null;

    #[ORM\Column(length: 20, enumType: StatutReservation::class)]
    private StatutReservation $statut = StatutReservation::ACTIVE;

    #[ORM\Column(name: 'date_annulation', nullable: true)]
    private ?\DateTimeImmutable $dateAnnulation = null;

    #[ORM\Column(name: 'motif_annulation', type: 'text', nullable: true)]
    private ?string $motifAnnulation = null;

    #[ORM\Column(name: 'rappel_envoye_at', nullable: true)]
    private ?\DateTimeImmutable $rappelEnvoyeAt = null;

    public function __construct()
    {
        $this->dateReservation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreneau(): Creneau
    {
        return $this->creneau;
    }

    public function setCreneau(Creneau $creneau): static
    {
        $this->creneau = $creneau;

        return $this;
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

    public function getDateReservation(): \DateTimeImmutable
    {
        return $this->dateReservation;
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

    public function getStatut(): StatutReservation
    {
        return $this->statut;
    }

    public function setStatut(StatutReservation $statut): static
    {
        $this->statut = $statut;

        return $this;
    }

    public function getDateAnnulation(): ?\DateTimeImmutable
    {
        return $this->dateAnnulation;
    }

    public function setDateAnnulation(?\DateTimeImmutable $dateAnnulation): static
    {
        $this->dateAnnulation = $dateAnnulation;

        return $this;
    }

    public function getMotifAnnulation(): ?string
    {
        return $this->motifAnnulation;
    }

    public function setMotifAnnulation(?string $motifAnnulation): static
    {
        $this->motifAnnulation = $motifAnnulation;

        return $this;
    }

    /**
     * Une réservation est annulable si elle est encore active ET que le créneau
     * n'a pas encore commencé. Cette règle métier vit ici (Domain-Driven Design),
     * le contrôleur d'annulation s'y réfère sans la dupliquer.
     */
    public function isAnnulable(): bool
    {
        return $this->statut === StatutReservation::ACTIVE
            && $this->creneau->getDateDebut() > new \DateTimeImmutable();
    }

    /**
     * Transition d'état atomique : annule la réservation et trace la date + le motif.
     * Le contrôleur appelant DOIT avoir validé l'annulabilité au préalable via
     * isAnnulable() — cette méthode ne re-valide pas (laissée aux invariants futurs).
     */
    public function annuler(?string $motif): void
    {
        $this->statut          = StatutReservation::ANNULEE;
        $this->dateAnnulation  = new \DateTimeImmutable();
        $this->motifAnnulation = $motif;
    }

    public function getRappelEnvoyeAt(): ?\DateTimeImmutable
    {
        return $this->rappelEnvoyeAt;
    }

    public function setRappelEnvoyeAt(?\DateTimeImmutable $rappelEnvoyeAt): static
    {
        $this->rappelEnvoyeAt = $rappelEnvoyeAt;

        return $this;
    }
}
