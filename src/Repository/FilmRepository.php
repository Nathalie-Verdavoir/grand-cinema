<?php

namespace App\Repository;

use App\Entity\Film;
use App\Entity\Seance;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Film>
 */
class FilmRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Film::class);
    }

    /**
     * @return Film[]
     */
    public function findInCurrentProgram(): array
    {
        return $this->createQueryBuilder('film')
            ->select('film', 'seance', 'salle')
            ->join('film.seances', 'seance')
            ->join('seance.salle', 'salle')
            ->andWhere('seance.dateTime >= :now')
            ->andWhere('seance.status = :status')
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findInCurrentProgramById(int $id): ?Film
    {
        return $this->createQueryBuilder('film')
            ->select('film', 'seance', 'salle')
            ->join('film.seances', 'seance')
            ->join('seance.salle', 'salle')
            ->andWhere('film.id = :id')
            ->andWhere('seance.dateTime >= :now')
            ->andWhere('seance.status = :status')
            ->setParameter('id', $id)
            ->setParameter('now', new DateTimeImmutable())
            ->setParameter('status', SeanceStatus::PUBLISHED)
            ->orderBy('seance.dateTime', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Film[]
     */
    public function findProgrammableWithDuration(): array
    {
        return $this->createQueryBuilder('film')
            ->andWhere('film.programmable = :programmable')
            ->andWhere('film.duration IS NOT NULL')
            ->andWhere('film.duration BETWEEN :minimumDuration AND :maximumDuration')
            ->setParameter('programmable', true)
            ->setParameter('minimumDuration', 1)
            ->setParameter('maximumDuration', 600)
            ->orderBy('film.title', 'ASC')
            ->addOrderBy('film.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return Film[] Returns an array of Film objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('f')
//            ->andWhere('f.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('f.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Film
//    {
//        return $this->createQueryBuilder('f')
//            ->andWhere('f.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
