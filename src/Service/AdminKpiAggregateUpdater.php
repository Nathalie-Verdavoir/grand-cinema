<?php

namespace App\Service;

use App\Entity\Billet;
use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Enum\SeanceStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;

final class AdminKpiAggregateUpdater implements AdminKpiAggregateUpdaterInterface
{
    private readonly Connection $connection;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->connection = $doctrine->getConnection();
    }

    public function applyStatusChange(
        Reservation $reservation,
        ?ReservationStatus $previousStatus,
        ?ReservationStatus $newStatus,
    ): void {
        $seance = $reservation->getSeance();
        if (null === $seance || SeanceStatus::PUBLISHED !== $seance->getStatus()) {
            return;
        }

        $wasIncluded = $this->isIncludedStatus($previousStatus);
        $isIncluded = $this->isIncludedStatus($newStatus);
        if ($wasIncluded === $isIncluded) {
            return;
        }

        $dateTime = $seance->getDateTime();
        $filmId = $seance->getFilm()?->getId();
        $channel = $reservation->getChannel();
        if (null === $dateTime || null === $filmId || null === $channel) {
            throw new LogicException('Une réservation KPI doit avoir une séance, un film et un canal valides.');
        }

        $totals = $this->ticketTotals($reservation);
        if (0 === $totals['count']) {
            return;
        }

        $screeningDateTime = DateTimeImmutable::createFromInterface($dateTime);
        $hour = (int) $screeningDateTime->format('G');
        $timeBucket = $hour < 10 ? 0 : 1 + intdiv($hour - 10, 2);
        $dimensions = [
            'date' => $screeningDateTime->format('Y-m-d'),
            'filmId' => $filmId,
            'channel' => $channel->value,
            'timeBucket' => $timeBucket,
        ];

        if ($isIncluded) {
            $this->addToAggregate($dimensions, $totals);

            return;
        }

        $this->subtractFromAggregate($dimensions, $totals);
    }

    /**
     * @param array{date: string, filmId: int, channel: string, timeBucket: int} $dimensions
     * @param array{count: int, revenueCents: int} $totals
     */
    private function addToAggregate(array $dimensions, array $totals): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO kpi_daily_sales (
                    screening_date, film_id, channel, time_bucket, reservation_count, ticket_count, revenue
                ) VALUES (
                    :date, :filmId, :channel, :timeBucket, 1, :ticketCount, :revenue
                )
                ON DUPLICATE KEY UPDATE
                    reservation_count = reservation_count + VALUES(reservation_count),
                    ticket_count = ticket_count + VALUES(ticket_count),
                    revenue = revenue + VALUES(revenue)
                SQL,
            $dimensions + [
                'ticketCount' => $totals['count'],
                'revenue' => $this->formatCents($totals['revenueCents']),
            ],
        );
    }

    /**
     * @param array{date: string, filmId: int, channel: string, timeBucket: int} $dimensions
     * @param array{count: int, revenueCents: int} $totals
     */
    private function subtractFromAggregate(array $dimensions, array $totals): void
    {
        $affectedRows = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE kpi_daily_sales
                SET
                    reservation_count = reservation_count - 1,
                    ticket_count = ticket_count - :ticketCount,
                    revenue = revenue - :revenue
                WHERE screening_date = :date
                    AND film_id = :filmId
                    AND channel = :channel
                    AND time_bucket = :timeBucket
                    AND reservation_count >= 1
                    AND ticket_count >= :ticketCount
                    AND revenue >= :revenue
                SQL,
            $dimensions + [
                'ticketCount' => $totals['count'],
                'revenue' => $this->formatCents($totals['revenueCents']),
            ],
        );

        if (1 !== $affectedRows) {
            throw new LogicException(sprintf(
                'Les agrégats KPI sont absents ou incohérents pour la séance du %s ; exécutez app:kpi:rebuild.',
                $dimensions['date'],
            ));
        }

        $this->connection->delete('kpi_daily_sales', [
            'screening_date' => $dimensions['date'],
            'film_id' => $dimensions['filmId'],
            'channel' => $dimensions['channel'],
            'time_bucket' => $dimensions['timeBucket'],
            'reservation_count' => 0,
            'ticket_count' => 0,
            'revenue' => '0.00',
        ]);
    }

    /**
     * @return array{count: int, revenueCents: int}
     */
    private function ticketTotals(Reservation $reservation): array
    {
        $id = $reservation->getId();
        if (null !== $id) {
            $totals = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS ticket_count, COALESCE(SUM(price_applied), 0) AS revenue FROM billet WHERE reservation_id = :reservationId',
                ['reservationId' => $id],
            );

            if (!is_array($totals)) {
                throw new LogicException(sprintf('Impossible de lire les billets de la réservation %d.', $id));
            }

            return [
                'count' => (int) $totals['ticket_count'],
                'revenueCents' => $this->toCents((string) $totals['revenue']),
            ];
        }

        $count = 0;
        $revenueCents = 0;
        foreach ($reservation->getBillets() as $billet) {
            if (!$billet instanceof Billet || null === $billet->getPriceApplied()) {
                throw new LogicException('Un billet KPI doit avoir un prix appliqué valide.');
            }

            ++$count;
            $revenueCents += $this->toCents($billet->getPriceApplied());
        }

        return ['count' => $count, 'revenueCents' => $revenueCents];
    }

    private function isIncludedStatus(?ReservationStatus $status): bool
    {
        return in_array($status, [ReservationStatus::PAID, ReservationStatus::PAID_AT_COUNTER], true);
    }

    private function toCents(string $amount): int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new LogicException(sprintf('Montant de billet KPI invalide : "%s".', $amount));
        }

        return (int) $matches[1] * 100 + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function formatCents(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
