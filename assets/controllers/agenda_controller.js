import { Controller } from '@hotwired/stimulus';

/*
 * FullCalendar v6 est fourni par son bundle global officiel (core + plugins + preact
 * en un seul fichier au linking interne cohérent), vendorisé dans assets/vendor/ et
 * inclus via des <script> classiques par le template de l'agenda. Il expose
 * window.FullCalendar (cf. construireCalendrier). On NE PASSE PAS par l'ESM jsDelivr
 * (@fullcalendar/* + preact éclatés) : il dédouble le runtime core et provoque
 * « Class constructor component cannot be invoked without 'new' ».
 */

/*
 * Agenda visuel du Personnel (FullCalendar v6, self-hosté via AssetMapper).
 *
 * Remplace l'ancien chargement CDN + script inline : le JS vit désormais dans
 * ce contrôleur Stimulus (pattern natif AssetMapper du projet).
 *
 * Les 3 contrôles DOM du template (toggle disponibilités, boutons « Suivant »
 * et « Aujourd'hui » de l'overlay vide) sont câblés via data-action. Les
 * callbacks internes à FullCalendar (eventClick, eventContent, loading…)
 * restent en configuration JS car FullCalendar génère son propre DOM.
 */

const LS_KEY_AGENDA_DISPONIBILITES = 'agenda_show_disponibilites';

