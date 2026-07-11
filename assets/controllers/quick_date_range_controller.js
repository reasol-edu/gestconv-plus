import { Controller } from '@hotwired/stimulus';

// Botones de rango rápido en filtros de fecha: rellenan "desde"/"hasta" con
// hoy y N días atrás, y envían el formulario. Uso: data-controller="quick-date-range"
// en el <form>, data-quick-date-range-target="from|to" en los <input type="date">,
// y data-action="quick-date-range#apply" data-quick-date-range-days-param="N" en el botón.
export default class extends Controller {
    static targets = ['from', 'to'];

    apply(event) {
        const days = parseInt(event.params.days, 10);
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - days);

        this.fromTarget.value = this.toIsoDate(from);
        this.toTarget.value = this.toIsoDate(to);

        this.element.requestSubmit();
    }

    toIsoDate(date) {
        return date.toISOString().slice(0, 10);
    }
}
