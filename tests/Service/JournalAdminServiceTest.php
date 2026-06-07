<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\JournalAdmin;
use App\Entity\Utilisateur;
use App\Enum\TypeActionJournal;
use App\Service\JournalAdminService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Test unitaire du service d'enregistrement du journal (US-5.5).
 *
 * Vérifie que `enregistrer()` fige correctement acteur et cible (id + libellé),
 * et qu'il fait UNIQUEMENT `persist()` (jamais `flush()` : l'atomicité avec
 * l'action métier repose sur le flush du contrôleur).
 */
final class JournalAdminServiceTest extends TestCase
{
    public function test_enregistre_fige_les_libelles_acteur_et_cible(): void
    {
        $acteur = $this->creerUtilisateur(10, 'Alice', 'Martin');
        $cible = $this->creerUtilisateur(20, 'Bob', 'Durand');

        $entree = $this->capturerEntree(
            fn (JournalAdminService $service) => $service->enregistrer(
                TypeActionJournal::COMPTE_CHANGEMENT_ROLE,
                $acteur,
                $cible,
                'Personnel → Super-administrateur',
            ),
        );

        self::assertSame(TypeActionJournal::COMPTE_CHANGEMENT_ROLE, $entree->getTypeAction());
        self::assertSame(10, $entree->getActeurId());
        self::assertSame('Alice Martin', $entree->getActeurLibelle());
        self::assertSame(20, $entree->getCibleId());
        self::assertSame('Bob Durand', $entree->getCibleLibelle());
        self::assertSame('Personnel → Super-administrateur', $entree->getDetails());
    }

    public function test_cible_null_donne_des_libelles_null(): void
    {
        $acteur = $this->creerUtilisateur(10, 'Alice', 'Martin');

        $entree = $this->capturerEntree(
            fn (JournalAdminService $service) => $service->enregistrer(TypeActionJournal::COMPTE_CREATION, $acteur),
        );

        self::assertSame(TypeActionJournal::COMPTE_CREATION, $entree->getTypeAction());
        self::assertNull($entree->getCibleId());
        self::assertNull($entree->getCibleLibelle());
        self::assertNull($entree->getDetails());
    }

    /**
     * Exécute l'action sur un service dont l'EntityManager est mocké, en capturant
     * l'entrée persistée. Asserte au passage : exactement un persist(), zéro flush().
     *
     * @param callable(JournalAdminService): void $action
     */
    private function capturerEntree(callable $action): JournalAdmin
    {
        $entreeCapturee = null;

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (JournalAdmin $entree) use (&$entreeCapturee): bool {
                $entreeCapturee = $entree;

                return true;
            }));
        $entityManager->expects(self::never())->method('flush');

        $action(new JournalAdminService($entityManager));

        self::assertInstanceOf(JournalAdmin::class, $entreeCapturee);

        return $entreeCapturee;
    }

    private function creerUtilisateur(int $id, string $prenom, string $nom): Utilisateur
    {
        $utilisateur = (new Utilisateur())->setPrenom($prenom)->setNom($nom);

        $prop = new \ReflectionProperty(Utilisateur::class, 'id');
        $prop->setValue($utilisateur, $id);

        return $utilisateur;
    }
}
