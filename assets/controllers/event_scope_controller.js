import { Controller } from '@hotwired/stimulus';

/**
 * Formulario de eventos: las opciones «general» / «restringido a grupos» solo aparecen cuando hay
 * al menos un grupo asociado; sin grupos el evento es general y no hay nada que elegir.
 */
export default class extends Controller {
    static targets = ['groups', 'scopeBlock', 'radio'];

    connect() {
        this.#apply();
    }

    toggle() {
        this.#apply();
    }

    #apply() {
        const hasGroups = this.groupsTarget.selectedOptions.length > 0;
        this.scopeBlockTarget.classList.toggle('hidden', !hasGroups);

        // Al elegir el primer grupo, «restringido» es la opción esperable; el valor solo se usa con grupos.
        if (hasGroups && !this.radioTargets.some(r => r.checked)) {
            this.radioTargets.find(r => r.value === 'restricted')?.click();
        }
    }
}
