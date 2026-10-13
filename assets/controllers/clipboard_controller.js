import { Controller } from '@hotwired/stimulus';

/*
 * Copia al portapapeles el texto de la propiedad «text» y avisa durante un instante
 * cambiando la etiqueta del botón por la del valor «done».
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = { text: String, done: String };
    static targets = ['label'];

    async copy(event) {
        event.preventDefault();
        try {
            await navigator.clipboard.writeText(this.textValue);
        } catch {
            return;
        }
        if (!this.hasLabelTarget) {
            return;
        }
        const original = this.labelTarget.textContent;
        this.labelTarget.textContent = this.doneValue;
        setTimeout(() => { this.labelTarget.textContent = original; }, 2000);
    }
}
