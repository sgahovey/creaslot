<?php
declare(strict_types=1);

$phpVersion        = PHP_VERSION;
$appEnv            = $_ENV['APP_ENV']                ?? getenv('APP_ENV')                ?: 'inconnu';
$environmentLabel  = $_ENV['APP_ENVIRONMENT_LABEL']  ?? getenv('APP_ENVIRONMENT_LABEL')  ?: '';
$databaseUrl       = $_ENV['DATABASE_URL']           ?? getenv('DATABASE_URL')           ?: 'non définie';

// opcache_get_status(false) est le seul test fiable : extension_loaded('opcache')
// retourne false car OPcache est une zend_extension, pas une extension classique.
$extensionsStatus = [
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'intl'      => extension_loaded('intl'),
    'zip'       => extension_loaded('zip'),
    'opcache'   => function_exists('opcache_get_status') && opcache_get_status(false) !== false,
];
$toutesChargees = !in_array(false, $extensionsStatus, true);

$opcacheStats = $extensionsStatus['opcache'] ? opcache_get_status(false) : null;

// Le bandeau obéit uniquement à APP_ENVIRONMENT_LABEL, pas à APP_ENV.
// Deux états possibles selon le design system : "preprod" = bandeau orange, tout le reste = rien.
$afficherBandeau = $environmentLabel === 'preprod';
$bandeauCouleur  = '#FD7E14';
$bandeauTexte    = 'PRÉ-PRODUCTION';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CreaSlot — Environnement Docker</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background-color: #F8F9FA;
            color: #212529;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .bandeau {
            background-color: <?= htmlspecialchars($bandeauCouleur) ?>;
            color: #fff;
            text-align: center;
            font-weight: 700;
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            padding: 0.375rem;
        }

        .container {
            max-width: 720px;
            margin: 3rem auto;
            padding: 0 1rem;
            flex: 1;
        }

        .card {
            background: #fff;
            border: 1px solid #DEE2E6;
            border-radius: 0.5rem;
            padding: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }

        .logo {
            font-size: 1.75rem;
            font-weight: 800;
            color: #0066CC;
            margin-bottom: 0.25rem;
        }

        .subtitle {
            color: #6C757D;
            font-size: 0.9rem;
            margin-bottom: 2rem;
        }

        .status-ok {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #D4EDDA;
            color: #155724;
            border: 1px solid #C3E6CB;
            border-radius: 0.375rem;
            padding: 0.75rem 1.25rem;
            font-weight: 600;
            margin-bottom: 2rem;
        }

        .section-title {
            font-size: 0.75rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #6C757D;
            margin-bottom: 0.75rem;
            padding-bottom: 0.375rem;
            border-bottom: 1px solid #DEE2E6;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
        }

        .info-item { font-size: 0.875rem; }
        .info-label { color: #6C757D; margin-bottom: 0.125rem; }
        .info-value { font-weight: 600; font-family: 'Courier New', monospace; }

        .ext-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }

        .ext-badge {
            font-size: 0.8rem;
            padding: 0.25rem 0.625rem;
            border-radius: 999px;
            font-family: 'Courier New', monospace;
        }
        .ext-ok  { background: #D4EDDA; color: #155724; }
        .ext-ko  { background: #F8D7DA; color: #721C24; }

        .next-step {
            background: #E9ECEF;
            border-radius: 0.375rem;
            padding: 1rem 1.25rem;
            font-size: 0.875rem;
            color: #495057;
        }
        .next-step strong { color: #212529; }

        .opcache-details {
            margin-top: 0.75rem;
            border: 1px solid #DEE2E6;
            border-radius: 0.375rem;
            font-size: 0.8rem;
        }
        .opcache-details summary {
            padding: 0.5rem 0.75rem;
            cursor: pointer;
            color: #0066CC;
            user-select: none;
            list-style: none;
        }
        .opcache-details summary::-webkit-details-marker { display: none; }
        .opcache-details summary::before { content: '+ '; font-weight: 700; }
        .opcache-details[open] summary::before { content: '- '; }
        .opcache-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            padding: 0.75rem;
            border-top: 1px solid #DEE2E6;
            background: #F8F9FA;
            border-radius: 0 0 0.375rem 0.375rem;
        }
        .opcache-stat { text-align: center; }
        .opcache-stat-value { font-weight: 700; font-size: 1rem; color: #155724; font-family: 'Courier New', monospace; }
        .opcache-stat-label { color: #6C757D; font-size: 0.7rem; margin-top: 0.125rem; }
    </style>
</head>
<body>

    <?php if ($afficherBandeau): ?>
    <div class="bandeau" role="status" aria-label="Environnement pré-production">
        <?= htmlspecialchars($bandeauTexte) ?>
    </div>
    <?php endif; ?>

    <div class="container">
        <div class="card">
            <div class="logo">CreaSlot</div>
            <div class="subtitle">Application de gestion de rendez-vous — Cnam Réunion</div>

            <div class="status-ok" role="status">
                &#10003; Environnement Docker fonctionnel
            </div>

            <div class="section-title">Informations système</div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Version PHP</div>
                    <div class="info-value"><?= htmlspecialchars($phpVersion) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Environnement (APP_ENV)</div>
                    <div class="info-value"><?= htmlspecialchars($appEnv) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">SAPI</div>
                    <div class="info-value"><?= htmlspecialchars(PHP_SAPI) ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label">Système</div>
                    <div class="info-value"><?= htmlspecialchars(PHP_OS_FAMILY) ?></div>
                </div>
            </div>

            <div class="section-title">Extensions PHP requises par Symfony</div>
            <div class="ext-list">
                <?php foreach ($extensionsStatus as $nom => $chargee): ?>
                <span class="ext-badge <?= $chargee ? 'ext-ok' : 'ext-ko' ?>">
                    <?= $chargee ? '&#10003;' : '&#10007;' ?> <?= htmlspecialchars($nom) ?>
                </span>
                <?php endforeach; ?>
            </div>

            <?php if ($opcacheStats !== null): ?>
            <?php
                $memUsed  = round($opcacheStats['memory_usage']['used_memory'] / 1024 / 1024, 1);
                $memFree  = round($opcacheStats['memory_usage']['free_memory'] / 1024 / 1024, 1);
                $hits     = number_format($opcacheStats['opcache_statistics']['hits']);
                $misses   = number_format($opcacheStats['opcache_statistics']['misses']);
                $scripts  = number_format($opcacheStats['opcache_statistics']['num_cached_scripts']);
            ?>
            <details class="opcache-details">
                <summary>OPcache — statistiques détaillées</summary>
                <div class="opcache-stats-grid">
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars((string)$memUsed) ?> Mo</div>
                        <div class="opcache-stat-label">Mémoire utilisée</div>
                    </div>
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars((string)$memFree) ?> Mo</div>
                        <div class="opcache-stat-label">Mémoire libre</div>
                    </div>
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars($scripts) ?></div>
                        <div class="opcache-stat-label">Scripts en cache</div>
                    </div>
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars($hits) ?></div>
                        <div class="opcache-stat-label">Hits</div>
                    </div>
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars($misses) ?></div>
                        <div class="opcache-stat-label">Misses</div>
                    </div>
                    <div class="opcache-stat">
                        <div class="opcache-stat-value"><?= htmlspecialchars($opcacheStats['opcache_statistics']['oom_restarts']) ?></div>
                        <div class="opcache-stat-label">OOM restarts</div>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <div class="next-step">
                <strong>Prochaine étape :</strong>
                Cette page sera remplacée par le front controller Symfony lors de l'US-1.2
                (installation de Symfony 8 et configuration de Doctrine ORM).
            </div>
        </div>
    </div>

</body>
</html>
