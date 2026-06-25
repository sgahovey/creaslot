/* Helpers partages des calendriers FullCalendar (DT-16).
   FullCalendar est fourni par le bundle global (window.FullCalendar), pas par l'ESM (cf. DT-8). */

/* Echappe les caracteres HTML sensibles (&, <, >, "). */
export function escapeHtml(str) {
    if (str == null || str === '') {
        return '';
    }
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* Heure 'HH:MM' (locale fr-FR) d'une Date, ou '' si absente. */
export function heureSlot(d) {
    if (!d) {
        return '';
    }
    return d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
}

/* '#rrggbb' (ou '#rgb') -> { r, g, b } ; gris de repli si invalide. */
export function hexVersRgb(hex) {
    let h = String(hex).replace('#', '');
    if (h.length === 3) {
        h = h.split('').map(function (c) { return c + c; }).join('');
    }
    const n = parseInt(h, 16);
    if (isNaN(n)) return { r: 40, g: 40, b: 40 };
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

/* Melange une couleur hex avec du blanc (ratio 0..1) -> 'rgb(r,g,b)'. */
export function melangerBlanc(hex, ratio) {
    const c = hexVersRgb(hex);
    const t = Math.min(1, Math.max(0, ratio));
    const r = Math.round(c.r + (255 - c.r) * t);
    const g = Math.round(c.g + (255 - c.g) * t);
    const b = Math.round(c.b + (255 - c.b) * t);
    return 'rgb(' + r + ',' + g + ',' + b + ')';
}
