<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulaire de renvoi de l'email de confirmation d'inscription (US-12.4).
 *
 * Non mappé sur une entité (une simple adresse email). Protection CSRF via un jeton
 * dédié. La réponse du contrôleur est toujours neutre (anti-énumération OWASP).
 *
 * @extends AbstractType<array<string, mixed>>
 */
class RenvoiConfirmationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label'       => 'Votre adresse email',
            'attr'        => ['placeholder' => 'votre.email@exemple.fr', 'autocomplete' => 'email'],
            'constraints' => [
                new NotBlank(message: 'Veuillez saisir votre adresse email.'),
                new Email(message: 'Veuillez saisir une adresse email valide.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'renvoi_confirmation',
        ]);
    }
}
