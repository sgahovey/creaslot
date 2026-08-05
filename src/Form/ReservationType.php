<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Formulaire de confirmation d'une réservation : il ne porte que l'unique champ de
 * commentaire facultatif de l'auditeur. Utilisé par ReservationController::nouveau().
 *
 * @extends AbstractType<mixed>
 */
class ReservationType extends AbstractType
{
    use ProtectionCsrfTrait;

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
            // Invite l'auditeur à ne pas consigner d'information sensible : le périmètre
            // du projet exclut par construction les données sensibles au sens du RGPD.
            'help'        => 'Optionnel — précisez brièvement (sans donnée médicale, santé ou autre information sensible).',
            'constraints' => [
                new Length(max: 500, maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $this->configurerProtectionCsrf($resolver, 'reservation_form');
    }
}
