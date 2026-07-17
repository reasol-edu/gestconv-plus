import { Controller } from '@hotwired/stimulus';

// Filtra las opciones del <select> de tramos horarios para mostrar solo las
// del día de la semana de la fecha seleccionada. Uso: data-controller="activity-time-slot"
// en el contenedor, data-activity-time-slot-target="date" en el <input type="date">,
// data-activity-time-slot-target="timeSlot" en el <select>, con data-action=
// "input->activity-time-slot#sync change->activity-time-slot#sync" en la fecha,
// y data-day-of-week="N" (0 = lunes) en cada <option> del select.
export default class extends Controller {
    static targets = ['date', 'timeSlot'];

    connect() {
        this.sync();
    }

    sync() {
        const dayOfWeek = this.dayOfWeekFromDate(this.dateTarget.value);
        const select = this.timeSlotTarget;
        const previousValue = select.value;
        let previousValueStillVisible = false;

        for (const option of select.options) {
            if (option.value === '') {
                continue;
            }

            const visible = dayOfWeek === null || option.dataset.dayOfWeek === String(dayOfWeek);
            option.hidden = !visible;
            option.disabled = !visible;

            if (visible && option.value === previousValue) {
                previousValueStillVisible = true;
            }
        }

        if (!previousValueStillVisible) {
            select.value = '';
        }
    }

    dayOfWeekFromDate(value) {
        if (!value) {
            return null;
        }

        const [year, month, day] = value.split('-').map(Number);
        if (!year || !month || !day) {
            return null;
        }

        // 0 = domingo … 6 = sábado (JS) → se convierte a 0 = lunes … 6 = domingo,
        // que es como se almacena TimeSlot::dayOfWeek.
        const jsDay = new Date(Date.UTC(year, month - 1, day)).getUTCDay();

        return (jsDay + 6) % 7;
    }
}
