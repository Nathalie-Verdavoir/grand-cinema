<?php

namespace App\Entity;

use App\Repository\BilletRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: BilletRepository::class)]
class Billet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'billets')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Reservation $reservation = null;

    #[ORM\ManyToOne(inversedBy: 'billets')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tarif $tarif = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 7, scale: 2)]
    private ?string $priceApplied = null;

    // seatNumber can be a string like "A1", "B2", etc. It represents the seat assigned to this ticket.
    // It can be null if the seat assignment is not applicable or not yet assigned. The length of 10 is arbitrary but should be sufficient for most seat numbering schemes.
    #[ORM\Column(length: 10, nullable: true)]
    private ?string $seatNumber = null;

    #[ORM\Column(type: 'ulid')]
    private ?Ulid $reference = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTarif(): ?Tarif
    {
        return $this->tarif;
    }

    public function setTarif(?Tarif $tarif): static
    {
        $this->tarif = $tarif;

        return $this;
    }

    public function getPriceApplied(): ?string
    {
        return $this->priceApplied;
    }

    public function setPriceApplied(string $priceApplied): static
    {
        $this->priceApplied = $priceApplied;

        return $this;
    }

    public function getSeatNumber(): ?string
    {
        return $this->seatNumber;
    }

    public function setSeatNumber(string $seatNumber): static
    {
        $this->seatNumber = $seatNumber;

        return $this;
    }

    public function getReference(): ?Ulid
    {
        return $this->reference;
    }

    public function setReference(Ulid $reference): static
    {
        $this->reference = $reference;

        return $this;
    }
}
