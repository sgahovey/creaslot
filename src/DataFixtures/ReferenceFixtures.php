<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Service;
use App\Entity\TypeRdv;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Données métier stables (Services et types de rendez-vous), chargeables aussi
 * bien en production qu'en préproduction. Les objets créés sont exposés via
 * addReference() pour être réutilisés par DemoFixtures.
 */
class ReferenceFixtures extends Fixture implements FixtureGroupInterface
{
    public const PREFIXE_SERVICE = 'service_';
    public const PREFIXE_TYPE = 'type_';

    public function load(ObjectManager $manager): void
    {
        $this->creerServices($manager);
        $this->creerTypesRdv($manager);

        $manager->flush();
    }

    public static function getGroups(): array
    {
        return ['reference'];
    }

    private function creerServices(ObjectManager $manager): void
    {
        $donnees = [
            ['Service Commercial', 'Responsables commerciaux du Cnam'],
            ['Service Alternance', 'Gestionnaires de l\'alternance'],
            ['Accueil',            'Accueil et orientation des auditeurs'],
        ];

        foreach ($donnees as $index => [$nom, $description]) {
            $service = new Service();
            $service->setNom($nom)->setDescription($description)->setEstActif(true);
            $manager->persist($service);
            $this->addReference(self::PREFIXE_SERVICE . $index, $service);
        }
    }

    private function creerTypesRdv(ObjectManager $manager): void
    {
        $donnees = [
            ['PRESENTIEL', 'Présentiel', '#28A745', 'bi-geo-alt',      'Rendez-vous en présentiel au Cnam Réunion'],
            ['VISIO',      'Visio',      '#FD7E14', 'bi-camera-video', 'Rendez-vous en visioconférence'],
            ['TELEPHONE',  'Téléphone',  '#007BFF', 'bi-telephone',    'Rendez-vous par téléphone'],
        ];

        foreach ($donnees as $index => [$code, $libelle, $couleur, $icone, $description]) {
            $type = new TypeRdv();
            $type->setCode($code)
                 ->setLibelle($libelle)
                 ->setCouleurHex($couleur)
                 ->setIcone($icone)
                 ->setDescription($description)
                 ->setEstActif(true);
            $manager->persist($type);
            $this->addReference(self::PREFIXE_TYPE . $index, $type);
        }
    }
}
