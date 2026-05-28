<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\CreneauType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Test fonctionnel CreneauType (DT-2 niveau 2 — fix principal).
 *
 * Vérifie que validerCoherenceHoraires() (hook POST_SUBMIT) :
 * - n'ajoute PAS d'erreur sur heureFin quand heureFin > heureDebut (custom)
 * - ajoute une erreur sur heureFin quand heureFin <= heureDebut (custom)
 *
 * Pattern : KernelTestCase + vrai FormFactory. Choix vs TypeTestCase :
 * CreneauType embarque un EntityType (typeRdv) avec query_builder Doctrine ;
 * le mocker en TypeTestCase pur demandait une doublure d'EntityType
 * disproportionnée. Le FormFactory réel résout l'EntityType via le container.
 *
 * Les assertions ciblent les erreurs DU CHAMP heureFin (pas la validité
 * globale du form) : inutile donc de fournir un typeRdv valide (qui exigerait
 * des fixtures TypeRdv en BDD test). On isole strictement la logique DT-2.
 *
 * Apparition : DT-2 (validation horaire créneau), 28/05/2026.
 *
 * @see CreneauType::validerCoherenceHoraires()
 */
final class CreneauTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    public function test_heure_fin_apres_debut_ne_genere_pas_erreur_horaire(): void
    {
        $form = $this->soumettreCustom('10:00', '11:00');

        self::assertCount(
            0,
            $form->get('heureFin')->getErrors(),
            'heureFin > heureDebut ne doit générer aucune erreur de cohérence horaire.',
        );
    }

    public function test_heure_fin_avant_debut_est_rejetee(): void
    {
        // Cas DT-2 : 10h00 → 02h00.
        $form = $this->soumettreCustom('10:00', '02:00');

        $messages = $this->messagesErreur($form->get('heureFin')->getErrors());

        self::assertContains(
            "L'heure de fin doit être postérieure à l'heure de début.",
            $messages,
            'heureFin < heureDebut doit déclencher l\'erreur de cohérence horaire.',
        );
    }

    public function test_heure_fin_egale_debut_est_rejetee(): void
    {
        // Edge case A1 strict : durée nulle (10h00 → 10h00).
        $form = $this->soumettreCustom('10:00', '10:00');

        $messages = $this->messagesErreur($form->get('heureFin')->getErrors());

        self::assertContains(
            "L'heure de fin doit être postérieure à l'heure de début.",
            $messages,
        );
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function soumettreCustom(string $heureDebut, string $heureFin): \Symfony\Component\Form\FormInterface
    {
        $form = $this->formFactory->create(CreneauType::class);
        $form->submit([
            'date'       => '2026-12-01',
            'heureDebut' => $heureDebut,
            'duree'      => 'custom',
            'heureFin'   => $heureFin,
        ]);

        return $form;
    }

    /**
     * @return list<string>
     */
    private function messagesErreur(\Symfony\Component\Form\FormErrorIterator $errors): array
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[] = $error->getMessage();
        }

        return $messages;
    }
}
