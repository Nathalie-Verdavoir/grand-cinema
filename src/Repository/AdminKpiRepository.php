<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class AdminKpiRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return list<array{screeningDateTime: DateTimeImmutable|string, entries: int|string, revenue: string|null, reservations: int|string, isCounter: int|string}>
     */
    public function findSalesByScreening(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select(
                'seance.dateTime AS screeningDateTime',
                'COUNT(billet.id) AS entries',
                'SUM(billet.priceApplied) AS revenue',
                'COUNT(DISTINCT reservation.id) AS reservations',
                'CASE WHEN reservation.channel = :counterChannel THEN 1 ELSE 0 END AS isCounter'
            )
            ->from(Seance::class, 'seance')
            ->join('seance.reservations', 'reservation')
            ->join('reservation.billets', 'billet')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :until')
            ->andWhere('seance.status = :publishedStatus')
            ->andWhere('reservation.status IN (:paidStatus, :counterStatus)')
            ->groupBy('seance.dateTime, reservation.status, reservation.channel')
            ->orderBy('seance.dateTime', 'ASC')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('publishedStatus', SeanceStatus::PUBLISHED)
            ->setParameter('paidStatus', ReservationStatus::PAID)
            ->setParameter('counterStatus', ReservationStatus::PAID_AT_COUNTER)
            ->setParameter('counterChannel', ReservationChannel::COUNTER)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return list<array{filmTitle: string, entries: int|string, revenue: string|null}>
     */
    public function findSalesByFilm(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('film.title AS filmTitle', 'COUNT(billet.id) AS entries', 'SUM(billet.priceApplied) AS revenue')
            ->from(Seance::class, 'seance')
            ->join('seance.film', 'film')
            ->join('seance.reservations', 'reservation')
            ->join('reservation.billets', 'billet')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :until')
            ->andWhere('seance.status = :publishedStatus')
            ->andWhere('reservation.status IN (:paidStatus, :counterStatus)')
            ->groupBy('film.id, film.title')
            ->orderBy('entries', 'DESC')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('publishedStatus', SeanceStatus::PUBLISHED)
            ->setParameter('paidStatus', ReservationStatus::PAID)
            ->setParameter('counterStatus', ReservationStatus::PAID_AT_COUNTER)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return list<array{screeningId: int|string, roomId: int|string, capacity: int|string}>
     */
    public function findPublishedScreeningCapacities(DateTimeImmutable $from, DateTimeImmutable $until): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('seance.id AS screeningId', 'salle.id AS roomId', 'salle.capacity AS capacity')
            ->from(Seance::class, 'seance')
            ->join('seance.salle', 'salle')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :until')
            ->andWhere('seance.status = :publishedStatus')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('publishedStatus', SeanceStatus::PUBLISHED)
            ->getQuery()
            ->getArrayResult();
    }
}
