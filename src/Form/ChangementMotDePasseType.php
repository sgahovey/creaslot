<?php

declare(strict_types=1);

namespace App\Form;

use App\DTO\ChangementMotDePasse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Changement de mot de passe self-service par l'utilisateur connecté (US-6.1).
 *
 * Mappé sur le DTO `ChangementMotDePasse` (pas sur l'entité). Le mot de passe
 * actuel est exigé : le contrôleur le vérifie via UserPasswordHasher::isPasswordValid
 * avant tout re-hachage (défense contre un détournement de session).
 *
 * Les contraintes du nouveau mot de passe sont recopiées à l'identique
 * d'InscriptionType (mêmes règles, mêmes messages). Cette triple réplication
 * (Inscription / Admin / Profil) est une dette assumée tracée en DT-18.
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
                    'help'  => 'Minimum 12 caractères, avec au moins 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial (@ ! ? # _ - etc.).',
                    // Contraintes répliquées d'InscriptionType (mêmes règles, mêmes messages).
                    'constraints' => [
                        new NotBlank(message: 'Le mot de passe est obligatoire.'),
                        new Length(
                            min: 12,
                            minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
                        ),
                        new Regex(
                            pattern: '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@!?#_\-%&*+=.,;:()\[\]{}\/\\\\|])/',
                            message: 'Le mot de passe doit contenir au moins 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial (@ ! ? # _ - etc.).',
                        ),
                    ],
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
