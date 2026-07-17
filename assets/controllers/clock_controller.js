import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['display'];

    connect() {
        this.tick();
        this.timer = setInterval(this.tick, 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    tick = () => {
        this.displayTarget.textContent = new Date().toLocaleTimeString('es-ES', { hour12: false });
    };
}
