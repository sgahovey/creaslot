<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TypeRdvRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TypeRdvRepository::class)]
#[ORM\Table(name: 'type_rdv')]
class TypeRdv
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $libelle;

    #[ORM\Column(length: 7)]
    private string $couleurHex;

    #[ORM\Column]
    private bool $estActif = true;

    /** @var Collection<int, Creneau> */
    #[ORM\OneToMany(targetEntity: Creneau::class, mappedBy: 'typeRdv')]
    private Collection $creneaux;

    public function __construct()
    {
        $this->creneaux = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLibelle(): string
    {
        return $this->libelle;
    }

    public function setLibelle(string $libelle): static
    {
        $this->libelle = $libelle;

        return $this;
    }

    public function getCouleurHex(): string
    {
        return $this->couleurHex;
    }

    public function setCouleurHex(string $couleurHex): static
    {
        $this->couleurHex = $couleurHex;

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

    /** @return Collection<int, Creneau> */
    public function getCreneaux(): Collection
    {
        return $this->creneaux;
    }

    public function addCreneau(Creneau $creneau): static
    {
        if (!$this->creneaux->contains($creneau)) {
            $this->creneaux->add($creneau);
            $creneau->setTypeRdv($this);
        }

        return $this;
    }

    public function removeCreneau(Creneau $creneau): static
    {
        $this->creneaux->removeElement($creneau);

        return $this;
    }
}
