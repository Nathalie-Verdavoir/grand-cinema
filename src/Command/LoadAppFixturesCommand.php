<?php

namespace App\Command;

use Doctrine\Bundle\FixturesBundle\Loader\FixturesProvider;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:fixtures:load', description: 'Load application fixtures with independently committed batches')]
final class LoadAppFixturesCommand extends Command
{
    public function __construct(
        private readonly FixturesProvider $fixturesProvider,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('append', null, InputOption::VALUE_NONE, 'Keep existing data instead of purging first')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirm purging the current database')
            ->addOption('group', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Only load fixtures from these groups');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $entityManager = $this->doctrine->getManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new \LogicException('Le gestionnaire Doctrine ne prend pas en charge les fixtures ORM.');
        }

        $fixtures = $this->fixturesProvider->getFixtures($input->getOption('group'));
        if ([] === $fixtures) {
            $io->error('Aucune fixture correspondante n’a été trouvée.');

            return Command::FAILURE;
        }

        if (!$input->getOption('append') && !$input->getOption('force')) {
            if (!$io->confirm(sprintf(
                'La base "%s" sera purgée. Continuer ?',
                $entityManager->getConnection()->getDatabase(),
            ))) {
                return Command::SUCCESS;
            }
        }

        if (!$input->getOption('append')) {
            (new ORMPurger($entityManager))->purge();
            $entityManager->clear();
        }

        foreach ($fixtures as $fixture) {
            $io->text(sprintf('Chargement de %s', $fixture::class));
            $fixture->load($entityManager);
            $entityManager->clear();
        }

        $io->success('Toutes les fixtures ont été chargées.');

        return Command::SUCCESS;
    }
}
