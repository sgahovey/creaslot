import { Controller } from '@hotwired/stimulus';

/*
 * Graphiques de la page Statistiques Super-admin (US-5.8).
 *
 * Un seul contrôleur pilote les deux graphiques de la page :
 *  - barres (axe service) : créneaux offerts vs réservés, deux datasets groupés ;
 *  - doughnut (axe type)  : répartition des créneaux réservés par type de RDV.
 *
 * Chart.js v4 est fourni par son bundle UMD officiel, vendorisé dans
 * assets/vendor/chartjs/ et inclus via une <script> classique par le template.
 * Il expose window.Chart. On NE PASSE PAS par l'ESM (chart.js/auto en importmap) :
 * même piège que FullCalendar (DT-8). couleurToken/garde window.Chart/cycle
 * connect-disconnect sont dupliqués depuis graphique_occupation_controller (DT-17).
 *
 * Les séries sont transmises par attributs data-value JSON (échappés côté Twig) ;
 * chaque <canvas role="img" aria-label> est doublé d'un <table> RGAA (le <canvas>
 * n'est pas lisible par un lecteur d'écran).
 */
export default class extends Controller {
    static targets = ['barres', 'doughnut'];

    static values = {
        service: Array,
        type: Array,
    };

    connect() {
        if (typeof window.Chart === 'undefined') {
            console.error(
                "Chart.js n'est pas chargé : vérifiez la <script> du bundle UMD "
                + '(assets/vendor/chartjs/chart.umd.min.js) dans le bloc javascripts du template.'
            );
            return;
        }

        if (this.hasBarresTarget) {
            this.graphiqueBarres = new window.Chart(this.barresTarget, this.configurationBarres());
        }
        if (this.hasDoughnutTarget) {
            this.graphiqueDoughnut = new window.Chart(this.doughnutTarget, this.configurationDoughnut());
        }
    }

    disconnect() {
        if (this.graphiqueBarres) {
            this.graphiqueBarres.destroy();
            this.graphiqueBarres = null;
        }
        if (this.graphiqueDoughnut) {
            this.graphiqueDoughnut.destroy();
            this.graphiqueDoughnut = null;
        }
    }

    /* Axe service : barres groupées offerts / réservés. Tableau vide → Chart.js
       rend des axes propres sans série (pas de graphe cassé). */
    configurationBarres() {
        const lignes = this.serviceValue;

        return {
            type: 'bar',
            data: {
                labels: lignes.map((ligne) => ligne.libelle),
                datasets: [
                    {
                        label: 'Créneaux offerts',
                        data: lignes.map((ligne) => ligne.offre),
                        backgroundColor: this.couleurToken('--cs-blue-primary', '#0d6efd'),
                    },
                    {
                        label: 'Dont réservés',
                        data: lignes.map((ligne) => ligne.reserves),
                        backgroundColor: this.couleurToken('--cs-success', '#198754'),
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 },
                    },
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                    },
                },
            },
        };
    }

    /* Axe type : répartition des réservés. Couleur métier du TypeRdv ; repli sur le
       token bleu si une couleur manque. Aucune réservation → doughnut vide propre. */
    configurationDoughnut() {
        const lignes = this.typeValue;
        const repliBleu = this.couleurToken('--cs-blue-primary', '#0d6efd');

        return {
            type: 'doughnut',
            data: {
                labels: lignes.map((ligne) => ligne.libelle),
                datasets: [
                    {
                        label: 'Créneaux réservés',
                        data: lignes.map((ligne) => ligne.reserves),
                        backgroundColor: lignes.map((ligne) => ligne.couleurHex || repliBleu),
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                    },
                },
            },
        };
    }

    /* Lit un token de charte --cs-* ; repli sur une couleur sûre si absent.
       (Dupliqué depuis graphique_occupation_controller — cf. DT-17.) */
    couleurToken(nomToken, repli) {
        const valeur = getComputedStyle(document.documentElement).getPropertyValue(nomToken).trim();
        return valeur !== '' ? valeur : repli;
    }
}
