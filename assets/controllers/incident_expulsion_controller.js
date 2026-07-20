import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['yesRadio', 'details'];

    connect() {
        this.sync();
    }

    toggle() {
        this.sync();
    }

    sync() {
        const expelled = this.yesRadioTarget.checked;
        this.detailsTarget.classList.toggle('hidden', !expelled);
    }
}
