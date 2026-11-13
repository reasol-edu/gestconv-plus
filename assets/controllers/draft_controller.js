import { Controller } from '@hotwired/stimulus';

const SKIPPED_FIELDS = ['_token', 'registered_by', 'students[]', 'location_id'];

/**
 * Borrador recuperable del formulario de nuevo parte. Guarda lo escrito en el almacenamiento local del
 * dispositivo (nunca en el servidor) para no perderlo si el móvil se bloquea o se cierra la pestaña a media
 * clase; al volver, un aviso ofrece recuperarlo o descartarlo. No restaura nada en silencio: en equipos
 * compartidos, el borrador de otra persona no debe aparecer solo.
 *
 * La clave lleva el docente y el centro. Caduca a las `ttlHours` horas. No se borra al enviar el formulario
 * (un error de validación lo perdería) sino en la pantalla de «partes creados» (`draft-clear`) o al cerrar sesión.
 *
 * Mientras el aviso está pendiente no se guarda, para no pisar el borrador que se está ofreciendo.
 */
export default class extends Controller {
    static targets = ['banner', 'age'];
    static values = {
        key: String,
        ttlHours: { type: Number, default: 24 },
        studentsId: String,
        locationId: String,
        // true si la página se muestra tras un envío con errores: el formulario ya trae lo enviado.
        posted: Boolean,
    };

    connect() {
        this.pending = false;
        this.timer = null;

        const draft = this.#read();
        if (draft !== null && !this.postedValue) {
            this.pending = true;
            this.draft = draft;
            this.ageTarget.textContent = this.#ago(draft.savedAt);
            this.bannerTarget.classList.remove('hidden');
        }

        this.onChange = () => this.#schedule();
        this.onHide = () => {
            if (document.visibilityState === 'hidden') this.save();
        };
        this.element.addEventListener('input', this.onChange);
        this.element.addEventListener('change', this.onChange);
        document.addEventListener('visibilitychange', this.onHide);
        window.addEventListener('pagehide', this.save);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('input', this.onChange);
        this.element.removeEventListener('change', this.onChange);
        document.removeEventListener('visibilitychange', this.onHide);
        window.removeEventListener('pagehide', this.save);
    }

    restore() {
        const draft = this.draft;
        this.pending = false;
        this.bannerTarget.classList.add('hidden');
        if (!draft) return;

        this.#restoreStudents(draft.students ?? []);
        this.#restoreLocation(draft.location ?? null);

        const byName = new Map();
        for (const [name, value] of draft.fields ?? []) {
            if (!byName.has(name)) byName.set(name, []);
            byName.get(name).push(value);
        }
        for (const [name, values] of byName) {
            for (const el of this.element.querySelectorAll(`[name="${CSS.escape(name)}"]`)) {
                if (el.type === 'checkbox' || el.type === 'radio') {
                    el.checked = values.includes(el.value);
                } else {
                    el.value = values[0];
                }
                // Los controladores de la página (p. ej. el de expulsión) reaccionan a estos eventos.
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
        this.#restoreRichText(byName.get('description')?.[0] ?? '');
    }

    discard() {
        this.pending = false;
        this.bannerTarget.classList.add('hidden');
        localStorage.removeItem(this.keyValue);
    }

    save = () => {
        if (this.pending) return;

        const fields = [];
        for (const [name, value] of new FormData(this.element).entries()) {
            if (typeof value !== 'string' || SKIPPED_FIELDS.includes(name)) continue;
            fields.push([name, value]);
        }

        const ts = this.#students();
        const students = ts ? ts.items.map((value) => this.#option(ts, value)) : [];
        const description = fields.find(([name]) => name === 'description')?.[1] ?? '';
        const hasText = description.replace(/<[^>]*>/g, '').trim() !== '';
        const hasBehaviors = fields.some(([name]) => name === 'behaviors[]');
        if (!hasText && !hasBehaviors && students.length === 0) {
            localStorage.removeItem(this.keyValue);
            return;
        }

        const location = this.#locationSelect();
        const locationValue = location ? location.getValue() : '';
        try {
            localStorage.setItem(this.keyValue, JSON.stringify({
                savedAt: Date.now(),
                fields,
                students,
                location: locationValue ? this.#option(location, locationValue) : null,
            }));
        } catch {
            // Almacenamiento lleno o desactivado: sin borrador, pero el formulario sigue funcionando.
        }
    };

    #schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(this.save, 700);
    }

    #read() {
        let draft = null;
        try {
            draft = JSON.parse(localStorage.getItem(this.keyValue) ?? 'null');
        } catch {
            draft = null;
        }
        if (draft === null || typeof draft.savedAt !== 'number'
            || Date.now() - draft.savedAt > this.ttlHoursValue * 3600 * 1000) {
            localStorage.removeItem(this.keyValue);
            return null;
        }
        return draft;
    }

    #students() {
        return this.studentsIdValue ? document.getElementById(this.studentsIdValue)?.tomselect ?? null : null;
    }

    #locationSelect() {
        return this.locationIdValue ? document.getElementById(this.locationIdValue)?.tomselect ?? null : null;
    }

    #option(ts, value) {
        const option = ts.options[value] ?? {};
        return { value, label: option.label ?? '', secondary: option.secondary ?? '' };
    }

    #restoreStudents(students) {
        const ts = this.#students();
        if (!ts) return;
        for (const student of students) {
            ts.addOption(student);
            ts.addItem(student.value, true);
        }
        ts.trigger('change');
    }

    #restoreLocation(location) {
        const ts = this.#locationSelect();
        if (!ts || location === null) return;
        if (!ts.options[location.value]) ts.addOption(location);
        ts.addItem(location.value, true);
        ts.trigger('change');
    }

    #restoreRichText(html) {
        const editor = this.element.querySelector('[data-controller~="rich-editor"]');
        const controller = editor ? this.application.getControllerForElementAndIdentifier(editor, 'rich-editor') : null;
        if (controller?.quill && html !== '') {
            controller.quill.clipboard.dangerouslyPasteHTML(html);
        }
    }

    #ago(savedAt) {
        const minutes = Math.max(0, Math.round((Date.now() - savedAt) / 60000));
        const rtf = new Intl.RelativeTimeFormat('es', { numeric: 'auto' });
        if (minutes < 60) return rtf.format(-minutes, 'minute');
        return rtf.format(-Math.round(minutes / 60), 'hour');
    }
}
