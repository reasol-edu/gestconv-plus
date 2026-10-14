import { Controller } from '@hotwired/stimulus';

// Filtra en el navegador una lista corta (p. ej. los estudiantes de un grupo) mientras se
// escribe, sin acentos ni mayúsculas. Cada elemento filtrable lleva `data-list-filter-target="item"`
// y su texto de búsqueda en `data-text`; `empty` es un aviso opcional cuando no queda ninguno.
export default class extends Controller {
    static targets = ['input', 'item', 'empty'];

    filter() {
        const query = this.normalize(this.inputTarget.value);
        let visible = 0;

        this.itemTargets.forEach((item) => {
            const match = query === '' || this.normalize(item.dataset.text || '').includes(query);
            item.classList.toggle('hidden', !match);
            if (match) {
                visible++;
            }
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('hidden', visible > 0);
        }
    }

    normalize(text) {
        return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
}