function formaterDateFr(date) {
    return date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

function formaterHeure(date) {
    return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

function heureSlot(d) {
    if (!d) {
        return '';
    }
    return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

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
    static targets = [
        'calendar',
        'emptyOverlay',
        'toggle',
        'modal',
        'modalTitre',
        'modalType',
        'modalDate',
        'modalHoraires',
        'modalStatut',
        'modalAuditeur',
        'modalCommentaire',
        'modalMotifAuditeur',
        'lienModifier',
    ];

    static values = {
        urlApi: String,
        urlNextReserved: String,
        urlModifierModele: String,
    };

    connect() {
        if (!this.hasCalendarTarget) {
            return;
        }

        if (typeof window.FullCalendar === 'undefined' || !window.FullCalendar.Calendar) {
            console.error(
                "FullCalendar n'est pas chargé : vérifiez les <script> du bundle global "
                + 'dans le bloc javascripts du template de l\'agenda.'
            );
            return;
        }

        this.showDisponibilites = false;
        if (this.hasToggleTarget) {
            this.showDisponibilites = localStorage.getItem(LS_KEY_AGENDA_DISPONIBILITES) === 'true';
            this.toggleTarget.checked = this.showDisponibilites;
        }

        this.calendar = this.construireCalendrier();
        this.calendar.render();

        this.majOverlayCreationAgendaVide();
        this.decorerToolbarMesProchainsRdv();

        const titreCal = this.calendarTarget.querySelector('.fc-toolbar-title');
        if (titreCal) {
            titreCal.setAttribute('aria-live', 'polite');
        }
    }

    disconnect() {
        if (this.calendar) {
            this.calendar.destroy();
            this.calendar = null;
        }
    }

    /* ── Actions liées au template (data-action) ─────────────────────────── */

    onToggleDispo() {
        if (!this.hasToggleTarget) {
            return;
        }
        this.showDisponibilites = this.toggleTarget.checked;
        localStorage.setItem(LS_KEY_AGENDA_DISPONIBILITES, this.showDisponibilites ? 'true' : 'false');
        const srcCreneaux = this.calendar.getEventSourceById('creneaux-personnel');
        if (srcCreneaux) {
            srcCreneaux.refetch();
        }
    }

    allerSuivant() {
        if (this.calendar) {
            this.calendar.next();
        }
    }

    allerAujourdhui() {
        if (this.calendar) {
            this.calendar.today();
        }
    }

    /* ── Internes ────────────────────────────────────────────────────────── */

    reserveOnlyQueryParam() {
        return this.showDisponibilites ? 'false' : 'true';
    }

    get sourcePauseDejeuner() {
        return {
            events: function (info, successCallback) {
                successCallback([
                    {
                        groupId: 'pause-dejeuner',
                        daysOfWeek: [1, 2, 3, 4, 5],
                        startTime: '12:30:00',
                        endTime: '13:30:00',
                        title: ' ',
                        editable: false,
                        startEditable: false,
                        durationEditable: false,
                        display: 'auto',
                        overlap: true,
                        extendedProps: { estPause: true },
                    },
                ]);
            },
        };
    }

    nombreEvenementsHorsPause() {
        const es = this.calendar.getEvents();
        let n = 0;
        for (let i = 0; i < es.length; i++) {
            const px = es[i].extendedProps || {};
            if (!px.estPause) {
                n += 1;
            }
        }
        return n;
    }

    majOverlayCreationAgendaVide() {
        if (!this.hasEmptyOverlayTarget || !this.calendar) {
            return;
        }
        if (this.calendarTarget.getAttribute('aria-busy') === 'true') {
            return;
        }
        const vide = this.nombreEvenementsHorsPause() === 0;
        this.emptyOverlayTarget.hidden = !vide;
        this.emptyOverlayTarget.setAttribute('aria-hidden', vide ? 'false' : 'true');
    }

    flashInfoAgenda(message) {
        const live = document.createElement('div');
        live.className = 'alert alert-info mb-0 py-2 shadow-sm cs-agenda-flash-live';
        live.setAttribute('role', 'status');
        live.textContent = message;
        document.body.appendChild(live);
        window.setTimeout(function () {
            live.remove();
        }, 3800);
    }

    decorerToolbarMesProchainsRdv() {
        const shell = this.calendarTarget.closest('.cs-fc-calendar-shell');
        if (!shell) {
            return;
        }
        const btns = shell.querySelectorAll('.fc-header-toolbar button.fc-button');
        btns.forEach(function (btn) {
            if (btn.textContent.indexOf('Mes prochains RDV') === -1) {
                return;
            }
            btn.innerHTML =
                '<i class="bi bi-calendar-event me-1" aria-hidden="true"></i>' +
                '<span>Mes prochains RDV</span>';
            btn.setAttribute(
                'aria-label',
                'Mes prochains RDV — afficher la semaine du prochain rendez-vous réservé'
            );
        });
    }

    allerVersProchainsRdvReserve() {
        if (!this.hasUrlNextReservedValue || this.urlNextReservedValue === '') {
            this.flashInfoAgenda('Aucun rendez-vous à venir.');
            return;
        }
        fetch(this.urlNextReservedValue, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((r) => {
                if (!r.ok) {
                    throw new Error('HTTP');
                }
                return r.json();
            })
            .then((data) => {
                if (!data.date) {
                    this.flashInfoAgenda('Aucun rendez-vous à venir.');
                    return;
                }
                this.calendar.changeView('timeGridWeek', data.date);
                window.requestAnimationFrame(() => {
                    this.decorerToolbarMesProchainsRdv();
                });
            })
            .catch(() => {
                this.flashInfoAgenda('Impossible de retrouver vos prochains rendez-vous. Réessayez plus tard.');
            });
    }

    construireCalendrier() {
        // Bundle global : les plugins (timeGrid, dayGrid…) sont auto-enregistrés dans
        // FullCalendar.globalPlugins, inutile de passer un tableau `plugins`. La locale
        // « fr » est référencée par sa clé (enregistrée via l'import de fr.global.min.js).
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
            customButtons: {
                mesProchainsRdv: {
                    text: 'Mes prochains RDV',
                    hint: 'Aller à la semaine du prochain rendez-vous réservé',
                    click: () => {
                        this.allerVersProchainsRdvReserve();
                    },
                },
            },
            headerToolbar: {
                left: 'timeGridDay,timeGridWeek,dayGridMonth',
                center: 'prev,title,next',
                right: 'mesProchainsRdv today',
            },
            buttonText: {
                today: 'Aujourd\'hui',
                month: 'Mois',
                week: 'Semaine',
                day: 'Jour',
            },
            eventSources: [
                {
                    id: 'creneaux-personnel',
                    url: this.urlApiValue,
                    method: 'GET',
                    extraParams: () => {
                        return { reserve_only: this.reserveOnlyQueryParam() };
                    },
                    failure: () => {
                        this.calendarTarget.setAttribute('aria-busy', 'false');
                        console.error('Impossible de charger les créneaux.');
                    },
                },
                this.sourcePauseDejeuner,
            ],
            loading: (bool) => {
                this.calendarTarget.setAttribute('aria-busy', bool ? 'true' : 'false');
                if (bool && this.hasEmptyOverlayTarget) {
                    this.emptyOverlayTarget.hidden = true;
                    this.emptyOverlayTarget.setAttribute('aria-hidden', 'true');
                }
                if (!bool) {
                    window.requestAnimationFrame(() => {
                        this.majOverlayCreationAgendaVide();
                    });
                }
            },
            eventsSet: () => {
                this.majOverlayCreationAgendaVide();
            },
            datesSet: () => {
                window.requestAnimationFrame(() => {
                    this.decorerToolbarMesProchainsRdv();
                });
            },
            eventClassNames: function (arg) {
                if (arg.event.extendedProps && arg.event.extendedProps.estPause) {
                    return ['cs-fc-pause-dejeuner'];
                }
                const p = arg.event.extendedProps || {};
                const cs = ['cs-fc-creneau'];
                if (p.isPasse === true || p.isPasse === 'true') {
                    cs.push('fc-event-passe');
                }
                if (p.reserve === true || p.reserve === 'true') {
                    cs.push('fc-event-reservee', 'creneau-reserve');
                } else {
                    cs.push('fc-event-libre');
                }
                return cs;
            },
            eventContent: function (arg) {
                if (arg.event.extendedProps && arg.event.extendedProps.estPause) {
                    return { html: '<div class="text-center w-100 small">PAUSE DÉJEUNER<br><span class="fw-normal">12h30 – 13h30</span></div>' };
                }
                const p = arg.event.extendedProps || {};
                const typeL = p.typeRdv || arg.event.title || '';
                const reserve = p.reserve === true || p.reserve === 'true';
                let horaire = '';
                if (arg.event.start && arg.event.end) {
                    horaire = heureSlot(arg.event.start) + ' – ' + heureSlot(arg.event.end);
                }
                let l3;
                if (!reserve) {
                    l3 = 'Disponible';
                } else {
                    const nomAu = (p.auditeurNom || '').trim();
                    l3 = 'Réservé par ' + (nomAu !== '' ? escapeHtml(nomAu) : '—');
                }
                return {
                    html:
                        '<div class="fc-event-main-frame cs-fc-lines px-1 py-1">' +
                        '<div class="cs-fc-line-time">' + escapeHtml(horaire || '—') + '</div>' +
                        '<div class="cs-fc-line-type">' + escapeHtml(typeL || '—') + '</div>' +
                        '<div class="cs-fc-line-etat">' + l3 + '</div>' +
                        '</div>',
                };
            },
            eventDidMount: function (arg) {
                if (arg.event.extendedProps && arg.event.extendedProps.estPause) {
                    arg.el.style.pointerEvents = 'none';
                    arg.el.setAttribute('aria-hidden', 'true');
                    return;
                }
                const p = arg.event.extendedProps || {};
                const hex = p.typeCouleurHex || arg.event.backgroundColor || '#6c757d';
                const reserve = p.reserve === true || p.reserve === 'true';
                const el = arg.el;
                /* Fonds pastel + texte foncé (#1a1a1a) via creaslot.css — contraste RGAA */
                el.style.border = '2px solid ' + hex;
                if (reserve) {
                    el.style.backgroundColor = melangerBlanc(hex, 0.56);
                } else {
                    el.style.backgroundColor = melangerBlanc(hex, 0.82);
                }
                el.style.removeProperty('color');
            },
            eventClick: (info) => {
                this.ouvrirModaleDetail(info);
            },
        });
    }

    ouvrirModaleDetail(info) {
        if (info.event.extendedProps && info.event.extendedProps.estPause) {
            return;
        }
        info.jsEvent.preventDefault();
        const ev = info.event;
        const p = ev.extendedProps || {};
        const deb = ev.start;
        const fin = ev.end;

        if (this.hasModalTitreTarget) this.modalTitreTarget.textContent = p.typeRdv || ev.title || 'Créneau';
        if (this.hasModalTypeTarget) this.modalTypeTarget.textContent = p.typeRdv || '—';

        if (this.hasModalDateTarget && deb) this.modalDateTarget.textContent = formaterDateFr(deb);
        if (this.hasModalHorairesTarget && deb && fin) {
            this.modalHorairesTarget.textContent = formaterHeure(deb) + ' – ' + formaterHeure(fin);
        }

        let statut = 'Disponible (libre)';
        if (p.isPasse === true || p.isPasse === 'true') {
            statut = p.reserve === true || p.reserve === 'true'
                ? 'Passé · Réservé'
                : 'Passé';
        } else if (p.reserve === true || p.reserve === 'true') {
            statut = 'Réservé';
        }
        if (this.hasModalStatutTarget) this.modalStatutTarget.textContent = statut;

        const audNom = (p.auditeurNom || '').trim();
        if (this.hasModalAuditeurTarget) this.modalAuditeurTarget.textContent = audNom !== '' ? audNom : '—';

        const com = (p.commentaire || '').trim();
        if (this.hasModalCommentaireTarget) this.modalCommentaireTarget.textContent = com !== '' ? com : '—';

        const motif = (p.motifAuditeur || '').trim();
        if (this.hasModalMotifAuditeurTarget) this.modalMotifAuditeurTarget.textContent = motif !== '' ? motif : '—';

        const id = ev.id;
        const futurPasPasse = p.isPasse !== true && p.isPasse !== 'true';

        if (this.hasLienModifierTarget) {
            const modele = this.urlModifierModeleValue;
            if (futurPasPasse && id && modele.indexOf('__ID__') !== -1) {
                this.lienModifierTarget.href = modele.replace('__ID__', String(id));
                this.lienModifierTarget.classList.remove('d-none');
                this.lienModifierTarget.removeAttribute('aria-disabled');
            } else {
                this.lienModifierTarget.classList.add('d-none');
                this.lienModifierTarget.href = '#';
                this.lienModifierTarget.setAttribute('aria-disabled', 'true');
            }
        }

        if (this.hasModalTarget && typeof window.bootstrap !== 'undefined') {
            window.bootstrap.Modal.getOrCreateInstance(this.modalTarget).show();
        }
    }
}
