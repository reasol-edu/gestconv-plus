import { Controller } from '@hotwired/stimulus';

// Exige que se marque al menos un parte antes de enviar el formulario de notificación masiva.
export default class extends Controller {
    static targets = ['checkbox', 'error'];

    connect() {
        this.element.addEventListener('submit', this.onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    onSubmit = (event) => {
        const anyChecked = this.checkboxTargets.some((checkbox) => checkbox.checked);
        if (anyChecked) {
            if (this.hasErrorTarget) {
                this.errorTarget.classList.add('hidden');
            }
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        if (this.hasErrorTarget) {
            this.errorTarget.classList.remove('hidden');
            const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            this.errorTarget.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        }
    };
}
