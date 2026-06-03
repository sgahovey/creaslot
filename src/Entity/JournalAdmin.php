<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TypeActionJournal;
use App\Repository\JournalAdminRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d'accountability des actions d'administration sur les comptes (US-5.5).
 *
 * Enregistrement append-only : jamais modifié ni supprimé via l'application — la
 * valeur de la trace réside dans son immutabilité. Acteur et cible sont FIGÉS
 * (id + libellé au moment de l'action), sans clé étrangère vivante : la trace
 * survit donc au renommage ou à la suppression des comptes concernés, et son
 * affichage ne nécessite aucun JOIN.
 *
 * RGPD : contient du nominatif (libellés) → finalité sécurité / accountability,
 * minimisation (aucune donnée sensible : ni mot de passe, ni motif), conservation
 * 12 mois (purge automatisée reportée, cf. DT-15).
 */
#[ORM\Entity(repositoryClass: JournalAdminRepository::class)]
#[ORM\Table(name: 'journal_admin')]
#[ORM\Index(columns: ['date_action'], name: 'idx_journal_admin_date')]
class JournalAdmin
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'date_action')]
    private \DateTimeImmutable $dateAction;

    #[ORM\Column(name: 'type_action', length: 40, enumType: TypeActionJournal::class)]
    private TypeActionJournal $typeAction;

    #[ORM\Column(name: 'acteur_id')]
    private int $acteurId;

    #[ORM\Column(name: 'acteur_libelle', length: 201)]
    private string $acteurLibelle;

    #[ORM\Column(name: 'cible_id', nullable: true)]
    private ?int $cibleId = null;

    #[ORM\Column(name: 'cible_libelle', length: 201, nullable: true)]
    private ?string $cibleLibelle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    public function __construct()
    {
        $this->dateAction = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateAction(): \DateTimeImmutable
    {
        return $this->dateAction;
    }

    public function getTypeAction(): TypeActionJournal
    {
        return $this->typeAction;
    }

    public function setTypeAction(TypeActionJournal $typeAction): static
    {
        $this->typeAction = $typeAction;

        return $this;
    }

    public function getActeurId(): int
    {
        return $this->acteurId;
    }

    public function setActeurId(int $acteurId): static
    {
        $this->acteurId = $acteurId;

        return $this;
    }

    public function getActeurLibelle(): string
    {
        return $this->acteurLibelle;
    }

    public function setActeurLibelle(string $acteurLibelle): static
    {
        $this->acteurLibelle = $acteurLibelle;

        return $this;
    }

    public function getCibleId(): ?int
    {
        return $this->cibleId;
    }

    public function setCibleId(?int $cibleId): static
    {
        $this->cibleId = $cibleId;

        return $this;
    }

    public function getCibleLibelle(): ?string
    {
        return $this->cibleLibelle;
    }

    public function setCibleLibelle(?string $cibleLibelle): static
    {
        $this->cibleLibelle = $cibleLibelle;

        return $this;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): static
    {
        $this->details = $details;

        return $this;
    }
}
