import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['enterIcon', 'exitIcon'];

    connect() {
        document.addEventListener('fullscreenchange', this.onChange);
        this.onChange();
        this.request();
    }

    disconnect() {
        document.removeEventListener('fullscreenchange', this.onChange);
    }

    onChange = () => {
        const active = document.fullscreenElement !== null;
        this.enterIconTarget.classList.toggle('hidden', active);
        this.exitIconTarget.classList.toggle('hidden', !active);
    };

    async request() {
        if (document.fullscreenElement) {
            return;
        }
        try {
            await document.documentElement.requestFullscreen();
        } catch {
            // El navegador puede rechazar la petición automática al llegar por
            // navegación (sin gesto directo del usuario); el botón permite reintentarlo.
        }
    }

    toggle() {
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else {
            this.request();
        }
    }
}
