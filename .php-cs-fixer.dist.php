<?php

declare(strict_types=1);

/*
 * Configuration PHP-CS-Fixer du projet CreaSlot (US-7.1).
 *
 * Périmètre : code applicatif (src/) et tests (tests/) uniquement. Sont donc hors
 * scope, par construction : var/, vendor/, config/, public/, et migrations/ (les
 * migrations Doctrine générées sont volontairement laissées telles quelles — leur
 * reformatage est bruyant et sans valeur ; à rediscuter si l'on veut les inclure).
 *
 * setRiskyAllowed(false) : aucune règle « risky » (qui pourrait changer la
 * sémantique du code). Seul du formatage sûr est appliqué.
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ]);

/*
 * Déviations assumées par rapport à @Symfony — chacune préserve une convention de
 * lisibilité délibérée du projet (justifications pour la soutenance) :
 *
 *  - php_unit_method_casing (snake_case) : les méthodes de test sont nommées en
 *    snake_case français descriptif (test_le_jeton_reutilise_est_refuse) — c'est
 *    un choix de lisibilité (CLAUDE.md « noms révélateurs d'intention ») ; @Symfony
 *    imposerait du camelCase, moins lisible pour des phrases.
 *  - concat_space (one) : concaténation espacée ('a' . $b), comme tout le code et
 *    les exemples de CLAUDE.md ; @Symfony colle les opérandes ('a'.$b).
 *  - yoda_style (false) : conditions en ordre naturel ($x !== 'now') plutôt qu'en
 *    Yoda ('now' !== $x), plus proche de la lecture courante.
 *  - binary_operator_spaces (=> aligné) : on conserve l'alignement des `=>` dans
 *    les tableaux, omniprésent dans le code (lisibilité des structures de config).
 *  - trailing_comma_in_multiline : virgule finale étendue aux arguments et
 *    paramètres (PHP 8.0+), déjà présente dans le code — harmonisation.
 */
return (new PhpCsFixer\Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12'   => true,
        '@Symfony' => true,
        'php_unit_method_casing'      => ['case' => 'snake_case'],
        'concat_space'                => ['spacing' => 'one'],
        'yoda_style'                  => false,
        'binary_operator_spaces'      => ['default' => 'single_space', 'operators' => ['=>' => 'align_single_space_minimal']],
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
    ])
    ->setFinder($finder);
