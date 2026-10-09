<?php

namespace App\Controller\Admin;

use App\Entity\Tarif;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TarifCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Tarif::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tarif')
            ->setEntityLabelInPlural('Tarifs')
            ->setSearchFields(['name', 'code'])
            ->setPaginatorPageSize(10);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'Nom');
        yield TextField::new('code', 'Code');
        yield MoneyField::new('price', 'Prix')
            ->setCurrency('EUR')
            ->setStoredAsCents(false);
    }
}
