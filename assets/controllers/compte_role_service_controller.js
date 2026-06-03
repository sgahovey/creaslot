import { Controller } from '@hotwired/stimulus';

/*
 * Cohérence rôle ↔ service (US-5.3) — amélioration progressive du formulaire
 * d'administration des comptes (création ET édition, formulaire partagé).
 *
 * La GARANTIE reste côté serveur (listener POST_SUBMIT de UtilisateurAdminType :
 * un Auditeur n'a jamais de service). Ce contrôleur ne fait que RENDRE VISIBLE la
 * règle : quand le rôle Auditeur est choisi, le service est vidé puis grisé.
 *
 * Sans dépendance ; rien ne casse si un target manque (gardes hasXxxTarget).
 */

const ROLE_AUDITEUR = 'ROLE_AUDITEUR';

export default class extends Controller {
    static targets = ['role', 'service'];

    connect() {
        this.actualiser();
    }

    actualiser() {
        if (!this.hasRoleTarget || !this.hasServiceTarget) {
            return;
        }

        const estAuditeur = this.roleTarget.value === ROLE_AUDITEUR;

        if (estAuditeur) {
            this.serviceTarget.value = '';
        }
        this.serviceTarget.disabled = estAuditeur;
    }
}
