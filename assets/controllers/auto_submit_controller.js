import { Controller } from '@hotwired/stimulus';

// Envía el formulario en cuanto cambia uno de sus filtros (selects), sin botón de «Filtrar».
// Uso: <form data-controller="auto-submit"> con `data-action="change->auto-submit#submit"` en cada campo.
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
