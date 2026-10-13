<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'mapped' => false,
            'invalid_message' => 'Les deux mots de passe ne correspondent pas.',
            'first_options' => [
                'label' => 'Nouveau mot de passe',
                'attr' => [
                    'autocomplete' => 'new-password',
                    'maxlength' => 4096,
                    'class' => 'form-control',
                ],
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
                'attr' => [
                    'autocomplete' => 'new-password',
                    'maxlength' => 4096,
                    'class' => 'form-control',
                ],
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
