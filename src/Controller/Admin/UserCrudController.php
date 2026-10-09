<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class UserCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return User::class;
    }


    public function configureFields(string $pageName): iterable
    {
        //on doit voir si l'utilisateur a vérifié son email ou pas, donc on ajoute le champ isVerified mais ça ne doit pas pouvoir être modifié par l'admin, donc on le met en lecture seule. On peut aussi ajouter un champ pour les rôles de l'utilisateur, mais ça ne doit pas être modifiable par l'admin non plus. Donc on met ce champ en lecture seule aussi. On peut aussi ajouter un champ pour le mot de passe, mais ça ne doit pas être modifiable par l'admin non plus. Donc on met ce champ en lecture seule aussi. On peut aussi ajouter un champ pour l'email, mais ça ne doit pas être modifiable par l'admin non plus. Donc on met ce champ en lecture seule aussi. On peut aussi ajouter un champ pour l'id, mais ça ne doit pas être modifiable par l'admin non plus. Donc on met ce champ en lecture seule aussi.
        return [
            IdField::new('id')->setFormTypeOption('disabled', true),
            TextField::new('email')->setFormTypeOption('disabled', true),
            TextField::new('password')->setFormTypeOption('disabled', true),
            BooleanField::new('isVerified')->setDisabled(true),
            ChoiceField::new('roles')->setChoices([
                'Admin' => 'ROLE_ADMIN',
                'User' => 'ROLE_USER',
            ])->allowMultipleChoices(),
        ];
    }

}
