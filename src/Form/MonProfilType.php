<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Édition self-service du profil par l'utilisateur connecté (US-6.1).
 *
 * Ne mappe QUE le prénom et le nom. Le rôle, le service et l'email sont
 * volontairement ABSENTS : ne pas les déclarer ici les rend non liables côté
 * serveur, ce qui constitue la garantie structurelle anti-escalade de privilège
 * (un champ « readonly » HTML resterait, lui, soumis et bindable). L'email, le
 * rôle et le service sont affichés en lecture seule dans le template.
 *
 * Les contraintes de validation de prénom/nom (NotBlank, Length 2..100) portent
 * déjà sur l'entité Utilisateur : rien à répliquer ici.
 *
 * @extends AbstractType<Utilisateur>
 */
class MonProfilType extends AbstractType
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Utilisateur::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'mon_profil_informations',
        ]);
    }
}
