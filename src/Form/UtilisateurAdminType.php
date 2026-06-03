<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Service;
use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Formulaire d'administration d'un compte (US-5.3), côté Super-admin.
 *
 * Un seul type pour la création et l'édition : l'option `avec_mot_de_passe`
 * (true à la création, false en édition) conditionne la présence du champ
 * mot de passe. Distinct de `InscriptionType` (auto-inscription publique) :
 * pas de CGU, et un champ rôle géré par l'admin.
 */
class UtilisateurAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'attr'  => ['autocomplete' => 'given-name'],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'attr'  => ['autocomplete' => 'family-name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'attr'  => ['autocomplete' => 'email'],
            ])
            ->add('role', EnumType::class, [
                'class'        => RoleUtilisateur::class,
                'label'        => 'Rôle',
                'choice_label' => fn (RoleUtilisateur $role): string => $role->libelle(),
                'attr'         => [
                    'data-compte-role-service-target' => 'role',
                    'data-action'                     => 'change->compte-role-service#actualiser',
                ],
            ])
            ->add('service', EntityType::class, [
                'class'         => Service::class,
                'label'         => 'Service',
                'choice_label'  => 'nom',
                'required'      => false,
                'placeholder'   => '— Aucun —',
                'query_builder' => fn ($repository) => $repository->createQueryBuilder('s')->orderBy('s.nom', 'ASC'),
                'attr'          => ['data-compte-role-service-target' => 'service'],
            ]);

        if ($options['avec_mot_de_passe'] === true) {
            $this->ajouterChampMotDePasse($builder);
        }

        // Cohérence métier (création ET édition, un seul endroit) : un Auditeur n'a
        // jamais de service. Le Personnel et le Super-admin (qui agit aussi comme
        // personnel, cf. role_hierarchy) le conservent. On force donc service à null
        // pour le seul rôle Auditeur, après binding, quel que soit le champ soumis.
        $builder->addEventListener(FormEvents::POST_SUBMIT, $this->normaliserServiceSelonRole(...));
    }

    private function normaliserServiceSelonRole(FormEvent $event): void
    {
        $utilisateur = $event->getData();

        if ($utilisateur instanceof Utilisateur && $utilisateur->getRole() === RoleUtilisateur::AUDITEUR) {
            $utilisateur->setService(null);
        }
    }

    private function ajouterChampMotDePasse(FormBuilderInterface $builder): void
    {
        $builder->add('motDePasse', RepeatedType::class, [
            'type'            => PasswordType::class,
            'mapped'          => false,
            'first_options'   => [
                'label' => 'Mot de passe',
                'attr'  => ['autocomplete' => 'new-password', 'minlength' => '12'],
                'help'  => 'Minimum 12 caractères, avec au moins 1 majuscule, 1 minuscule, 1 chiffre et 1 caractère spécial (@ ! ? # _ - etc.).',
                // Contraintes répliquées d'InscriptionType (sans réutiliser ce type).
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
                'label' => 'Confirmer le mot de passe',
                'attr'  => ['autocomplete' => 'new-password', 'minlength' => '12'],
            ],
            'invalid_message' => 'Les mots de passe ne correspondent pas.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'        => Utilisateur::class,
            'csrf_protection'   => true,
            'csrf_token_id'     => 'utilisateur_admin',
            'avec_mot_de_passe' => false,
            // Amélioration progressive : grise le service quand le rôle Auditeur
            // est choisi (la cohérence reste garantie côté serveur, cf. POST_SUBMIT).
            'attr'              => ['data-controller' => 'compte-role-service'],
        ]);

        $resolver->setAllowedTypes('avec_mot_de_passe', 'bool');
    }
}
