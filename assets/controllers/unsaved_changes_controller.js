import { Controller } from '@hotwired/stimulus';

// Avisa antes de abandonar la página si el formulario tiene cambios sin guardar
// (cerrar la pestaña, recargar o seguir un enlace). No avisa si no se ha tocado
// ningún campo ni al enviar el formulario. Uso: <form data-controller="unsaved-changes">
export default class extends Controller {
    connect() {
        this.touched = false;
        this.submitting = false;
        this.snapshot = null;
        // Los editores de texto enriquecido normalizan su contenido al iniciarse:
        // la comparación parte de la foto tomada una vez estabilizado el formulario.
        this.timer = setTimeout(() => { this.snapshot = this.serialize(); }, 500);
        this.element.addEventListener('input', this.markTouched);
        this.element.addEventListener('change', this.markTouched);
        this.element.addEventListener('submit', this.onSubmit);
        window.addEventListener('beforeunload', this.onBeforeUnload);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.element.removeEventListener('input', this.markTouched);
        this.element.removeEventListener('change', this.markTouched);
        this.element.removeEventListener('submit', this.onSubmit);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
    }

    serialize() {
        const entries = [...new FormData(this.element).entries()].map(([name, value]) => [
            name,
            value instanceof File ? `${value.name}:${value.size}` : value,
        ]);

        return JSON.stringify(entries);
    }

    markTouched = () => {
        this.touched = true;
    };

    onSubmit = (event) => {
        // Otro controlador puede cancelar el envío (validaciones): se espera a que terminen todos.
        setTimeout(() => {
            if (!event.defaultPrevented) {
                this.submitting = true;
            }
        });
    };

    onBeforeUnload = (event) => {
        if (this.submitting || !this.touched || this.snapshot === null) {
            return;
        }
        if (this.serialize() === this.snapshot) {
            return;
        }
        event.preventDefault();
        event.returnValue = '';
    };
}
