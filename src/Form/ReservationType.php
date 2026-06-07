<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class ReservationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('commentaireAuditeur', TextareaType::class, [
            'label'    => 'Commentaire (optionnel)',
            'required' => false,
            'attr'     => [
                'class'       => 'form-control',
                'rows'        => 4,
                'maxlength'   => 500,
                'placeholder' => 'Optionnel — précisez brièvement la raison de votre demande (sans information personnelle sensible)…',
            ],
            'help'        => 'Optionnel — précisez brièvement (sans donnée médicale, santé ou autre information sensible).',
            'constraints' => [
                new Length(max: 500, maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'reservation_form',
        ]);
    }
}
