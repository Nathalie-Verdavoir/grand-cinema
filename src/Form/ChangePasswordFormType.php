<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'mapped' => false,
                'label' => 'Mot de passe actuel',
                'attr' => ['autocomplete' => 'current-password', 'maxlength' => 4096],
                'constraints' => [
                    new NotBlank(message: 'Saisissez votre mot de passe actuel.'),
                ],
            ])
            ->add('newPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'invalid_message' => 'Les deux nouveaux mots de passe ne correspondent pas.',
                'first_options' => [
                    'label' => 'Nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password', 'maxlength' => 4096],
                    'constraints' => [
                        new NotBlank(message: 'Saisissez un nouveau mot de passe.'),
                        new Regex([
                            'pattern' => '/^(?=.*\d)(?=.*[A-Z])(?=.*[!#$%&*+\/=?^_`{|}~-])(?!.*(.)\1{2}).*[a-z].{8,}$/m',
                            'match' => true,
                            'message' => 'Le mot de passe doit comporter au moins huit caractères, avec une majuscule, une minuscule, un chiffre et un symbole.',
                        ]),
                    ],
                ],
                'second_options' => [
                    'label' => 'Confirmer le nouveau mot de passe',
                    'attr' => ['autocomplete' => 'new-password', 'maxlength' => 4096],
                ],
            ]);
    }
}
