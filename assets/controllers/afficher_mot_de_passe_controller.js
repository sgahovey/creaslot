import { Controller } from '@hotwired/stimulus';

/*
 * Bascule afficher/masquer un champ mot de passe (US-6.1).
 *
 * Amélioration progressive : sans JS, le champ reste un mot de passe masqué
 * normal. Le contrôleur permute le type du champ (password ↔ text) et l'icône
 * (œil ↔ œil barré), et met à jour l'état accessible du bouton (aria-pressed +
 * aria-label) pour les lecteurs d'écran (RGAA).
 *
 * Rien ne casse si un target manque (gardes hasXxxTarget).
 */
export default class extends Controller {
    static targets = ['champ', 'icone'];

    basculer(event) {
        if (!this.hasChampTarget) {
            return;
        }

        const bouton  = event.currentTarget;
        const afficher = this.champTarget.type === 'password';

        this.champTarget.type = afficher ? 'text' : 'password';

        if (this.hasIconeTarget) {
            this.iconeTarget.classList.toggle('bi-eye', !afficher);
            this.iconeTarget.classList.toggle('bi-eye-slash', afficher);
        }

        bouton.setAttribute('aria-pressed', afficher ? 'true' : 'false');
        bouton.setAttribute('aria-label', afficher ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    }
}
