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
 * Saisie de l'adresse email pour demander une réinitialisation (US-6.2).
 *
 * Aucune fuite d'information : la validité métier de l'email (compte existant ou
 * non) n'est jamais vérifiée ici — seul le format est contrôlé. La réponse est
 * identique quel que soit le résultat (cf. ResetPasswordController).
 *
 * @extends AbstractType<mixed>
 */
class ResetPasswordRequestFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label'       => 'Adresse email',
                'attr'        => ['autocomplete' => 'email'],
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
            'csrf_token_id'   => 'reset_password_request',
        ]);
    }
}
