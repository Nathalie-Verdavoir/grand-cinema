<?php

namespace App\DataFixtures;

use App\Entity\Film;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    // Injection du service de hachage pour les mots de passe des utilisateurs
    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Initialisation de Faker en français
        $faker = Factory::create('fr_FR');

        // 1. Génération de 2 admins
        for ($i = 0; $i < 2; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email());
            $user->setRoles(['ROLE_ADMIN']);
            $user->setIsVerified(true); // 100% de chance d'être vérifié

            // Hachage d'un mot de passe par défaut
            $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
        }

        // 2. Génération de 10 Utilisateurs
        for ($i = 0; $i < 10; $i++) {
            $user = new User();
            $user->setEmail($faker->unique()->email());
            $user->setRoles(['ROLE_USER']);
            $user->setIsVerified($faker->boolean(80)); // 80% de chance d'être vérifié

            // Hachage d'un mot de passe par défaut
            $hashedPassword = $this->passwordHasher->hashPassword($user, 'password123');
            $user->setPassword($hashedPassword);

            $manager->persist($user);
        }

        // 2. Génération de quelques Films
        $genres = ['Action', 'Comédie', 'Drame', 'Science-Fiction', 'Thriller', 'Horreur'];

        for ($i = 0; $i < 15; $i++) {
            $film = new Film();

            // Génère un faux titre de film (ex: "Le mystère de la chambre")
            $film->setTitle($faker->sentence(3));

            // Génère un texte de description réaliste
            $film->setDescription($faker->paragraph(3));

            // Génère une date de sortie aléatoire entre il y a 30 ans et aujourd'hui
            $film->setReleaseDate(\DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-30 years', 'now')));

            $manager->persist($film);
        }

        // Envoi de toutes les données en base de données
        $manager->flush();
    }
}
