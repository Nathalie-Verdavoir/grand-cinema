<?php

namespace App\Command;

use App\Service\AdminKpiAggregateRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:kpi:rebuild', description: 'Rebuild persisted daily sales aggregates for admin KPIs')]
final class RebuildAdminKpiAggregatesCommand extends Command
{
    public function __construct(private readonly AdminKpiAggregateRebuilder $rebuilder)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Recalcul des agrégats KPI');

        $result = $this->rebuilder->rebuildAll();

        $io->success(sprintf(
            '%d jours recalculés ; %d agrégats enregistrés.',
            $result['days'],
            $result['rows'],
        ));

        return Command::SUCCESS;
    }
}
