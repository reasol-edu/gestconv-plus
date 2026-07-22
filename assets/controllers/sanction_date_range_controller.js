import { Controller } from '@hotwired/stimulus';
import { isNonWorkingDate, countSchoolDays, addSchoolDays } from '../utils/non_working_days.js';

// Fechas de vigencia de una sanción: mantiene sincronizados la fecha de fin y
// el nº de días lectivos del rango (min 1, máx 90), y si el inicio o el fin
// caen en un día no lectivo (fin de semana o festivo) muestra un aviso y
// bloquea el envío del formulario sin revertir la fecha elegida.
export default class extends Controller {
    static targets = ['dateBlock', 'start', 'end', 'count', 'startWarning', 'endWarning'];
    static values  = { nonWorkingDates: Array, blockedMessage: String };

    connect() {
        if (!this.hasStartTarget) {
            return;
        }

        if (this.startTarget.value && this.endTarget.value
            && !this.isInvalid(this.startTarget.value) && !this.isInvalid(this.endTarget.value)) {
            this.countTarget.value = countSchoolDays(this.startTarget.value, this.endTarget.value, this.nonWorkingDatesValue);
        } else if (!this.countTarget.value) {
            this.countTarget.value = '1';
        }
    }

    startChanged() {
        this.validateField(this.startTarget, this.startWarningTarget);
        this.recomputeEndFromStart();
    }

    endChanged() {
        this.validateField(this.endTarget, this.endWarningTarget);
        this.recomputeCountFromEnd();
    }

    countChanged() {
        this.applyCount();
    }

    increment() {
        this.countTarget.value = this.clampedCount() + 1;
        this.applyCount();
    }

    decrement() {
        this.countTarget.value = this.clampedCount() - 1;
        this.applyCount();
    }

    preventIfInvalid(event) {
        if (!this.hasStartTarget || (this.hasDateBlockTarget && this.dateBlockTarget.classList.contains('hidden'))) {
            return;
        }

        const startInvalid = this.validateField(this.startTarget, this.startWarningTarget);
        const endInvalid   = this.validateField(this.endTarget, this.endWarningTarget);

        if (startInvalid || endInvalid) {
            event.preventDefault();
        }
    }

    recomputeEndFromStart() {
        if (this.isInvalid(this.startTarget.value)) {
            return;
        }

        this.endTarget.value = addSchoolDays(this.startTarget.value, this.clampedCount(), this.nonWorkingDatesValue);
        this.validateField(this.endTarget, this.endWarningTarget);
    }

    recomputeCountFromEnd() {
        if (this.isInvalid(this.startTarget.value) || this.isInvalid(this.endTarget.value)) {
            return;
        }

        this.countTarget.value = this.clamp(countSchoolDays(this.startTarget.value, this.endTarget.value, this.nonWorkingDatesValue));
    }

    applyCount() {
        this.countTarget.value = this.clampedCount();

        if (this.isInvalid(this.startTarget.value)) {
            return;
        }

        this.endTarget.value = addSchoolDays(this.startTarget.value, this.clampedCount(), this.nonWorkingDatesValue);
        this.validateField(this.endTarget, this.endWarningTarget);
    }

    validateField(input, warningTarget) {
        const invalid = input.value !== '' && this.isInvalid(input.value);
        warningTarget.textContent = invalid ? this.blockedMessageValue : '';
        warningTarget.classList.toggle('hidden', !invalid);
        input.classList.toggle('border-red-300', invalid);

        return invalid;
    }

    isInvalid(value) {
        return value !== '' && isNonWorkingDate(value, this.nonWorkingDatesValue);
    }

    clampedCount() {
        return this.clamp(parseInt(this.countTarget.value, 10) || 1);
    }

    clamp(count) {
        return Math.min(90, Math.max(1, count));
    }
}
