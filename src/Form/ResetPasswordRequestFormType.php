<?php

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'mapped' => false,
            'attr' => [
                'autocomplete' => 'email',
                'maxlength' => 180,
                'class' => 'form-control',
            ],
            'constraints' => [
                new NotBlank(message: 'Saisissez votre adresse e-mail.'),
                new Email(message: 'Saisissez une adresse e-mail valide.'),
                new Length(
                    max: 180,
                    maxMessage: 'Cette adresse e-mail est trop longue.',
                ),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
