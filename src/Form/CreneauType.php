<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Creneau;
use App\Entity\TypeRdv;
use App\Repository\TypeRdvRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class CreneauType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $estReserve = $options['creneau_reserve'];

        if (!$estReserve) {
            $this->ajouterChampsModifiables($builder);
        }

        $builder->add('commentaireAuditeur', TextareaType::class, [
            'label'    => 'Commentaire (optionnel, visible par les auditeurs)',
            'required' => false,
            'attr'     => [
                'class'       => 'form-control',
                'rows'        => 4,
                'maxlength'   => 500,
                'placeholder' => 'Disponible pour question sur le cycle ingénieur',
            ],
            'constraints' => [
                new Length(max: 500, maxMessage: 'Le commentaire ne peut pas dépasser {{ limit }} caractères.'),
            ],
        ]);

        if (!$estReserve) {
            $builder->addEventListener(FormEvents::POST_SUBMIT, $this->validerCoherenceHoraires(...));
        }
    }

    /**
     * Ajoute les champs non accessibles quand le créneau est réservé.
     * Séparé pour respecter le SRP et garder buildForm court.
     */
    private function ajouterChampsModifiables(FormBuilderInterface $builder): void
    {
        $builder
            ->add('typeRdv', EntityType::class, [
                'class'         => TypeRdv::class,
                'choice_label'  => 'libelle',
                'label'         => 'Type de RDV',
                'expanded'      => true,
                'multiple'      => false,
                'query_builder' => fn (TypeRdvRepository $repo) => $repo
                    ->createQueryBuilder('t')
                    ->andWhere('t.estActif = :actif')
                    ->setParameter('actif', true)
                    ->orderBy('t.libelle', 'ASC'),
                'choice_attr' => fn (TypeRdv $typeRdv) => [
                    'data-couleur'     => $typeRdv->getCouleurHex(),
                    'data-description' => $typeRdv->getDescription() ?? '',
                    'data-icone'       => $typeRdv->getIcone() ?? '',
                ],
                'invalid_message' => 'Veuillez sélectionner un type de rendez-vous.',
            ])
            ->add('date', DateType::class, [
                'label'       => 'Date',
                'widget'      => 'single_text',
                'input'       => 'datetime_immutable',
                'mapped'      => false,
                'attr'        => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(message: 'La date est obligatoire.'),
                    new GreaterThanOrEqual(
                        value: 'today',
                        message: 'La date ne peut pas être dans le passé.',
                    ),
                ],
            ])
            ->add('heureDebut', TimeType::class, [
                'label'       => 'Heure de début',
                'widget'      => 'single_text',
                'input'       => 'datetime_immutable',
                'mapped'      => false,
                'attr'        => ['class' => 'form-control'],
                'constraints' => [
                    new NotBlank(message: "L'heure de début est obligatoire."),
                ],
            ])
            ->add('duree', ChoiceType::class, [
                'label'    => 'Durée',
                'mapped'   => false,
                'expanded' => true,
                'multiple' => false,
                'choices'  => [
                    '15 min'        => '15',
                    '30 min'        => '30',
                    '1 heure'       => '60',
                    'Personnalisée' => 'custom',
                ],
                'data'        => '60',
                'constraints' => [
                    new NotBlank(message: 'Veuillez sélectionner une durée.'),
                ],
            ])
            ->add('heureFin', TimeType::class, [
                'label'    => 'Heure de fin',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'mapped'   => false,
                'required' => false,
                'attr'     => ['class' => 'form-control'],
            ])
        ;
    }

    private function validerCoherenceHoraires(FormEvent $event): void
    {
        $form = $event->getForm();
        $dateFld = $form->get('date');
        $heureFld = $form->get('heureDebut');
        $dureeFld = $form->get('duree');
        $heureFinFld = $form->get('heureFin');

        if ($dateFld->isValid() && $heureFld->isValid()) {
            $date = $dateFld->getData();
            $heure = $heureFld->getData();

            if ($date !== null && $heure !== null) {
                $dateDebut = \DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $date->format('Y-m-d') . ' ' . $heure->format('H:i'),
                );

                if ($dateDebut < new \DateTimeImmutable('-1 minute')) {
                    $heureFld->addError(new FormError("L'heure de début est dans le passé."));
                }
            }
        }

        if ($dureeFld->getData() === 'custom') {
            $heureDebut = $heureFld->getData();
            $heureFin = $heureFinFld->getData();

            if ($heureFin === null) {
                $heureFinFld->addError(new FormError("L'heure de fin est obligatoire pour une durée personnalisée."));
            } elseif ($heureDebut !== null && $heureFin->format('H:i') <= $heureDebut->format('H:i')) {
                // DT-2 : heureFin doit être strictement postérieure à heureDebut (règle A1).
                // Sans ce garde-fou, calculerDateFin() pose dateFin au même jour que
                // dateDebut → un créneau 10h00→02h00 produit dateFin < dateDebut.
                // Comparaison sur la composante horaire seule : les deux TimeType
                // partagent une date conventionnelle, seule l'heure porte du sens métier.
                $heureFinFld->addError(new FormError("L'heure de fin doit être postérieure à l'heure de début."));
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'      => Creneau::class,
            'csrf_protection' => true,
            'csrf_token_id'   => 'creneau_form',
            'creneau_reserve' => false,
        ]);

        $resolver->setAllowedTypes('creneau_reserve', 'bool');
    }
}
