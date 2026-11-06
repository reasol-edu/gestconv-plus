import { Controller } from '@hotwired/stimulus';

/**
 * Vista previa de la importación de la configuración de incidencias: el árbol de tipos y la lista de prioridades. Marcar
 * un tipo marca también sus ascendentes (cuelga de ellos) y desmarcarlo desmarca sus descendientes. El servidor valida lo
 * mismo: esto solo facilita elegir.
 */
export default class extends Controller {
    static targets = ['item'];

    toggled(event) {
        const input = event.currentTarget;
        if (input.dataset.index === undefined) {
            return;
        }

        if (input.checked) {
            // Los ascendentes, hasta la raíz.
            let parent = input.dataset.parent;
            while (parent !== undefined && parent !== '') {
                const node = this.#node(parent);
                if (!node) {
                    break;
                }
                node.checked = true;
                parent = node.dataset.parent;
            }

            return;
        }

        // Los descendientes, a cualquier profundidad.
        const unchecked = new Set([input.dataset.index]);
        let changed = true;
        while (changed) {
            changed = false;
            this.itemTargets.forEach((item) => {
                if (item.dataset.index !== undefined && unchecked.has(item.dataset.parent) && !unchecked.has(item.dataset.index)) {
                    item.checked = false;
                    unchecked.add(item.dataset.index);
                    changed = true;
                }
            });
        }
    }

    all() {
        this.itemTargets.forEach((item) => { item.checked = true; });
    }

    none() {
        this.itemTargets.forEach((item) => { item.checked = false; });
    }

    #node(index) {
        return this.itemTargets.find((item) => item.dataset.index === index);
    }
}
