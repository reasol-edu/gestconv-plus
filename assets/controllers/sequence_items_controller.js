import { Controller } from '@hotwired/stimulus';

// Lista de elementos de una secuencia del generador de calendarios: añadir una fila, subirla, bajarla
// o quitarla. El servidor lee las filas en el orden en que aparecen en la página, así que mover una
// fila de sitio es todo lo que hace falta para cambiar el orden. Sin JavaScript la lista sigue
// funcionando: solo faltan estos botones.
export default class extends Controller {
    static targets = ['list', 'row', 'template'];

    connect() {
        this.counter = 0;
    }

    add() {
        // El marcador del índice se sustituye por uno único: el servidor solo necesita que no se repita.
        const html = this.templateTarget.innerHTML.replaceAll('__INDEX__', `js${Date.now()}_${this.counter++}`);
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.listTarget.lastElementChild?.querySelector('input')?.focus();
    }

    up(event) {
        const row = event.currentTarget.closest('[data-sequence-items-target="row"]');
        const previous = row?.previousElementSibling;
        if (previous) {
            this.listTarget.insertBefore(row, previous);
            event.currentTarget.focus();
        }
    }

    down(event) {
        const row = event.currentTarget.closest('[data-sequence-items-target="row"]');
        const next = row?.nextElementSibling;
        if (next) {
            this.listTarget.insertBefore(next, row);
            event.currentTarget.focus();
        }
    }

    remove(event) {
        event.currentTarget.closest('[data-sequence-items-target="row"]')?.remove();
    }
}
