<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Utilisateur;
use App\Enum\RoleUtilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration du trigger SQL trg_historique_utilisateur et de la procédure
 * stockée consulter_historique_utilisateur (US-12.1).
 *
 * Le trigger et la procédure vivent en BASE (créés par la migration
 * Version20260629120000), PAS dans le code PHP : ce test prouve donc un
 * comportement de la base de données, complémentaire au journal applicatif PHP.
 *
 * Mécanique (copiée de NotificationServicePersistTest) : KernelTestCase +
 * transaction beginTransaction()/rollback(). Un flush() Doctrine émet un vrai
 * UPDATE SQL → le trigger se déclenche et insère dans historique_utilisateur
 * DANS la même transaction. La lecture (SQL natif / CALL) se fait donc avant le
 * rollback du tearDown, qui annule ensuite tout (utilisateur + lignes d'historique)
 * pour ne pas polluer la base.
 *
 * @see Version20260629120000
 */
final class HistoriqueUtilisateurTriggerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel(['environment' => 'test']);
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->entityManager->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $this->entityManager->rollback();
        }

        $this->entityManager->close();
        parent::tearDown();
    }

    public function test_modifier_le_nom_d_un_utilisateur_remplit_l_historique_via_le_trigger(): void
    {
        // GIVEN — un utilisateur avec un nom initial.
        $utilisateur = $this->creerUtilisateur('Initial');
        $id = $utilisateur->getId();
        self::assertNotNull($id);

        // WHEN — modification du nom : ce flush émet un UPDATE SQL → trigger.
        $utilisateur->setNom('Modifie');
        $this->entityManager->flush();

        // THEN — une ligne d'historique a été créée automatiquement par le trigger.
        $ligne = $this->lireHistorique($id, 'nom');

        self::assertNotFalse($ligne, 'Le trigger doit insérer une ligne pour le champ « nom ».');
        self::assertSame('nom', $ligne['champ_modifie']);
        self::assertSame('Initial', $ligne['ancienne_valeur']);
        self::assertSame('Modifie', $ligne['nouvelle_valeur']);
    }

    public function test_modifier_le_mot_de_passe_ne_stocke_pas_le_hash_dans_l_historique(): void
    {
        // GIVEN
        $utilisateur = $this->creerUtilisateur('Secret');
        $id = $utilisateur->getId();
        self::assertNotNull($id);

        // WHEN — modification du hash de mot de passe.
        $utilisateur->setMotDePasseHash('$argon2id$v=19$nouveau-hash-secret-qui-ne-doit-pas-fuiter');
        $this->entityManager->flush();

        // THEN — assertion SÉCURITÉ : la trace signale le changement sans jamais
        // exposer le hash (ancienne/nouvelle valeur figées à « modifie »).
        $ligne = $this->lireHistorique($id, 'mot_de_passe');

        self::assertNotFalse($ligne, 'Le trigger doit tracer le changement de mot de passe.');
        self::assertSame('mot_de_passe', $ligne['champ_modifie']);
        self::assertSame('modifie', $ligne['ancienne_valeur']);
        self::assertSame('modifie', $ligne['nouvelle_valeur']);
        self::assertStringNotContainsString('argon2id', (string) $ligne['ancienne_valeur'], 'Le hash ne doit jamais être stocké.');
        self::assertStringNotContainsString('argon2id', (string) $ligne['nouvelle_valeur'], 'Le hash ne doit jamais être stocké.');
    }

    public function test_la_procedure_consulter_historique_retourne_les_modifications(): void
    {
        // GIVEN — deux modifications distinctes → deux lignes d'historique.
        $utilisateur = $this->creerUtilisateur('AvantNom');
        $id = $utilisateur->getId();
        self::assertNotNull($id);

        $utilisateur->setNom('ApresNom');
        $this->entityManager->flush();

        $utilisateur->setEmail('apres-' . uniqid() . '@test.local');
        $this->entityManager->flush();

        // WHEN — lecture via la procédure stockée (SQL natif).
        $lignes = $this->appelerProcedureConsulter($id);

        // THEN — autant de lignes que de modifications, colonnes attendues présentes.
        self::assertCount(2, $lignes, 'La procédure doit retourner les deux modifications.');

        foreach (['champ_modifie', 'ancienne_valeur', 'nouvelle_valeur', 'date_modification'] as $colonne) {
            self::assertArrayHasKey($colonne, $lignes[0], "La colonne « $colonne » doit être présente.");
        }

        $champsModifies = array_column($lignes, 'champ_modifie');
        self::assertContains('nom', $champsModifies);
        self::assertContains('email', $champsModifies);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function creerUtilisateur(string $nom): Utilisateur
    {
        $utilisateur = new Utilisateur();
        $utilisateur->setEmail('trigger-' . uniqid() . '@test.local')
            ->setPrenom('Test')
            ->setNom($nom)
            ->setRole(RoleUtilisateur::AUDITEUR)
            ->setEstActif(true)
            ->setMotDePasseHash('placeholder-not-real');

        $this->entityManager->persist($utilisateur);
        $this->entityManager->flush();

        return $utilisateur;
    }

    /**
     * Lit la ligne d'historique d'un champ donné via une requête SQL native,
     * dans la transaction courante (avant rollback).
     *
     * @return array<string, mixed>|false
     */
    private function lireHistorique(int $utilisateurId, string $champModifie): array|false
    {
        return $this->entityManager->getConnection()->executeQuery(
            'SELECT champ_modifie, ancienne_valeur, nouvelle_valeur, date_modification
             FROM historique_utilisateur
             WHERE utilisateur_id = ? AND champ_modifie = ?',
            [$utilisateurId, $champModifie],
        )->fetchAssociative();
    }

    /**
     * Appelle la procédure stockée et renvoie l'historique.
     *
     * Point délicat PDO : un CALL renvoie plusieurs jeux de résultats (le SELECT
     * + un statut). On vide donc tout (fetchAllAssociative) puis on libère le
     * curseur (free()) pour éviter « Cannot execute queries while other
     * unbuffered queries are active » sur la requête suivante / le rollback.
     *
     * @return list<array<string, mixed>>
     */
    private function appelerProcedureConsulter(int $utilisateurId): array
    {
        $resultat = $this->entityManager->getConnection()->executeQuery(
            'CALL consulter_historique_utilisateur(?)',
            [$utilisateurId],
        );

        $lignes = $resultat->fetchAllAssociative();
        $resultat->free();

        return $lignes;
    }
}
