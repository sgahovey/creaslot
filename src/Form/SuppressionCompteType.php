<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;

/**
 * Confirmation forte de la suppression (anonymisation) de son propre compte (US-12.3).
 *
 * Case à cocher obligatoire (non mappée : aucune entité derrière ce formulaire) qui
 * matérialise le consentement éclairé à une action irréversible. Protection CSRF via
 * le jeton dédié « suppression_compte ».
 *
 * @extends AbstractType<array<string, mixed>>
 */
class SuppressionCompteType extends AbstractType
{
    use ProtectionCsrfTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('confirmation', CheckboxType::class, [
            'label'       => 'Je comprends que cette action est définitive et irréversible.',
            'mapped'      => false,
            'required'    => true,
            'constraints' => [
                new IsTrue(message: 'Vous devez confirmer avoir compris que cette action est irréversible.'),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $this->configurerProtectionCsrf($resolver, 'suppression_compte');
    }
}
