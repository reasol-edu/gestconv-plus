import { Controller } from '@hotwired/stimulus';

/**
 * Mantiene al día el contenido de una zona de la página sin recargarla entera: cada cierto tiempo (y al volver a la
 * pestaña) vuelve a pedir la misma URL y sustituye el contenido del target «content» solo si ha cambiado. Los filtros,
 * la navegación y el resto de la página no se tocan. Si la petición falla o la sesión ha caducado (redirección o
 * respuesta sin la zona), se ignora y se reintenta en el siguiente ciclo.
 */
export default class extends Controller {
    static targets = ['content'];
    static values  = { interval: { type: Number, default: 30000 } };

    connect() {
        this.timer = setInterval(this.refresh, this.intervalValue);
        document.addEventListener('visibilitychange', this.onVisibility);
    }

    disconnect() {
        clearInterval(this.timer);
        document.removeEventListener('visibilitychange', this.onVisibility);
        this.abort?.abort();
    }

    onVisibility = () => {
        if (!document.hidden) {
            this.refresh();
        }
    };

    refresh = async () => {
        if (document.hidden || !this.hasContentTarget) {
            return;
        }

        this.abort?.abort();
        this.abort = new AbortController();

        try {
            const response = await fetch(window.location.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: this.abort.signal,
            });
            if (!response.ok || response.redirected) {
                return;
            }

            const doc  = new DOMParser().parseFromString(await response.text(), 'text/html');
            const next = doc.querySelector('[data-live-refresh-target="content"]');
            if (next && this.hasContentTarget && next.innerHTML !== this.contentTarget.innerHTML) {
                this.contentTarget.innerHTML = next.innerHTML;
            }
        } catch {
            // Sin red o petición cancelada: se reintenta en el siguiente ciclo.
        }
    };
}
