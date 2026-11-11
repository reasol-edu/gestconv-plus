import { Controller } from '@hotwired/stimulus';

// Muestra un bloque solo mientras una casilla está marcada (p. ej. la opción de detalle del resumen de
// jornadas de un periodo, que solo tiene sentido si el resumen está activado). Meramente visual: el
// servidor valida igualmente lo que reciba.
export default class extends Controller {
    static targets = ['trigger', 'content'];

    connect() {
        this.update();
    }

    update() {
        const checked = this.triggerTarget.checked;
        for (const block of this.contentTargets) {
            block.hidden = !checked;
        }
    }
}
