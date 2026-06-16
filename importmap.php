<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    // Bootstrap JS (data-api : dropdowns, navbar collapse, modales…) self-hosté via
    // AssetMapper (DT-13). @popperjs/core est sa dépendance (dropdowns/tooltips).
    // Le CSS Bootstrap n'est PAS dans l'importmap : il est vendorisé à la main et
    // chargé par <link> (cf. base.html.twig), pour maîtriser l'ordre de cascade
    // vis-à-vis de creaslot.css.
    'bootstrap' => [
        'version' => '5.3.8',
    ],
    '@popperjs/core' => [
        'version' => '2.11.8',
    ],
    // NB : FullCalendar v6 n'est PAS dans l'importmap. Son bundle global officiel
    // (assets/vendor/fullcalendar/) déclare `var FullCalendar` au niveau global d'un
    // script classique : chargé en ESM ce `var` resterait local au module et
    // window.FullCalendar serait indéfini. Il est donc inclus via des balises <script>
    // classiques dans templates/personnel/creneau/agenda.html.twig (bloc javascripts).
    // L'ESM jsDelivr (@fullcalendar/* + preact éclatés) est volontairement banni : il
    // dédouble le runtime core et casse le rendu (« Class constructor … 'new' »).
];
