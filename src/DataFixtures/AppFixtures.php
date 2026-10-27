<?php

namespace App\DataFixtures;

use App\Entity\Film;
use App\Entity\Salle;
use App\Entity\Seance;
use App\Entity\Tarif;
use App\Entity\User;
use App\Enum\SeanceStatus;
use App\Service\ReservationFixturesGenerator;
use App\Service\WeeklySeanceDraftGenerator;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use DateTime;
use DateTimeImmutable;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly WeeklySeanceDraftGenerator $seanceDraftGenerator,
        private readonly ReservationFixturesGenerator $reservationFixturesGenerator,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $today = new DateTimeImmutable('today');

        // Initialisation de Faker en français
        $faker = Factory::create('fr_FR');

        // 1. Génération des 10 salles (1 700 places au total)
        $rooms = [
            ['Salle 1 - Lumière', 150],
            ['Salle 2 - Méliès', 80],
            ['Salle 3 - Renoir', 200],
            ['Salle 4 - Truffaut', 300],
            ['Salle 5 - Godard', 80],
            ['Salle 6 - Demy', 120],
            ['Salle 7 - Varda', 300],
            ['Salle 8 - Rohmer', 150],
            ['Salle 9 - Cocteau', 160],
            ['Salle 10 - Chabrol', 160],
        ];

        $roomsByName = [];
        foreach ($rooms as [$name, $capacity]) {
            $salle = (new Salle())
                ->setName($name)
                ->setCapacity($capacity);
            $manager->persist($salle);
            $roomsByName[$name] = $salle;
        }

        $tariffDefinitions = [
            ['Enfant (-12 ans)', 'ENFANT', '7.00'],
            ['Chômeur', 'CHOMEUR', '7.50'],
            ['Senior (+60 ans)', 'SENIOR', '8.00'],
            ['Étudiant', 'ETUDIANT', '8.50'],
            ['Réduit', 'REDUIT', '9.50'],
            ['Plein tarif', 'PLEIN', '12.00'],
        ];
        $tariffsByCode = [];
        foreach ($tariffDefinitions as [$name, $code, $price]) {
            $tariff = (new Tarif())
                ->setName($name)
                ->setCode($code)
                ->setPrice($price);
            $manager->persist($tariff);
            $tariffsByCode[$code] = $tariff;
        }

        // 2. Génération de 2 admins
        $administrators = [];
        for ($i = 0; $i < 2; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email());
            $user->setRoles(['ROLE_ADMIN']);
            $user->setIsVerified(true); // 100% de chance d'être vérifié

            // Hachage d'un mot de passe par défaut
            $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
            $administrators[] = $user;
        }

        // 3. Génération de 10 Utilisateurs
        $customers = [];
        for ($i = 0; $i < 10; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email());
            $user->setRoles(['ROLE_USER']);
            $user->setIsVerified($faker->boolean(80)); // 80% de chance d'être vérifié

            // Hachage d'un mot de passe par défaut
            $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
            $customers[] = $user;
        }

        // 4. Création des films à partir de l'export de la base de données
        $filmData = file_get_contents(__DIR__.'/films.json');
        if (false === $filmData) {
            throw new \RuntimeException('Impossible de lire les données des films.');
        }

        $filmsByTitle = [];
        foreach (json_decode($filmData, true, 512, JSON_THROW_ON_ERROR) as $filmDataRow) {
            $film = (new Film())
                ->setTitle($filmDataRow['title'])
                ->setDescription($filmDataRow['description'])
                ->setImg($filmDataRow['img'])
                ->setProgrammable((bool) $filmDataRow['programmable'])
                ->setDuration(null === $filmDataRow['duration'] ? null : (int) $filmDataRow['duration']);

            if (null !== $filmDataRow['release_date']) {
                $film->setReleaseDate(new \DateTimeImmutable($filmDataRow['release_date']));
            }

            $manager->persist($film);
            $filmsByTitle[$filmDataRow['title']] = $film;
        }

        $manager->flush();

        $seanceData = file_get_contents(__DIR__.'/seances.json');
        if (false === $seanceData) {
            throw new \RuntimeException('Impossible de lire les données des séances.');
        }
        $importedSeances = 0;
        foreach (json_decode($seanceData, true, 512, JSON_THROW_ON_ERROR) as $seanceDataRow) {
            $film = $filmsByTitle[$seanceDataRow['film_title']] ?? null;
            $salle = $roomsByName[$seanceDataRow['room_name']] ?? null;
            $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $seanceDataRow['date_time']);
            if (null === $film || null === $salle || false === $dateTime) {
                throw new \UnexpectedValueException(sprintf(
                    'Données de séance invalides pour « %s » dans « %s ».',
                    $seanceDataRow['film_title'],
                    $seanceDataRow['room_name'],
                ));
            }

            $status = SeanceStatus::from($seanceDataRow['status']);
            if ($dateTime < $today && SeanceStatus::DRAFT === $status) {
                $status = SeanceStatus::PUBLISHED;
            }

            $manager->persist((new Seance())
                ->setFilm($film)
                ->setSalle($salle)
                ->setDateTime($dateTime)
                ->setStatus($status));
            ++$importedSeances;
        }

        $manager->flush();
        $generated = $this->seanceDraftGenerator->generateFixtureRange(
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2026-12-25'),
        );
        $sales = $this->reservationFixturesGenerator->generate(
            $today,
            $administrators,
            $customers,
            $tariffsByCode,
        );

        echo sprintf(
            "Séances importées : %d ; séances générées du 01/01/2024 au 25/12/2026 : %d ; réservations : %d ; billets : %d.\n",
            $importedSeances,
            $generated['created'],
            $sales['reservations'],
            $sales['tickets'],
        );
    }
}
