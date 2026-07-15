<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use App\Validator\ContraintesMotDePasse;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * @extends AbstractType<Utilisateur>
 */
class InscriptionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label'    => 'Prénom',
                'required' => true,
                'attr'     => ['placeholder' => 'Votre prénom', 'autocomplete' => 'given-name'],
            ])
            ->add('nom', TextType::class, [
                'label'    => 'Nom',
                'required' => true,
                'attr'     => ['placeholder' => 'Votre nom de famille', 'autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label'    => 'Adresse email',
                'required' => true,
                'attr'     => ['placeholder' => 'votre.email@exemple.fr', 'autocomplete' => 'email'],
            ])
            ->add('motDePasse', RepeatedType::class, [
                'type'          => PasswordType::class,
                'mapped'        => false,
                'first_options' => [
                    'label' => 'Mot de passe',
                    'attr'  => [
                        'placeholder'  => 'Minimum 12 caractères',
                        'autocomplete' => 'new-password',
                        'minlength'    => '12',
                    ],
                    'help' => ContraintesMotDePasse::AIDE,
                    // Contraintes sur le premier champ : c'est là qu'elles sont évaluées (source unique, DT-18).
                    'constraints' => ContraintesMotDePasse::regles(),
                ],
                'second_options' => [
                    'label' => 'Confirmer le mot de passe',
                    'attr'  => [
                        'placeholder'  => 'Répétez votre mot de passe',
                        'autocomplete' => 'new-password',
                        'minlength'    => '12',
                    ],
                ],
                'invalid_message' => 'Les mots de passe ne correspondent pas.',
            ])
            ->add('cgu', CheckboxType::class, [
                // Le label (avec le lien cliquable vers les CGU) est défini dans le template via form_row,
                // car il doit être construit avec path('app_cgu') côté Twig (DT-29).
                'mapped'      => false,
                'constraints' => [
                    new IsTrue(message: "Vous devez accepter les conditions générales d'utilisation."),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Utilisateur::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'inscription',
        ]);
    }
}
