import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

// Bootstrap JS self-hosté via importmap (DT-13) : l'import enregistre le data-api
// (data-bs-* : dropdowns, navbar collapse, modales…). Remplace le <script> CDN.
import 'bootstrap';
