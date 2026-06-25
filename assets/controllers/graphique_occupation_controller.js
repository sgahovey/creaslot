import { Controller } from '@hotwired/stimulus';
import { couleurToken, chartEstDisponible } from '../chartjs_helpers.js';

/*
 * Graphique d'occupation du tableau de bord Super-admin (US-5.2).
 *
 * Chart.js v4 est fourni par son bundle UMD officiel (batteries incluses),
 * vendorisé dans assets/vendor/chartjs/ et inclus via une <script> classique
 * par le template du dashboard. Il expose window.Chart. On NE PASSE PAS par
 * l'ESM (chart.js/auto en importmap) : même piège que FullCalendar (DT-8).
 *
 * La série est transmise par attribut data-value JSON (échappé côté Twig) ;
 * un <canvas role="img" aria-label> + un <table> RGAA fournissent l'alternative
 * textuelle (le <canvas> n'est pas lisible par un lecteur d'écran).
 */
export default class extends Controller {
    static targets = ['canvas'];

    static values = {
        series: Array,
    };

    connect() {
        if (!this.hasCanvasTarget) {
            return;
        }

        if (!chartEstDisponible()) {
            return;
        }

        this.chart = new window.Chart(this.canvasTarget, this.configurationGraphique());
    }

    disconnect() {
        if (this.chart) {
            this.chart.destroy();
            this.chart = null;
        }
    }

    configurationGraphique() {
        const series = this.seriesValue;

        return {
            type: 'bar',
            data: {
                labels: series.map((jour) => this.formaterJourCourt(jour.jour)),
                datasets: [
                    {
                        label: 'Créneaux offerts',
                        data: series.map((jour) => jour.offre),
                        backgroundColor: couleurToken('--cs-blue-primary', '#0d6efd'),
                    },
                    {
                        label: 'Dont réservés',
                        data: series.map((jour) => jour.reserves),
                        backgroundColor: couleurToken('--cs-success', '#198754'),
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

    /* 'YYYY-MM-DD' → 'JJ/MM' (pas de dépendance date, la chaîne est déjà en jour Réunion). */
    formaterJourCourt(jourIso) {
        return jourIso.slice(8, 10) + '/' + jourIso.slice(5, 7);
    }
}
