<?php

namespace App\Entity;

use App\Enum\SeanceStatus;
use App\Repository\SeanceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeanceRepository::class)]
#[ORM\Table(name: 'seance')]
#[ORM\Index(name: 'IDX_SEANCE_SALLE_DATE_TIME', columns: ['salle_id', 'date_time'])]
#[ORM\Index(name: 'IDX_SEANCE_DATE_TIME', columns: ['date_time'])]
#[ORM\Index(name: 'IDX_SEANCE_STATUS_DATE_TIME', columns: ['status', 'date_time'])]
#[ORM\Index(name: 'IDX_SEANCE_STATUS_DATE_ROOM', columns: ['status', 'date_time', 'salle_id'])]
class Seance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?\DateTime $dateTime = null;

    #[ORM\Column(length: 20, enumType: SeanceStatus::class, options: ['default' => 'draft'])]
    private SeanceStatus $status = SeanceStatus::DRAFT;

    #[ORM\ManyToOne(inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Film $film = null;

    #[ORM\ManyToOne(inversedBy: 'seances')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Salle $salle = null;

    /**
     * @var Collection<int, Reservation>
     */
    #[ORM\OneToMany(targetEntity: Reservation::class, mappedBy: 'seance')]
    private Collection $reservations;

    public function __construct()
    {
        $this->reservations = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateTime(): ?\DateTime
    {
        return $this->dateTime;
    }

    public function setDateTime(\DateTime $dateTime): static
    {
        $this->dateTime = $dateTime;

        return $this;
    }

    public function getStatus(): SeanceStatus
    {
        return $this->status;
    }

    public function setStatus(SeanceStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getWorkflowStatus(): string
    {
        return $this->status->value;
    }

    public function setWorkflowStatus(string $status): void
    {
        $this->status = SeanceStatus::from($status);
    }

    public function getFilm(): ?Film
    {
        return $this->film;
    }

    public function setFilm(?Film $film): static
    {
        $this->film = $film;

        return $this;
    }

    public function getSalle(): ?Salle
    {
        return $this->salle;
    }

    public function setSalle(?Salle $salle): static
    {
        $this->salle = $salle;

        return $this;
    }

    /**
     * @return Collection<int, Reservation>
     */
    public function getReservations(): Collection
    {
        return $this->reservations;
    }

    public function addReservation(Reservation $reservation): static
    {
        if (!$this->reservations->contains($reservation)) {
            $this->reservations->add($reservation);
            $reservation->setSeance($this);
        }

        return $this;
    }

    public function removeReservation(Reservation $reservation): static
    {
        if ($this->reservations->removeElement($reservation)) {
            // set the owning side to null (unless already changed)
            if ($reservation->getSeance() === $this) {
                $reservation->setSeance(null);
            }
        }

        return $this;
    }
}
