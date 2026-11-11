import { Controller } from '@hotwired/stimulus';

// Lista de elementos de una secuencia del generador de calendarios: añadir una fila, subirla, bajarla
// o quitarla, y mostrar al lado de cada una las fechas que le tocarían (el mismo cálculo que usa el
// PDF, pedido al servidor mientras se rellena el formulario). El servidor lee las filas en el orden
// en que aparecen en la página, así que mover una fila de sitio es todo lo que hace falta para
// cambiar el orden. Sin JavaScript la lista sigue funcionando: solo faltan estos botones y las fechas.
export default class extends Controller {
    static targets = ['list', 'row', 'template', 'summary', 'warning'];
    static values = { url: String };

    connect() {
        this.counter = 0;
        this.refresh();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.pending?.abort();
    }

    add() {
        // El marcador del índice se sustituye por uno único: el servidor solo necesita que no se repita.
        const html = this.templateTarget.innerHTML.replaceAll('__INDEX__', `js${Date.now()}_${this.counter++}`);
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.listTarget.lastElementChild?.querySelector('input')?.focus();
        this.refresh();
    }

    up(event) {
        const row = event.currentTarget.closest('[data-sequence-items-target="row"]');
        const previous = row?.previousElementSibling;
        if (previous) {
            this.listTarget.insertBefore(row, previous);
            event.currentTarget.focus();
            this.refresh();
        }
    }

    down(event) {
        const row = event.currentTarget.closest('[data-sequence-items-target="row"]');
        const next = row?.nextElementSibling;
        if (next) {
            this.listTarget.insertBefore(next, row);
            event.currentTarget.focus();
            this.refresh();
        }
    }

    remove(event) {
        event.currentTarget.closest('[data-sequence-items-target="row"]')?.remove();
        this.refresh();
    }

    // Se llama al escribir en cualquier campo de la secuencia: solo importan la fecha de inicio, las
    // horas de cada día de la semana y las horas de cada elemento (no el nombre, los colores ni las descripciones).
    refresh(event) {
        const name = event?.target?.name ?? '';
        if (name !== '' && !/\[(startDate|mondayHours|tuesdayHours|wednesdayHours|thursdayHours|fridayHours|hours)\]$/.test(name)) {
            return;
        }
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.fetchDates(), 300);
    }

    field(suffix) {
        return this.element.querySelector(`[name$="[${suffix}]"]`)?.value ?? '';
    }

    async fetchDates() {
        const rows = this.rowTargets;
        const params = new URLSearchParams();
        params.set('curso', this.element.closest('form')?.elements.academicYear?.value ?? '');
        params.set('inicio', this.field('startDate'));
        ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].forEach((day, i) => params.set(`dias[${i + 1}]`, this.field(`${day}Hours`)));
        rows.forEach((row) => params.append('horas[]', row.querySelector('input[name$="[hours]"]')?.value ?? ''));

        this.pending?.abort();
        this.pending = new AbortController();
        try {
            const response = await fetch(`${this.urlValue}?${params}`, { signal: this.pending.signal, headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return this.show(rows, null);
            }
            this.show(rows, await response.json());
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.show(rows, null);
            }
        }
    }

    show(rows, data) {
        rows.forEach((row, i) => {
            const span = row.querySelector('[data-sequence-items-target="dates"]');
            if (span) {
                span.textContent = data?.items?.[i] ?? '';
            }
        });
        if (this.hasSummaryTarget) {
            this.summaryTarget.textContent = data?.summary ?? '';
        }
        if (this.hasWarningTarget) {
            this.warningTarget.textContent = data?.warning ?? '';
        }
    }
}
