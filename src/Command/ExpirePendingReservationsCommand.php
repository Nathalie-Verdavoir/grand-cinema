<?php

namespace App\Command;

use App\Service\PendingReservationExpirer;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:reservation:expire',
    description: 'Expire les réservations en attente de paiement depuis 15 minutes.'
)]
final class ExpirePendingReservationsCommand extends Command
{
    private const PENDING_TIMEOUT_MINUTES = 15;

    public function __construct(private readonly PendingReservationExpirer $expirer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d minutes', self::PENDING_TIMEOUT_MINUTES));
        $expiredCount = $this->expirer->expireBefore($cutoff);

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d réservation(s) expirée(s).',
            $expiredCount
        ));

        return Command::SUCCESS;
    }
}
