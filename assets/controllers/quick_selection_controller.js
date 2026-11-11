import { Controller } from '@hotwired/stimulus';

const GROUP_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';

/**
 * «Selección rápida» de estudiantes bajo el buscador del formulario de parte: botones de grupo; al pulsar uno se
 * listan sus estudiantes para marcar uno o varios. Cada marca añade (o quita) el estudiante en el Tom Select
 * del buscador, que sigue siendo la única fuente de verdad (lo que se envía son sus valores «estudiante::grupo»).
 *
 * Mientras se escribe en el buscador, todo el bloque se oculta; los estudiantes ya elegidos siguen en el
 * buscador como etiquetas. Al vaciarlo, el bloque vuelve tal como estaba.
 *
 * El Tom Select lo crea un script de la página, que avisa con el evento `students-select:ready`; si ya existía
 * al conectar este controlador, se enlaza directamente.
 */
export default class extends Controller {
    static targets = ['root', 'groups', 'panel', 'title', 'list', 'status', 'count', 'toggleAll', 'others', 'othersButton', 'othersIcon'];
    static values = {
        selectId: String,
        url: String,
        loadingLabel: String,
        errorLabel: String,
        emptyLabel: String,
        selectAllLabel: String,
        deselectAllLabel: String,
        countLabel: String,
    };

    connect() {
        this.students = [];
        this.request = 0;
        this.ts = null;

        this.onReady = () => this.#attach();
        window.addEventListener('students-select:ready', this.onReady);
        this.#attach();
    }

    disconnect() {
        window.removeEventListener('students-select:ready', this.onReady);
        if (this.ts !== null && this.onInput) {
            this.ts.control_input.removeEventListener('input', this.onInput);
        }
    }

    open(event) {
        const button = event.currentTarget;
        const request = ++this.request;

        this.titleTarget.textContent = button.dataset.groupName;
        this.listTarget.replaceChildren();
        this.students = [];
        this.#status(this.loadingLabelValue);
        this.groupsTarget.classList.add('hidden');
        this.panelTarget.classList.remove('hidden');
        this.#updateToggle();

        fetch(this.urlValue.replace(GROUP_PLACEHOLDER, button.dataset.groupId), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                return response.json();
            })
            .then((students) => {
                if (request !== this.request) {
                    return;
                }
                this.students = students;
                this.#render();
            })
            .catch(() => {
                if (request === this.request) {
                    this.#status(this.errorLabelValue);
                }
            });
    }

    toggleOthers() {
        const open = this.othersTarget.classList.toggle('hidden') === false;
        this.othersButtonTarget.setAttribute('aria-expanded', String(open));
        this.othersIconTarget.classList.toggle('rotate-180', open);
    }

    back() {
        this.request++;
        this.panelTarget.classList.add('hidden');
        this.groupsTarget.classList.remove('hidden');
    }

    toggleAll() {
        if (this.ts === null || this.students.length === 0) {
            return;
        }

        const allSelected = this.students.every((student) => this.ts.items.includes(student.value));
        if (allSelected) {
            this.students.forEach((student) => this.ts.removeItem(student.value, true));
            this.ts.updateOriginalInput();
        } else {
            this.students.forEach((student) => this.ts.addOption(student));
            this.ts.addItems(this.students.map((student) => student.value));
        }
        this.#sync();
    }

    toggle(event) {
        if (this.ts === null) {
            return;
        }

        const box = event.currentTarget;
        const student = this.students.find((candidate) => candidate.value === box.value);
        if (student === undefined) {
            return;
        }

        if (box.checked) {
            this.ts.addOption(student);
            this.ts.addItem(student.value);
        } else {
            this.ts.removeItem(student.value);
        }
        this.#sync();
    }

    #attach() {
        if (this.ts !== null) {
            return;
        }

        const element = document.getElementById(this.selectIdValue);
        if (!element || !element.tomselect) {
            return;
        }

        this.ts = element.tomselect;
        this.onInput = () => this.#visibility();
        this.ts.control_input.addEventListener('input', this.onInput);
        // Tom Select vacía el texto al elegir una opción o al perder el foco: se mira después de que lo haga.
        ['item_add', 'item_remove', 'clear', 'blur'].forEach((name) => {
            this.ts.on(name, () => setTimeout(() => {
                this.#visibility();
                this.#sync();
            }, 0));
        });
        this.#visibility();
    }

    #visibility() {
        if (this.ts === null) {
            return;
        }
        this.rootTarget.classList.toggle('hidden', this.ts.control_input.value.trim() !== '');
    }

    #render() {
        if (this.students.length === 0) {
            this.#status(this.emptyLabelValue);
            this.#updateToggle();
            return;
        }

        this.#status('');
        const rows = this.students.map((student) => {
            const label = document.createElement('label');
            label.className = 'flex min-h-12 cursor-pointer items-center gap-3 rounded-xl border border-gray-200 bg-white px-4 py-2.5 '
                + 'text-base text-gray-900 shadow-xs transition-colors hover:bg-forest-50 active:bg-forest-100 '
                + 'has-[:checked]:border-forest-500 has-[:checked]:bg-forest-50';

            const box = document.createElement('input');
            box.type = 'checkbox';
            box.value = student.value;
            box.checked = this.ts !== null && this.ts.items.includes(student.value);
            box.className = 'h-5 w-5 shrink-0 rounded-sm border-gray-300 text-forest-600 focus:ring-forest-500';
            box.dataset.action = 'change->quick-selection#toggle';

            const name = document.createElement('span');
            name.className = 'min-w-0 font-medium';
            name.textContent = student.label;

            label.append(box, name);

            const item = document.createElement('li');
            item.append(label);

            return item;
        });
        this.listTarget.replaceChildren(...rows);
        this.#sync();
    }

    // Pone al día las casillas, el contador y el botón de «seleccionar todos» con lo que hay elegido en el buscador.
    #sync() {
        if (this.ts === null) {
            return;
        }

        this.listTarget.querySelectorAll('input[type="checkbox"]').forEach((box) => {
            box.checked = this.ts.items.includes(box.value);
        });
        this.countTarget.textContent = this.countLabelValue.replace('%count%', String(this.ts.items.length));
        this.#updateToggle();
    }

    #updateToggle() {
        const allSelected = this.ts !== null && this.students.length > 0
            && this.students.every((student) => this.ts.items.includes(student.value));
        this.toggleAllTarget.textContent = allSelected ? this.deselectAllLabelValue : this.selectAllLabelValue;
        this.toggleAllTarget.classList.toggle('hidden', this.students.length === 0);
    }

    #status(text) {
        this.statusTarget.textContent = text;
        this.statusTarget.classList.toggle('hidden', text === '');
    }
}
