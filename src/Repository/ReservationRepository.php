<?php

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\ReservationStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

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
            ->orderBy('reservation.reservedAt', 'DESC')
            ->getQuery()
            ->getResult();
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
            ->orderBy('reservation.reservedAt', 'ASC')
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
