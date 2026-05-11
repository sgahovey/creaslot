<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

class AnnulationReservationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('motifAnnulation', TextareaType::class, [
            'label'       => 'Motif (optionnel)',
            'required'    => false,
            'attr'        => [
                'class'       => 'form-control',
                'rows'        => 3,
                'maxlength'   => 500,
                'placeholder' => 'Si vous le souhaitez, précisez la raison de votre annulation…',
            ],
            'help'        => 'Optionnel — vous pouvez préciser le motif de votre annulation.',
            'constraints' => [
                new Length(max: 500, maxMessage: 'Le motif ne peut pas dépasser {{ limit }} caractères.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'annulation_reservation',
        ]);
    }

    /**
     * Block prefix figé : la modale d'annulation rend les champs en HTML
     * manuel avec les noms 'annulation_reservation[motifAnnulation]' et
     * 'annulation_reservation[_token]'. Toute modification ici doit être
     * répercutée dans templates/components/modal_annulation_reservation.html.twig.
     */
    public function getBlockPrefix(): string
    {
        return 'annulation_reservation';
    }
}
