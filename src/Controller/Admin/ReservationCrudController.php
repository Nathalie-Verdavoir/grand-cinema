<?php

namespace App\Controller\Admin;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class ReservationCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Reservation::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Réservation')
            ->setEntityLabelInPlural('Réservations')
            ->setSearchFields(['reference'])
            ->setPaginatorPageSize(10);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW, Action::DELETE);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference', 'Référence')
            ->setFormTypeOption('disabled', true);

        yield AssociationField::new('user', 'Client')
            ->setFormTypeOption('choice_label', 'email')
            ->setFormTypeOption('disabled', true)
            ->formatValue(static fn ($user): ?string => $user?->getEmail());

        yield DateTimeField::new('reservedAt', 'Date de réservation')
            ->setFormat('dd/MM/yyyy HH:mm')
            ->setFormTypeOption('disabled', true);

        yield MoneyField::new('totalPrice', 'Montant total')
            ->setCurrency('EUR')
            ->setStoredAsCents(false)
            ->setFormTypeOption('disabled', true);

        yield IntegerField::new('nbPlaces', 'Nombre de places')
            ->setFormTypeOption('disabled', true);

        yield ChoiceField::new('status', 'Statut')
            ->setChoices(array_combine(
                array_map(static fn (ReservationStatus $status): string => $status->getLabel(), ReservationStatus::cases()),
                ReservationStatus::cases()
            ));

        yield AssociationField::new('seance', 'Séance')
            ->setFormTypeOption('choice_label', static fn ($seance): string => sprintf(
                '%s - %s',
                $seance->getFilm()?->getTitle() ?? 'Film inconnu',
                $seance->getDateTime()?->format('d/m/Y H:i') ?? 'Date inconnue'
            ))
            ->setFormTypeOption('disabled', true)
            ->formatValue(static fn ($seance): ?string => $seance ? sprintf(
                '%s - %s',
                $seance->getFilm()?->getTitle() ?? 'Film inconnu',
                $seance->getDateTime()?->format('d/m/Y H:i') ?? 'Date inconnue'
            ) : null);
    }
}
