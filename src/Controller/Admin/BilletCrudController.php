<?php

namespace App\Controller\Admin;

use App\Entity\Billet;
use App\Entity\Reservation;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class BilletCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Billet::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Billet')
            ->setEntityLabelInPlural('Billets')
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

        yield AssociationField::new('reservation', 'Réservation')
            ->setFormTypeOption('choice_label', static fn (Reservation $reservation): string => (string) $reservation->getReference())
            ->setFormTypeOption('disabled', true)
            ->formatValue(static fn ($reservation): ?string => $reservation ? (string) $reservation->getReference() : null);

        yield AssociationField::new('tarif', 'Tarif')
            ->setFormTypeOption('choice_label', 'name')
            ->setFormTypeOption('disabled', true)
            ->formatValue(static fn ($tarif): ?string => $tarif?->getName());

        yield MoneyField::new('priceApplied', 'Prix appliqué')
            ->setCurrency('EUR')
            ->setNumDecimals(0)
            ->setStoredAsCents(false)
            ->setFormTypeOption('disabled', true);

        yield TextField::new('seatNumber', 'Place')
            ->setRequired(false);
    }
}
