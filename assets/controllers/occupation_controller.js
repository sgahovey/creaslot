import { Controller } from '@hotwired/stimulus';

/*
 * Vue globale occupé/libre du Super-admin (US-5.7), FullCalendar v6 self-hosté.
 *
 * Lecture seule : pas de toggle, pas de modal d'édition, pas de lien « Modifier ».
 * Le bundle global officiel de FullCalendar (window.FullCalendar) est inclus par
 * des <script> classiques dans le bloc javascripts du template (cf. agenda).
 *
 * Helpers (échappement, couleurs) embarqués localement à dessein : on ne touche
 * pas agenda_controller.js. La mutualisation est tracée en dette technique [[DT-16]].
 */

function escapeHtml(str) {
    if (str == null || str === '') {
        return '';
    }
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function heureSlot(d) {
    if (!d) {
        return '';
    }
    return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

function hexVersRgb(hex) {
    let h = String(hex).replace('#', '');
    if (h.length === 3) {
        h = h.split('').map(function (c) { return c + c; }).join('');
    }
    const n = parseInt(h, 16);
    if (isNaN(n)) return { r: 40, g: 40, b: 40 };
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function melangerBlanc(hex, ratio) {
    const c = hexVersRgb(hex);
    const t = Math.min(1, Math.max(0, ratio));
    const r = Math.round(c.r + (255 - c.r) * t);
    const g = Math.round(c.g + (255 - c.g) * t);
    const b = Math.round(c.b + (255 - c.b) * t);
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}

export default class extends Controller {
    static targets = ['calendar'];

    static values = {
        urlApi: String,
        filtreService: String,
        filtreType: String,
    };

    connect() {
        if (!this.hasCalendarTarget) {
            return;
        }

        if (typeof window.FullCalendar === 'undefined' || !window.FullCalendar.Calendar) {
            console.error(
                "FullCalendar n'est pas chargé : vérifiez les <script> du bundle global "
                + 'dans le bloc javascripts du template d\'occupation.'
            );
            return;
        }

        this.calendar = this.construireCalendrier();
        this.calendar.render();

        const titreCalendrier = this.calendarTarget.querySelector('.fc-toolbar-title');
        if (titreCalendrier) {
            titreCalendrier.setAttribute('aria-live', 'polite');
        }
    }

    disconnect() {
        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
    }

    construireCalendrier() {
        const { Calendar } = window.FullCalendar;
        return new Calendar(this.calendarTarget, {
            initialView: 'timeGridWeek',
            locale: 'fr',
            firstDay: 1,
            weekends: false,
            slotMinTime: '08:30:00',
            slotMaxTime: '17:00:00',
            slotDuration: '00:30:00',
            slotLabelInterval: '01:00:00',
            slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
            nowIndicator: true,
            height: 'auto',
            headerToolbar: {
                left: 'timeGridDay,timeGridWeek,dayGridMonth',
                center: 'prev,title,next',
                right: 'today',
            },
            buttonText: {
                today: 'Aujourd\'hui',
                month: 'Mois',
                week: 'Semaine',
                day: 'Jour',
            },
            eventSources: [
                {
                    id: 'occupation-globale',
                    url: this.urlApiValue,
                    method: 'GET',
                    extraParams: () => {
                        return {
                            service: this.filtreServiceValue,
                            type: this.filtreTypeValue,
                        };
                    },
                    failure: () => {
                        this.calendarTarget.setAttribute('aria-busy', 'false');
                        console.error('Impossible de charger l\'occupation.');
                    },
                },
            ],
            loading: (bool) => {
                this.calendarTarget.setAttribute('aria-busy', bool ? 'true' : 'false');
            },
            eventClassNames: function (arg) {
                const p = arg.event.extendedProps || {};
                const cs = ['cs-fc-creneau'];
                if (p.isPasse === true || p.isPasse === 'true') {
                    cs.push('fc-event-passe');
                }
                if (p.occupe === true || p.occupe === 'true') {
                    cs.push('fc-event-occupe');
                } else {
                    cs.push('fc-event-libre');
                }
                return cs;
            },
            eventContent: function (arg) {
                const p = arg.event.extendedProps || {};
                const personnel = p.personnelNom || '';
                const type = p.typeRdv || '';
                const etat = p.etat || '';
                let horaire = '';
                if (arg.event.start && arg.event.end) {
                    horaire = heureSlot(arg.event.start) + ' – ' + heureSlot(arg.event.end);
                }
                return {
                    html:
                        '<div class="fc-event-main-frame cs-fc-lines px-1 py-1">' +
                        '<div class="cs-fc-line-time">' + escapeHtml(horaire || '—') + '</div>' +
                        '<div class="cs-fc-line-personnel">' + escapeHtml(personnel || '—') + '</div>' +
                        '<div class="cs-fc-line-type">' + escapeHtml(type || '—') + '</div>' +
                        '<div class="cs-fc-line-etat">' + escapeHtml(etat || '—') + '</div>' +
                        '</div>',
                };
            },
            eventDidMount: function (arg) {
                const p = arg.event.extendedProps || {};
                const hex = p.typeCouleurHex || arg.event.backgroundColor || '#6c757d';
                const occupe = p.occupe === true || p.occupe === 'true';
                const el = arg.el;
                /* Plein = occupé, clair = libre ; texte foncé via creaslot.css — contraste RGAA. */
                el.style.border = '2px solid ' + hex;
                el.style.backgroundColor = occupe ? melangerBlanc(hex, 0.56) : melangerBlanc(hex, 0.82);
                el.style.removeProperty('color');
            },
            eventClick: () => {
                /* Lecture seule : le détail accessible est la table sous le calendrier. */
            },
        });
    }
}
