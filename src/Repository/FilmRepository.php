<?php

namespace App\Repository;

use App\Entity\Film;
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
            ->setParameter('now', new DateTimeImmutable())
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
            ->setParameter('id', $id)
            ->setParameter('now', new DateTimeImmutable())
            ->orderBy('seance.dateTime', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
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
