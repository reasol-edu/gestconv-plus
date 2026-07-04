import { Controller } from '@hotwired/stimulus';

const normalize = (text) => text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();

export default class extends Controller {
    static targets = ['input', 'count', 'item', 'group', 'empty'];
    static values  = { singular: String, plural: String };

    connect() {
        this.updateCount();
    }

    filter() {
        const q = normalize(this.inputTarget.value.trim());

        for (const item of this.itemTargets) {
            const matches = q === '' || normalize(item.textContent).includes(q);
            item.classList.toggle('hidden', !matches);
        }

        for (const group of this.groupTargets) {
            const anyVisible = group.querySelector('[data-checkbox-filter-target~="item"]:not(.hidden)') !== null;
            group.classList.toggle('hidden', !anyVisible);
        }

        if (this.hasEmptyTarget) {
            const anyVisible = this.itemTargets.some((item) => !item.classList.contains('hidden'));
            this.emptyTarget.classList.toggle('hidden', anyVisible);
        }
    }

    updateCount() {
        if (!this.hasCountTarget) return;

        const n = this.itemTargets.filter(
            (item) => item.querySelector('input[type="checkbox"]:checked') !== null,
        ).length;

        this.countTarget.textContent = n > 0
            ? `${n} ${n === 1 ? this.singularValue : this.pluralValue}`
            : '';
    }
}
