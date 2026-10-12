<?php

namespace App\Repository;

use App\Entity\Salle;
use App\Entity\Seance;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

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
            ->orderBy('seance.dateTime', 'ASC')
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
            ->orderBy('salle.name', 'ASC')
            ->addOrderBy('seance.dateTime', 'ASC')
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
