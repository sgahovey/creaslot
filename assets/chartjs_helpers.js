/* Helpers partages des graphiques Chart.js (DT-17).
   Chart.js est fourni par le bundle UMD (window.Chart), pas par l'ESM (cf. DT-8). */

/* Lit un token de charte --cs-* ; repli sur une couleur sure si absent. */
export function couleurToken(nomToken, repli) {
    const valeur = getComputedStyle(document.documentElement).getPropertyValue(nomToken).trim();
    return valeur !== '' ? valeur : repli;
}

/* Garde : window.Chart doit etre charge avant l'init. Loggue et retourne false sinon. */
export function chartEstDisponible() {
    if (typeof window.Chart === 'undefined') {
        console.error(
            "Chart.js n'est pas chargé : vérifiez la <script> du bundle UMD "
            + '(assets/vendor/chartjs/chart.umd.min.js) dans le bloc javascripts du template.'
        );
        return false;
    }
    return true;
}
