<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TypeNotification;
use App\Repository\NotificationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificationRepository::class)]
#[ORM\Table(name: 'notification')]
#[ORM\Index(columns: ['id_destinataire', 'lu'], name: 'idx_notification_destinataire_lu')]
class Notification
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Utilisateur::class)]
    #[ORM\JoinColumn(name: 'id_destinataire', nullable: false)]
    private Utilisateur $destinataire;

    #[ORM\Column(length: 30, enumType: TypeNotification::class)]
    private TypeNotification $type;

    #[ORM\Column(length: 255)]
    private string $titre;

    #[ORM\Column(type: 'text')]
    private string $message;

    #[ORM\Column]
    private bool $lu = false;

    #[ORM\Column(name: 'date_creation')]
    private \DateTimeImmutable $dateCreation;

    /**
     * Lien optionnel vers la Reservation à l'origine de la notification.
     *
     * Nullable + onDelete SET NULL pour :
     * - survivre à une suppression RGPD de la Reservation (la Notification reste
     *   lisible grâce à ses champs titre/message autonomes) ;
     * - autoriser des notifications futures non liées à une Reservation.
     */
    #[ORM\ManyToOne(targetEntity: Reservation::class)]
    #[ORM\JoinColumn(name: 'id_reservation', nullable: true, onDelete: 'SET NULL')]
    private ?Reservation $reservation = null;

    public function __construct()
    {
        $this->dateCreation = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDestinataire(): Utilisateur
    {
        return $this->destinataire;
    }

    public function setDestinataire(Utilisateur $destinataire): static
    {
        $this->destinataire = $destinataire;

        return $this;
    }

    public function getType(): TypeNotification
    {
        return $this->type;
    }

    public function setType(TypeNotification $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTitre(): string
    {
        return $this->titre;
    }

    public function setTitre(string $titre): static
    {
        $this->titre = $titre;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function isLu(): bool
    {
        return $this->lu;
    }

    public function setLu(bool $lu): static
    {
        $this->lu = $lu;

        return $this;
    }

    public function getDateCreation(): \DateTimeImmutable
    {
        return $this->dateCreation;
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
}
