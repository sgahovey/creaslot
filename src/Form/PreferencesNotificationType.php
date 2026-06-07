<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Utilisateur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Préférences notifications email de l'Auditeur (US-4.8).
 *
 * Base légale RGPD : art. 6.1.b (exécution du contrat). Seuls les 2 types
 * « confort » sont désactivables côté email :
 * - MODIFICATION_COMMENTAIRE
 * - RAPPEL_J1
 *
 * Les 3 types « critiques » (CONFIRMATION / ANNULATION / SUPPRESSION) ne sont
 * pas exposés ici : toujours envoyés car nécessaires à l'exécution du service.
 *
 * L'in-app reste TOUJOURS persistée pour les 5 types (audit trail B2) ; seul le
 * canal email est conditionné par ces préférences.
 *
 * Pas de SubmitType ici : le bouton est rendu dans le template (convention projet,
 * cf. InscriptionType / CreneauType).
 *
 * @extends AbstractType<Utilisateur>
 */
final class PreferencesNotificationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $aideInApp = 'Vous recevrez toujours cette notification dans CreaSlot, même si l\'email est désactivé.';

        $builder
            ->add('emailModificationCommentaire', CheckboxType::class, [
                'label'    => 'Recevoir un email lorsqu\'un commentaire de rendez-vous est modifié',
                'required' => false,
                'help'     => $aideInApp,
            ])
            ->add('emailRappelJ1', CheckboxType::class, [
                'label'    => 'Recevoir un email de rappel la veille du rendez-vous',
                'required' => false,
                'help'     => $aideInApp,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Utilisateur::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'preferences_notification',
        ]);
    }
}
