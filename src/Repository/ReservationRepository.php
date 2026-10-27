<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\Seance;
use App\Entity\User;
use App\Enum\ReservationChannel;
use App\Enum\ReservationStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<Reservation>
 */
class ReservationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Reservation::class);
    }

    /**
     * @return Reservation[]
     */
    public function findPendingForUser(User $user): array
    {
        return $this->createQueryBuilder('reservation')
            ->addSelect('seance', 'film', 'salle', 'billet', 'tarif')
            ->join('reservation.seance', 'seance')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->leftJoin('reservation.billets', 'billet')
            ->leftJoin('billet.tarif', 'tarif')
            ->andWhere('IDENTITY(reservation.user) = :userId')
            ->andWhere('reservation.status = :status')
            ->setParameter('userId', $user->getId(), UuidType::NAME)
            ->setParameter('status', ReservationStatus::PENDING)
            ->orderBy('reservation.reservedAt', SortDirection::Descending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Reservation[]
     */
    public function findForUserPage(User $user, int $page, int $pageSize = 25): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->createQueryBuilder('reservation')
                ->select('reservation.id')
                ->andWhere('IDENTITY(reservation.user) = :userId')
                ->andWhere('reservation.channel != :counterChannel')
                ->setParameter('userId', $user->getId(), UuidType::NAME)
                ->setParameter('counterChannel', ReservationChannel::COUNTER)
                ->orderBy('reservation.reservedAt', SortDirection::Descending)
                ->addOrderBy('reservation.id', SortDirection::Descending)
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
                ->getQuery()
                ->getScalarResult(),
        );

        if ([] === $ids) {
            return [];
        }

        $reservationsById = [];
        foreach ($this->createQueryBuilder('reservation')
            ->addSelect('seance', 'film', 'salle', 'billet', 'tarif')
            ->join('reservation.seance', 'seance')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->leftJoin('reservation.billets', 'billet')
            ->leftJoin('billet.tarif', 'tarif')
            ->andWhere('reservation.id IN (:ids)')
            ->andWhere('reservation.channel != :counterChannel')
            ->setParameter('ids', $ids)
            ->setParameter('counterChannel', ReservationChannel::COUNTER)
            ->getQuery()
            ->getResult() as $reservation) {
            $reservationsById[$reservation->getId()] = $reservation;
        }

        return array_values(array_filter(
            array_map(static fn (int $id): ?Reservation => $reservationsById[$id] ?? null, $ids),
        ));
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COUNT(reservation.id)')
            ->andWhere('IDENTITY(reservation.user) = :userId')
            ->andWhere('reservation.channel != :counterChannel')
            ->setParameter('userId', $user->getId(), UuidType::NAME)
            ->setParameter('counterChannel', ReservationChannel::COUNTER)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneForUserByReference(Ulid $reference, User $user): ?Reservation
    {
        return $this->createQueryBuilder('reservation')
            ->addSelect('seance', 'film', 'salle', 'billet', 'tarif')
            ->join('reservation.seance', 'seance')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->leftJoin('reservation.billets', 'billet')
            ->leftJoin('billet.tarif', 'tarif')
            ->andWhere('reservation.reference = :reference')
            ->andWhere('IDENTITY(reservation.user) = :userId')
            ->andWhere('reservation.channel != :counterChannel')
            ->setParameter('reference', $reference, UlidType::NAME)
            ->setParameter('userId', $user->getId(), UuidType::NAME)
            ->setParameter('counterChannel', ReservationChannel::COUNTER)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByReferenceCached(Ulid $reference): ?Reservation
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.reference = :reference')
            ->setParameter('reference', $reference, UlidType::NAME)
            ->getQuery()
            ->enableResultCache(60, 'reservation_payment_'.$reference)
            ->getOneOrNullResult();
    }

    /**
     * @return Reservation[]
     */
    public function findCounterReservations(int $page, int $pageSize): array
    {
        $ids = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->createQueryBuilder('reservation')
                ->select('reservation.id')
                ->andWhere('reservation.channel = :channel')
                ->setParameter('channel', ReservationChannel::COUNTER)
                ->orderBy('reservation.reservedAt', SortDirection::Descending)
                ->addOrderBy('reservation.id', SortDirection::Descending)
                ->setFirstResult(($page - 1) * $pageSize)
                ->setMaxResults($pageSize)
                ->getQuery()
                ->getScalarResult(),
        );

        if ([] === $ids) {
            return [];
        }

        $reservationsById = [];
        foreach ($this->createQueryBuilder('reservation')
            ->addSelect('user', 'seance', 'film', 'salle')
            ->join('reservation.user', 'user')
            ->join('reservation.seance', 'seance')
            ->join('seance.film', 'film')
            ->join('seance.salle', 'salle')
            ->andWhere('reservation.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult() as $reservation) {
            $reservationsById[$reservation->getId()] = $reservation;
        }

        return array_values(array_filter(
            array_map(static fn (int $id): ?Reservation => $reservationsById[$id] ?? null, $ids),
        ));
    }

    public function findCounterReservation(int $id): ?Reservation
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.id = :id')
            ->andWhere('reservation.channel = :channel')
            ->setParameter('id', $id)
            ->setParameter('channel', ReservationChannel::COUNTER)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countPendingSeatsForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.nbPlaces), 0)')
            ->andWhere('IDENTITY(reservation.user) = :userId')
            ->andWhere('reservation.status = :status')
            ->setParameter('userId', $user->getId(), UuidType::NAME)
            ->setParameter('status', ReservationStatus::PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countReservedSeatsForSeance(Seance $seance): int
    {
        return (int) $this->createQueryBuilder('reservation')
            ->select('COALESCE(SUM(reservation.nbPlaces), 0)')
            ->andWhere('IDENTITY(reservation.seance) = :seanceId')
            ->andWhere('reservation.status IN (:statuses)')
            ->setParameter('seanceId', $seance->getId())
            ->setParameter('statuses', array_map(
                static fn (ReservationStatus $status): string => $status->value,
                array_filter(
                    ReservationStatus::cases(),
                    static fn (ReservationStatus $status): bool => $status->reservesSeats()
                )
            ))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Reservation[]
     */
    public function findPendingBefore(DateTimeImmutable $cutoff): array
    {
        return $this->createQueryBuilder('reservation')
            ->andWhere('reservation.status = :status')
            ->andWhere('reservation.reservedAt <= :cutoff')
            ->setParameter('status', ReservationStatus::PENDING)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('reservation.reservedAt', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return Reservation[] Returns an array of Reservation objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('r.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Reservation
//    {
//        return $this->createQueryBuilder('r')
//            ->andWhere('r.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
