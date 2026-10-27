<?php

namespace App\EventSubscriber;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Service\AdminKpiAggregateUpdaterInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use LogicException;

#[AsDoctrineListener(event: Events::onFlush)]
final class ReservationKpiAggregateSubscriber
{
    public function __construct(private readonly AdminKpiAggregateUpdaterInterface $aggregateUpdater)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException('Le listener KPI requiert un gestionnaire Doctrine ORM.');
        }

        $unitOfWork = $entityManager->getUnitOfWork();
        foreach ($unitOfWork->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Reservation) {
                continue;
            }

            $changeSet = $unitOfWork->getEntityChangeSet($entity);
            if (!array_key_exists('status', $changeSet)) {
                continue;
            }

            [$previousStatus, $newStatus] = $changeSet['status'];
            $this->aggregateUpdater->applyStatusChange(
                $entity,
                $this->normalizeStatus($previousStatus),
                $this->normalizeStatus($newStatus),
            );
        }
    }

    private function normalizeStatus(mixed $status): ?ReservationStatus
    {
        if (null === $status || $status instanceof ReservationStatus) {
            return $status;
        }

        if (is_string($status)) {
            $status = ReservationStatus::tryFrom($status);
            if (null !== $status) {
                return $status;
            }
        }

        throw new LogicException('Le statut de réservation KPI est invalide.');
    }
}
