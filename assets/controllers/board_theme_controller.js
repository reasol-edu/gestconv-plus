import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = { mode: String };

    connect() {
        this.media = window.matchMedia('(prefers-color-scheme: dark)');
        this.media.addEventListener('change', this.apply);
        this.apply();
    }

    disconnect() {
        this.media.removeEventListener('change', this.apply);
    }

    apply = () => {
        const dark = this.modeValue === 'system' ? this.media.matches : this.modeValue !== 'light';
        this.element.classList.toggle('dark', dark);
    };
}
