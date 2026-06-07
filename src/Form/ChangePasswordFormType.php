<?php

declare(strict_types=1);

namespace App\Form;

use App\Validator\ContraintesMotDePasse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Saisie du nouveau mot de passe lors d'une réinitialisation (US-6.2).
 *
 * Champ non mappé : le mot de passe en clair est lu puis re-haché par le
 * contrôleur. Les contraintes proviennent de la source unique
 * `ContraintesMotDePasse` (DT-18), partagée avec l'inscription, l'administration
 * des comptes et le changement self-service.
 */
class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', RepeatedType::class, [
                'type'   => PasswordType::class,
                'mapped' => false,
                'first_options'   => [
                    'label' => 'Nouveau mot de passe',
                    'attr'  => ['autocomplete' => 'new-password', 'minlength' => '12'],
                    'help'  => ContraintesMotDePasse::AIDE,
                    'constraints' => ContraintesMotDePasse::regles(),
                ],
                'second_options'  => [
                    'label' => 'Confirmer le nouveau mot de passe',
                    'attr'  => ['autocomplete' => 'new-password', 'minlength' => '12'],
                ],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'csrf_token_id'   => 'reset_password',
        ]);
    }
}
