import { Controller } from '@hotwired/stimulus';

const ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
const MAX_STUDENTS = 5;

/**
 * Resumen de convivencia de los estudiantes elegidos en el formulario de parte: partes del último mes, sanción
 * vigente y notas activas, para decidir con contexto sin salir del formulario. Una tarjeta por estudiante.
 *
 * La lista sale del Tom Select del buscador (valores «estudiante::grupo»), al que se engancha igual que la
 * «selección rápida»; si el estudiante viene fijado de antemano (no hay buscador), se usa `initial`. El servidor
 * solo devuelve lo que la ficha del estudiante enseñaría al docente; aquí solo se pinta, con DOM y `textContent`.
 * Con más de MAX_STUDENTS elegidos no se consulta nada (una clase entera serían decenas de peticiones).
 */
export default class extends Controller {
    static targets = ['list'];
    static values = {
        selectId: String,
        url: String,
        profileUrl: String,
        initial: Array,
        title: String,
        errorLabel: String,
        tooManyLabel: String,
        recentOneLabel: String,
        recentManyLabel: String,
        recentNoneLabel: String,
        seriousLabel: String,
        sanctionLabel: String,
        sanctionUntilLabel: String,
        notesLabel: String,
        profileLabel: String,
    };

    connect() {
        this.cache = new Map();
        this.ts = null;
        this.names = new Map();

        this.onReady = () => this.#attach();
        window.addEventListener('students-select:ready', this.onReady);

        if (this.initialValue.length > 0) {
            for (const item of this.initialValue) {
                this.names.set(item.id, item.label);
            }
            this.#show(this.initialValue.map((item) => item.id));
        } else {
            this.#attach();
        }
    }

    disconnect() {
        window.removeEventListener('students-select:ready', this.onReady);
    }

    #attach() {
        if (this.ts !== null || this.selectIdValue === '') {
            return;
        }

        const element = document.getElementById(this.selectIdValue);
        if (!element || !element.tomselect) {
            return;
        }

        this.ts = element.tomselect;
        ['item_add', 'item_remove', 'clear'].forEach((name) => {
            this.ts.on(name, () => setTimeout(() => this.#sync(), 0));
        });
        this.#sync();
    }

    #sync() {
        const ids = [];
        for (const value of this.ts.items) {
            const id = String(value).split('::')[0];
            if (!ids.includes(id)) {
                ids.push(id);
            }
            const option = this.ts.options[value];
            if (option) {
                this.names.set(id, option.label);
            }
        }
        this.#show(ids);
    }

    #show(ids) {
        if (ids.length > MAX_STUDENTS) {
            this.#renderHint();
            return;
        }

        for (const id of ids) {
            if (!this.cache.has(id)) {
                this.cache.set(id, { state: 'loading' });
                this.#load(id, ids);
            }
        }
        this.#render(ids);
    }

    #load(id, ids) {
        fetch(this.urlValue.replace(ID_PLACEHOLDER, id), {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }
                return response.json();
            })
            .then((data) => this.cache.set(id, { state: 'ready', data }))
            .catch(() => this.cache.set(id, { state: 'error' }))
            .finally(() => this.#render(this.#currentIds(ids)));
    }

    #currentIds(fallback) {
        if (this.ts === null) {
            return fallback;
        }
        const ids = [];
        for (const value of this.ts.items) {
            const id = String(value).split('::')[0];
            if (!ids.includes(id)) {
                ids.push(id);
            }
        }
        return ids;
    }

    #renderHint() {
        const hint = document.createElement('p');
        hint.className = 'text-xs text-gray-500';
        hint.textContent = this.tooManyLabelValue;
        this.listTarget.replaceChildren(hint);
        this.element.classList.remove('hidden');
    }

    #render(ids) {
        if (ids.length > MAX_STUDENTS) {
            this.#renderHint();
            return;
        }

        const cards = [];
        for (const id of ids) {
            const entry = this.cache.get(id);
            if (!entry || entry.state === 'loading') {
                continue;
            }
            const card = entry.state === 'error' ? this.#errorCard(id) : this.#card(id, entry.data);
            if (card !== null) {
                cards.push(card);
            }
        }

        this.listTarget.replaceChildren(...cards);
        this.element.classList.toggle('hidden', cards.length === 0);
    }

    #card(id, data) {
        // El servidor devuelve un objeto vacío si no hay nada que enseñar (estudiante de otro centro, etc.).
        if (!data || data.related === undefined) {
            return null;
        }

        const chips = [];
        if (data.related || data.recentReports > 0) {
            if (data.recentReports === 0) {
                chips.push(this.#chip(this.recentNoneLabelValue, 'gray'));
            } else {
                const label = data.recentReports === 1 ? this.recentOneLabelValue : this.recentManyLabelValue;
                chips.push(this.#chip(label.replace('%count%', String(data.recentReports)), 'gray'));
                if (data.recentSerious > 0) {
                    chips.push(this.#chip(this.seriousLabelValue.replace('%count%', String(data.recentSerious)), 'amber'));
                }
            }
        }
        if (data.hasActiveSanction) {
            const label = data.sanctionUntil !== null
                ? this.sanctionUntilLabelValue.replace('%date%', data.sanctionUntil)
                : this.sanctionLabelValue;
            chips.push(this.#chip(label, 'red'));
        }
        for (const note of data.notes) {
            chips.push(this.#chip(`${this.notesLabelValue}: ${note.type} ×${note.count}`, 'sky'));
        }

        return this.#wrap(id, chips);
    }

    #errorCard(id) {
        return this.#wrap(id, [this.#chip(this.errorLabelValue, 'gray')]);
    }

    #wrap(id, chips) {
        const card = document.createElement('li');
        card.className = 'rounded-xl border border-gray-200 bg-white p-3';

        const head = document.createElement('div');
        head.className = 'flex items-baseline justify-between gap-2';
        const name = document.createElement('p');
        name.className = 'text-sm font-semibold text-gray-800';
        name.textContent = this.names.get(id) ?? '';
        const link = document.createElement('a');
        link.className = 'shrink-0 text-xs font-medium text-forest-700 hover:underline';
        link.href = this.profileUrlValue.replace(ID_PLACEHOLDER, id);
        link.target = '_blank';
        link.rel = 'noopener';
        link.textContent = this.profileLabelValue;
        head.append(name, link);

        const row = document.createElement('div');
        row.className = 'mt-2 flex flex-wrap gap-1.5';
        row.append(...chips);

        card.append(head, row);
        return card;
    }

    #chip(text, tone) {
        const tones = {
            gray: 'bg-gray-100 text-gray-700',
            amber: 'bg-amber-100 text-amber-800',
            red: 'bg-red-100 text-red-800',
            sky: 'bg-sky-100 text-sky-800',
        };
        const chip = document.createElement('span');
        chip.className = `inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium ${tones[tone]}`;
        chip.textContent = text;
        return chip;
    }
}
