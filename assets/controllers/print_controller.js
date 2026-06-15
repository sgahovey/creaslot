import { Controller } from '@hotwired/stimulus';

/*
 * Déclenche l'impression de la page (remplace onclick="window.print()" — DT-13/CSP,
 * US-9.2). Évite tout gestionnaire inline pour rester compatible avec un script-src
 * strict (sans 'unsafe-inline').
 *
 * Usage : <button type="button" data-controller="print" data-action="print#now">
 */
export default class extends Controller {
    now() {
        window.print();
    }
}
