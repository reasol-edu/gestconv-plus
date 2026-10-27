import { Controller } from '@hotwired/stimulus';

/**
 * Formulario de reserva: los tramos horarios son de un día de la semana, así que solo se ofrecen los de la
 * fecha elegida (los demás se ocultan y se deshabilitan para que no se envíen), y la periodicidad semanal
 * solo muestra su fecha final cuando está marcada. El servidor valida lo mismo: esto solo facilita elegir.
 */
export default class extends Controller {
    static targets = ['date', 'slotGroup', 'noSlots', 'repeat', 'repeatBlock'];

    connect() {
        this.dateChanged();
        this.repeatChanged();
    }

    dateChanged() {
        if (!this.hasDateTarget || this.slotGroupTargets.length === 0) {
            return;
        }

        const value = this.dateTarget.value;
        // Sin fecha válida se muestran todos; los días van de 0 (lunes) a 6 (domingo), como en TimeSlot.
        const weekday = /^\d{4}-\d{2}-\d{2}$/.test(value) ? (new Date(value + 'T00:00:00').getDay() + 6) % 7 : null;

        let visible = 0;
        this.slotGroupTargets.forEach((group) => {
            const show = weekday === null || Number(group.dataset.day) === weekday;
            group.classList.toggle('hidden', !show);
            group.querySelectorAll('input').forEach((input) => {
                input.disabled = !show;
                if (!show) {
                    input.checked = false;
                }
            });
            if (show) {
                visible++;
            }
        });

        if (this.hasNoSlotsTarget) {
            this.noSlotsTarget.classList.toggle('hidden', visible > 0);
        }
    }

    repeatChanged() {
        if (this.hasRepeatTarget && this.hasRepeatBlockTarget) {
            this.repeatBlockTarget.classList.toggle('hidden', !this.repeatTarget.checked);
        }
    }
}
