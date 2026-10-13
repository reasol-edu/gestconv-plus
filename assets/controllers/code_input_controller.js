import { Controller } from '@hotwired/stimulus';

/*
 * Campo de código Base32 agrupado: pasa a mayúsculas, quita lo que no sea letra o dígito
 * y mete un guion cada 5 caracteres mientras se escribe (ABCDE-FGHIJ).
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { group: { type: Number, default: 5 } };

    format() {
        const raw = this.element.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        const groups = raw.match(new RegExp(`.{1,${this.groupValue}}`, 'g')) ?? [];
        this.element.value = groups.join('-');
    }
}
