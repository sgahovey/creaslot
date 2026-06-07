<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\ChangementMotDePasse;
use App\Validator\ContraintesMotDePasse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Changement de mot de passe self-service par l'utilisateur connecté (US-6.1).
 *
 * Mappé sur le DTO `ChangementMotDePasse` (pas sur l'entité). Le mot de passe
 * actuel est exigé : le contrôleur le vérifie via UserPasswordHasher::isPasswordValid
 * avant tout re-hachage (défense contre un détournement de session).
 *
 * Les contraintes du nouveau mot de passe proviennent de la source unique
 * `ContraintesMotDePasse` (DT-18 résolue), partagée avec l'inscription et
 * l'administration des comptes.
 */
class ChangementMotDePasseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('motDePasseActuel', PasswordType::class, [
                'label'       => 'Mot de passe actuel',
                'attr'        => ['autocomplete' => 'current-password'],
                'constraints' => [
                    new NotBlank(message: 'Veuillez saisir votre mot de passe actuel.'),
                ],
            ])
            ->add('nouveauMotDePasse', RepeatedType::class, [
                'type'            => PasswordType::class,
                'first_options'   => [
                    'label' => 'Nouveau mot de passe',
                    'attr'  => ['autocomplete' => 'new-password', 'minlength' => '12'],
                    'help'  => ContraintesMotDePasse::AIDE,
                    // Source unique de la politique de mot de passe (DT-18).
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
            'data_class'      => ChangementMotDePasse::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'mon_profil_mot_de_passe',
        ]);
    }
}
