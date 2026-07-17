import { Controller } from '@hotwired/stimulus';

// Formulario de ausencia: al cambiar la fecha de inicio, si la fecha de fin
// está vacía o es anterior a la nueva fecha de inicio, la iguala a ella.
// Uso: data-controller="absence-dates" en el <form>, data-absence-dates-target="endDate"
// en el <input> de fecha de fin, y data-action="absence-dates#syncEndDate"
// data-absence-dates-target="startDate" en el <input> de fecha de inicio.
export default class extends Controller {
    static targets = ['startDate', 'endDate'];

    syncEndDate() {
        const startValue = this.startDateTarget.value;

        if (this.endDateTarget.value === '' || this.endDateTarget.value < startValue) {
            this.endDateTarget.value = startValue;
        }
    }
}
