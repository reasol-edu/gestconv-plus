import { Controller } from '@hotwired/stimulus';

/**
 * Selección de alumnado agrupado por curso y grupo: búsqueda por nombre (sin distinguir mayúsculas ni tildes),
 * «seleccionar todos» global y por grupo (solo sobre lo que muestra el filtro) y contador. Un estudiante que está
 * en dos grupos aparece en ambos con la misma casilla lógica: marcar una marca la otra.
 */
export default class extends Controller {
    static targets = ['search', 'row', 'checkbox', 'group', 'course', 'toggleAll', 'toggleGroup', 'count'];
    static values = { selectLabel: String, deselectLabel: String, countLabel: String };

    connect() {
        this.#refresh();
    }

    filter() {
        const query = this.#normalize(this.searchTarget.value.trim());
        this.rowTargets.forEach((row) => {
            row.classList.toggle('hidden', query !== '' && !this.#normalize(row.dataset.name).includes(query));
        });
        this.groupTargets.forEach((group) => {
            group.classList.toggle('hidden', !group.querySelector('[data-student-picker-target="row"]:not(.hidden)'));
        });
        this.courseTargets.forEach((course) => {
            course.classList.toggle('hidden', !course.querySelector('[data-student-picker-target="group"]:not(.hidden)'));
        });
        this.#refresh();
    }

    toggleAll() {
        this.#toggle(this.rowTargets);
    }

    toggleGroup(event) {
        const group = event.currentTarget.closest('[data-student-picker-target="group"]');
        this.#toggle(this.rowTargets.filter((row) => group.contains(row)));
    }

    changed(event) {
        const changed = event.currentTarget;
        this.checkboxTargets.filter((box) => box !== changed && box.value === changed.value).forEach((box) => {
            box.checked = changed.checked;
        });
        this.#refresh();
    }

    // Si todo lo visible ya está marcado se desmarca; si no, se marca.
    #toggle(rows) {
        const boxes = rows.filter((row) => !row.classList.contains('hidden')).map((row) => row.querySelector('input[type="checkbox"]'));
        const check = !boxes.every((box) => box.checked);
        boxes.forEach((box) => {
            box.checked = check;
            this.checkboxTargets.filter((other) => other !== box && other.value === box.value).forEach((other) => {
                other.checked = check;
            });
        });
        this.#refresh();
    }

    #refresh() {
        const visible = (rows) => rows.filter((row) => !row.classList.contains('hidden')).map((row) => row.querySelector('input[type="checkbox"]'));
        const label = (boxes) => (boxes.length > 0 && boxes.every((box) => box.checked) ? this.deselectLabelValue : this.selectLabelValue);

        this.toggleAllTargets.forEach((button) => {
            button.textContent = label(visible(this.rowTargets));
        });
        this.toggleGroupTargets.forEach((button) => {
            const group = button.closest('[data-student-picker-target="group"]');
            button.textContent = label(visible(this.rowTargets.filter((row) => group.contains(row))));
        });

        const selected = new Set(this.checkboxTargets.filter((box) => box.checked).map((box) => box.value));
        this.countTargets.forEach((target) => {
            target.textContent = this.countLabelValue.replace('%count%', String(selected.size));
        });
    }

    #normalize(text) {
        return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    }
}
