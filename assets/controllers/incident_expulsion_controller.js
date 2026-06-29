import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['checkbox', 'details'];

    connect() {
        this.sync();
    }

    toggle() {
        this.sync();
    }

    sync() {
        const expelled = this.checkboxTarget.checked;
        this.detailsTarget.classList.toggle('hidden', !expelled);
    }
}
