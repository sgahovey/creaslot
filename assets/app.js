import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 *
 * NB : assets/styles/app.css n'est PAS importé ici — il est chargé via <link> dans
 * base.html.twig. Un import CSS-depuis-JS génèrerait une entrée importmap
 * "data:application/javascript," que le navigateur charge comme module, bloquée par
 * un script-src strict (US-9.2/CSP).
 */

// Bootstrap JS self-hosté via importmap (DT-13) : l'import enregistre le data-api
// (data-bs-* : dropdowns, navbar collapse, modales…). Remplace le <script> CDN.
import 'bootstrap';
