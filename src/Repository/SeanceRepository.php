<?php

namespace App\Repository;

use App\Entity\Salle;
use App\Entity\Seance;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Seance>
 */
class SeanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Seance::class);
    }

    /**
     * @return Seance[]
     */
    public function findPublishedBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('seance')
            ->addSelect('film', 'salle')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :to')
            ->andWhere('seance.status = :status')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Seance[]
     */
    public function findPublishedFrom(DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('seance')
            ->addSelect('film', 'salle')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.status = :status')
            ->setParameter('from', $from)
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<array{screeningId: int|string, screeningDateTime: \DateTimeInterface|string, filmTitle: string, roomName: string, capacity: int|string, reservedSeats: int|string}>
     */
    public function findPublishedOccupanciesBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $reservedStatuses = array_map(
            static fn (ReservationStatus $status): string => $status->value,
            array_filter(
                ReservationStatus::cases(),
                static fn (ReservationStatus $status): bool => $status->reservesSeats()
            )
        );

        return $this->createQueryBuilder('seance')
            ->select(
                'seance.id AS screeningId',
                'seance.dateTime AS screeningDateTime',
                'film.title AS filmTitle',
                'salle.name AS roomName',
                'salle.capacity AS capacity',
                'COALESCE(SUM(CASE WHEN reservation.status IN (:reservedStatuses) THEN reservation.nbPlaces ELSE 0 END), 0) AS reservedSeats'
            )
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->leftJoin('seance.reservations', 'reservation')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :to')
            ->andWhere('seance.status = :status')
            ->groupBy('seance.id, seance.dateTime, film.id, film.title, salle.id, salle.name, salle.capacity')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->setParameter('reservedStatuses', $reservedStatuses)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * @return Seance[]
     */
    public function findPublishedForFilmFrom(int $filmId, DateTimeImmutable $from): array
    {
        return $this->createQueryBuilder('seance')
            ->addSelect('film', 'salle')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->andWhere('film.id = :filmId')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.status = :status')
            ->setParameter('filmId', $filmId)
            ->setParameter('from', $from)
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<DateTimeImmutable>
     */
    public function findPublishedDateTimesFrom(DateTimeImmutable $from): array
    {
        $rows = $this->createQueryBuilder('seance')
            ->select('DISTINCT seance.dateTime AS screeningDateTime')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.status = :status')
            ->setParameter('from', $from)
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static function (array $row): DateTimeImmutable {
                $dateTime = $row['screeningDateTime'];

                if ($dateTime instanceof DateTimeImmutable) {
                    return $dateTime;
                }
                if ($dateTime instanceof \DateTimeInterface) {
                    return DateTimeImmutable::createFromInterface($dateTime);
                }

                return new DateTimeImmutable((string) $dateTime);
            },
            $rows
        );
    }

    /**
     * @return Seance[]
     */
    public function findForRoomBetween(Salle $salle, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('seance')
            ->addSelect('film', 'reservation')
            ->join('seance.film', 'film')
            ->leftJoin('seance.reservations', 'reservation')
            ->andWhere('seance.salle = :salle')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :to')
            ->setParameter('salle', $salle)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Seance[]
     */
    public function findBetween(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('seance')
            ->addSelect('film', 'salle', 'reservation')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->leftJoin('seance.reservations', 'reservation')
            ->andWhere('seance.dateTime >= :from')
            ->andWhere('seance.dateTime < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('salle.name', SortDirection::Ascending)
            ->addOrderBy('seance.dateTime', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    //    /**
    //     * @return Seance[] Returns an array of Seance objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('s.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Seance
    //    {
    //        return $this->createQueryBuilder('s')
    //            ->andWhere('s.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
